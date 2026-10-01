#!/bin/bash
# Membuat file cetak casing dari casing.scad:
#   hasil/casing-depan.stl, hasil/casing-belakang.stl  -> kirim ke jasa cetak 3D
#   hasil/pratinjau-*.png                              -> gambar untuk dicek
# lalu memeriksa tabrakan casing dengan PCB, LCD, RC522, ESP32, buzzer, dll.
# Pakai: cd casing && ./buat.sh    (butuh OpenSCAD: brew install --cask openscad@snapshot)
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p hasil

echo "1/3 STL ..."
for b in depan belakang; do
  openscad --backend=manifold -o "hasil/casing-$b.stl" -D "bagian=\"$b\"" casing.scad >/dev/null 2>&1
done

echo "2/3 Gambar ..."
png() { openscad --backend=manifold --render=force -o "hasil/$1.png" -D "bagian=\"$2\"" --imgsize=1200,1000 \
          --camera="$3" --colorscheme=Tomorrow casing.scad >/dev/null 2>&1; }
png pratinjau-depan depan 50,55,20,25,0,15,330
png pratinjau-belakang belakang 50,55,20,155,0,195,330
png pratinjau-dalam belakang 50,55,10,35,0,20,300
openscad -o hasil/pratinjau-rakit.png -D 'bagian="rakit"' --imgsize=1200,1000 \
  --camera=50,55,20,70,0,35,360 --colorscheme=Tomorrow casing.scad >/dev/null 2>&1

echo "3/3 Cek tabrakan ..."
gagal=0
for i in 0 1 2 3 4 5 6 7 8 9 10; do
  tmp=$(mktemp -t tabrakan).stl
  out=$(openscad --backend=manifold -D "item=$i" -o "$tmp" cek_tabrakan.scad 2>&1 || true)
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
[ $gagal = 0 ] && echo "Selesai. File cetak: hardware/casing/hasil/casing-depan.stl dan casing-belakang.stl" || exit 1
