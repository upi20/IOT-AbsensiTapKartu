#!/bin/bash
# Membuat file cetak casing v2 (papan dot matrix 1 sisi 90 x 113) dari casing2.scad:
#   hasil/v2-depan.stl, hasil/v2-belakang.stl  -> kirim ke jasa cetak 3D
#   hasil/v2-*.png                             -> gambar untuk dicek
# lalu memeriksa tabrakan casing dengan papan, LCD, RC522, ESP32, USB-C, buzzer, dan LED.
# Pakai: cd hardware/casing && ./buat2.sh    (butuh OpenSCAD)
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p hasil
o() { openscad --backend=manifold "$@" casing2.scad >/dev/null 2>&1; }

echo "1/3 STL ..."
for b in depan belakang; do o -o "hasil/v2-$b.stl" -D "bagian=\"$b\""; done

echo "2/3 Gambar ..."
png() { o --render=force -o "hasil/v2-$1.png" -D "bagian=\"$2\"" --imgsize=1200,1000 --camera="$3" --colorscheme=Tomorrow; }
png depan lihat-depan 53,59,14,30,0,20,330
png dalam-depan lihat-depan 53,59,14,200,0,20,330
png belakang belakang 53,59,0,30,0,200,300
png dalam-belakang belakang 53,59,0,40,0,20,300
openscad -o hasil/v2-rakit.png -D 'bagian="rakit"' --imgsize=1200,1000 \
  --camera=53,59,10,40,0,20,330 --colorscheme=Tomorrow casing2.scad >/dev/null 2>&1

echo "3/3 Cek tabrakan ..."
gagal=0
for i in 0 1 2 3 4 5 6 7 8 9; do
  tmp=$(mktemp -t tabrakan).stl
  out=$(openscad --backend=manifold -D 'bagian="cek"' -D "item=$i" -o "$tmp" casing2.scad 2>&1 || true)
  nama=$(echo "$out" | sed -n 's/.*NAMA = "\(.*\)".*/\1/p' | head -1)
  vol=0
  [ -s "$tmp" ] && ! echo "$out" | grep -qi "empty" && vol=$(python3 volume_stl.py "$tmp")
  rm -f "$tmp"
  if python3 -c "import sys; sys.exit(0 if float('$vol') < 0.01 else 1)"; then
    echo "   ok   $nama"
  else
    echo "   TABRAKAN $nama ($vol mm3)"; gagal=1
  fi
done
[ $gagal = 0 ] && echo "Selesai. File cetak: hardware/casing/hasil/v2-depan.stl dan v2-belakang.stl" || exit 1
