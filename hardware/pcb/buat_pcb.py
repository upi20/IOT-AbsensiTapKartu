# Membuat PCB papan induk Absensi RFID Terintegrasi (KiCad 10) dari nol: bentuk papan, posisi komponen, sambungan (net),
# area larangan tembaga (antena), dan tulisan. Jalur tembaga dibuat terpisah oleh rute.py (Freerouting).
#
# Jalankan dengan Python bawaan KiCad (lihat buat.sh). Semua ukuran dalam mm, dilihat dari DEPAN papan
# (sisi LCD dan RFID), titik (0,0) = pojok kiri atas papan, X ke kanan, Y ke bawah.
#
# Sumber ukuran:
#   LCD    : gambar resmi LCDWIKI MSP3520 (MSP3520_Size.pdf): PCB 98 x 56.34, lubang 92 x 49.5 (Ø3.2),
#            header 14 pin di tengah sisi pendek, 2.00 mm dari tepi. Skema: pin LED -> 1k -> transistor S8050.
#   RC522  : 60 x 40, footprint AZ-Delivery RC522 (tonberry-pico di GitHub): header 1.9 mm dari tepi,
#            pin SDA 9.5 mm dari tepi bawah. Posisi lubang bisa beda antar pabrik -> cek dengan cetak 1:1.
#   ESP32  : DOIT DevKit V1 30 pin, 28 x 51.5, jarak deret 25.4 mm, pin pertama 6 mm dari ujung antena.
import sys
import pcbnew as p

KELUAR = sys.argv[1] if len(sys.argv) > 1 else "absensi-pcb.kicad_pcb"
LIB = "/Applications/KiCad/KiCad.app/Contents/SharedSupport/footprints/"
OX, OY = 40.0, 40.0           # geser papan di lembar kerja KiCad (tidak mengubah desain)

# ---------- Ukuran papan & modul ----------
PAPAN_W, PAPAN_H = 100.0, 100.0

LCD_X, LCD_Y = 1.0, 1.0                     # pojok kiri atas PCB LCD (landscape, pin di kanan)
LCD_W, LCD_H = 98.0, 56.34
LCD_PIN_X = LCD_X + LCD_W - 2.00            # deret pin 2.00 mm dari tepi kanan LCD
LCD_PIN1_Y = LCD_Y + 11.66 + 13 * 2.54      # pin 1 (VCC) paling bawah, pin 14 (T_IRQ) paling atas
LCD_LUBANG = [(LCD_X + 3.00, LCD_Y + 3.42), (LCD_X + 95.00, LCD_Y + 3.42),
              (LCD_X + 3.00, LCD_Y + 52.92), (LCD_X + 95.00, LCD_Y + 52.92)]

RF_KANAN, RF_BAWAH = 80.0, 99.0             # tepi kanan & bawah modul RC522 (60 x 40), pin di kanan
RF_W, RF_H = 60.0, 40.0
RF_PIN_X = RF_KANAN - 1.9
RF_PIN1_Y = RF_BAWAH - 9.5                  # pin 1 (SDA) paling bawah, pin 8 (3.3V) paling atas
RF_LUBANG = [(RF_KANAN - 15.65, RF_BAWAH - 2.48), (RF_KANAN - 15.65, RF_BAWAH - 37.48),
             (RF_KANAN - 53.15, RF_BAWAH - 7.28), (RF_KANAN - 53.15, RF_BAWAH - 33.13)]

# ESP32 DevKit di SISI BELAKANG, mendatar, USB di tepi kiri (dilihat dari depan).
ESP_PIN1_X = 45.96                          # pin 1 (EN / D23) di ujung antena, pin 15 (VIN / 3V3) dekat USB
ESP_TENGAH_Y = 29.0
ESP_KIRI_Y = ESP_TENGAH_Y + 12.7            # deret EN ... VIN
ESP_KANAN_Y = ESP_TENGAH_Y - 12.7           # deret D23 ... 3V3
ESP_UJUNG_ANTENA_X = ESP_PIN1_X + 6.04
ESP_UJUNG_USB_X = ESP_PIN1_X - 35.56 - 9.9

LUBANG_PAPAN = [(4.0, 96.0), (96.0, 96.0)]  # baut casing (atas memakai lubang LCD)

# ---------- Sambungan ----------
ESP_KIRI = ["", "", "", "", "RFID_MISO", "RFID_RST", "RFID_SS", "LED_R_IO", "LED_G_IO", "LED_B_IO",
            "RFID_SCK", "", "RFID_MOSI", "GND", ""]                       # EN VP VN 34 35 32 33 25 26 27 14 12 13 GND VIN
ESP_KANAN = ["LCD_MOSI", "T_CLK", "", "", "T_DIN", "T_OUT", "LCD_SCK", "LCD_CS", "BUZ", "LCD_DC",
             "LCD_RST", "LCD_LED", "T_CS", "GND", "+3V3"]                 # 23 22 TX RX 21 19 18 5 17 16 4 2 15 GND 3V3
ESP_KIRI_LABEL = ["EN", "VP", "VN", "34", "35", "32", "33", "25", "26", "27", "14", "12", "13", "GND", "VIN"]
ESP_KANAN_LABEL = ["23", "22", "TX", "RX", "21", "19", "18", "5", "17", "16", "4", "2", "15", "GND", "3V3"]
LCD_NET = ["+3V3", "GND", "LCD_CS", "LCD_RST", "LCD_DC", "LCD_MOSI", "LCD_SCK", "LCD_LED", "",
           "T_CLK", "T_CS", "T_DIN", "T_OUT", ""]
LCD_LABEL = ["VCC", "GND", "CS", "RESET", "DC", "SDI", "SCK", "LED", "SDO", "T_CLK", "T_CS", "T_DIN",
             "T_DO", "T_IRQ"]
RF_NET = ["RFID_SS", "RFID_SCK", "RFID_MOSI", "RFID_MISO", "", "GND", "RFID_RST", "+3V3"]
RF_LABEL = ["SDA", "SCK", "MOSI", "MISO", "IRQ", "GND", "RST", "3.3V"]

board = p.BOARD()
V = lambda x, y: p.VECTOR2I(p.FromMM(OX + x), p.FromMM(OY + y))
nets = {}


def net(nama):
    if not nama:
        return None
    if nama not in nets:
        n = p.NETINFO_ITEM(board, nama)
        board.Add(n)
        nets[nama] = n
    return nets[nama]


def taruh(lib, nama, ref, nilai, pad1, pad2=None, belakang=False, net_pad=None):
    """Pasang footprint supaya pad 1 tepat di `pad1` dan pad 2 di `pad2` (sudut dicari otomatis)."""
    fp = p.FootprintLoad(LIB + lib + ".pretty", nama)
    if fp is None:
        raise SystemExit(f"Footprint tidak ada: {lib}:{nama}")
    fp.SetReference(ref)
    fp.SetValue(nilai)
    board.Add(fp)
    if belakang:
        fp.Flip(fp.GetPosition(), p.FLIP_DIRECTION_LEFT_RIGHT)
    for sudut in (0, 90, 180, 270):
        fp.SetOrientationDegrees(sudut)
        fp.SetPosition(V(*pad1))
        pads = {pd.GetNumber(): pd for pd in fp.Pads()}
        if pad2 is None:
            break
        pos = pads["2"].GetPosition()
        if abs(pos.x - V(*pad2).x) < 2000 and abs(pos.y - V(*pad2).y) < 2000:
            break
    else:
        raise SystemExit(f"{ref}: tidak ada sudut yang cocok")
    if "1" in pads:
        pos = pads["1"].GetPosition()
        assert abs(pos.x - V(*pad1).x) < 2000 and abs(pos.y - V(*pad1).y) < 2000, ref
    for nomor, nama_net in (net_pad or {}).items():
        n = net(nama_net)
        if n:
            pads[str(nomor)].SetNet(n)
    return fp


def garis(x1, y1, x2, y2, lapisan, tebal=0.15):
    s = p.PCB_SHAPE(board, p.SHAPE_T_SEGMENT)
    s.SetStart(V(x1, y1))
    s.SetEnd(V(x2, y2))
    s.SetLayer(lapisan)
    s.SetWidth(p.FromMM(tebal))
    board.Add(s)


def kotak(x1, y1, x2, y2, lapisan, tebal=0.15):
    for a, b in (((x1, y1), (x2, y1)), ((x2, y1), (x2, y2)), ((x2, y2), (x1, y2)), ((x1, y2), (x1, y1))):
        garis(*a, *b, lapisan, tebal)


def tulis(teks, x, y, ukuran=1.0, lapisan=p.F_SilkS, sudut=0, kiri=False, kanan=False):
    t = p.PCB_TEXT(board)
    t.SetText(teks)
    t.SetPosition(V(x, y))
    t.SetLayer(lapisan)
    t.SetTextSize(p.VECTOR2I(p.FromMM(ukuran), p.FromMM(ukuran)))
    t.SetTextThickness(p.FromMM(ukuran * 0.15))
    t.SetTextAngleDegrees(sudut)
    if kiri:
        t.SetHorizJustify(p.GR_TEXT_H_ALIGN_LEFT)
    if kanan:
        t.SetHorizJustify(p.GR_TEXT_H_ALIGN_RIGHT)
    if lapisan in (p.B_SilkS, p.B_Fab):
        t.SetMirrored(True)
    board.Add(t)


def area_larangan(x1, y1, x2, y2, nama):
    """Rule area: tanpa jalur, via, dan tuangan tembaga di kedua sisi (antena)."""
    z = p.ZONE(board)
    z.SetIsRuleArea(True)
    z.SetZoneName(nama)
    ls = p.LSET()
    ls.AddLayer(p.F_Cu)
    ls.AddLayer(p.B_Cu)
    z.SetLayerSet(ls)
    z.SetDoNotAllowTracks(True)
    z.SetDoNotAllowVias(True)
    z.SetDoNotAllowZoneFills(True)
    z.SetDoNotAllowPads(False)
    z.SetDoNotAllowFootprints(False)
    o = z.Outline()
    o.NewOutline()
    for x, y in ((x1, y1), (x2, y1), (x2, y2), (x1, y2)):
        o.Append(p.FromMM(OX + x), p.FromMM(OY + y))
    board.Add(z)


def tuang_gnd(lapisan, prioritas):
    z = p.ZONE(board)
    z.SetLayer(lapisan)
    z.SetNet(net("GND"))
    z.SetAssignedPriority(prioritas)
    z.SetLocalClearance(p.FromMM(0.4))
    z.SetMinThickness(p.FromMM(0.3))
    z.SetPadConnection(p.ZONE_CONNECTION_THERMAL)
    o = z.Outline()
    o.NewOutline()
    for x, y in ((0.5, 0.5), (PAPAN_W - 0.5, 0.5), (PAPAN_W - 0.5, PAPAN_H - 0.5), (0.5, PAPAN_H - 0.5)):
        o.Append(p.FromMM(OX + x), p.FromMM(OY + y))
    board.Add(z)


# ---------- Aturan desain (aman untuk JLCPCB) & kelas net ----------
ds = board.GetDesignSettings()
ds.m_TrackMinWidth = p.FromMM(0.2)
ds.m_MinClearance = p.FromMM(0.2)
ds.m_ViasMinSize = p.FromMM(0.6)
ds.m_MinThroughDrill = p.FromMM(0.3)
ds.m_CopperEdgeClearance = p.FromMM(0.5)
ns = ds.m_NetSettings
bawaan = ns.GetDefaultNetclass()
bawaan.SetClearance(p.FromMM(0.2))
bawaan.SetTrackWidth(p.FromMM(0.25))
bawaan.SetViaDiameter(p.FromMM(0.7))
bawaan.SetViaDrill(p.FromMM(0.35))
daya = p.NETCLASS("Daya")
daya.SetClearance(p.FromMM(0.2))
daya.SetTrackWidth(p.FromMM(0.6))
daya.SetViaDiameter(p.FromMM(0.9))
daya.SetViaDrill(p.FromMM(0.45))
ns.SetNetclass("Daya", daya)
ns.SetNetclassPatternAssignment("GND", "Daya")
ns.SetNetclassPatternAssignment("+3V3", "Daya")

# ---------- Bentuk papan ----------
kotak(0, 0, PAPAN_W, PAPAN_H, p.Edge_Cuts, 0.1)

# ---------- ESP32 DevKit (sisi belakang) ----------
for kolom, (y, daftar, label, ref) in enumerate(((ESP_KIRI_Y, ESP_KIRI, ESP_KIRI_LABEL, "J1"),
                                                  (ESP_KANAN_Y, ESP_KANAN, ESP_KANAN_LABEL, "J2"))):
    taruh("Connector_PinSocket_2.54mm", "PinSocket_1x15_P2.54mm_Vertical", ref,
          "ESP32 " + ("EN..VIN" if kolom == 0 else "D23..3V3"),
          (ESP_PIN1_X, y), (ESP_PIN1_X - 2.54, y), belakang=True,
          net_pad={i + 1: n for i, n in enumerate(daftar)})
    for i, teks in enumerate(label):
        tulis(teks, ESP_PIN1_X - i * 2.54, y + (3.6 if kolom == 0 else -3.6), 0.8, p.B_SilkS, 90)
kotak(ESP_UJUNG_USB_X, ESP_TENGAH_Y - 14, ESP_UJUNG_ANTENA_X, ESP_TENGAH_Y + 14, p.B_Fab)
kotak(ESP_UJUNG_USB_X, ESP_TENGAH_Y - 14.3, ESP_UJUNG_ANTENA_X, ESP_TENGAH_Y + 14.3, p.B_SilkS)
tulis("ESP32 DevKit - komponen menghadap keluar", 25, ESP_TENGAH_Y - 3, 1.0, p.B_SilkS)
tulis("<- USB", 6, ESP_TENGAH_Y + 3, 1.2, p.B_SilkS)
tulis("EN", 6.3, ESP_TENGAH_Y + 9, 1.0, p.B_SilkS)
tulis("BOOT", 6.3, ESP_TENGAH_Y - 9, 1.0, p.B_SilkS)
area_larangan(ESP_PIN1_X + 1.2, ESP_TENGAH_Y - 9, ESP_UJUNG_ANTENA_X + 1.5, ESP_TENGAH_Y + 9, "Antena WiFi")
tulis("ANTENA", ESP_UJUNG_ANTENA_X - 3, ESP_TENGAH_Y, 1.0, p.B_SilkS, 90)
tulis("WiFi", ESP_UJUNG_ANTENA_X - 1.2, ESP_TENGAH_Y, 1.0, p.B_SilkS, 90)

# ---------- LCD (depan) ----------
taruh("Connector_PinSocket_2.54mm", "PinSocket_1x14_P2.54mm_Vertical", "J3", "LCD 3.5in",
      (LCD_PIN_X, LCD_PIN1_Y), (LCD_PIN_X, LCD_PIN1_Y - 2.54),
      net_pad={i + 1: n for i, n in enumerate(LCD_NET)})
for i, teks in enumerate(LCD_LABEL):
    tulis(teks, LCD_PIN_X - 1.8, LCD_PIN1_Y - i * 2.54, 0.9, kanan=True)
kotak(LCD_X, LCD_Y, LCD_X + LCD_W, LCD_Y + LCD_H, p.F_SilkS)
kotak(LCD_X, LCD_Y, LCD_X + LCD_W, LCD_Y + LCD_H, p.F_Fab)
tulis("LCD 3.5\" ILI9488 (pin di kanan)", 50, 24, 2.0)
tulis("Absensi RFID Terintegrasi - papan induk v1.0", 50, 30, 1.5)
for i, (x, y) in enumerate(LCD_LUBANG):
    taruh("MountingHole", "MountingHole_3.2mm_M3", f"H{i + 1}", "M3 LCD", (x, y))

# ---------- RC522 (depan) ----------
taruh("Connector_PinSocket_2.54mm", "PinSocket_1x08_P2.54mm_Vertical", "J4", "RC522",
      (RF_PIN_X, RF_PIN1_Y), (RF_PIN_X, RF_PIN1_Y - 2.54),
      net_pad={i + 1: n for i, n in enumerate(RF_NET)})
for i, teks in enumerate(RF_LABEL):
    tulis(teks, RF_PIN_X - 1.8, RF_PIN1_Y - i * 2.54, 0.9, kanan=True)
kotak(RF_KANAN - RF_W, RF_BAWAH - RF_H, RF_KANAN, RF_BAWAH, p.F_SilkS)
kotak(RF_KANAN - RF_W, RF_BAWAH - RF_H, RF_KANAN, RF_BAWAH, p.F_Fab)
tulis("RC522 - TEMPEL KARTU", RF_KANAN - 36, RF_BAWAH - 20, 2.0)
tulis("(tanpa tembaga di bawah antena)", RF_KANAN - 36, RF_BAWAH - 16, 1.0)
area_larangan(RF_KANAN - RF_W - 1, RF_BAWAH - RF_H - 0.5, RF_KANAN - 17.5, RF_BAWAH + 0.5, "Antena RFID")
for i, (x, y) in enumerate(RF_LUBANG):
    taruh("MountingHole", "MountingHole_3.2mm_M3", f"H{i + 5}", "M3 RC522", (x, y))
for i, (x, y) in enumerate(LUBANG_PAPAN):
    taruh("MountingHole", "MountingHole_3.2mm_M3", f"H{i + 9}", "M3 casing", (x, y))

# ---------- LED RGB (kiri bawah) ----------
taruh("LED_THT", "LED_D5.0mm-4_RGB_Wide_Pins", "D2", "RGB katoda bersama", (6.0, 64.0), (8.159, 64.0),
      net_pad={1: "LED_R", 2: "GND", 3: "LED_G", 4: "LED_B"})
tulis("R  K  G  B", 9.2, 60.2, 1.0)
led = board.FindFootprintByReference("D2")
led.Reference().SetPosition(V(2.6, 64.0))
led.Reference().SetTextAngleDegrees(90)
for i, (ref, io, led, x) in enumerate((("R1", "LED_R_IO", "LED_R", 5.0), ("R2", "LED_G_IO", "LED_G", 9.5),
                                       ("R3", "LED_B_IO", "LED_B", 14.0))):
    taruh("Resistor_THT", "R_Axial_DIN0207_L6.3mm_D2.5mm_P10.16mm_Horizontal", ref, "220",
          (x, 70.0), (x, 80.16), net_pad={1: io, 2: led})
taruh("Capacitor_THT", "CP_Radial_D8.0mm_P3.50mm", "C1", "470uF 10V", (8.25, 88.0), (11.75, 88.0),
      net_pad={1: "+3V3", 2: "GND"})

# ---------- Buzzer (kanan bawah) ----------
taruh("Buzzer_Beeper", "Buzzer_12x9.5RM7.6", "BZ1", "Buzzer aktif", (86.7, 66.0), (94.3, 66.0),
      net_pad={1: "+3V3", 2: "BUZ_K"})
taruh("Package_TO_SOT_THT", "TO-92_Inline_Wide", "Q1", "S8050", (84.0, 76.0), (86.54, 76.0),
      net_pad={1: "GND", 2: "BUZ_B", 3: "BUZ_K"})
tulis("E  B  C", 86.54, 78.6, 0.8)
taruh("Resistor_THT", "R_Axial_DIN0207_L6.3mm_D2.5mm_P10.16mm_Horizontal", "R4", "1k",
      (92.6, 74.0), (92.6, 84.16), net_pad={1: "BUZ", 2: "BUZ_B"})
taruh("Resistor_THT", "R_Axial_DIN0207_L6.3mm_D2.5mm_P10.16mm_Horizontal", "R5", "10k",
      (97.2, 74.0), (97.2, 84.16), net_pad={1: "BUZ_B", 2: "GND"})
taruh("Diode_THT", "D_DO-35_SOD27_P7.62mm_Horizontal", "D1", "1N4148", (83.0, 89.5), (90.62, 89.5),
      net_pad={1: "+3V3", 2: "BUZ_K"})
taruh("Capacitor_THT", "CP_Radial_D5.0mm_P2.50mm", "C3", "10uF", (83.25, 84.3), (85.75, 84.3),
      net_pad={1: "+3V3", 2: "GND"})
taruh("Capacitor_THT", "C_Disc_D3.0mm_W1.6mm_P2.50mm", "C4", "100nF", (82.6, 69.0), (82.6, 71.5),
      net_pad={1: "+3V3", 2: "GND"})

# ---------- Di bawah LCD (komponen pendek saja, maks. ± 6 mm) ----------
taruh("Resistor_THT", "R_Axial_DIN0207_L6.3mm_D2.5mm_P10.16mm_Horizontal", "R6", "10k",
      (14.0, 8.0), (24.16, 8.0), net_pad={1: "LCD_LED", 2: "GND"})
taruh("Capacitor_THT", "C_Disc_D3.0mm_W1.6mm_P2.50mm", "C2", "100nF", (92.5, 47.5), (92.5, 50.0),
      net_pad={1: "+3V3", 2: "GND"})

# ---------- Rapikan tulisan referensi ----------
for fp in board.GetFootprints():
    ref = fp.Reference()
    if fp.GetReference().startswith("H"):
        ref.SetVisible(False)
        continue
    pads = list(fp.Pads())
    if (fp.GetReference()[0] == "R" or fp.GetReference() == "D1") and len(pads) == 2:
        a, b = pads[0].GetPosition(), pads[1].GetPosition()
        ref.SetPosition(p.VECTOR2I((a.x + b.x) // 2, (a.y + b.y) // 2))
        ref.SetTextAngleDegrees(90 if abs(a.x - b.x) < abs(a.y - b.y) else 0)
        ref.SetTextSize(p.VECTOR2I(p.FromMM(0.8), p.FromMM(0.8)))
        ref.SetTextThickness(p.FromMM(0.12))

# Tuangan GND ditambahkan oleh rute.py SETELAH jalur ditarik (Freerouting menganggap tuangan sebagai penghalang).
if "--dengan-gnd" in sys.argv:
    tuang_gnd(p.B_Cu, 0)
    tuang_gnd(p.F_Cu, 0)

tulis("Absensi RFID Terintegrasi v1.0 | 2026-10", 50, 2.0, 0.9, p.B_SilkS)
board.SetFileName(KELUAR)
p.SaveBoard(KELUAR, board)
# Aturan tambahan: pin GND sudah tersambung lewat jalur, jadi 1 jari thermal ke tuangan GND sudah cukup.
with open(KELUAR.replace(".kicad_pcb", ".kicad_dru"), "w") as f:
    f.write('(version 1)\n(rule "Thermal GND minimal 1 jari"\n'
            '  (condition "A.NetName == \'GND\'")\n  (constraint thermal_spoke_count (min 1)))\n')
print(f"Tersimpan: {KELUAR} ({len(list(board.GetFootprints()))} footprint, {len(nets)} net)")
