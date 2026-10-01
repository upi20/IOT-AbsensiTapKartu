#!/bin/bash
# Compile dan upload sketsa ke ESP32 tanpa membuka Arduino IDE (macOS / Linux).
#
# Pemakaian (dari folder firmware/):
#   ./upload.sh                 -> compile + upload firmware utama (absensi)
#   ./upload.sh -m              -> sama, lalu langsung buka monitor serial
#   ./upload.sh -c              -> compile saja, tanpa upload (tidak butuh alat)
#   ./upload.sh tes_rfid -m     -> sketsa lain: nama folder di firmware/ atau firmware/tes/
#   PORT=/dev/ttyUSB0 ./upload.sh            -> pilih port sendiri
#   ARDUINO_CLI=/path/arduino-cli ./upload.sh -> pakai arduino-cli di lokasi lain
#
# Opsi board per sketsa (tidak wajib): kalau folder sketsa berisi board_options.txt, isinya
# ditambahkan ke opsi board (satu opsi per baris; baris kosong dan baris diawali # diabaikan).
# absensi/board_options.txt berisi PartitionScheme=huge_app, sehingga board menjadi
#   esp32:esp32:esp32:UploadSpeed=460800,PartitionScheme=huge_app
# (Huge APP = aplikasi 3 MB tanpa OTA; firmware utama +- 1,3 MB tidak muat di partisi bawaan.)

set -e
cd "$(dirname "$0")"

cari_cli() {
  if [ -n "$ARDUINO_CLI" ]; then echo "$ARDUINO_CLI"; return; fi
  if command -v arduino-cli >/dev/null 2>&1; then command -v arduino-cli; return; fi
  local mac="/Applications/Arduino IDE.app/Contents/Resources/app/lib/backend/resources/arduino-cli"
  if [ -x "$mac" ]; then echo "$mac"; return; fi
}

cari_port() {
  if [ -n "$PORT" ]; then echo "$PORT"; return; fi
  ls /dev/cu.usbserial-* /dev/cu.wchusbserial* /dev/cu.SLAB_USBtoUART* /dev/ttyUSB* /dev/ttyACM* 2>/dev/null | head -1
}

BOARD="esp32:esp32:esp32:UploadSpeed=460800"   # ESP32 Dev Module, 460800 baud (921600 sering gagal di CH340)
MONITOR=""
CEK=""
ARGS=()
for a in "$@"; do
  case "$a" in
    -m) MONITOR=1 ;;
    -c) CEK=1 ;;
    *) ARGS+=("$a") ;;
  esac
done
NAMA="${ARGS[0]:-absensi}"

if [ -d "$NAMA" ]; then SKETCH="$NAMA"
elif [ -d "tes/$NAMA" ]; then SKETCH="tes/$NAMA"
else
  echo "Sketsa '$NAMA' tidak ditemukan di firmware/ maupun firmware/tes/."
  exit 1
fi

CLI="$(cari_cli)"
if [ -z "$CLI" ]; then
  echo "arduino-cli tidak ditemukan. Pasang Arduino IDE 2 / arduino-cli, atau isi ARDUINO_CLI=/path/arduino-cli."
  exit 1
fi

# Tambahkan opsi board dari board_options.txt (kalau ada)
if [ -f "$SKETCH/board_options.txt" ]; then
  OPSI=$(grep -v '^[[:space:]]*#' "$SKETCH/board_options.txt" | tr -d ' \r' | tr '\n' ',' | tr -s ',' | sed 's/^,//; s/,$//')
  if [ -n "$OPSI" ]; then
    BOARD="$BOARD,$OPSI"
  fi
fi

if [ -n "$CEK" ]; then
  echo "Compile '$SKETCH' (board $BOARD) ..."
  "$CLI" compile --fqbn "$BOARD" "$SKETCH"
  echo "Compile berhasil."
  exit 0
fi

PORT="$(cari_port)"
if [ -z "$PORT" ]; then
  echo "ESP32 tidak terdeteksi. Cek kabel USB data (colok langsung ke komputer, bukan lewat hub),"
  echo "atau tentukan port sendiri, contoh: PORT=/dev/ttyUSB0 ./upload.sh"
  exit 1
fi

# Hentikan Serial Monitor (dari Arduino IDE atau ./monitor.sh) yang masih memegang port
pkill -f "tools/serial-monitor" 2>/dev/null && sleep 1 || true

echo "Upload '$SKETCH' ke $PORT (board $BOARD) ..."
"$CLI" compile --fqbn "$BOARD" --upload --port "$PORT" "$SKETCH"
echo "Upload selesai."

if [ -n "$MONITOR" ]; then
  exec ./monitor.sh
fi
