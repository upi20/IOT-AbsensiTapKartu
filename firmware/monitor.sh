#!/bin/bash
# Tampilkan output Serial ESP32 di terminal (macOS / Linux). Tekan Ctrl+C untuk keluar.
#
# Pemakaian (dari folder firmware/):
#   ./monitor.sh          -> 115200 baud
#   ./monitor.sh 9600     -> kecepatan lain
#   PORT=/dev/ttyUSB0 ./monitor.sh

cd "$(dirname "$0")"

CLI="$ARDUINO_CLI"
[ -z "$CLI" ] && command -v arduino-cli >/dev/null 2>&1 && CLI="$(command -v arduino-cli)"
[ -z "$CLI" ] && CLI="/Applications/Arduino IDE.app/Contents/Resources/app/lib/backend/resources/arduino-cli"
if [ ! -x "$CLI" ]; then
  echo "arduino-cli tidak ditemukan. Pasang Arduino IDE 2 / arduino-cli, atau isi ARDUINO_CLI=/path/arduino-cli."
  exit 1
fi

BAUD="${1:-115200}"
PORT="${PORT:-$(ls /dev/cu.usbserial-* /dev/cu.wchusbserial* /dev/cu.SLAB_USBtoUART* /dev/ttyUSB* /dev/ttyACM* 2>/dev/null | head -1)}"

if [ -z "$PORT" ]; then
  echo "ESP32 tidak terdeteksi. Cek kabel USB data (colok langsung ke komputer, bukan lewat hub)."
  exit 1
fi

# Hentikan Serial Monitor lain yang masih memegang port
pkill -f "tools/serial-monitor" 2>/dev/null && sleep 1

echo "Monitor $PORT @ $BAUD baud. Tekan tombol EN di ESP32 untuk mengulang program, Ctrl+C untuk keluar."
exec "$CLI" monitor --port "$PORT" --config "baudrate=$BAUD"
