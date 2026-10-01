// Casing Absensi RFID Terintegrasi untuk papan 100 x 100 mm (hardware/pcb/buat_pcb.py dan
// hardware/dotmatrix/tata_letak.py; pilih dengan parameter `versi`).
// Dua bagian, disatukan 4 baut M3 dari belakang:
//   depan    : panel depan + dinding (jendela LCD, area tempel kartu, lubang LED & buzzer)
//   belakang : tutup belakang (lubang USB di kiri, lubang EN & BOOT, gantungan dinding, ventilasi)
//
// Buka di OpenSCAD, pilih bagian di bawah (atau lewat Customizer), lalu F6 dan File > Export > STL.
// Atau jalankan hardware/casing/buat.sh untuk membuat semua STL + gambar sekaligus.
//
// Koordinat papan sama dengan hardware/pcb/buat_pcb.py: dilihat dari DEPAN, (0,0) = pojok kiri atas PCB,
// X ke kanan, Y ke BAWAH. Fungsi di() mengubahnya ke koordinat OpenSCAD (Y ke atas).
// Z = 0 di permukaan belakang casing, makin besar makin ke depan.

/* [Bagian] */
bagian = "rakit"; // [depan, belakang, rakit]
// dotmatrix = papan dot matrix (hardware/dotmatrix/tata_letak.py), pcb = PCB cetak (hardware/pcb/buat_pcb.py)
versi = "dotmatrix"; // [dotmatrix, pcb]

/* [Ukuran umum (mm)] */
dinding = 2.4;        // tebal dinding samping & tutup belakang
panel = 2.0;          // tebal panel depan (di atas kartu RFID; makin tipis makin jauh jarak baca)
jarak_pcb = 0.5;      // celah PCB ke dinding
pita_atas = 8.5;      // ruang di atas PCB untuk baut penyatu casing
bulat = 4;            // jari-jari sudut luar

/* [Tinggi susunan (mm)] */
ruang_esp32 = 18;     // tutup belakang ke PCB: ESP32 + soket 8.5 + komponen ESP32
tebal_pcb = 1.6;
tinggi_soket = 11.04; // soket female 8.5 + plastik header modul 2.54 (LCD & RC522 sama)
tebal_lcd = 5.8;      // PCB LCD + layar + touch (gambar resmi MSP3520)
celah_lcd = 0.4;      // layar ke panel depan

/* [Baut] */
lubang_baut = 3.4;    // lubang tembus M3
lubang_ulir = 2.6;    // lubang pilot untuk baut M3 masuk langsung ke plastik
kepala_baut = 6.2;    // ceruk kepala baut di belakang
dalam_kepala = 2.5;
tiang = 7;            // diameter tiang baut

$fn = 48;

// ---------- Turunan ----------
Z_PCB = dinding + ruang_esp32;                              // permukaan belakang PCB
Z_PCB_ATAS = Z_PCB + tebal_pcb;
Z_PANEL = Z_PCB_ATAS + tinggi_soket + tebal_lcd + celah_lcd; // permukaan dalam panel depan
Z_ATAS = Z_PANEL + panel;
Z_SAMBUNG = Z_PCB - 2;                                       // sambungan berundak depan/belakang

// Rongga dalam & luar (koordinat papan: Y ke bawah)
D_X0 = -jarak_pcb;  D_X1 = 100 + jarak_pcb;
D_Y0 = -pita_atas;  D_Y1 = 100 + jarak_pcb;
L_X0 = D_X0 - dinding;  L_X1 = D_X1 + dinding;
L_Y0 = D_Y0 - dinding;  L_Y1 = D_Y1 + dinding;

function di(x, y) = [x, 100 - y];

// ---------- Posisi dari PCB (pcb/buat_pcb.py) ----------
LUBANG_LCD = [[4.0, 4.42], [96.0, 4.42], [4.0, 53.92], [96.0, 53.92]];
// Versi dot matrix: komponen di grid 2.54 mm, RC522 & ESP32 bergeser +-1 mm dari desain PCB
DM = versi == "dotmatrix";
LUBANG_RC522 = DM ? [[65.47, 95.88], [65.47, 60.88], [27.97, 91.08], [27.97, 65.23]]
                  : [[64.35, 96.52], [64.35, 61.52], [26.85, 91.72], [26.85, 65.87]];
LUBANG_PCB_BAWAH = [[4.0, 96.0], [96.0, 96.0]];     // baut casing lewat PCB
BAUT_ATAS = [[10, -4.25], [90, -4.25]];             // baut casing di pita atas (tanpa PCB)

// Area layar sentuh yang terlihat (RTP VA 77.84 x 50.56, landscape, LCD di (1,1))
JENDELA = [1 + 9.05, 1 + 2.89, 1 + 9.05 + 77.84, 1 + 2.89 + 50.56];
LED = DM ? [9.37, 63.46] : [9.24, 64.0];
BUZZER = DM ? [90.65, 66.0] : [90.5, 66.0];
KARTU_TENGAH = DM ? [39.12, 78.36] : [39, 79];      // tengah antena RC522

// ESP32 di belakang PCB: USB di tepi kiri, tombol EN & BOOT dekat USB
USB_Y = DM ? 27.9 : 29.0;
TOMBOL_EN = DM ? [6.52, 35.52] : [6.28, 36.62];
TOMBOL_BOOT = DM ? [6.52, 20.28] : [6.28, 21.38];
Z_ESP32_PCB = Z_PCB - tinggi_soket - tebal_pcb;     // permukaan komponen ESP32 (menghadap belakang)

// ---------- Alat bantu ----------
module kotak_bulat(x0, y0, x1, y1, z0, z1, r) {
    // x0,y0,x1,y1 dalam koordinat papan
    a = di(x0, y1); b = di(x1, y0);
    rr = max(0.01, r);
    translate([0, 0, z0]) hull() for (x = [a[0] + rr, b[0] - rr], y = [a[1] + rr, b[1] - rr])
        translate([x, y, 0]) cylinder(r = rr, h = z1 - z0);
}

module di_titik(p) { translate([di(p[0], p[1])[0], di(p[0], p[1])[1], 0]) children(); }

module luar(z0, z1) { kotak_bulat(L_X0, L_Y0, L_X1, L_Y1, z0, z1, bulat); }
module rongga(z0, z1, susut = 0) {
    kotak_bulat(D_X0 + susut, D_Y0 + susut, D_X1 - susut, D_Y1 - susut, z0, z1, max(0.5, bulat - dinding - susut));
}

// ---------- Tutup belakang ----------
module belakang() {
    difference() {
        union() {
            luar(0, dinding);
            // dinding penuh sampai sambungan, lalu bibir dalam (setengah tebal) sampai PCB
            difference() { luar(0, Z_SAMBUNG); rongga(-1, Z_SAMBUNG + 1); }
            difference() { rongga(Z_SAMBUNG - 0.01, Z_PCB, -dinding / 2); rongga(Z_SAMBUNG - 1, Z_PCB + 1); }
            // tiang: PCB duduk di atasnya
            for (p = concat(LUBANG_LCD, LUBANG_RC522, LUBANG_PCB_BAWAH, BAUT_ATAS))
                di_titik(p) cylinder(d = tiang, h = Z_PCB);
        }
        // lubang tembus + ceruk kepala baut (dari luar belakang)
        for (p = concat(LUBANG_LCD, LUBANG_RC522, LUBANG_PCB_BAWAH, BAUT_ATAS)) di_titik(p) {
            translate([0, 0, -1]) cylinder(d = lubang_baut, h = Z_PCB + 2);
            translate([0, 0, -1]) cylinder(d = kepala_baut, h = dalam_kepala + 1);
        }
        // lubang USB-C di dinding kiri
        translate([L_X0 - 1, di(0, USB_Y)[1] - 6.5, dinding])
            cube([dinding + 2, 13, max(9, Z_ESP32_PCB + 2 - dinding)]);
        // lubang tombol EN & BOOT (tekan dengan tusuk gigi)
        for (p = [TOMBOL_EN, TOMBOL_BOOT]) di_titik(p) translate([0, 0, -1]) cylinder(d = 3.5, h = dinding + 2);
        // gantungan dinding (lubang kunci): masukkan kepala sekrup ke lingkaran besar, geser ke bawah
        for (x = [35, 65]) {
            di_titik([x, -1.5]) translate([0, 0, -1]) cylinder(d = 8.5, h = dinding + 2);
            hull() for (y = [-1.5, -6.5]) di_titik([x, y]) translate([0, 0, -1]) cylinder(d = 4.2, h = dinding + 2);
        }
        // ventilasi di belakang ESP32
        for (x = [16 : 5 : 41]) hull() for (y = [19, 39]) di_titik([x, y]) translate([0, 0, -1]) cylinder(d = 2, h = dinding + 2);
        // tulisan (dicerminkan supaya terbaca dari belakang)
        for (t = [["EN", TOMBOL_EN + [0, 4.5]], ["BOOT", TOMBOL_BOOT - [0, 4.5]]]) di_titik(t[1])
            translate([0, 0, -0.01]) mirror([1, 0, 0]) linear_extrude(0.6)
                text(t[0], size = 2.5, halign = "center", valign = "center");
        // di_titik([46, 80]) translate([0, 0, -0.01]) mirror([1, 0, 0]) linear_extrude(0.6)
            // text("Absensi Alat", size = 6, halign = "center", valign = "center");
    }
}

// ---------- Panel depan ----------
module depan() {
    difference() {
        union() {
            luar(Z_PANEL, Z_ATAS);
            // dinding penuh dari PCB ke panel, lalu rok luar (setengah tebal) turun ke sambungan
            difference() { luar(Z_PCB, Z_ATAS); rongga(Z_PCB - 1, Z_ATAS + 1); }
            difference() { luar(Z_SAMBUNG, Z_PCB + 0.01); rongga(Z_SAMBUNG - 1, Z_PCB + 1, -dinding / 2); }
            // tiang baut: di lubang PCB bawah (menekan PCB) dan di pita atas (bertemu tiang belakang)
            for (p = LUBANG_PCB_BAWAH) di_titik(p) translate([0, 0, Z_PCB_ATAS]) cylinder(d = tiang, h = Z_PANEL - Z_PCB_ATAS + 0.01);
            for (p = BAUT_ATAS) di_titik(p) translate([0, 0, Z_PCB]) cylinder(d = tiang, h = Z_PANEL - Z_PCB + 0.01);
            // tabung cahaya LED: kubah LED masuk ke tabung ini
            di_titik(LED) translate([0, 0, Z_PANEL - 8]) cylinder(d = 7.8, h = 8.01);
        }
        // lubang pilot baut (tidak tembus panel)
        for (p = concat(LUBANG_PCB_BAWAH, BAUT_ATAS)) di_titik(p)
            translate([0, 0, Z_PCB - 1]) cylinder(d = lubang_ulir, h = Z_PANEL - Z_PCB + 1);
        // jendela LCD, tepi miring 45° ke luar
        hull() {
            kotak_bulat(JENDELA[0], JENDELA[1], JENDELA[2], JENDELA[3], Z_PANEL - 1, Z_PANEL + 0.01, 0.5);
            kotak_bulat(JENDELA[0] - panel, JENDELA[1] - panel, JENDELA[2] + panel, JENDELA[3] + panel,
                        Z_ATAS, Z_ATAS + 0.01, 0.5);
        }
        kotak_bulat(JENDELA[0], JENDELA[1], JENDELA[2], JENDELA[3], Z_PANEL - 1, Z_ATAS + 1, 0.5);
        // LED
        di_titik(LED) translate([0, 0, Z_PANEL - 9]) cylinder(d = 5.4, h = 20);
        // lubang suara buzzer
        for (dx = [-4.5 : 3 : 4.5], dy = [-4.5 : 3 : 4.5]) if (dx * dx + dy * dy <= 30)
            di_titik(BUZZER + [dx, dy]) translate([0, 0, Z_PANEL - 1]) cylinder(d = 1.6, h = panel + 2, $fn = 16);
        // area tempel kartu: garis kartu + tulisan, diukir 0.6 mm
        translate([0, 0, Z_ATAS - 0.6]) linear_extrude(1) {
            difference() {
                kartu(52, 32, 3);
                kartu(49.6, 29.6, 1.8);
            }
            translate(di(KARTU_TENGAH[0], KARTU_TENGAH[1] + 5)) text("TEMPEL KARTU", size = 3.8, halign = "center", valign = "center");
            translate(di(KARTU_TENGAH[0], KARTU_TENGAH[1] - 6)) gelombang();
        }
    }
}

module kartu(w, h, r) {
    translate(di(KARTU_TENGAH[0], KARTU_TENGAH[1]))
        hull() for (x = [-w / 2 + r, w / 2 - r], y = [-h / 2 + r, h / 2 - r]) translate([x, y]) circle(r = r);
}

module gelombang() {
    // simbol nirkontak: 3 busur
    for (i = [1 : 3]) intersection() {
        difference() { circle(r = 2 + i * 2.2); circle(r = 1 + i * 2.2); }
        polygon([[0, 0], [12, 7], [12, -7]]);
    }
    circle(r = 1.2);
}

// ---------- Tampilan rakitan (hanya untuk melihat, tidak dicetak) ----------
module rakit() {
    color("SaddleBrown", 0.9) belakang();
    color("WhiteSmoke", 0.55) depan();
    color("Green") translate([0, 0, Z_PCB]) linear_extrude(tebal_pcb) translate(di(0, 100)) square([100, 100]);
    color("SteelBlue") translate([0, 0, Z_PCB_ATAS + tinggi_soket]) linear_extrude(tebal_lcd)
        translate(di(1, 57.34)) square([98, 56.34]);
    color("RoyalBlue") translate([0, 0, Z_PCB_ATAS + tinggi_soket]) linear_extrude(1.6)
        translate(di(20, 99)) square([60, 40]);
    color("DimGray") translate([0, 0, Z_ESP32_PCB - 3.5]) linear_extrude(tebal_pcb + 3.5)
        translate(di(-0.6, 43)) square([51.5, 28]);
}

if (bagian == "depan") depan();
else if (bagian == "belakang") belakang();
else if (bagian == "rakit") rakit();
