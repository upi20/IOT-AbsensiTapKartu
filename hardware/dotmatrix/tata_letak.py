# Tata letak Absensi RFID Terintegrasi di PCB dot matrix (lubang 2.54 mm): satu sumber data untuk
#   - doc/rakit-dotmatrix.html   (panduan interaktif; data disisipkan di antara penanda DATA)
#   - hardware/dotmatrix/hasil/tata-letak.json
#   - hardware/dotmatrix/hasil/templat-1-1.svg / .pdf  (cetak 1:1 untuk memotong & mengebor)
#
# Jalankan dari folder utama repositori:  python3 hardware/dotmatrix/tata_letak.py
# (PDF dibuat kalau modul PyMuPDF tersedia: pip install pymupdf. Kalau tidak, cetak file SVG dari
#  browser dengan skala 100 %.)
#
# Koordinat mm sama dengan hardware/pcb/buat_pcb.py dan hardware/casing/casing.scad: dilihat dari DEPAN, (0,0) = pojok
# kiri atas papan, X ke kanan, Y ke bawah. Lubang dot matrix: kolom 1..38 (kiri ke kanan dari depan),
# baris 1..39 (atas ke bawah). Grid dikunci ke header LCD (kolom 38 = X 97.00, persis desain PCB),
# supaya LCD tepat di jendela casing.
import json
import os
import re

DIR = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(DIR))

KOLOM, BARIS = 38, 39
SAMBUNG = 33           # papan utama kolom 1..33 (dari papan 9 x 15 cm), potongan kanan kolom 34..38


def X(c):
    return round(3.02 + 2.54 * (c - 1), 2)


def Y(r):
    return round(2.50 + 2.54 * (r - 1), 2)


def P(c, r):
    return [X(c), Y(r)]


TEPI = {"kiri": round(X(1) - 1.27, 2), "kanan": round(X(KOLOM) + 1.27, 2),
        "atas": round(Y(1) - 1.27, 2), "bawah": round(Y(BARIS) + 1.27, 2),
        "sambung": round((X(SAMBUNG) + X(SAMBUNG + 1)) / 2, 2)}

# Lubang bor 3 mm (posisi mm, tidak di grid). Sama dengan tiang casing.
BOR = [
    {"id": "H1", "xy": [4.0, 4.42], "untuk": "LCD", "wajib": True},
    {"id": "H2", "xy": [96.0, 4.42], "untuk": "LCD", "wajib": True},
    {"id": "H3", "xy": [4.0, 53.92], "untuk": "LCD", "wajib": True},
    {"id": "H4", "xy": [96.0, 53.92], "untuk": "LCD", "wajib": True},
    {"id": "H5", "xy": [4.0, 96.0], "untuk": "casing", "wajib": True},
    {"id": "H6", "xy": [96.0, 96.0], "untuk": "casing", "wajib": True},
    {"id": "H7", "xy": [65.47, 95.88], "untuk": "RC522", "wajib": False},
    {"id": "H8", "xy": [65.47, 60.88], "untuk": "RC522", "wajib": False},
    {"id": "H9", "xy": [27.97, 91.08], "untuk": "RC522", "wajib": False},
    {"id": "H10", "xy": [27.97, 65.23], "untuk": "RC522", "wajib": False},
]

# ---------- Komponen ----------
ESP_KANAN = ["D23", "D22", "TX", "RX", "D21", "D19", "D18", "D5", "D17", "D16", "D4", "D2", "D15", "GND", "3V3"]
ESP_KIRI = ["EN", "VP", "VN", "D34", "D35", "D32", "D33", "D25", "D26", "D27", "D14", "D12", "D13", "GND", "VIN"]
LCD_PIN = ["VCC", "GND", "CS", "RESET", "DC", "SDI", "SCK", "LED", "SDO", "T_CLK", "T_CS", "T_DIN", "T_DO", "T_IRQ"]
RF_PIN = ["SDA", "SCK", "MOSI", "MISO", "IRQ", "GND", "RST", "3.3V"]


def soket(id_, nama, sisi, pins, catatan):
    return {"id": id_, "nama": nama, "jenis": "soket", "sisi": sisi, "catatan": catatan,
            "pin": [{"nama": n, "cr": cr, "xy": P(*cr)} for n, cr in pins]}


KOMPONEN = [
    soket("J2", "Soket ESP32 (D23 ... 3V3)", "belakang",
          [(n, [18 - i, 6]) for i, n in enumerate(ESP_KANAN)],
          "Header female 1x15, dipasang di BELAKANG, disolder di depan"),
    soket("J1", "Soket ESP32 (EN ... VIN)", "belakang",
          [(n, [18 - i, 16]) for i, n in enumerate(ESP_KIRI)],
          "Header female 1x15, dipasang di BELAKANG, disolder di depan"),
    soket("J3", "Soket LCD", "depan", [(n, [38, 18 - i]) for i, n in enumerate(LCD_PIN)],
          "Header female 1x14, dipasang di DEPAN. VCC paling bawah"),
    soket("J4", "Soket RC522", "depan", [(n, [31, 35 - i]) for i, n in enumerate(RF_PIN)],
          "Header female 1x8, dipasang di DEPAN. SDA paling bawah"),
    {"id": "LED", "nama": "LED RGB", "jenis": "led", "sisi": "depan", "nilai": "5 mm katoda bersama",
     "catatan": "Kubah LED +- 13 mm di atas papan (masuk tabung cahaya casing). Kaki terpanjang = K",
     "pin": [{"nama": n, "cr": [c, 25], "xy": P(c, 25)} for n, c in (("R", 2), ("K", 3), ("G", 4), ("B", 5))]},
]


def dua_kaki(id_, nama, jenis, nilai, a, b, na="1", nb="2", catatan=""):
    return {"id": id_, "nama": nama, "jenis": jenis, "sisi": "depan", "nilai": nilai, "catatan": catatan,
            "pin": [{"nama": na, "cr": a, "xy": P(*a)}, {"nama": nb, "cr": b, "xy": P(*b)}]}


KOMPONEN += [
    dua_kaki("R1", "Resistor LED merah", "resistor", "220 ohm", [2, 28], [2, 32]),
    dua_kaki("R2", "Resistor LED hijau", "resistor", "220 ohm", [4, 28], [4, 32]),
    dua_kaki("R3", "Resistor LED biru", "resistor", "220 ohm", [6, 28], [6, 32]),
    dua_kaki("R4", "Resistor basis transistor", "resistor", "1 kohm", [33, 34], [37, 34]),
    dua_kaki("R5", "Resistor penarik basis", "resistor", "10 kohm", [33, 36], [37, 36]),
    dua_kaki("C1", "Elco penstabil daya", "elco", "220 uF 25 V", [5, 35], [3, 35], "+", "-",
             "Kaki panjang (+) di kolom 5, sisi bergaris (-) di kolom 3. Rentangkan kaki ke jarak 2 lubang"),
    dua_kaki("C2", "Keramik dekat LCD", "keramik", "47 nF (473)", [37, 17], [37, 18],
             catatan="Bebas arah. Kaki dirapatkan ke 2 lubang bersebelahan"),
    dua_kaki("C3", "Elco dekat RC522", "elco", "22 uF 100 V", [37, 32], [38, 32], "+", "-",
             "Kaki panjang (+) di kolom 37, sisi bergaris (-) di kolom 38"),
    dua_kaki("C4", "Keramik dekat RC522", "keramik", "47 nF (473)", [32, 28], [32, 30],
             catatan="Bebas arah"),
    dua_kaki("D1", "Dioda pelindung buzzer", "dioda", "FR107 / 1N4007", [34, 30], [38, 30], "K", "A",
             "Sisi BERGARIS (K) di kolom 34"),
    dua_kaki("BZ1", "Buzzer aktif", "buzzer", "aktif 12 mm", [34, 26], [37, 26], "+", "-",
             "Kaki + (lebih panjang, ada tanda +) di kolom 34"),
    {"id": "Q1", "nama": "Transistor buzzer", "jenis": "transistor", "sisi": "depan", "nilai": "2N3904 (atau S8050)",
     "catatan": "Sisi DATAR menghadap ke tepi BAWAH papan. Kaki kiri-ke-kanan: E, B, C",
     "pin": [{"nama": n, "cr": [c, 32], "xy": P(c, 32)} for n, c in (("E", 34), ("B", 35), ("C", 36))]},
]

# Garis bentuk modul (hanya gambar)
MODUL = [
    {"id": "m-lcd", "nama": "LCD 3.5\"", "sisi": "depan", "kotak": [1.0, 1.0, 99.0, 57.34]},
    {"id": "m-rc522", "nama": "RC522", "sisi": "depan", "kotak": [X(31) + 1.9 - 60, Y(35) + 9.5 - 40, X(31) + 1.9, Y(35) + 9.5]},
    {"id": "m-esp32", "nama": "ESP32 DevKit", "sisi": "belakang",
     "kotak": [round(X(18) - 35.56 - 9.9, 2), round(Y(11) - 14, 2), round(X(18) + 6.04, 2), round(Y(11) + 14, 2)]},
]


def pin(id_, nama):
    for k in KOMPONEN:
        if k["id"] == id_:
            for p_ in k["pin"]:
                if p_["nama"] == nama:
                    return p_
    raise KeyError(id_ + ":" + nama)


# ---------- Jembatan pendek ----------
# Depan: kaki soket ESP32 ke "lubang tembus" di sebelahnya (baris 5 untuk J2, baris 17 untuk J1).
# Di belakang, ujung kawat yang keluar dari lubang tembus menjadi tempat menyolder kabel.
TEMBUS_J2 = ["D23", "D22", "D21", "D19", "D18", "D5", "D17", "D16", "D4", "D2", "D15", "GND", "3V3"]
TEMBUS_J1 = ["D35", "D32", "D33", "D25", "D26", "D27", "D14", "D13", "GND"]


def tembus(soket_id, nama):
    p_ = pin(soket_id, nama)
    c, r = p_["cr"]
    return [c, 5 if soket_id == "J2" else 17]


JEMBATAN = []
for s, daftar in (("J2", TEMBUS_J2), ("J1", TEMBUS_J1)):
    for n in daftar:
        a = pin(s, n)["cr"]
        JEMBATAN.append({"id": f"T-{s}-{n}", "sisi": "depan", "dari": a, "ke": tembus(s, n),
                         "label": f"{s} {n} ke lubang tembus"})
for id_, a, b, label in (("JB-C2a", [37, 17], [38, 17], "C2 ke GND LCD"),
                         ("JB-C2b", [37, 18], [38, 18], "C2 ke VCC LCD"),
                         ("JB-C4a", [32, 28], [31, 28], "C4 ke 3.3V RC522"),
                         ("JB-C4b", [32, 30], [31, 30], "C4 ke GND RC522")):
    JEMBATAN.append({"id": id_, "sisi": "belakang", "dari": a, "ke": b, "label": label})
# Kawat penguat sambungan papan (melintang di belakang, di lubang yang tidak dipakai)
PENGUAT = [{"id": f"S{r}", "dari": [SAMBUNG, r], "ke": [SAMBUNG + 1, r]} for r in (1, 20, 39)]

# ---------- Kabel (semua di BELAKANG) ----------
# warna: "merah" = 3V3, "hitam" = GND & sinyal. kelompok: daya / gnd / lcd / rfid / led / buzzer
KABEL = []


def kabel(id_, kelompok, warna, dari, ke, label, jalur):
    KABEL.append({"id": id_, "kelompok": kelompok, "warna": warna, "dari": dari, "ke": ke, "label": label,
                  "jalur": [[round(x, 2), round(y, 2)] for x, y in jalur]})


def ttk(cr):
    return P(*cr)


# --- Kelompok atas: ESP32 (J2) ke LCD, plus D17 ke buzzer. Naik ke jalur atas, ke kanan, turun, masuk J3.
ATAS = [  # (id, sumber J2, tujuan [komponen, pin], kelompok, warna, label)
    ("P1", "3V3", ("C2", "2"), "daya", "merah", "3V3 ke LCD (lewat C2)"),
    ("G8", "GND", ("C2", "1"), "gnd", "hitam", "GND ke LCD (lewat C2)"),
    ("L8", "D15", ("J3", "T_CS"), "lcd", "hitam", "D15 ke T_CS"),
    ("L6", "D2", ("J3", "LED"), "lcd", "hitam", "D2 ke LED (lampu layar)"),
    ("L2", "D4", ("J3", "RESET"), "lcd", "hitam", "D4 ke RESET"),
    ("L3", "D16", ("J3", "DC"), "lcd", "hitam", "D16 ke DC"),
    ("B1", "D17", ("R4", "1"), "buzzer", "hitam", "D17 ke R4 (buzzer)"),
    ("L1", "D5", ("J3", "CS"), "lcd", "hitam", "D5 ke CS"),
    ("L5", "D18", ("J3", "SCK"), "lcd", "hitam", "D18 ke SCK"),
    ("L10", "D19", ("J3", "T_DO"), "lcd", "hitam", "D19 ke T_DO"),
    ("L9", "D21", ("J3", "T_DIN"), "lcd", "hitam", "D21 ke T_DIN"),
    ("L7", "D22", ("J3", "T_CLK"), "lcd", "hitam", "D22 ke T_CLK"),
    ("L4", "D23", ("J3", "SDI"), "lcd", "hitam", "D23 ke SDI"),
]
for i, (id_, sumber, (kid, kpin), kel, warna, label) in enumerate(ATAS):
    a = tembus("J2", sumber)
    b = pin(kid, kpin)["cr"]
    ax, ay = ttk(a)
    bx, by = ttk(b)
    jalur_y = 3.0 + i * 0.62
    if id_ == "B1":
        jalur = [(ax, ay), (ax, jalur_y), (83.2, jalur_y), (83.2, by), (bx, by)]
    else:
        jalur_x = 91.8 - i * 0.6
        jalur = [(ax, ay), (ax, jalur_y), (jalur_x, jalur_y), (jalur_x, by), (bx, by)]
    kabel(id_, kel, warna, a, b, label, jalur)

# --- Kelompok bawah kanan: ESP32 (J1) ke RC522. Turun, ke kanan, turun, masuk J4 dari kiri.
BAWAH = [("F4", "D35", "MISO"), ("F5", "D32", "RST"), ("F1", "D33", "SDA"), ("F2", "D14", "SCK"),
         ("F3", "D13", "MOSI"), ("G3", "GND", "GND")]
for j, (id_, sumber, tujuan) in enumerate(BAWAH):
    a = tembus("J1", sumber)
    b = pin("J4", tujuan)["cr"]
    ax, ay = ttk(a)
    bx, by = ttk(b)
    jalur_y, jalur_x = 45.2 + j * 0.7, 77.5 - j * 0.7
    gnd = id_ == "G3"
    kabel(id_, "gnd" if gnd else "rfid", "hitam", a, b, f"{'GND' if gnd else sumber} ke {tujuan} RC522",
          [(ax, ay), (ax, jalur_y), (jalur_x, jalur_y), (jalur_x, by), (bx, by)])

# --- LED: ESP32 (J1) ke ujung bawah R1..R3. Turun, ke kiri, turun, ke kiri, naik ke kaki resistor.
for k, (id_, sumber, rid) in enumerate((("E1", "D25", "R1"), ("E2", "D26", "R2"), ("E3", "D27", "R3"))):
    a = tembus("J1", sumber)
    b = pin(rid, "2")["cr"]
    ax, ay = ttk(a)
    bx, by = ttk(b)
    jy, jx, jb = 50.0 + k * 0.7, 17.4 + k * 0.7, 85.0 - k * 0.7
    kabel(id_, "led", "hitam", a, b, f"{sumber} ke {rid}",
          [(ax, ay), (ax, jy), (jx, jy), (jx, jb), (bx, jb), (bx, by)])

# --- Kabel pendek & daya lokal
PENDEK = [
    ("E4", "led", "hitam", ("LED", "R"), ("R1", "1"), "LED kaki R ke R1", None),
    ("E5", "led", "hitam", ("LED", "G"), ("R2", "1"), "LED kaki G ke R2", None),
    ("E6", "led", "hitam", ("LED", "B"), ("R3", "1"), "LED kaki B ke R3", [(13.18, 63.46), (13.18, 67.0), (15.72, 67.0), (15.72, 71.08)]),
    ("G1", "gnd", "hitam", ("J1", "GND*"), ("LED", "K"), "GND ke LED kaki K",
     [(13.18, 43.14), (13.18, 58.5), (8.10, 58.5), (8.10, 63.46)]),
    ("G2", "gnd", "hitam", ("LED", "K"), ("C1", "-"), "LED K ke C1 (-)", None),
    ("P3", "daya", "merah", ("J2", "3V3*"), ("C1", "+"), "3V3 ke C1 (+)",
     [(10.64, 12.66), (8.6, 12.66), (8.6, 59.5), (14.45, 59.5), (14.45, 86.6), (13.18, 86.6), (13.18, 88.86)]),
    ("P4", "daya", "merah", ("C2", "2"), ("C4", "1"), "3V3 LCD ke C4 (RC522)",
     [(94.46, 45.68), (94.46, 49.5), (82.6, 49.5), (82.6, 71.08), (81.76, 71.08)]),
    ("P5", "daya", "merah", ("C4", "1"), ("BZ1", "+"), "3V3 ke buzzer (+)",
     [(81.76, 71.08), (81.76, 68.5), (86.84, 68.5), (86.84, 66.0)]),
    ("P6", "daya", "merah", ("BZ1", "+"), ("D1", "K"), "Buzzer (+) ke D1 bergaris", None),
    ("P7", "daya", "merah", ("D1", "K"), ("C3", "+"), "D1 bergaris ke C3 (+)",
     [(86.84, 76.16), (86.84, 78.2), (94.46, 78.2), (94.46, 81.24)]),
    ("G4", "gnd", "hitam", ("C4", "2"), ("Q1", "E"), "GND RC522 ke Q1 E",
     [(81.76, 76.16), (81.76, 80.0), (86.84, 80.0), (86.84, 81.24)]),
    ("G5", "gnd", "hitam", ("Q1", "E"), ("R5", "2"), "Q1 E ke R5",
     [(86.84, 81.24), (86.84, 89.4), (94.46, 89.4), (94.46, 91.4)]),
    ("G6", "gnd", "hitam", ("R5", "2"), ("C3", "-"), "R5 ke C3 (-)",
     [(94.46, 91.4), (97.0, 91.4), (97.0, 81.24)]),
    ("B2", "buzzer", "hitam", ("R4", "2"), ("Q1", "B"), "R4 ke Q1 B",
     [(94.46, 86.32), (94.46, 84.0), (89.38, 84.0), (89.38, 81.24)]),
    ("B3", "buzzer", "hitam", ("R5", "1"), ("Q1", "B"), "R5 ke Q1 B",
     [(84.30, 91.4), (86.0, 91.4), (86.0, 83.0), (89.38, 83.0), (89.38, 81.24)]),
    ("B4", "buzzer", "hitam", ("BZ1", "-"), ("D1", "A"), "Buzzer (-) ke D1 polos",
     [(94.46, 66.0), (97.0, 66.0), (97.0, 76.16)]),
    ("B5", "buzzer", "hitam", ("D1", "A"), ("Q1", "C"), "D1 polos ke Q1 C",
     [(97.0, 76.16), (97.0, 79.0), (91.92, 79.0), (91.92, 81.24)]),
]
for id_, kel, warna, (ka, pa), (kb, pb), label, jalur in PENDEK:
    if pa.endswith("*"):
        a = tembus(ka, pa[:-1])
    else:
        a = pin(ka, pa)["cr"]
    b = pin(kb, pb)["cr"]
    if jalur is None:
        ax, ay = ttk(a)
        bx, by = ttk(b)
        jalur = [(ax, ay), (bx, by)] if ax == bx or ay == by else [(ax, ay), (ax, by), (bx, by)]
    kabel(id_, kel, warna, a, b, label, jalur)

# ---------- Data untuk HTML ----------
DATA = {
    "grid": {"kolom": KOLOM, "baris": BARIS, "sambung": SAMBUNG, "x1": X(1), "y1": Y(1), "jarak": 2.54},
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

# ---------- Templat cetak 1:1 (A4 mendatar, mm) ----------
def svg_papan(ox, oy, cermin):
    """Satu papan. cermin=True: tampak BELAKANG (kiri-kanan dibalik)."""
    lebar = 100.0
    fx = (lambda x: ox + (lebar - x)) if cermin else (lambda x: ox + x)
    fy = lambda y: oy + y
    o = []
    t = TEPI
    x0, x1 = sorted([fx(t["kiri"]), fx(t["kanan"])])
    o.append(f'<rect x="{x0:.2f}" y="{fy(t["atas"]):.2f}" width="{x1 - x0:.2f}" height="{t["bawah"] - t["atas"]:.2f}" '
             f'fill="none" stroke="#000" stroke-width="0.35"/>')
    xs = fx(t["sambung"])
    o.append(f'<line x1="{xs:.2f}" y1="{fy(t["atas"]):.2f}" x2="{xs:.2f}" y2="{fy(t["bawah"]):.2f}" '
             f'stroke="#000" stroke-width="0.3" stroke-dasharray="1.5 1"/>')
    for c in range(1, KOLOM + 1):
        for r in range(1, BARIS + 1):
            o.append(f'<circle cx="{fx(X(c)):.2f}" cy="{fy(Y(r)):.2f}" r="0.45" fill="none" stroke="#999" stroke-width="0.12"/>')
    for c in range(1, KOLOM + 1):
        if c == 1 or c % 5 == 0 or c == KOLOM:
            o.append(f'<text x="{fx(X(c)):.2f}" y="{fy(t["atas"]) - 1.2:.2f}" font-size="2" text-anchor="middle">{c}</text>')
    for r in range(1, BARIS + 1):
        if r == 1 or r % 5 == 0 or r == BARIS:
            xr = fx(t["kanan"]) + 2.2 if cermin else fx(t["kiri"]) - 2.2
            o.append(f'<text x="{xr:.2f}" y="{fy(Y(r)) + 0.7:.2f}" font-size="2" text-anchor="middle">{r}</text>')
    for m in MODUL:
        if (m["sisi"] == "belakang") == cermin:
            a, b = sorted([fx(m["kotak"][0]), fx(m["kotak"][2])])
            o.append(f'<rect x="{a:.2f}" y="{fy(m["kotak"][1]):.2f}" width="{b - a:.2f}" '
                     f'height="{m["kotak"][3] - m["kotak"][1]:.2f}" fill="none" stroke="#555" stroke-width="0.2" stroke-dasharray="0.8 0.6"/>')
            o.append(f'<text x="{(a + b) / 2:.2f}" y="{fy(m["kotak"][1]) + 4:.2f}" font-size="2.6" text-anchor="middle" fill="#555">{m["nama"]}</text>')
    for k in KOMPONEN:
        di_sisi = (k["sisi"] == "belakang") == cermin
        for p_ in k["pin"]:
            x, y = p_["xy"]
            o.append(f'<circle cx="{fx(x):.2f}" cy="{fy(y):.2f}" r="0.75" fill="{"#000" if di_sisi else "none"}" '
                     f'stroke="#000" stroke-width="0.2"/>')
        x, y = k["pin"][0]["xy"]
        o.append(f'<text x="{fx(x) + (-1.6 if cermin else 1.6):.2f}" y="{fy(y) - 1.2:.2f}" font-size="1.8" '
                 f'text-anchor="{"end" if cermin else "start"}" font-weight="bold">{k["id"]}</text>')
    putus = 'stroke-dasharray="0.6 0.4"'
    for b in BOR:
        x, y = b["xy"]
        o.append(f'<circle cx="{fx(x):.2f}" cy="{fy(y):.2f}" r="1.5" fill="none" stroke="#000" '
                 f'stroke-width="{0.35 if b["wajib"] else 0.2}" {"" if b["wajib"] else putus}/>')
        o.append(f'<path d="M{fx(x) - 2.2:.2f} {fy(y):.2f}H{fx(x) + 2.2:.2f}M{fx(x):.2f} {fy(y) - 2.2:.2f}V{fy(y) + 2.2:.2f}" '
                 f'stroke="#000" stroke-width="0.15"/>')
    return "\n".join(o)


judul = ('<text x="20" y="12" font-size="5" font-weight="bold">Absensi RFID Terintegrasi - templat dot matrix 1:1</text>'
         '<text x="20" y="18" font-size="3">Cetak skala 100 % (Actual size). Garis skala di kiri bawah harus tepat 50 mm.'
         ' Garis putus-putus tegak = sambungan papan (kolom 33 | 34).</text>'
         '<text x="20" y="22.5" font-size="3">Lingkaran + silang = lubang bor 3 mm (putus-putus = RC522, opsional).'
         ' Titik hitam = kaki komponen yang dipasang di sisi ini. Lingkaran kosong = kaki dari sisi lain.</text>')
svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="297mm" height="210mm" viewBox="0 0 297 210" '
       'font-family="Helvetica, Arial, sans-serif">', '<rect width="297" height="210" fill="#fff"/>', judul,
       '<text x="70" y="31" font-size="4" text-anchor="middle" font-weight="bold">DEPAN (sisi LCD &amp; RC522)</text>',
       svg_papan(20, 34, False),
       '<text x="227" y="31" font-size="4" text-anchor="middle" font-weight="bold">BELAKANG (sisi ESP32)</text>',
       svg_papan(177, 34, True),
       '<path d="M20 202H70M20 200V204M70 200V204" stroke="#000" stroke-width="0.4"/>',
       '<text x="45" y="199" font-size="3" text-anchor="middle">50 mm</text>', '</svg>']
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
