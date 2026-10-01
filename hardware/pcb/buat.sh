#!/bin/bash
# Membuat ulang seluruh PCB papan induk dari buat_pcb.py, lalu:
#   1. menarik jalur (Freerouting) + tuangan GND      -> absensi-pcb.kicad_pcb
#   2. memeriksa DRC (berhenti kalau ada kesalahan)   -> hasil/drc.json
#   3. file pesanan JLCPCB (Gerber + bor, .zip)       -> hasil/absensi-pcb-jlcpcb.zip
#   4. file cetak 1:1 untuk cek ukuran di kertas      -> hasil/cetak-depan.pdf, hasil/cetak-belakang.pdf
#   5. gambar 3D                                      -> hasil/3d-depan.png, hasil/3d-belakang.png
# Pakai: ./buat.sh    (dari folder pcb/). Butuh KiCad 10 di /Applications/KiCad.
set -euo pipefail
cd "$(dirname "$0")"

K=/Applications/KiCad/KiCad.app/Contents
PY=$K/Frameworks/Python.framework/Versions/Current/bin/python3
CLI=$K/MacOS/kicad-cli
PCB=absensi-pcb.kicad_pcb

# Alat bantu (sekali saja): Freerouting + Java portabel di pcb/alat/
if ! ls alat/jdk-*/Contents/Home/bin/java >/dev/null 2>&1 || [ ! -f alat/freerouting-2.4.1.jar ]; then
  echo "Mengunduh Freerouting dan Java portabel ke pcb/alat/ ..."
  mkdir -p alat
  curl -sL -o alat/freerouting-2.4.1.jar \
    https://github.com/freerouting/freerouting/releases/download/v2.4.1/freerouting-2.4.1.jar
  curl -sL -o alat/jre.tar.gz "https://api.adoptium.net/v3/binary/latest/25/ga/mac/aarch64/jre/hotspot/normal/eclipse"
  tar xzf alat/jre.tar.gz -C alat && rm alat/jre.tar.gz
fi

diam() { grep -v "wxApp" || true; }

echo "1/5 Membuat papan dan menarik jalur ..."
for ulang in 1 2 3 4 5; do
  "$PY" buat_pcb.py "$PCB" 2>&1 | diam
  if "$PY" rute.py "$PWD/$PCB" 2>&1 | diam; then break; fi
  [ "$ulang" = 5 ] && { echo "Gagal menarik semua jalur setelah 5 kali."; exit 1; }
  echo "   Papan dibuat ulang, coba lagi ..."
done

echo "2/5 Memeriksa DRC ..."
mkdir -p hasil
"$CLI" pcb drc --severity-all --format json -o hasil/drc.json "$PCB" >/dev/null
python3 - <<'EOF'
import json, sys
d = json.load(open("hasil/drc.json"))
v, u = d["violations"], d["unconnected_items"]
print(f"   DRC: {len(v)} pelanggaran, {len(u)} belum tersambung")
for x in v[:20]:
    print("   -", x["severity"], x["description"], [i["description"] for i in x["items"]][:2])
sys.exit(1 if (v or u) else 0)
EOF

echo "3/5 File pesanan JLCPCB ..."
rm -rf hasil/gerber && mkdir -p hasil/gerber
"$CLI" pcb export gerbers -o hasil/gerber/ \
  -l "F.Cu,B.Cu,F.Mask,B.Mask,F.SilkS,B.SilkS,Edge.Cuts" --subtract-soldermask "$PCB" >/dev/null
"$CLI" pcb export drill -o hasil/gerber/ --format excellon --excellon-separate-th "$PCB" >/dev/null
rm -f hasil/absensi-pcb-jlcpcb.zip
(cd hasil/gerber && zip -q ../absensi-pcb-jlcpcb.zip ./*)
# Versi nama lain untuk pabrik yang meminta ekstensi tertentu (isinya sama persis):
#   -gko.zip : nama gaya Protel/Altium huruf besar, outline = .GKO, bor = .DRL
#   -gbr.zip : semua lapisan berekstensi .gbr
rm -rf hasil/tmp && mkdir -p hasil/tmp/gko hasil/tmp/gbr
for f in hasil/gerber/*; do
  n=$(basename "$f"); ext="${n##*.}"; nama="${n%.*}"
  case "$ext" in
    gm1) cp "$f" "hasil/tmp/gko/$nama.GKO" ;;
    drl) cp "$f" "hasil/tmp/gko/$nama.DRL" ;;
    gbrjob) ;;
    *) cp "$f" "hasil/tmp/gko/$nama.$(echo "$ext" | tr a-z A-Z)" ;;
  esac
  case "$ext" in
    drl|gbrjob) cp "$f" "hasil/tmp/gbr/$n" ;;
    *) cp "$f" "hasil/tmp/gbr/$nama.gbr" ;;
  esac
done
rm -f hasil/absensi-pcb-gko.zip hasil/absensi-pcb-gbr.zip
(cd hasil/tmp/gko && zip -q ../../absensi-pcb-gko.zip ./*)
(cd hasil/tmp/gbr && zip -q ../../absensi-pcb-gbr.zip ./*)
rm -rf hasil/tmp

echo "4/5 File cetak 1:1 ..."
"$CLI" pcb export pdf --mode-single -l "Edge.Cuts,F.SilkS,F.Fab,F.Cu" --drill-shape-opt 2 --black-and-white \
  -o hasil/cetak-depan.pdf "$PCB" >/dev/null
"$CLI" pcb export pdf --mode-single -l "Edge.Cuts,B.SilkS,B.Fab,B.Cu" --drill-shape-opt 2 --black-and-white --mirror \
  -o hasil/cetak-belakang.pdf "$PCB" >/dev/null

echo "5/5 Gambar 3D ..."
"$CLI" pcb render --side top -w 1600 -h 1600 --quality high -o hasil/3d-depan.png "$PCB" >/dev/null 2>&1 || true
"$CLI" pcb render --side bottom -w 1600 -h 1600 --quality high -o hasil/3d-belakang.png "$PCB" >/dev/null 2>&1 || true

echo "Selesai. Hasil ada di hardware/pcb/hasil/"
ls -1 hasil
