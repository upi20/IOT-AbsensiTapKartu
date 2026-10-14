# Tata letak Absensi RFID Terintegrasi di PCB dot matrix 1 sisi (lubang 2.54 mm): satu sumber data untuk
#   - doc/rakit-dotmatrix.html   (panduan interaktif; data disisipkan di antara penanda DATA)
#   - hardware/dotmatrix/hasil/tata-letak.json
#   - hardware/dotmatrix/hasil/templat-1-1.svg / .pdf  (cetak 1:1, A4 tegak)
#
# Tiga versi (sama kecuali bagian LED / jam / baterai):
#   led : LED RGB di casing lewat header JLED + R1-R3        -> doc/rakit-dotmatrix.html
#   rtc : modul jam RTC DS3231 di header yang sama (JRTC), tanpa LED & R1-R3, plus baterai cadangan
#         (modul UPS LX-2BUPS -> saklar -> terminal JDAYA)    -> doc/rakit-dotmatrix-rtc.html
#         Firmware sama (1.10.0+): RTC terdeteksi otomatis saat menyala.
#   ups : versi rtc + pantau baterai: dua pembagi 10k + 10k di modul UPS, kabel sinyal lewat header JBAT
#         (BAT -> D34, PLN -> VP)                             -> doc/rakit-dotmatrix-ups.html
#         Firmware 1.11.2+ (disarankan 1.11.4+): pemantau baterai terdeteksi otomatis.
# Hasil versi rtc/ups memakai akhiran -rtc/-ups (tata-letak-ups.json, templat-1-1-ups.svg/.pdf).
#
# Jalankan dari folder utama repositori:  python3 hardware/dotmatrix/tata_letak.py        (ketiga versi)
#                                          python3 hardware/dotmatrix/tata_letak.py ups    (satu versi: led, rtc, ups)
# (PDF dibuat kalau modul PyMuPDF tersedia: pip install pymupdf. Kalau tidak, cetak file SVG dari
#  browser dengan skala 100 %.)
#
# Papan: PCB dot matrix 1 SISI 9 x 15 cm berisi 30 x 50 lubang, label tercetak di sisi tembaga:
#   kolom 1a ... 1z, lalu 2a ... 2d  = kolom 1 ... 30
#   baris 001 ... 050                 = baris 1 ... 50
# Semua komponen dipasang di DEPAN (sisi polos), semua solder & kabel di BELAKANG (sisi tembaga, tempat label
# terbaca). Dilihat dari depan, kolom 1a ada di KANAN. Di data, X/Y (mm) = tampak belakang: (0,0) = pojok
# kolom 1a baris 001, X ke arah 2d, Y ke bawah. HTML membalik gambar untuk tampak depan.
#
# Posisi tetap (diukur langsung di papan):
#   header LCD   kolom 1a baris 001-014 (T_IRQ 001, VCC 014)
#   header RC522 baris 041 kolom 1q-1x  (3.3V 1q, SDA 1x)
#   ESP32        D23..3V3 di kolom 1a baris 024-038, EN..VIN di kolom 1k baris 024-038 (USB ke arah baris 050)
# LED tidak di papan: dipasang di casing, disambung lewat header + kabel (JLED). Buzzer di papan, di bawah LCD,
# digerakkan langsung oleh D17 (tanpa transistor). Alat pertama masih memasang R4/R5/Q1/D1 dari
# rancangan sebelumnya tanpa sambungan; tidak berpengaruh dan tidak perlu dipasang di unit berikutnya.
# Baris 042-050 TIDAK dipakai. Resistor, transistor, dioda, dan header kabel di sebelah RC522 (kolom 2b-2d,
# baris 019-041); kapasitor C1 & C2 di bawah LCD (celah +- 1 cm). Tidak ada komponen di bawah antena RC522.
import json
import os
import re
import subprocess
import sys

if len(sys.argv) < 2:                                       # tanpa argumen: buat ketiga versi
    for v in ("led", "rtc", "ups"):
        subprocess.run([sys.executable, os.path.abspath(__file__), v], check=True)
    sys.exit(0)
VERSI = sys.argv[1]
assert VERSI in ("led", "rtc", "ups"), "versi: led, rtc, atau ups"
UPS = VERSI == "ups"                                        # versi rtc + pantau baterai
RTC = VERSI in ("rtc", "ups")                               # modul jam + baterai cadangan
AKHIRAN = "" if VERSI == "led" else "-" + VERSI             # nama file hasil versi rtc / ups

DIR = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(DIR))

KOLOM, BARIS = 30, 50


def label(c, r):
    """Label lubang seperti tercetak di papan: kolom 1..26 = 1a..1z, 27..30 = 2a..2d; baris 3 angka."""
    return ("1" + chr(96 + c) if c <= 26 else "2" + chr(96 + c - 26)) + f"-{r:03d}"


def X(c):
    return round(3.02 + 2.54 * (c - 1), 2)


def Y(r):
    return round(2.50 + 2.54 * (r - 1), 2)


def P(c, r):
    return [X(c), Y(r)]


BARIS_TERAKHIR = 41      # baris 042-050 tidak dipakai (boleh dipotong)
TEPI = {"kiri": round(X(1) - 1.27, 2), "kanan": round(X(KOLOM) + 1.27, 2),
        "atas": round(Y(1) - 1.27, 2), "bawah": round(Y(BARIS) + 1.27, 2)}

BOR = []         # lubang baut menyusul bersama casing

# ---------- Komponen (semua di DEPAN) ----------
ESP_D23 = ["D23", "D22", "TX", "RX", "D21", "D19", "D18", "D5", "D17", "D16", "D4", "D2", "D15", "GND", "3V3"]
ESP_EN = ["EN", "VP", "VN", "D34", "D35", "D32", "D33", "D25", "D26", "D27", "D14", "D12", "D13", "GND", "VIN"]
LCD_PIN = ["VCC", "GND", "CS", "RESET", "DC", "SDI", "SCK", "LED", "SDO", "T_CLK", "T_CS", "T_DIN", "T_DO", "T_IRQ"]
RF_PIN = ["SDA", "SCK", "MOSI", "MISO", "IRQ", "GND", "RST", "3.3V"]


def soket(id_, nama, pins, catatan):
    return {"id": id_, "nama": nama, "jenis": "soket", "sisi": "depan", "catatan": catatan,
            "pin": [{"nama": n, "cr": cr, "xy": P(*cr)} for n, cr in pins]}


def dua_kaki(id_, nama, jenis, nilai, a, b, na="1", nb="2", catatan=""):
    return {"id": id_, "nama": nama, "jenis": jenis, "sisi": "depan", "nilai": nilai, "catatan": catatan,
            "pin": [{"nama": na, "cr": a, "xy": P(*a)}, {"nama": nb, "cr": b, "xy": P(*b)}]}


KOMPONEN = [
    soket("J2", "Soket ESP32 (D23 ... 3V3)", [(n, [1, 24 + i]) for i, n in enumerate(ESP_D23)],
          "Header female 1x15. D23 di 1a-024, 3V3 di 1a-038"),
    soket("J1", "Soket ESP32 (EN ... VIN)", [(n, [11, 24 + i]) for i, n in enumerate(ESP_EN)],
          "Header female 1x15. EN di 1k-024, VIN di 1k-038"),
    soket("J3", "Soket LCD", [(n, [1, 14 - i]) for i, n in enumerate(LCD_PIN)],
          "Header female 1x14. T_IRQ di 1a-001, VCC di 1a-014"),
    soket("J4", "Soket RC522", [(n, [24 - i, 41]) for i, n in enumerate(RF_PIN)],
          "Header female 1x8, mendatar. 3.3V di 1q-041, SDA di 1x-041"),
    soket("JRTC", "Header kabel modul jam RTC DS3231",
          [(n, [30, r]) for n, r in (("GND", 21), ("VCC", 22), ("SDA", 23), ("SCL", 24))],
          "Header male 1x4 tegak di tepi 2d, baris 021-024: GND, VCC, SDA, SCL (urutan sama dengan pin modul, "
          "kabel lurus tanpa disilang). Jauh dari modul baterai supaya baterai koin modul jam mudah diganti. "
          "Kabel jumper female ke pin "
          "SDA, SCL, VCC, GND modul DS3231 (ZS-042). Pin 32K dan SQW modul tidak disambung") if RTC else
    soket("JLED", "Header kabel LED RGB", [(n, [30, r]) for n, r in (("R", 36), ("G", 37), ("B", 38), ("K", 39))],
          "Header male 1x4 tegak di tepi 2d, baris 036-039: R, G, B, K. Kabel ke kaki LED (K = kaki terpanjang)"),
    dua_kaki("BZ1", "Buzzer aktif", "buzzer", "aktif 12 mm", [28, 4], [28, 1], "+", "-",
             "Langsung di papan, di bawah LCD. Kaki + (lebih panjang, ada tanda +) di 2b-004, kaki - di 2b-001. "
             "Digerakkan langsung oleh pin D17 (tanpa transistor), seperti prototipe expansion board"),
    dua_kaki("C2", "Keramik dekat LCD", "keramik", "47 nF (473)", [2, 13], [2, 14],
             catatan="Tidak punya plus-minus. Di bawah LCD, tepat di sebelah pin LCD: 1b-013 disatukan dengan "
                     "1a-013 (GND), 1b-014 disatukan dengan 1a-014 (VCC). Baris 013 dan 014 TIDAK boleh menyatu"),
    dua_kaki("C1", "Elco penstabil daya", "elco-tidur", "220 uF 16-25 V", [5, 15], [5, 17], "+", "-",
             "Dipasang TIDUR di bawah LCD (badan rebah ke arah kolom 1f-1j). Kaki panjang (+) di 1e-015, "
             "sisi bergaris (-) di 1e-017. Kaki direntangkan 2 lubang (5 mm); baris 016 kosong sebagai pemisah"),
    dua_kaki("C4", "Keramik dekat RC522", "keramik", "47 nF (473)", [17, 40], [19, 40],
             catatan="Tidak punya plus-minus. Pasang rapat ke papan. 1q-040 tersambung ke 3.3V RC522 (1q-041), "
                     "1s-040 ke GND RC522 (1s-041) lewat jembatan JB3 dan JB4. Masih di bawah ujung header RC522, "
                     "bukan di bawah antena"),
]
if RTC:
    KOMPONEN += [
        # +5V dan GND sengaja berjarak 2 lubang (5,08 mm), baris 032 kosong: timah tidak mungkin menyambung keduanya
        soket("JDAYA", "Terminal daya baterai (modul UPS)", [("+5V", [30, 31]), ("GND", [30, 33])],
              "Terminal sekrup 2 pin KF301-2P (jarak kaki 5,08 mm), lubang kabel menghadap ke luar papan: "
              "+5V di 2d-031, GND di 2d-033, baris 2d-032 KOSONG sebagai pemisah. Pengganti: header male 1x3 "
              "dengan pin tengah dicabut. Kabel dari modul UPS lewat saklar: kuning = +5V, hitam = GND. Jangan tertukar"),
    ]
if UPS:
    # Pembagi tegangan disolder di modul UPS; di papan hanya kabel sinyalnya. Baris 034-035 kosong: jarak ke badan
    # terminal JDAYA. GND pembagi sudah lewat UPS- -> JDAYA GND, jadi tidak perlu kabel GND tambahan.
    KOMPONEN += [
        soket("JBAT", "Header kabel pantau baterai", [("BAT", [30, 36]), ("PLN", [30, 37])],
              "Header male 1x2 tegak di tepi 2d, baris 036-037, di bawah terminal JDAYA: BAT (2d-036) = titik tengah "
              "pembagi + baterai, ke D34; PLN (2d-037) = titik tengah pembagi 5V+ charger, ke VP. Kabel jumper female "
              "dari titik tengah kedua pembagi 10k + 10k yang disolder di modul UPS"),
    ]
if not RTC:
    KOMPONEN += [
        dua_kaki("R1", "Resistor LED merah", "resistor", "220 ohm", [30, 19], [30, 23]),
        dua_kaki("R2", "Resistor LED hijau", "resistor", "220 ohm", [30, 25], [30, 29]),
        dua_kaki("R3", "Resistor LED biru", "resistor", "220 ohm", [30, 31], [30, 35]),
    ]

# Garis bentuk modul (hanya gambar). Ukuran & letak relatif terhadap header sama dengan rancangan PCB.
MODUL = [
    {"id": "m-lcd", "nama": "LCD 3.5\"", "sisi": "depan",
     "kotak": [round(X(1) - 2, 2), round(Y(1) - 11.66, 2), round(X(1) + 96, 2), round(Y(1) + 44.68, 2)]},
    {"id": "m-rc522", "nama": "RC522", "sisi": "depan",
     "kotak": [round(X(17) - 12.72, 2), round(Y(1) + 44.68, 2), round(X(24) + 9.5, 2), round(Y(41) + 1.9, 2)]},
    {"id": "m-esp32", "nama": "ESP32 DevKit", "sisi": "depan",
     "kotak": [round(X(6) - 14, 2), round(Y(24) - 6.04, 2), round(X(6) + 14, 2), round(Y(38) + 9.9, 2)]},
]


def pin(id_, nama):
    for k in KOMPONEN:
        if k["id"] == id_:
            for p_ in k["pin"]:
                if p_["nama"] == nama:
                    return p_
    raise KeyError(id_ + ":" + nama)


# ---------- Jembatan (kawat polos di BELAKANG, antar lubang segaris) ----------
JEMBATAN = []
for id_, a, b, label_ in (("JB1", [1, 13], [2, 13], "GND LCD - C2 (disatukan dengan timah)"),
                          ("JB2", [1, 14], [2, 14], "VCC LCD - C2 (disatukan dengan timah)"),
                          ("JB3", [17, 40], [17, 41], "C4 ke 3.3V RC522"),
                          ("JB4", [19, 40], [19, 41], "C4 ke GND RC522")):
    JEMBATAN.append({"id": id_, "sisi": "belakang", "dari": a, "ke": b, "label": label_})
PENGUAT = []

# ---------- Kabel (semua di BELAKANG) ----------
# Jalur otomatis: dari kaki, setengah langkah ke celah antar-baris, menyusuri celah, lalu menyusuri celah
# antar-kolom ke kaki tujuan. Jadi kabel tidak pernah melintas tepat di atas lubang. Kabel boleh bersilangan
# (berisolasi); kawat polos hanya jembatan pendek di atas.
KABEL = []
SETENGAH = 1.27


def rute(a, b, n, lajur=None):
    """lajur: nomor celah antar-kolom (0 = celah terdekat) untuk kabel yang ujungnya satu kolom."""
    ax, ay = P(*a)
    bx, by = P(*b)
    geser = ((n % 5) - 2) * 0.2                  # supaya kabel di celah yang sama tidak menumpuk
    pilihan = []
    for sa in (1, -1):
        for sb in (1, -1):
            yl = ay + sa * (SETENGAH + geser)
            xl = bx + sb * (SETENGAH + geser) if lajur is None else bx + sb * (SETENGAH + 2.54 * (lajur // 2) + (lajur % 2 - 0.5) * 0.7)
            if not (TEPI["kiri"] < xl < TEPI["kanan"] and TEPI["atas"] < yl < TEPI["bawah"]):
                continue
            jalur = [(ax, ay), (ax, yl), (xl, yl), (xl, by), (bx, by)]
            panjang = sum(abs(jalur[k + 1][0] - jalur[k][0]) + abs(jalur[k + 1][1] - jalur[k][1]) for k in range(4))
            pilihan.append((panjang, jalur))
    return min(pilihan)[1]


def rute_pita(a, b, n, pita, lajur=0):
    """Lewat 'pita' mendatar (Y mm) di dekat header RC522, supaya tidak melintas di belakang antena.
    lajur: naik/turun di celah kolom ke-n dari tujuan (0 = celah terdekat), supaya kabel tidak menumpuk di satu celah.
    lajur=-1: celah di sisi seberang kaki tujuan (kabel melewati kolom tujuan dulu)."""
    ax, ay = P(*a)
    bx, by = P(*b)
    geser = ((n % 5) - 2) * 0.25
    arah = 1 if bx > ax else -1
    x1 = ax + arah * (SETENGAH + geser)
    xl = bx - arah * (SETENGAH + 2.54 * lajur + geser)
    return [(ax, ay), (x1, ay), (x1, pita + geser), (xl, pita + geser), (xl, by), (bx, by)]


PITA = Y(38) + SETENGAH          # celah antara baris 038 dan 039 (kabel LED & D17 ke kolom 2b-2d)
PITA_RF = Y(39) + SETENGAH       # celah antara baris 039 dan 040 (kabel ke header RC522)
PITA_ATAS = Y(37) + SETENGAH     # celah antara baris 037 dan 038 (versi rtc: kabel modul jam, memisah dari kabel daya)


def kabel(id_, kelompok, warna, ka, pa, kb, pb, label_, lajur=None, pita=None):
    a = pin(ka, pa)["cr"]
    b = pin(kb, pb)["cr"]
    jalur = rute_pita(a, b, len(KABEL), pita, lajur or 0) if pita else rute(a, b, len(KABEL), lajur)
    KABEL.append({"id": id_, "kelompok": kelompok, "warna": warna, "dari": a, "ke": b, "label": label_,
                  "jalur": [[round(x, 2), round(y, 2)] for x, y in jalur], "_rute": (lajur, pita)})


# Daya 3V3 (merah)
kabel("P1", "daya", "merah", "J2", "3V3", "C1", "+", "3V3 ESP32 ke C1 (+)")
kabel("P5", "daya", "merah", "C1", "+", "J3", "VCC", "C1 (+) ke VCC LCD")
kabel("P2", "daya", "merah", "J2", "3V3", "C4", "1", "3V3 ESP32 ke C4 / 3.3V RC522")
if RTC:
    kabel("P6", "daya", "merah", "C4", "1", "JRTC", "VCC", "C4 (3.3V) ke VCC modul jam")
# GND (hitam)
kabel("G1", "gnd", "hitam", "J2", "GND", "C1", "-", "GND ESP32 ke C1 (-)")
kabel("G6", "gnd", "hitam", "C1", "-", "J3", "GND", "C1 (-) ke GND LCD")
kabel("G2", "gnd", "hitam", "J1", "GND", "C4", "2", "GND ESP32 ke C4 / GND RC522", pita=PITA_RF)
if RTC:
    # GND modul jam diambil dari terminal daya JDAYA di kolom yang sama: pendek, lurus, tidak ikut jalur bawah
    kabel("G3", "gnd", "hitam", "JDAYA", "GND", "JRTC", "GND", "GND terminal daya ke modul jam")
else:
    kabel("G3", "gnd", "hitam", "C4", "2", "JLED", "K", "GND ke LED (K)")
# LCD: semua dari kolom 1a (ESP32) ke kolom 1a (LCD). Disebar ke beberapa celah; bentang terpanjang di luar.
LCD_KABEL = [("L1", "D5", "CS"), ("L2", "D4", "RESET"), ("L3", "D16", "DC"), ("L4", "D23", "SDI"),
             ("L5", "D18", "SCK"), ("L6", "D2", "LED"), ("L7", "D22", "T_CLK"),
             ("L8", "D15", "T_CS"), ("L9", "D21", "T_DIN"), ("L10", "D19", "T_DO")]
bentang = sorted(LCD_KABEL, key=lambda t: pin("J2", t[1])["cr"][1] - pin("J3", t[2])["cr"][1])
for id_, sumber, tujuan in LCD_KABEL:
    kabel(id_, "lcd", "hitam", "J2", sumber, "J3", tujuan, f"{sumber} ke {tujuan}" + (" (lampu layar)" if tujuan == "LED" else ""),
          lajur=bentang.index((id_, sumber, tujuan)))
# RC522
for id_, sumber, tujuan in (("F1", "D33", "SDA"), ("F2", "D14", "SCK"), ("F3", "D13", "MOSI"),
                            ("F4", "D35", "MISO"), ("F5", "D32", "RST")):
    kabel(id_, "rfid", "hitam", "J1", sumber, "J4", tujuan, f"{sumber} ke {tujuan} RC522", pita=PITA_RF)
# LED (versi led) atau modul jam I2C (versi rtc, pin yang sama: D25 = SDA, D26 = SCL)
if RTC:
    for id_, sumber, tujuan in (("E1", "D25", "SDA"), ("E2", "D26", "SCL")):
        kabel(id_, "led", "hitam", "J1", sumber, "JRTC", tujuan, f"{sumber} ke {tujuan} modul jam", pita=PITA_ATAS, lajur=1)
else:
    for id_, sumber, rid in (("E1", "D25", "R1"), ("E2", "D26", "R2"), ("E3", "D27", "R3")):
        kabel(id_, "led", "hitam", "J1", sumber, rid, "1", f"{sumber} ke {rid}", pita=PITA)
    for id_, rid, warna_led in (("E4", "R1", "R"), ("E5", "R2", "G"), ("E6", "R3", "B")):
        kabel(id_, "led", "hitam", rid, "2", "JLED", warna_led, f"{rid} ke header LED ({warna_led})")
# Daya baterai (versi rtc): modul UPS -> saklar -> JDAYA -> VIN & GND ESP32. Kabel +5V KUNING (beda dari 3V3).
if RTC:
    kabel("V1", "baterai", "kuning", "JDAYA", "+5V", "J1", "VIN", "+5V baterai ke VIN ESP32", pita=PITA)
    kabel("V2", "baterai", "hitam", "JDAYA", "GND", "J1", "GND", "GND baterai ke GND ESP32", pita=PITA)
# Pantau baterai (versi ups): header JBAT -> D34 & VP ESP32. Lewat celah baris 038-039 bersama kabel daya baterai,
# lalu naik di celah kolom 1j-1k (lajur=-1: celah di sisi kiri kaki tujuan, di belakang ESP32). Celah 1k-1l
# sudah penuh kabel RC522 & modul jam. Hanya pin input, arus +-0,1 mA.
if UPS:
    kabel("U1", "pantau", "hitam", "JBAT", "BAT", "J1", "D34", "Pantau baterai: BAT ke D34", pita=PITA, lajur=-1)
    kabel("U2", "pantau", "hitam", "JBAT", "PLN", "J1", "VP", "Pantau listrik: PLN ke VP", pita=PITA, lajur=-1)
# Buzzer
# Buzzer langsung ke ESP32 (tanpa transistor): + ke D17, - ke GND. Arus pin D17 dinaikkan di firmware
# (feedback.h, GPIO_DRIVE_CAP_3). Jalur: ke kanan di celah kolom 1g-1h (belakang ESP32), naik ke celah baris
# 017-018 (di antara LCD dan RC522, bukan di belakang antena), ke kanan sampai kolom 2a-2b, lalu naik ke buzzer.
def rute_buzzer(a, b, x_lajur, y_lajur):
    ax, ay = P(*a)
    bx, by = P(*b)
    return [(ax, ay), (x_lajur, ay), (x_lajur, y_lajur), (bx - SETENGAH, y_lajur), (bx - SETENGAH, by), (bx, by)]


for id_, sumber, kaki, x_lajur, y_lajur, label_ in (
        ("B1", "D17", "+", X(7) + SETENGAH, Y(17) + SETENGAH - 0.35, "D17 ke buzzer (+)"),
        ("B2", "GND", "-", X(8) + SETENGAH, Y(17) + SETENGAH + 0.35, "GND ke buzzer (-)")):
    a = pin("J2", sumber)["cr"]
    b = pin("BZ1", kaki)["cr"]
    KABEL.append({"id": id_, "kelompok": "buzzer", "warna": "hitam", "dari": a, "ke": b, "label": label_,
                  "jalur": [[round(x, 2), round(y, 2)] for x, y in rute_buzzer(a, b, x_lajur, y_lajur)], "_rute": "tetap"})

# ---------- Titik sambung: maksimal 1 kabel per lubang ----------
# Menyolder 2-3 kabel di satu lubang kecil sangat sulit. Kalau satu lubang (kaki komponen / pin soket) menerima
# lebih dari 1 kabel, kabel lainnya dipindah ke lubang kosong TEPAT DI SEBELAHNYA, lalu kedua lubang disatukan
# dengan timah (jembatan "TS"). Lubang pengganti harus kosong, bukan di belakang antena RC522, dan tetangganya
# tidak dipakai jalur lain (supaya timah tidak menyambung ke sinyal lain).
def titik_sambung():
    induk = {}

    def cari(h):
        induk.setdefault(h, h)
        while induk[h] != h:
            induk[h] = induk[induk[h]]
            h = induk[h]
        return h

    def satukan(a, b):
        induk[cari(a)] = cari(b)

    terpakai = set()
    for k in KOMPONEN:
        for p_ in k["pin"]:
            terpakai.add(tuple(p_["cr"]))
    for k in KABEL:
        terpakai.update([tuple(k["dari"]), tuple(k["ke"])])
        satukan(tuple(k["dari"]), tuple(k["ke"]))
    for j in JEMBATAN:
        terpakai.update([tuple(j["dari"]), tuple(j["ke"])])
        satukan(tuple(j["dari"]), tuple(j["ke"]))

    def aman(n, h):
        c, r = n
        if not (1 <= c <= KOLOM and 1 <= r <= BARIS_TERAKHIR) or n in terpakai:
            return False
        if 12 <= c <= 27 and 19 <= r <= 38:                  # di belakang antena RC522
            return False
        for m in ((c + 1, r), (c - 1, r), (c, r + 1), (c, r - 1)):
            if m != h and m in terpakai and cari(m) != cari(h):
                return False
        return True

    ujung = {}
    for k in KABEL:
        for sisi in ("dari", "ke"):
            ujung.setdefault(tuple(k[sisi]), []).append((k, sisi))
    ramai = {id(k): sum(len(ujung[tuple(k[s_])]) > 1 for s_ in ("dari", "ke")) for k in KABEL}
    nomor = 0
    for h, daftar in sorted(ujung.items(), key=lambda t: (t[0][1], t[0][0])):
        if len(daftar) < 2:
            continue
        # Yang tetap di pin: kabel berjalur khusus (buzzer), lalu kabel yang sudah dipindah di ujung lain atau yang
        # kedua ujungnya ramai (supaya satu kabel paling banyak punya satu titik sambung). Sisanya pindah.
        daftar.sort(key=lambda t: (t[0]["_rute"] != "tetap", "titik" not in t[0], -ramai[id(t[0])]))
        for k, sisi in daftar[1:]:
            lain = tuple(k["ke" if sisi == "dari" else "dari"])
            pilihan = [n for n in ((h[0] + 1, h[1]), (h[0] - 1, h[1]), (h[0], h[1] + 1), (h[0], h[1] - 1)) if aman(n, h)]
            if not pilihan:
                print(f"PERINGATAN: {label(*h)} tetap {len(daftar)} kabel (tidak ada lubang aman di sebelahnya)")
                continue
            n = min(pilihan, key=lambda q: (abs(q[0] - lain[0]) + abs(q[1] - lain[1]), q[1] != h[1]))
            nomor += 1
            ts = f"TS{nomor}"
            terpakai.add(n)
            satukan(n, h)
            k[sisi] = list(n)
            k.setdefault("titik", []).append({"pin": list(h), "ts": ts})
            k["label"] += f" (ujung di titik sambung {label(*n)})"
            JEMBATAN.append({"id": ts, "sisi": "belakang", "dari": list(h), "ke": list(n), "timah": True,
                             "label": f"Titik sambung kabel {k['id']}: satukan {label(*h)} dan {label(*n)} dengan timah"})
    for i, k in enumerate(KABEL):                          # jalur dihitung ulang dengan ujung baru
        if "titik" in k:
            lajur, pita = k["_rute"]
            jalur = rute_pita(k["dari"], k["ke"], i, pita, lajur or 0) if pita else rute(k["dari"], k["ke"], i, lajur)
            k["jalur"] = [[round(x, 2), round(y, 2)] for x, y in jalur]
    for k in KABEL:
        k.pop("_rute", None)


titik_sambung()

# ---------- Data untuk HTML ----------
DATA = {
    "versi": VERSI,
    "grid": {"kolom": KOLOM, "baris": BARIS, "baris_terakhir": BARIS_TERAKHIR, "x1": X(1), "y1": Y(1), "jarak": 2.54},
    "tepi": TEPI, "bor": BOR, "komponen": KOMPONEN, "modul": MODUL, "jembatan": JEMBATAN,
    "penguat": PENGUAT, "kabel": KABEL,
}

os.makedirs(os.path.join(DIR, "hasil"), exist_ok=True)
with open(os.path.join(DIR, "hasil", f"tata-letak{AKHIRAN}.json"), "w") as f:
    json.dump(DATA, f, ensure_ascii=False, indent=1)

# Teks HTML versi rtc: (teks di rakit-dotmatrix.html, penggantinya). Langkah resistor dihapus di bawah.
LEDE_RTC = ('Buzzer di papan. Versi ini memakai <b>modul jam RTC DS3231</b> (jam tetap tersimpan saat listrik mati) di header '
            'kabel yang di <a href="rakit-dotmatrix.html">versi LED</a> dipakai untuk LED, jadi <b>tanpa LED RGB</b>. Firmware '
            'sama untuk semua versi (1.10.0 ke atas): modul jam terdeteksi otomatis. Ada juga '
            '<a href="rakit-dotmatrix-ups.html">versi UPS</a>: sama dengan versi ini, ditambah pantau baterai di layar.')
GANTI_RTC = [
    ('<title>Rakit Dot Matrix</title>', '<title>Rakit Dot Matrix RTC</title>'),
    ('<h1>Rakit Absensi RFID di PCB dot matrix</h1>', '<h1>Rakit Absensi RFID di PCB dot matrix: versi modul jam (RTC)</h1>'),
    ('Buzzer di papan; LED dipasang di luar papan lewat kabel (ada juga <a href="rakit-dotmatrix-rtc.html">versi modul jam RTC</a>, '
     'tanpa LED, dan <a href="rakit-dotmatrix-ups.html">versi UPS</a>: modul jam + pantau baterai).', LEDE_RTC),
    ('<span class="mono">hardware/dotmatrix/hasil/templat-1-1.pdf</span>',
     f'<span class="mono">hardware/dotmatrix/hasil/templat-1-1{AKHIRAN}.pdf</span>'),
    ('<tr><td>LED salah warna / mati</td><td>Urutan header JLED di kolom 2d: R 036, G 037, B 038, K 039. Kaki terpanjang LED = K. Kabel E1–E6, G3.</td></tr>',
     '<tr><td>Jam hilang setelah mati listrik / Info alat tanpa "+ RTC"</td><td>Urutan header JRTC di kolom 2d: GND 021, '
     'VCC 022, SDA 023, SCL 024, sama dengan pin modul. Kabel E1, E2, P6, G3. Baterai koin CR2032 di modul terpasang.</td></tr>'),
    ('led: { css: "var(--w-led)", nama: "LED RGB" },', 'led: { css: "var(--w-led)", nama: "Modul jam (I2C)" },'),
    ('header male 1×4 (untuk kabel LED), resistor 220 Ω ×3, ', 'header male 1×4 (untuk kabel modul jam), '),
    ('kabel jumper female 4 jalur untuk LED.</p>',
     'kabel jumper female 4 jalur, modul jam <b>DS3231 (ZS-042)</b> + baterai koin CR2032 (bukan DS1307: modul itu butuh 5 V). '
     '<b>Baterai:</b> terminal sekrup 2 pin <b>KF301-2P</b> (5,08 mm), modul UPS <b>LX-2BUPS varian 5V</b>, 2 × baterai 18650 sama persis, saklar rocker '
     '<b>KCD11</b> (3 A), elco 1000 µF 10 V, kabel <b>kuning</b> ± 30 cm, charger 5 V minimal 2 A.</p>'),
    ('Kabel asli cukup dua warna: <b>merah = 3V3</b>, <b>hitam = GND dan semua sinyal</b>.',
     'Kabel asli tiga warna: <b>merah = 3V3</b>, <b>kuning = +5V baterai</b>, <b>hitam = GND dan semua sinyal</b>. '
     'Kuning dan merah <b>jangan tertukar</b>: 5 V di jalur 3V3 merusak LCD dan RC522.'),
    ('<tr><td>Upload gagal</td>',
     '<tr><td>Alat restart saat listrik mati / charger dicabut</td><td>Jumper keluaran modul UPS di <b>5V</b>, elco 1000 µF '
     'di UPS+/UPS−, kabel V1 (kuning) ke VIN 1k-038 dan V2 ke GND 1k-037, charger minimal 2 A.</td></tr>\n'
     '            <tr><td>Alat tidak menyala dengan baterai</td><td>Saklar ON, baterai terpasang benar (kutub + sesuai tanda), '
     'terminal JDAYA: +5V di 2d-031, GND di 2d-033.</td></tr>\n            <tr><td>Upload gagal</td>'),
    ('judul: "Buzzer & header kabel LED", sisi: "depan", sorot: ["BZ1", "JLED"],',
     'judul: "Buzzer, header modul jam & daya", sisi: "depan", sorot: ["BZ1", "JRTC", "JDAYA"],'),
    ('<li>JLED: header male <b>1×4</b> tegak di kolom <b>2d</b>, baris <b>036–039</b>: <b>R 036, G 037, B 038, K 039</b>.</li>',
     '<li>JRTC: header male <b>1×4</b> tegak di kolom <b>2d</b>, baris <b>021–024</b>: <b>GND 021, VCC 022, SDA 023, SCL 024</b> '
     '(urutan sama dengan pin modul DS3231, kabel lurus). Letaknya jauh dari modul baterai, supaya baterai koin modul jam mudah diganti.</li>'
     '<li>JDAYA: <b>terminal sekrup 2 pin KF301-2P</b> di kolom <b>2d</b>: <b>+5V di 031, GND di 033</b>, lubang kabel menghadap ke luar papan. '
     'Baris <b>032 dibiarkan kosong</b> sebagai pemisah, supaya +5V dan GND tidak mungkin tersambung timah. '
     'Beri tanda spidol "+" di sebelah 031. (Pengganti: header male 1×3, pin tengah dicabut.)</li>'),
    ('<li>Kabel ke LED (di casing) memakai kabel jumper female: kaki R, G, B, dan K (kaki terpanjang). Beri lem tembak di sambungan kabel ke kaki LED.</li></ol>',
     '<li>Modul jam ditempel di casing atau di belakang papan (double tape busa, jangan di belakang antena RC522), '
     'disambung kabel jumper female 4 jalur ke pin <b>SDA, SCL, VCC, GND</b> modul. Pin 32K dan SQW tidak dipakai. '
     'Pasang baterai koin CR2032 di modul.</li></ol>'),
    ('"JLED 2d-036…039 (R G B K)", "Kabel LED 4 jalur siap"',
     '"JRTC 2d-021…024 (GND VCC SDA SCL)", "Kabel modul jam 4 jalur siap", "JDAYA 2d-031 (+5V), 2d-033 (GND), 032 kosong"'),
    ('sorot: ["P1", "P5", "P2"],', 'sorot: ["P1", "P5", "P2", "P6"],'),
    ('ke C4 (1q-040), menyuplai RC522.</li></ul>',
     'ke C4 (1q-040), menyuplai RC522.</li><li>P6: titik sambung <b>1q-039</b> (timah ke C4 1q-040, 3.3 V) ke VCC header modul jam (2d-022).</li></ul>'),
    ('items: ["P1", "P5", "P2"] },', 'items: ["P1", "P5", "P2", "P6"] },'),
    ('<li>G3: titik sambung <b>1s-039</b> (timah ke C4 1s-040) ke K header LED (2d-039).</li>',
     '<li>G2: titik sambung <b>1j-037</b> (timah ke GND ESP32 1k-037) ke C4 (1s-040), GND RC522.</li>'
     '<li>G3: titik sambung <b>2c-033</b> (timah ke GND terminal JDAYA 2d-033) naik di celah kolom 2c–2d ke GND header modul jam (2d-021).</li>'),
    ('<li>G2: GND ESP32 (1k-037) ke C4 (1s-040), GND RC522.</li>', ''),
    ('{ id: "led", judul: "Kabel LED (6 kabel)", sisi: "belakang", sorot: ["E1", "E2", "E3", "E4", "E5", "E6"],',
     '{ id: "led", judul: "Kabel modul jam (2 kabel)", sisi: "belakang", sorot: ["E1", "E2"],'),
    ('<ul><li>E1–E3: D25, D26, D27 (1k-031…033) turun ke celah baris 038–039, ke kanan sampai kolom 2c–2d, lalu naik ke ujung atas R1, R2, R3 (2d-019, 025, 031).</li><li>E4–E6: ujung bawah R1, R2, R3 (2d-023, 029, 035) ke header JLED (R, G, B).</li></ul>',
     '<ul><li>E1: D25 (1k-031) turun ke celah baris <b>037–038</b>, ke kanan, lalu naik di celah kolom <b>2b–2c</b> ke SDA header modul jam (2d-023). Kabel daya baterai memakai celah 038–039, jadi keduanya tidak menumpuk.</li>'
     '<li>E2: D26 (1k-032) lewat jalur yang sama ke SCL (2d-024).</li></ul>'),
    ('items: ["E1", "E2", "E3", "E4", "E5", "E6"] },', 'items: ["E1", "E2"] },'),
    ('    { id: "buzzer", judul: "Kabel buzzer (2 kabel)"',
     '    { id: "baterai", judul: "Kabel daya baterai (2 kabel)", sisi: "belakang", sorot: ["V1", "V2"],\n'
     '      isi: `<ul><li>V1 (<b>kabel kuning</b>): +5V terminal JDAYA (2d-031) turun ke celah baris 038–039, ke kiri sampai '
     'kolom 1k–1l, lalu ke <b>VIN ESP32 (1k-038)</b>.</li><li>V2 (kabel hitam): GND JDAYA (2d-033) lewat jalur yang sama '
     'ke <b>GND ESP32 (1k-037)</b>.</li></ul><div class="note warn">VIN dan GND bersebelahan (1k-037/038): pastikan '
     'timahnya tidak menyatu. Kuning hanya ke VIN, jangan ke 3V3.</div>`,\n'
     '      items: ["V1", "V2"] },\n    { id: "buzzer", judul: "Kabel buzzer (2 kabel)"'),
    ('    { id: "tes", judul: "Pasang modul & tes"',
     '    { id: "ups", judul: "Modul UPS, saklar & baterai", sisi: "depan", sorot: ["JDAYA"],\n'
     '      isi: `<p>Dirakit di luar papan, sebelum dipasang ke casing. Urutan sambungan:</p>'
     '<ul><li><b>+5V:</b> charger 5V 2A → USB-C modul UPS → pad UPS+ → saklar → kabel kuning → terminal JDAYA +5V (2d-031)</li>'
     '<li><b>GND:</b> pad UPS− → kabel hitam → terminal JDAYA GND (2d-033)</li></ul>'
     '<ol><li>Cek <b>jumper keluaran modul di 5V</b> (kotak 5V/9V/12V). Dengan multimeter: UPS+ ke UPS− ≈ 5 V. '
     '<b>9 V atau 12 V merusak ESP32.</b></li>'
     '<li>Solder <b>elco 1000 µF</b> di pad UPS+ (kaki panjang) dan UPS− (sisi bergaris): menahan tegangan saat pindah ke baterai.</li>'
     '<li>Kabel kuning dari UPS+ ke salah satu kaki <b>saklar KCD11</b>, kaki lain ke kabel kuning menuju JDAYA +5V. '
     'Kabel hitam UPS− langsung ke JDAYA GND. Ujung kabel dikupas ± 5 mm, dilapisi timah, lalu dijepit sekrup terminal.</li>'
     '<li>Pasang <b>2 baterai 18650 searah</b> sesuai gambar di modul (paralel), tegangan keduanya hampir sama. '
     '<b>Baterai dulu, baru charger.</b></li>'
     '<li>Saklar OFF, jepit kabel di terminal JDAYA (kuning ke +5V di 031, hitam ke GND di 033), tarik pelan untuk memastikan kuat, lalu saklar ON.</li></ol>'
     '<div class="note">Saklar memutus daya ke alat saja; baterai tetap terisi saat charger dicolok. Pasang saklar '
     'di sisi bawah casing, agak masuk ke dalam, supaya tidak tertekan orang yang absen.</div>`,\n'
     '      cek: ["Keluaran modul 5 V", "Elco 1000 µF di UPS+/UPS−", "Saklar di jalur kuning (+5V)", '
     '"Baterai searah, tegangan sama", "Kuning ke JDAYA +5V"] },\n    { id: "tes", judul: "Pasang modul & tes"'),
    ('<li>Colokkan ESP32, LCD, RC522, lalu kabel LED.</li>', '<li>Colokkan ESP32, LCD, RC522, lalu kabel modul jam.</li>'),
    ('<li>Menu → <b>Tes buzzer & LED</b>: bunyi bip, LED merah → hijau → biru → putih.</li>',
     '<li>Monitor serial: "RTC: disetel dari jam server". Menu → <b>Info alat</b>: baris Jam bertanda <b>(server + RTC)</b>.</li>'
     '<li>Uji jam: matikan router (atau WiFi), cabut lalu colok listrik alat. Langkah jam di layar boot bertanda <b>(RTC)</b> dan tidak ada E31.</li>'),
    ('<li>Tempel kartu: bip, LED berwarna, nama tampil.</li></ol>',
     '<li>Tempel kartu: bip, layar berwarna, nama tampil.</li>'
     '<li>Uji baterai: saat alat menyala, cabut charger dari modul UPS. <b>Alat tidak boleh restart.</b> Ulangi 5 kali.</li>'
     '<li>Saklar OFF: alat mati total walaupun baterai terpasang.</li></ol>'),
    ('Coba menu Tes buzzer &amp; LED.', 'Coba menu Tes buzzer.'),     # firmware 1.11.4+: tanpa LED namanya "Tes buzzer"
    ('"Tes buzzer & LED benar"', '"Info alat: Jam (server + RTC)", "Jam (RTC) saat tanpa internet", '
     '"Charger dicabut: tidak restart", "Saklar OFF: mati total"'),
    ('dan LED serta buzzer dipasang di casing lewat kabel.', 'dan modul jam dipasang di casing lewat kabel.'),
    ('["LED RGB", "led"]', '["Modul jam", "led"], ["Daya baterai 5V (kuning)", "baterai"]'),
    ('const KEY = "absensi.dotmatrix.v3.";', 'const KEY = "absensi.dotmatrix-rtc.v1.";'),
]

# Teks HTML versi ups: diterapkan SETELAH GANTI_RTC (teks lama = hasil versi rtc). Pembagi tegangan di modul UPS,
# header JBAT, kabel U1/U2, dan tes baterai (firmware 1.11.2+, disarankan 1.11.4).
GANTI_UPS = [
    ('<title>Rakit Dot Matrix RTC</title>', '<title>Rakit Dot Matrix UPS</title>'),
    ('<h1>Rakit Absensi RFID di PCB dot matrix: versi modul jam (RTC)</h1>',
     '<h1>Rakit Absensi RFID di PCB dot matrix: versi UPS (modul jam + pantau baterai)</h1>'),
    (LEDE_RTC,
     'Buzzer di papan. Versi ini sama dengan <a href="rakit-dotmatrix-rtc.html">versi modul jam</a> (modul jam <b>RTC DS3231</b>, '
     'baterai cadangan <b>modul UPS LX-2BUPS</b> + 2 × 18650, <b>tanpa LED RGB</b>; ada juga <a href="rakit-dotmatrix.html">versi LED</a>), '
     'ditambah <b>pantau baterai</b>: dua pembagi tegangan di modul UPS membuat layar menampilkan isi baterai dan tanda '
     '"Listrik padam". Pakai firmware <b>1.11.4</b> ke atas (minimal 1.11.2): modul jam dan pantau baterai terdeteksi otomatis.'),
    ('kabel <b>kuning</b> ± 30 cm, charger 5 V minimal 2 A.</p>',
     'kabel <b>kuning</b> ± 30 cm, charger 5 V minimal 2 A. <b>Pantau baterai:</b> resistor <b>10 kΩ ×4</b> (gelang '
     'coklat-hitam-oranye-emas), header male 1×2, kabel jumper female 2 jalur, selongsong heatshrink kecil.</p>'),
    ('judul: "Buzzer, header modul jam & daya", sisi: "depan", sorot: ["BZ1", "JRTC", "JDAYA"],',
     'judul: "Buzzer, header modul jam, daya & baterai", sisi: "depan", sorot: ["BZ1", "JRTC", "JDAYA", "JBAT"],'),
    ('(Pengganti: header male 1×3, pin tengah dicabut.)</li>',
     '(Pengganti: header male 1×3, pin tengah dicabut.)</li>'
     '<li>JBAT: header male <b>1×2</b> tegak di kolom <b>2d</b>: <b>BAT di 036, PLN di 037</b>. Baris 034–035 kosong '
     '(jarak ke badan terminal JDAYA). Tempat kabel sinyal dari pembagi tegangan di modul UPS. Beri tanda spidol "B" di sebelah 036.</li>'),
    ('"JDAYA 2d-031 (+5V), 2d-033 (GND), 032 kosong"',
     '"JDAYA 2d-031 (+5V), 2d-033 (GND), 032 kosong", "JBAT 2d-036 (BAT), 2d-037 (PLN)"'),
    ('    { id: "buzzer", judul: "Kabel buzzer (2 kabel)"',
     '    { id: "pantau", judul: "Kabel pantau baterai (2 kabel)", sisi: "belakang", sorot: ["U1", "U2"],\n'
     '      isi: `<ul><li>U1: BAT header JBAT (2d-036) ke <b>D34 ESP32 (1k-027)</b>.</li>'
     '<li>U2: PLN (2d-037) ke <b>VP ESP32 (1k-025)</b>.</li></ul>'
     '<p>Keduanya turun di celah kolom 2c–2d ke celah baris 038–039 bersama kabel daya baterai, ke kiri sampai celah kolom '
     '1j–1k (lewat di bawah VIN 1k-038), lalu naik di celah itu, di belakang ESP32 dan terpisah dari kabel RC522, ke kaki tujuan. '
     'Kabel melintas di atas titik sambung 1j-037/1k-037 (GND): tidak apa-apa, kabelnya berisolasi. '
     'Kabel hitam biasa: hanya sinyal, arusnya ± 0,1 mA.</p>'
     '<div class="note warn">D34 (1k-027) bersebelahan dengan VN (1k-026) dan D35 (1k-028, kabel F4 ke RC522). '
     'VP (1k-025) bersebelahan dengan EN (1k-024). Timah tidak boleh menyatu ke lubang sebelahnya: VP tersambung EN '
     'membuat ESP32 mati saat charger dicabut.</div>`,\n'
     '      items: ["U1", "U2"] },\n    { id: "buzzer", judul: "Kabel buzzer (2 kabel)"'),
    ('    { id: "tes", judul: "Pasang modul & tes"',
     '    { id: "pembagi", judul: "Pembagi tegangan di modul UPS", sisi: "depan", sorot: ["JBAT"],\n'
     '      isi: `<p>Dua pembagi tegangan, masing-masing 2 resistor <b>10 kΩ</b>, disolder <b>di modul UPS</b>, bukan di papan. '
     'Alat membaca isi baterai lewat D34 dan ada/tidaknya listrik PLN lewat VP. Arusnya hanya ± 0,2 mA: resistor tidak hangat.</p>'
     '<ul><li><b>Baterai:</b> kaki <b>+ dudukan baterai 18650</b> → R1 10k → titik tengah → R2 10k → pad <b>UPS−</b>. '
     'Titik tengah → kabel ke <b>BAT</b> (JBAT 2d-036) → D34.</li>'
     '<li><b>Listrik PLN:</b> pad <b>5V+</b> (masukan charger USB-C) → R3 10k → titik tengah → R4 10k → pad <b>5V−</b> '
     '(atau UPS−). Titik tengah → kabel ke <b>PLN</b> (JBAT 2d-037) → VP.</li></ul>'
     '<ol><li><b>Lepas baterai</b> dan cabut charger sebelum menyolder.</li>'
     '<li>Solder R1 <b>menempel langsung</b> di kaki + dudukan baterai, dan R3 menempel langsung di pad 5V+. '
     'Jadi kalau kabel panjang ke papan tergores, arusnya hanya ± 0,2 mA.</li>'
     '<li>Kaki lain R1 disatukan dengan kaki R2 (titik tengah), kaki lain R2 ke pad UPS−. Begitu juga R3 dan R4 ke pad 5V−. '
     'Kalau kaki tidak sampai, sambung dengan kabel pendek.</li>'
     '<li>Potong satu ujung kabel jumper female 2 jalur, solder ke kedua titik tengah. Tandai: BAT dan PLN. '
     'Bungkus semua kaki resistor dan titik tengah dengan <b>heatshrink</b>.</li>'
     '<li>Pasang baterai, colok charger. Ukur dengan multimeter (probe hitam di UPS−) <b>sebelum</b> kabel dicolok ke JBAT:'
     '<ul><li>+ baterai: <b>positif</b>, 3,0–4,2 V. Negatif atau 0 V = salah kaki, perbaiki dulu.</li>'
     '<li>Titik tengah baterai: ± <b>setengah</b> tegangan baterai (contoh 2,0 V saat baterai 4,0 V). Kalau hampir sama '
     'dengan tegangan baterai, R2 belum tersambung ke UPS−: <b>jangan dicolok</b>.</li>'
     '<li>Titik tengah PLN: <b>2,5–2,75 V</b> saat charger dicolok, <b>0 V</b> saat dicabut.</li></ul></li>'
     '<li>Semua benar: saklar OFF, colok kabel BAT ke 2d-036 dan PLN ke 2d-037.</li></ol>'
     '<div class="note warn"><b>Kutub − baterai tidak disambung ke apa pun.</b> Kutub itu lewat IC proteksi FM5056 di modul; '
     'menyambungnya melompati proteksi. GND pembagi selalu pad UPS− atau 5V−.</div>'
     '<div class="note warn">Pad 5V+ hanya untuk charger 5 V. Jangan memakai adaptor 9 V atau 12 V: tegangan di VP melebihi 3,3 V.</div>`,\n'
     '      cek: ["R1 menempel di + dudukan baterai, R2 ke UPS−", "R3 menempel di pad 5V+, R4 ke 5V−", '
     '"Kutub − baterai tidak tersambung", "Semua dibungkus heatshrink", "+ baterai positif terhadap UPS−", '
     '"Titik tengah baterai ≈ setengah tegangan baterai", "Titik tengah PLN ≈ 2,5–2,75 V (charger dicolok), 0 V (dicabut)", '
     '"BAT di 2d-036, PLN di 2d-037"] },\n    { id: "tes", judul: "Pasang modul & tes"'),
    ('<li>Colokkan ESP32, LCD, RC522, lalu kabel modul jam.</li>',
     '<li>Pastikan firmware <b>1.11.4</b> ke atas. Colokkan ESP32, LCD, RC522, lalu kabel modul jam dan kabel pantau baterai.</li>'),
    ('<li>Uji baterai: saat alat menyala, cabut charger dari modul UPS. <b>Alat tidak boleh restart.</b> Ulangi 5 kali.</li>',
     '<li>Menu → <b>Info alat</b> → Ringkasan: baris Daya "<b>Listrik ada, baterai …% (dicas)</b>". Bilah status menampilkan ikon baterai dengan petir.</li>'
     '<li>Info alat → <b>Perangkat</b>: "Tegangan baterai (bacaan langsung)". Bandingkan dengan multimeter di + baterai ke UPS−. '
     'Selisih lebih dari 0,05 V: isi <span class="mono">BATERAI_KALIBRASI</span> di config.h = angka multimeter ÷ angka layar, lalu upload ulang.</li>'
     '<li>Uji baterai: saat alat menyala, cabut charger dari modul UPS. <b>Alat tidak boleh restart.</b> Ikon baterai berubah '
     '(tanpa petir), baris bawah "<b>Listrik padam - baterai …%</b>". Colok lagi: kembali "Listrik ada". Ulangi 5 kali. '
     'Selama padam persen tidak naik walau tegangan sempat pulih: memang begitu, seperti HP.</li>'),
    ('"Charger dicabut: tidak restart"',
     '"Daya: Listrik ada, baterai …% (dicas)", "Tegangan baterai sama dengan multimeter (± 0,05 V)", '
     '"Charger dicabut: tidak restart, Listrik padam - baterai …%"'),
    ('<tr><td>Upload gagal</td>',
     '<tr><td>Ikon baterai tidak tampil / Daya "Tanpa UPS"</td><td>Info alat → Perangkat → Tegangan baterai: "Tidak terdeteksi '
     '(terbaca … V)". 0 V: kabel U1, R1, atau colokan BAT (2d-036) putus. Angka loncat-loncat: R2 atau titik tengah lepas. '
     'BAT dan PLN tertukar juga membuat UPS tidak terdeteksi. Firmware minimal 1.11.2.</td></tr>\n'
     '            <tr><td>Selalu "Listrik padam" padahal charger tercolok</td><td>Kabel U2 (PLN 2d-037 → VP 1k-025), R3 di pad '
     '<b>5V+</b> (masukan charger, bukan UPS+), titik tengah PLN ≈ 2,5 V saat charger dicolok.</td></tr>\n'
     '            <tr><td>Tegangan baterai beda dengan multimeter</td><td><span class="mono">BATERAI_KALIBRASI</span> di config.h = '
     'multimeter ÷ layar. Saat dicas angkanya memang lebih tinggi daripada saat padam.</td></tr>\n'
     '            <tr><td>Upload gagal</td>'),
    ('--w-baterai: #1fb5a3;', '--w-baterai: #1fb5a3; --w-pantau: #e0609e;'),
    ('baterai: { css: "var(--w-baterai)", nama: "Daya baterai 5V (kabel kuning + hitam)" }',
     'baterai: { css: "var(--w-baterai)", nama: "Daya baterai 5V (kabel kuning + hitam)" },\n'
     '    pantau: { css: "var(--w-pantau)", nama: "Pantau baterai (sinyal)" }'),
    ('["Daya baterai 5V (kuning)", "baterai"]', '["Daya baterai 5V (kuning)", "baterai"], ["Pantau baterai", "pantau"]'),
    ('const KEY = "absensi.dotmatrix-rtc.v1.";', 'const KEY = "absensi.dotmatrix-ups.v1.";'),
]


# HTML: versi led = doc/rakit-dotmatrix.html (sumber). Versi rtc dibuat dari file itu + GANTI_RTC, versi ups
# dari hasil itu + GANTI_UPS.
html_path = os.path.join(ROOT, "doc", "rakit-dotmatrix.html")
if os.path.exists(html_path):
    s = open(html_path).read()
    if RTC:
        for lama, baru in GANTI_RTC:
            assert s.count(lama) == 1, "teks HTML berubah, perbarui GANTI_RTC: " + lama[:60]
            s = s.replace(lama, baru)
        s, n = re.subn(r'\n    \{ id: "resistor",.*?items: \["R1", "R2", "R3"\] \},', "", s, flags=re.S)
        assert n == 1, "langkah resistor tidak ditemukan"
    if UPS:
        for lama, baru in GANTI_UPS:
            assert s.count(lama) == 1, "teks HTML berubah, perbarui GANTI_UPS: " + lama[:60]
            s = s.replace(lama, baru)
    s2 = re.sub(r"(/\*DATA\*/).*?(/\*AKHIR-DATA\*/)", lambda m: m.group(1) + json.dumps(DATA, ensure_ascii=False,
                separators=(",", ":")) + m.group(2), s, flags=re.S)
    open(os.path.join(ROOT, "doc", f"rakit-dotmatrix{AKHIRAN}.html"), "w").write(s2)

# ---------- Templat cetak 1:1 (A4 tegak, mm) ----------
def svg_papan(ox, oy, cermin):
    """Satu papan. Data = tampak belakang; cermin=True: tampak DEPAN (kiri-kanan dibalik, 1a di kanan)."""
    t = TEPI
    lebar = t["kiri"] + t["kanan"]                          # cermin terhadap tengah papan
    fx = (lambda x: ox + (lebar - x)) if cermin else (lambda x: ox + x)
    fy = lambda y: oy + y
    o = []
    x0, x1 = sorted([fx(t["kiri"]), fx(t["kanan"])])
    o.append(f'<rect x="{x0:.2f}" y="{fy(t["atas"]):.2f}" width="{x1 - x0:.2f}" height="{t["bawah"] - t["atas"]:.2f}" '
             f'fill="none" stroke="#000" stroke-width="0.35"/>')
    for c in range(1, KOLOM + 1):
        for r in range(1, BARIS + 1):
            o.append(f'<circle cx="{fx(X(c)):.2f}" cy="{fy(Y(r)):.2f}" r="0.45" fill="none" stroke="#999" stroke-width="0.12"/>')
    for c in range(1, KOLOM + 1):                           # label kolom seperti di papan: 1a..1z, 2a..2d
        nama = label(c, 1)[:2]
        tebal = ' font-weight="bold"' if nama[1] in "aejoty" or c == KOLOM else ""
        o.append(f'<text x="{fx(X(c)):.2f}" y="{fy(t["atas"]) - 1.2:.2f}" font-size="1.25" '
                 f'text-anchor="middle"{tebal}>{nama}</text>')
    for r in range(1, BARIS + 1):
        if r == 1 or r % 5 == 0 or r == BARIS:
            xr = fx(t["kanan"]) + 2.6 if cermin else fx(t["kiri"]) - 2.6
            o.append(f'<text x="{xr:.2f}" y="{fy(Y(r)) + 0.6:.2f}" font-size="1.7" text-anchor="middle">{r:03d}</text>')
    ya = fy(Y(BARIS_TERAKHIR) + 1.27)
    o.append(f'<rect x="{x0:.2f}" y="{ya:.2f}" width="{x1 - x0:.2f}" height="{fy(t["bawah"]) - ya:.2f}" fill="#000" fill-opacity="0.08"/>')
    o.append(f'<text x="{(x0 + x1) / 2:.2f}" y="{ya + 12:.2f}" font-size="2.6" text-anchor="middle" fill="#555">baris 042-050 tidak dipakai</text>')
    for m in MODUL:
        if cermin:                                          # modul hanya digambar di tampak depan
            a, b = sorted([fx(m["kotak"][0]), fx(m["kotak"][2])])
            o.append(f'<rect x="{a:.2f}" y="{fy(m["kotak"][1]):.2f}" width="{b - a:.2f}" '
                     f'height="{m["kotak"][3] - m["kotak"][1]:.2f}" fill="none" stroke="#555" stroke-width="0.2" stroke-dasharray="0.8 0.6"/>')
            o.append(f'<text x="{(a + b) / 2:.2f}" y="{fy(m["kotak"][1]) + 4:.2f}" font-size="2.6" text-anchor="middle" fill="#555">{m["nama"]}</text>')
    for k in KOMPONEN:
        di_sisi = cermin                                    # semua komponen di depan
        for p_ in k["pin"]:
            x, y = p_["xy"]
            o.append(f'<circle cx="{fx(x):.2f}" cy="{fy(y):.2f}" r="0.75" fill="{"#000" if di_sisi else "none"}" '
                     f'stroke="#000" stroke-width="0.2"/>')
        x, y = k["pin"][0]["xy"]
        o.append(f'<text x="{fx(x) + (-1.6 if cermin else 1.6):.2f}" y="{fy(y) - 1.2:.2f}" font-size="1.8" '
                 f'text-anchor="{"end" if cermin else "start"}" font-weight="bold">{k["id"]}</text>')
    if not cermin:                                          # kabel & jembatan di tampak belakang
        for k in KABEL:
            d = " ".join(("M" if i == 0 else "L") + f"{fx(x):.2f} {fy(y):.2f}" for i, (x, y) in enumerate(k["jalur"]))
            warna = {"merah": "#c0392b", "kuning": "#d4a000"}.get(k["warna"], "#222")
            o.append(f'<path d="{d}" fill="none" stroke="{warna}" stroke-width="0.3"/>')
        for j in JEMBATAN:
            (ax, ay), (bx, by) = P(*j["dari"]), P(*j["ke"])
            o.append(f'<line x1="{fx(ax):.2f}" y1="{fy(ay):.2f}" x2="{fx(bx):.2f}" y2="{fy(by):.2f}" stroke="#888" stroke-width="0.6"/>')
    return "\n".join(o)


judul = ('<text x="12" y="12" font-size="5" font-weight="bold">Absensi RFID Terintegrasi - templat dot matrix 1:1'
         + (' (versi UPS)' if UPS else ' (versi modul jam RTC)' if RTC else '') + '</text>'
         '<text x="12" y="18" font-size="3">Papan 30 x 50 lubang (kolom 1a-1z, 2a-2d; baris 001-050). Cetak skala 100 %'
         ' (Actual size): garis skala di kiri bawah harus tepat 50 mm.</text>'
         '<text x="12" y="22.5" font-size="3">Papan 1 sisi: komponen di DEPAN (1a di kanan), solder &amp; kabel di BELAKANG'
         ' (sisi tembaga, 1a di kiri). Merah = 3V3, hitam = GND &amp; sinyal, abu tebal = kawat polos.' + (' Kuning = +5V baterai.' if RTC else '') + '</text>')
svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="210mm" height="297mm" viewBox="0 0 210 297" '
       'font-family="Helvetica, Arial, sans-serif">', '<rect width="210" height="297" fill="#fff"/>', judul,
       '<text x="56" y="40" font-size="4" text-anchor="middle" font-weight="bold">DEPAN (sisi komponen)</text>',
       svg_papan(17, 48, True),
       '<text x="155" y="40" font-size="4" text-anchor="middle" font-weight="bold">BELAKANG (sisi solder &amp; kabel)</text>',
       svg_papan(116, 48, False),
       '<path d="M12 287H62M12 285V289M62 285V289" stroke="#000" stroke-width="0.4"/>',
       '<text x="37" y="284" font-size="3" text-anchor="middle">50 mm</text>', '</svg>']
svg_path = os.path.join(DIR, "hasil", f"templat-1-1{AKHIRAN}.svg")
with open(svg_path, "w") as f:
    f.write("\n".join(svg))
try:
    import fitz  # PyMuPDF
    doc = fitz.open(svg_path)
    pdf = fitz.open("pdf", doc.convert_to_pdf())
    pdf.save(os.path.join(DIR, "hasil", f"templat-1-1{AKHIRAN}.pdf"))
    print(f"PDF: hardware/dotmatrix/hasil/templat-1-1{AKHIRAN}.pdf")
except ImportError:
    print(f"PyMuPDF tidak ada: cetak hardware/dotmatrix/hasil/templat-1-1{AKHIRAN}.svg dari browser (skala 100 %)")

print(f"[{VERSI}] {len(KOMPONEN)} komponen, {len(KABEL)} kabel, {len(JEMBATAN)} jembatan, {len(BOR)} lubang bor")
