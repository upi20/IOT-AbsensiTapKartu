#!/usr/bin/env bash
# =============================================================================
# Pemeriksa kesesuaian server dengan Spesifikasi API Absensi RFID Terintegrasi (v1)
# (doc/spesifikasi-api.md). Berpura-pura menjadi alat: memanggil /ping, /heartbeat
# (termasuk kesehatan lengkap: error, OTA gagal, crash), dan /tap, lalu memeriksa
# kode HTTP, batas waktu, dan field JSON yang wajib.
# - config (title, dim_after, dim_level, restart_at, announcements_rev) diperiksa
#   tipenya; yang tidak dikirim diberi tanda ⚠️ (dibutuhkan fitur wajib aplikasi).
# - GET /announcements: 404 hanya ⚠️, balasan 200 harus sesuai format.
# - Kalau server mengirim config.firmware_update, file firmware ikut diunduh dan
#   Content-Length, ukuran, serta MD5-nya dicocokkan.
# - POST /check-in & /check-out (mode absen "Pilih Datang/Pulang", opsional):
#   404 hanya ⚠️; kalau disediakan, balasan & kiriman ulang tap_id lintas endpoint diperiksa.
#
# Pakai:   ./cek-server.sh BASE_URL API_KEY [RFID_TERDAFTAR]
# Contoh:  ./cek-server.sh http://localhost:8080 ganti-dengan-kunci-anda 0218893066
#
# Opsional: HITUNG_DATA = perintah yang mencetak jumlah baris absensi di server
# Anda. Kalau diisi, skrip juga memastikan tap_id yang dikirim ulang tidak
# menambah baris. Contoh untuk server PHP bawaan:
#   HITUNG_DATA="sqlite3 ../php/data.sqlite 'SELECT COUNT(*) FROM absensi'" ./cek-server.sh ...
#
# Butuh: bash, curl, python3 (hanya untuk membaca JSON; tidak perlu jq).
# Catatan: skrip ini MENGIRIM TAP SUNGGUHAN — jalankan di server uji, bukan produksi.
# =============================================================================

BASE="${1%/}"
KEY="$2"
RFID="${3:-0218893066}"
DEVICE="ABS-TES001"
KARTU_ASING="9999999990"   # nomor kartu yang diasumsikan TIDAK terdaftar

if [ -z "$BASE" ] || [ -z "$KEY" ]; then
  echo "Pakai: $0 BASE_URL API_KEY [RFID_TERDAFTAR]"
  echo "Contoh: $0 http://localhost:8080 ganti-dengan-kunci-anda 0218893066"
  exit 2
fi
for p in curl python3; do
  command -v "$p" >/dev/null || { echo "Perintah '$p' tidak ditemukan."; exit 2; }
done

GAGAL=0
TMP="$(mktemp)"
PERTAMA="$(mktemp)"   # salinan respons tap pertama, untuk dibandingkan saat dikirim ulang
DETAK="$(mktemp)"     # salinan respons heartbeat terakhir (untuk config.firmware_update)
BIN="$(mktemp)"       # file firmware yang diunduh
HDR="$(mktemp)"       # header respons unduhan firmware
trap 'rm -f "$TMP" "$PERTAMA" "$DETAK" "$BIN" "$HDR"' EXIT

lulus() { echo "  ✅ $1"; }
gagal() { echo "  ❌ $1"; GAGAL=$((GAGAL + 1)); }

# panggil METODE PATH KUNCI [BODY]  → mengisi $KODE, $DETIK, $BATAS, dan file $TMP (isi respons)
panggil() {
  # Batas tunggu alat (spesifikasi bagian 2): /ping 8 detik, endpoint lain 3 detik.
  case "$2" in */ping) BATAS=8 ;; *) BATAS=3 ;; esac
  local args=(-s -o "$TMP" -w '%{http_code} %{time_total}' -m 10 -X "$1" "$BASE$2"
              -H "X-API-Key: $3" -H "X-Device-ID: $DEVICE" -H "X-Spec-Version: 1"
              -H "Accept: application/json")
  [ -n "$4" ] && args+=(-H "Content-Type: application/json" -d "$4")
  read -r KODE DETIK < <(curl "${args[@]}")
  echo "→ $1 $2   (HTTP $KODE, ${DETIK}s)"
  if [ "$KODE" = "000" ]; then
    : > "$TMP"; echo "    (tidak ada jawaban: server mati, Base URL salah, atau lewat batas waktu)"
  else
    echo "    $(head -c 300 "$TMP")"
  fi
}

# cek_http KODE_DIHARAPKAN
cek_http() {
  if [ "$KODE" = "$1" ]; then lulus "HTTP $1"; else gagal "HTTP harus $1, didapat $KODE"; fi
  python3 -c "import sys; sys.exit(0 if float('$DETIK' or 99) < $BATAS else 1)" \
    || gagal "terlalu lambat (${DETIK}s), alat hanya menunggu $BATAS detik"
}

# cek_json JENIS  → memeriksa isi JSON respons sesuai spesifikasi
#   JENIS: ping | heartbeat | tap | tap-asing | ulang (bandingkan dengan $PERTAMA) | salah (HTTP 400)
#          | pengumuman (GET /announcements)
cek_json() {
  python3 - "$1" "$TMP" "$PERTAMA" <<'PY'
import json, re, sys
jenis, berkas, pertama = sys.argv[1], sys.argv[2], sys.argv[3]
gagal = 0
def ok(pesan):  print("  ✅ " + pesan)
def salah(pesan):
    global gagal; gagal += 1; print("  ❌ " + pesan)
def cek(syarat, pesan): ok(pesan) if syarat else salah(pesan)

try:
    d = json.load(open(berkas, encoding="utf-8"))
except Exception:
    salah("respons bukan JSON yang valid"); sys.exit(1)
if not isinstance(d, dict):
    salah("respons harus objek JSON {...}"); sys.exit(1)

ISO = r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:\d{2}|Z)$"
STATUS = ["check_in", "check_out", "duplicate", "unknown", "rejected"]
OK_TRUE = ["check_in", "check_out", "duplicate"]   # ok=true; unknown & rejected → ok=false

if jenis == "salah":
    cek(isinstance(d.get("message"), str), '"message" menjelaskan kesalahannya')

if jenis in ("ping", "heartbeat"):
    cek(d.get("ok") is True, '"ok" = true')
    st = d.get("server_time")
    if st is None: print('  ⚠️  "server_time" tidak ada (disarankan, untuk menyamakan jam alat)')
    else: cek(isinstance(st, str) and re.match(ISO, st), '"server_time" format ISO 8601 + zona waktu (%s)' % st)
    if "config" not in d:
        print('  ⚠️  "config" tidak ada: judul, layar redup, jam restart, dan pengumuman tidak bisa diatur dari aplikasi')
    if "config" in d:
        c = d["config"]
        if isinstance(c, dict):
            for kunci in ("title", "dim_after", "dim_level", "restart_at", "announcements_rev"):
                if kunci not in c:
                    print('  ⚠️  "config.%s" tidak dikirim (dibutuhkan fitur wajib Pengaturan/Alat/Pengumuman)' % kunci)
        cek(isinstance(c, dict), '"config" berupa objek')
        if isinstance(c, dict) and "pin" in c:
            cek(isinstance(c["pin"], str) and re.match(r"^\d{4,8}$", c["pin"]), '"config.pin" teks 4–8 digit')
        if isinstance(c, dict) and "title" in c:
            cek(isinstance(c["title"], str) and 1 <= len(c["title"]) <= 30,
                '"config.title" berupa teks, maks. 30 karakter (%d)' % len(str(c["title"])))
        if isinstance(c, dict) and "announcements_rev" in c:
            cek(isinstance(c["announcements_rev"], str) and c["announcements_rev"] != "",
                '"config.announcements_rev" berupa teks (%r)' % (c["announcements_rev"],))
        if isinstance(c, dict) and "dim_after" in c:
            v = c["dim_after"]
            cek(type(v) is int and (v == 0 or 10 <= v <= 3600), '"config.dim_after" bilangan bulat 0 atau 10–3600 (%s)' % v)
        if isinstance(c, dict) and "dim_level" in c:
            v = c["dim_level"]
            cek(type(v) is int and 0 <= v <= 100, '"config.dim_level" bilangan bulat 0–100 (%s)' % v)
        if isinstance(c, dict) and "restart_at" in c:
            v = c["restart_at"]
            cek(isinstance(v, str) and (v == "" or re.fullmatch(r"([01]\d|2[0-3]):[0-5]\d", v)),
                '"config.restart_at" teks "" atau "HH:MM" 00:00–23:59 (%r)' % (v,))
        if isinstance(c, dict) and "tap_mode" in c:
            cek(c["tap_mode"] in ("auto", "select"), '"config.tap_mode" "auto" atau "select" (%r)' % (c["tap_mode"],))
        if isinstance(c, dict) and "firmware_update" in c:
            f = c["firmware_update"]
            cek(isinstance(f, dict), '"config.firmware_update" berupa objek')
            if isinstance(f, dict):
                cek(isinstance(f.get("version"), str) and f["version"] != "", 'firmware_update.version berupa teks (%r)' % (f.get("version"),))
                cek(isinstance(f.get("url"), str) and f["url"].startswith(("http://", "https://")),
                    'firmware_update.url berupa URL absolut http(s) (%r)' % (f.get("url"),))
                cek(type(f.get("size")) is int and 1 <= f["size"] <= 1966080, 'firmware_update.size bilangan bulat 1–1966080 (%r)' % (f.get("size"),))
                cek(isinstance(f.get("md5"), str) and re.fullmatch(r"[0-9a-f]{32}", f["md5"]),
                    'firmware_update.md5 32 huruf hex kecil (%r)' % (f.get("md5"),))

if jenis == "pengumuman":
    IKON = ["info", "pengumuman", "kalender", "jam", "peringatan", "rapat", "libur", "selamat", "kesehatan", "buku"]
    if "ok" in d: cek(d["ok"] is True, '"ok" = true')
    for kunci, bawah, atas in (("interval", 2, 60), ("idle", 5, 600)):
        if kunci in d:
            v = d[kunci]
            cek(isinstance(v, int) and not isinstance(v, bool) and bawah <= v <= atas,
                '"%s" bilangan bulat %d–%d (%s)' % (kunci, bawah, atas, v))
    items = d.get("items")
    cek(isinstance(items, list), '"items" berupa daftar')
    if isinstance(items, list):
        if not items: print('  ⚠️  "items" kosong: screensaver tidak tampil di alat')
        if len(items) > 10: print('  ⚠️  %d pengumuman, alat hanya menampilkan 10 pertama' % len(items))
        for n, it in enumerate(items[:10], 1):
            if not isinstance(it, dict):
                salah("items[%d] harus objek {...}" % n); continue
            t = it.get("title")
            cek(isinstance(t, str) and t.strip() != "", 'items[%d].title berupa teks (%s)' % (n, t))
            if isinstance(t, str) and len(t) > 40: print('  ⚠️  items[%d].title > 40 karakter' % n)
            ds = it.get("description")
            if ds is not None:
                cek(isinstance(ds, str), 'items[%d].description berupa teks' % n)
                if isinstance(ds, str) and len(ds) > 160: print('  ⚠️  items[%d].description > 160 karakter' % n)
            if "id" in it and it["id"] is not None:
                cek(isinstance(it["id"], str), 'items[%d].id berupa teks' % n)
            ik = it.get("icon")
            if ik not in (None, "") and ik not in IKON:
                print('  ⚠️  items[%d].icon "%s" tidak dikenal, alat memakai "info"' % (n, ik))

if jenis == "ulang":
    lama = json.load(open(pertama, encoding="utf-8"))
    cek(d == lama or d.get("status") == "duplicate",
        'tap_id sama → respons sama seperti sebelumnya atau "duplicate" (didapat: %s)' % d.get("status"))

if jenis in ("tap", "tap-asing", "ulang"):
    s = d.get("status")
    cek(isinstance(s, str) and s != "", '"status" ada (%s)' % s)
    if s in STATUS:
        cek(d.get("ok") is (s in OK_TRUE), '"ok" = %s untuk status %s' % (str(s in OK_TRUE).lower(), s))
    else:
        cek(isinstance(d.get("ok"), bool), '"ok" berupa true/false')
        print('  ⚠️  status "%s" bukan nilai standar, alat akan menampilkannya sebagai INFO' % s)
    if jenis == "tap-asing":
        cek(s == "unknown", 'kartu tidak terdaftar → status "unknown"')
    if s == "unknown" and jenis == "tap":
        print('  ⚠️  kartu uji belum terdaftar di server Anda; beri RFID terdaftar sebagai argumen ke-3')
    m = d.get("message")
    if m is None: print('  ⚠️  "message" tidak ada (disarankan)')
    else:
        cek(isinstance(m, str), '"message" berupa teks')
        if isinstance(m, str) and len(m) > 32: print('  ⚠️  "message" > 32 karakter, akan dipotong di layar')
    if "name" in d and d["name"] is not None: cek(isinstance(d["name"], str), '"name" berupa teks')
    if "time" in d and d["time"] is not None:
        cek(isinstance(d["time"], str) and re.match(r"^\d{2}:\d{2}$", d["time"]), '"time" format HH:MM')
    if "photo_url" in d and d["photo_url"] is not None:
        cek(isinstance(d["photo_url"], str) and d["photo_url"].startswith(("http://", "https://")),
            '"photo_url" berupa URL http(s) atau null')
    if "info" in d and d["info"] is not None:
        i = d["info"]
        cek(isinstance(i, list) and len(i) <= 2 and all(isinstance(x, str) for x in i),
            '"info" berupa daftar teks, maks. 2 baris')
sys.exit(gagal)
PY
  GAGAL=$((GAGAL + $?))
}

# Waktu sekarang dan kemarin jam 07:30, format ISO 8601 dengan zona waktu lokal.
SEKARANG="$(python3 -c 'import datetime; print(datetime.datetime.now().astimezone().replace(microsecond=0).isoformat())')"
KEMARIN="$(python3 -c 'import datetime as d; t=d.datetime.now().astimezone()-d.timedelta(days=1); print(t.replace(hour=7,minute=30,second=0,microsecond=0).isoformat())')"

ID_UJI="CEK-$(date +%s)-$$"   # awalan tap_id, unik per kali jalan

tap_json() { # tap_json RFID QUEUED TAPPED_AT TAP_ID [MODE]   (MODE untuk /check-in & /check-out)
  local mode=""
  [ -n "$5" ] && mode=",\"mode\":\"$5\""
  echo "{\"device_id\":\"$DEVICE\",\"tap_id\":\"$4\",\"rfid\":\"$1\",\"tapped_at\":\"$3\",\"queued\":$2$mode,\"raw\":{\"uid_hex\":\"0A0B0C0D\",\"rssi\":-52,\"ip\":\"192.168.1.23\",\"firmware\":\"cek-server\",\"uptime_s\":60,\"server_status\":\"online\"}}"
}

# ada_endpoint NAMA → 1 (dan ⚠️) kalau server membalas 404 = endpoint opsional tidak disediakan.
ada_endpoint() {
  if [ "$KODE" = "404" ]; then
    echo "  ⚠️  $1 tidak disediakan (opsional; jangan pakai mode absen \"Pilih Datang/Pulang\" di alat, alat akan menampilkan E23)"
    return 1
  fi
  return 0
}

echo "Memeriksa $BASE  (RFID uji: $RFID)"
echo

echo "1. Tes koneksi"
panggil GET /ping "$KEY";                    cek_http 200; cek_json ping
echo
echo "2. API key salah harus ditolak"
panggil GET /ping "kunci-salah-xyz";         cek_http 401
panggil POST /tap "kunci-salah-xyz" "$(tap_json "$RFID" false "$SEKARANG" "$ID_UJI-0")"; cek_http 401
echo
echo "3. Heartbeat dengan kesehatan lengkap (error di layar, update gagal, laporan crash)"
panggil POST /heartbeat "$KEY" "{\"device_id\":\"$DEVICE\",\"firmware\":\"cek-server\",\"time\":\"$SEKARANG\",\"raw\":{\"wifi_ssid\":\"Tes\",\"rssi\":-71,\"ip\":\"192.168.1.23\",\"uptime_s\":30,\"free_heap\":176000,\"min_free_heap\":148000,\"reset_reason\":\"panic\",\"rfid_ok\":false,\"queue\":3,\"error\":\"E30\",\"ota_failed\":\"0.0.1\",\"crash\":{\"task\":\"loopTask\",\"pc\":\"0x400d4634\",\"backtrace\":\"0x400d4634 0x400880ed\",\"elf\":\"3f2a9c1b\"}}}"
cek_http 200; cek_json heartbeat
echo
echo "4. Heartbeat biasa"
panggil POST /heartbeat "$KEY" "{\"device_id\":\"$DEVICE\",\"firmware\":\"cek-server\",\"time\":\"$SEKARANG\",\"raw\":{\"wifi_ssid\":\"Tes\",\"rssi\":-52,\"ip\":\"192.168.1.23\",\"uptime_s\":60,\"free_heap\":180000,\"min_free_heap\":150000,\"reset_reason\":\"poweron\",\"rfid_ok\":true,\"queue\":0}}"
cek_http 200; cek_json heartbeat
cp "$TMP" "$DETAK"
echo
echo "5. Tap kartu terdaftar"
panggil POST /tap "$KEY" "$(tap_json "$RFID" false "$SEKARANG" "$ID_UJI-1")"; cek_http 200; cek_json tap
cp "$TMP" "$PERTAMA"
echo
echo "6. Tap yang sama dikirim ulang dari antrean (tap_id sama) → tidak boleh tercatat dua kali"
[ -n "$HITUNG_DATA" ] && SEBELUM="$(bash -c "$HITUNG_DATA")"
panggil POST /tap "$KEY" "$(tap_json "$RFID" true "$SEKARANG" "$ID_UJI-1")";  cek_http 200; cek_json ulang
if [ -n "$HITUNG_DATA" ]; then
  SESUDAH="$(bash -c "$HITUNG_DATA")"
  if [ "$SEBELUM" = "$SESUDAH" ]; then lulus "jumlah baris absensi tetap ($SESUDAH)"
  else gagal "jumlah baris absensi bertambah ($SEBELUM → $SESUDAH)"; fi
fi
echo
echo "7. Tap antrean (queued: true, tapped_at kemarin 07:30)"
panggil POST /tap "$KEY" "$(tap_json "$RFID" true "$KEMARIN" "$ID_UJI-2")";   cek_http 200; cek_json tap
echo
echo "8. Tap antrean dengan tapped_at null dan raw null (jam alat belum tersinkron)"
panggil POST /tap "$KEY" "{\"device_id\":\"$DEVICE\",\"tap_id\":\"$ID_UJI-5\",\"rfid\":\"$RFID\",\"tapped_at\":null,\"queued\":true,\"raw\":null}"
cek_http 200; cek_json tap
echo
echo "9. Tap kartu tidak terdaftar ($KARTU_ASING) → tetap HTTP 200"
panggil POST /tap "$KEY" "$(tap_json "$KARTU_ASING" false "$SEKARANG" "$ID_UJI-3")"; cek_http 200; cek_json tap-asing
echo
echo "10. Request salah format → HTTP 400"
panggil POST /tap "$KEY" "ini-bukan-json";                                cek_http 400; cek_json salah
panggil POST /tap "$KEY" "$(tap_json "" false "$SEKARANG" "$ID_UJI-4")";  cek_http 400; cek_json salah
echo
echo "11. Pengumuman / screensaver (opsional)"
panggil GET /announcements "$KEY"
if [ "$KODE" = "404" ]; then
  echo "  ⚠️  GET /announcements tidak disediakan (opsional)"
else
  cek_http 200; [ "$KODE" = "200" ] && cek_json pengumuman
fi
echo
echo "12. Update firmware jarak jauh (opsional, hanya kalau heartbeat mengirim config.firmware_update)"
# Baca config.firmware_update dari heartbeat terakhir: "versi url size md5 satu_origin" (kosong = tidak ada).
python3 - "$DETAK" "$BASE" > "$HDR" <<'PY'
import json, sys
from urllib.parse import urlsplit
try:
    f = json.load(open(sys.argv[1], encoding="utf-8")).get("config", {}).get("firmware_update")
except Exception:
    f = None
if isinstance(f, dict) and isinstance(f.get("url"), str):
    def origin(u):
        p = urlsplit(u)
        return (p.scheme, p.hostname, p.port or {"http": 80, "https": 443}.get(p.scheme))
    print(f.get("version"), f["url"], f.get("size"), f.get("md5"), "ya" if origin(f["url"]) == origin(sys.argv[2]) else "tidak")
PY
read -r FW_VERSI FW_URL FW_SIZE FW_MD5 FW_ORIGIN < "$HDR"
if [ -z "$FW_URL" ]; then
  echo "  ⚠️  server tidak mengirim config.firmware_update untuk $DEVICE (wajar kalau alat ini tidak dijadwalkan update)"
else
  # Alat hanya mengirim header API kalau URL satu origin dengan Base URL (spesifikasi bagian 6.1).
  args=(-s -o "$BIN" -D "$HDR" -w '%{http_code} %{time_total}' -m 60 "$FW_URL")
  [ "$FW_ORIGIN" = "ya" ] && args+=(-H "X-API-Key: $KEY" -H "X-Device-ID: $DEVICE" -H "X-Spec-Version: 1")
  read -r KODE DETIK < <(curl "${args[@]}")
  echo "→ GET $FW_URL   (HTTP $KODE, ${DETIK}s, versi $FW_VERSI)"
  if [ "$KODE" = "200" ]; then lulus "HTTP 200 (tanpa redirect)"; else gagal "HTTP harus 200 tanpa redirect, didapat $KODE"; fi
  if [ "$KODE" = "200" ]; then
    python3 - "$BIN" "$HDR" "$FW_SIZE" "$FW_MD5" <<'PY'
import hashlib, sys
berkas, hdr, size, md5 = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4]
gagal = 0
def cek(syarat, pesan):
    global gagal
    print(("  ✅ " if syarat else "  ❌ ") + pesan); gagal += 0 if syarat else 1
isi = open(berkas, "rb").read()
header = {}
for baris in open(hdr, encoding="latin-1").read().splitlines():
    if ":" in baris:
        k, v = baris.split(":", 1); header[k.strip().lower()] = v.strip()
cl = header.get("content-length")
cek(cl is not None and cl.isdigit() and int(cl) == size, "Content-Length = firmware_update.size (%s / %d)" % (cl, size))
cek(len(isi) == size, "ukuran file yang diunduh = size (%d / %d byte)" % (len(isi), size))
dapat = hashlib.md5(isi).hexdigest()
cek(dapat == md5, "MD5 file = firmware_update.md5 (%s)" % dapat)
if "application/octet-stream" not in header.get("content-type", ""):
    print("  ⚠️  Content-Type sebaiknya application/octet-stream (%s)" % header.get("content-type"))
if not isi.startswith(b"\xe9"):
    print("  ⚠️  byte pertama bukan 0xE9: bukan image aplikasi ESP32?")
if "x-md5" in header:
    cek(header["x-md5"].lower() == dapat, "header x-MD5 cocok")
sys.exit(gagal)
PY
    GAGAL=$((GAGAL + $?))
  fi
fi
echo
echo "13. Mode absen \"Pilih Datang/Pulang\": POST /check-in & /check-out (opsional)"
# a) tap_id dari langkah 5 (/tap) dikirim ulang ke /check-in → respons lama, tidak dicatat lagi.
[ -n "$HITUNG_DATA" ] && SEBELUM="$(bash -c "$HITUNG_DATA")"
panggil POST /check-in "$KEY" "$(tap_json "$RFID" true "$SEKARANG" "$ID_UJI-1" check_in)"
if ada_endpoint "POST /check-in"; then
  cek_http 200; cek_json ulang
  if [ -n "$HITUNG_DATA" ]; then
    SESUDAH="$(bash -c "$HITUNG_DATA")"
    if [ "$SEBELUM" = "$SESUDAH" ]; then lulus "tap_id lintas endpoint: jumlah baris absensi tetap ($SESUDAH)"
    else gagal "tap_id lintas endpoint: jumlah baris absensi bertambah ($SEBELUM → $SESUDAH)"; fi
  fi
  # b) tap DATANG baru
  panggil POST /check-in "$KEY" "$(tap_json "$RFID" false "$SEKARANG" "$ID_UJI-6" check_in)"; cek_http 200; cek_json tap
  # c) kartu tidak terdaftar
  panggil POST /check-in "$KEY" "$(tap_json "$KARTU_ASING" false "$SEKARANG" "$ID_UJI-7" check_in)"; cek_http 200; cek_json tap-asing
fi
# d) tap PULANG baru, lalu tap_id-nya dikirim ulang (antrean) → respons sama atau duplicate
panggil POST /check-out "$KEY" "$(tap_json "$RFID" false "$SEKARANG" "$ID_UJI-8" check_out)"
if ada_endpoint "POST /check-out"; then
  cek_http 200; cek_json tap
  cp "$TMP" "$PERTAMA"
  panggil POST /check-out "$KEY" "$(tap_json "$RFID" true "$SEKARANG" "$ID_UJI-8" check_out)"; cek_http 200; cek_json ulang
  # e) format salah tetap 400
  panggil POST /check-out "$KEY" "$(tap_json "" false "$SEKARANG" "$ID_UJI-9" check_out)";   cek_http 400; cek_json salah
fi
echo

if [ "$GAGAL" -eq 0 ]; then
  echo "✅ SEMUA PEMERIKSAAN LULUS"
else
  echo "❌ $GAGAL pemeriksaan gagal"
fi
exit "$GAGAL"
