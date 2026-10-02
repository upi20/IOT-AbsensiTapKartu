# Tata letak Absensi RFID Terintegrasi di PCB dot matrix 1 sisi (lubang 2.54 mm): satu sumber data untuk
#   - doc/rakit-dotmatrix.html   (panduan interaktif; data disisipkan di antara penanda DATA)
#   - hardware/dotmatrix/hasil/tata-letak.json
#   - hardware/dotmatrix/hasil/templat-1-1.svg / .pdf  (cetak 1:1, A4 tegak)
#
# Jalankan dari folder utama repositori:  python3 hardware/dotmatrix/tata_letak.py
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


def rute_pita(a, b, n, pita):
    """Lewat 'pita' mendatar (Y mm) di dekat header RC522, supaya tidak melintas di belakang antena."""
    ax, ay = P(*a)
    bx, by = P(*b)
    geser = ((n % 5) - 2) * 0.25
    arah = 1 if bx > ax else -1
    x1 = ax + arah * (SETENGAH + geser)
    xl = bx - arah * (SETENGAH + geser)
    return [(ax, ay), (x1, ay), (x1, pita + geser), (xl, pita + geser), (xl, by), (bx, by)]


PITA = Y(38) + SETENGAH          # celah antara baris 038 dan 039 (kabel LED & D17 ke kolom 2b-2d)
PITA_RF = Y(39) + SETENGAH       # celah antara baris 039 dan 040 (kabel ke header RC522)


def kabel(id_, kelompok, warna, ka, pa, kb, pb, label_, lajur=None, pita=None):
    a = pin(ka, pa)["cr"]
    b = pin(kb, pb)["cr"]
    jalur = rute_pita(a, b, len(KABEL), pita) if pita else rute(a, b, len(KABEL), lajur)
    KABEL.append({"id": id_, "kelompok": kelompok, "warna": warna, "dari": a, "ke": b, "label": label_,
                  "jalur": [[round(x, 2), round(y, 2)] for x, y in jalur]})


# Daya 3V3 (merah)
kabel("P1", "daya", "merah", "J2", "3V3", "C1", "+", "3V3 ESP32 ke C1 (+)")
kabel("P5", "daya", "merah", "C1", "+", "J3", "VCC", "C1 (+) ke VCC LCD")
kabel("P2", "daya", "merah", "J2", "3V3", "C4", "1", "3V3 ESP32 ke C4 / 3.3V RC522")
# GND (hitam)
kabel("G1", "gnd", "hitam", "J2", "GND", "C1", "-", "GND ESP32 ke C1 (-)")
kabel("G6", "gnd", "hitam", "C1", "-", "J3", "GND", "C1 (-) ke GND LCD")
kabel("G2", "gnd", "hitam", "J1", "GND", "C4", "2", "GND ESP32 ke C4 / GND RC522", pita=PITA_RF)
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
# LED
for id_, sumber, rid in (("E1", "D25", "R1"), ("E2", "D26", "R2"), ("E3", "D27", "R3")):
    kabel(id_, "led", "hitam", "J1", sumber, rid, "1", f"{sumber} ke {rid}", pita=PITA)
for id_, rid, warna_led in (("E4", "R1", "R"), ("E5", "R2", "G"), ("E6", "R3", "B")):
    kabel(id_, "led", "hitam", rid, "2", "JLED", warna_led, f"{rid} ke header LED ({warna_led})")
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
                  "jalur": [[round(x, 2), round(y, 2)] for x, y in rute_buzzer(a, b, x_lajur, y_lajur)]})

# ---------- Data untuk HTML ----------
DATA = {
    "grid": {"kolom": KOLOM, "baris": BARIS, "baris_terakhir": BARIS_TERAKHIR, "x1": X(1), "y1": Y(1), "jarak": 2.54},
    "tepi": TEPI, "bor": BOR, "komponen": KOMPONEN, "modul": MODUL, "jembatan": JEMBATAN,
    "penguat": PENGUAT, "kabel": KABEL,
}

os.makedirs(os.path.join(DIR, "hasil"), exist_ok=True)
with open(os.path.join(DIR, "hasil", "tata-letak.json"), "w") as f:
    json.dump(DATA, f, ensure_ascii=False, indent=1)

html_path = os.path.join(ROOT, "doc", "rakit-dotmatrix.html")
if os.path.exists(html_path):
    s = open(html_path).read()
    s2 = re.sub(r"(/\*DATA\*/).*?(/\*AKHIR-DATA\*/)", lambda m: m.group(1) + json.dumps(DATA, ensure_ascii=False,
                separators=(",", ":")) + m.group(2), s, flags=re.S)
    open(html_path, "w").write(s2)

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
            o.append(f'<path d="{d}" fill="none" stroke="{"#c0392b" if k["warna"] == "merah" else "#222"}" stroke-width="0.3"/>')
        for j in JEMBATAN:
            (ax, ay), (bx, by) = P(*j["dari"]), P(*j["ke"])
            o.append(f'<line x1="{fx(ax):.2f}" y1="{fy(ay):.2f}" x2="{fx(bx):.2f}" y2="{fy(by):.2f}" stroke="#888" stroke-width="0.6"/>')
    return "\n".join(o)


judul = ('<text x="12" y="12" font-size="5" font-weight="bold">Absensi RFID Terintegrasi - templat dot matrix 1:1</text>'
         '<text x="12" y="18" font-size="3">Papan 30 x 50 lubang (kolom 1a-1z, 2a-2d; baris 001-050). Cetak skala 100 %'
         ' (Actual size): garis skala di kiri bawah harus tepat 50 mm.</text>'
         '<text x="12" y="22.5" font-size="3">Papan 1 sisi: komponen di DEPAN (1a di kanan), solder &amp; kabel di BELAKANG'
         ' (sisi tembaga, 1a di kiri). Merah = 3V3, hitam = GND &amp; sinyal, abu tebal = kawat polos.</text>')
svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="210mm" height="297mm" viewBox="0 0 210 297" '
       'font-family="Helvetica, Arial, sans-serif">', '<rect width="210" height="297" fill="#fff"/>', judul,
       '<text x="56" y="40" font-size="4" text-anchor="middle" font-weight="bold">DEPAN (sisi komponen)</text>',
       svg_papan(17, 48, True),
       '<text x="155" y="40" font-size="4" text-anchor="middle" font-weight="bold">BELAKANG (sisi solder &amp; kabel)</text>',
       svg_papan(116, 48, False),
       '<path d="M12 287H62M12 285V289M62 285V289" stroke="#000" stroke-width="0.4"/>',
       '<text x="37" y="284" font-size="3" text-anchor="middle">50 mm</text>', '</svg>']
svg_path = os.path.join(DIR, "hasil", "templat-1-1.svg")
with open(svg_path, "w") as f:
    f.write("\n".join(svg))
try:
    import fitz  # PyMuPDF
    doc = fitz.open(svg_path)
    pdf = fitz.open("pdf", doc.convert_to_pdf())
    pdf.save(os.path.join(DIR, "hasil", "templat-1-1.pdf"))
    print("PDF: hardware/dotmatrix/hasil/templat-1-1.pdf")
except ImportError:
    print("PyMuPDF tidak ada: cetak hardware/dotmatrix/hasil/templat-1-1.svg dari browser (skala 100 %)")

print(f"{len(KOMPONEN)} komponen, {len(KABEL)} kabel, {len(JEMBATAN)} jembatan, {len(BOR)} lubang bor")
