// Casing v2 Absensi RFID Terintegrasi untuk papan dot matrix 1 sisi yang sudah dipotong (90 x 113 mm,
// hardware/dotmatrix/tata_letak.py). Semua ukuran diukur langsung dari alat yang sudah dirakit.
// (casing.scad yang lama untuk PCB / dot matrix 100 x 100.)
//
// Dua bagian:
//   depan    : panel depan + 4 dinding (jendela LCD, tanda tempel kartu, LED, lubang EN & BOOT,
//              colokan USB-C di dinding bawah, lubang suara buzzer di dinding atas)
//   belakang : tutup belakang (penjepit LCD, penyangga papan, gantungan dinding)
// Tutup dipasang miring: lidah kanan masuk ke alur dinding kanan, sisi kiri diturunkan, lalu 2 baut M3 di kiri.
//
// Buka di OpenSCAD, pilih bagian, F6, File > Export > STL. Atau jalankan buat2.sh.
//
// Koordinat dilihat dari DEPAN (mm): (0,0) = pojok kiri atas ruang isi, X ke kanan, Y ke BAWAH.
// Ruang isi 106 x 117,5: modul LCD di (0,0)-(100,56), papan dot matrix di (16;4,5)-(106;117,5).
// Z = 0 di muka dalam tutup belakang, makin besar makin ke depan.

/* [Bagian] */
bagian = "rakit"; // [depan, belakang, rakit]

/* [Ukuran umum (mm)] */
dinding = 2.0;        // tebal dinding atas, bawah, kiri
dinding_kanan = 3.0;  // dinding kanan lebih tebal untuk alur lidah tutup
panel = 2.0;          // tebal panel depan (di atas RC522; makin tipis makin jauh jarak baca)
tutup = 2.0;          // tebal tutup belakang
celah = 0.5;          // celah isi ke dinding
celah_layar = 0.5;    // permukaan layar ke panel depan
bulat = 3;            // jari-jari sudut luar

/* [Susunan isi, dari tutup belakang (mm)] */
ruang_kabel = 8;      // kabel & solderan di belakang papan
tebal_papan = 2;
lcd_dari_papan = 11;  // muka papan -> belakang PCB LCD (header + pin)
tebal_pcb_lcd = 1.6;
layar_dari_papan = 16;   // muka papan -> permukaan layar

/* [Baut & gantungan] */
lubang_baut = 3.4;    // lubang tembus M3 di tutup
lubang_ulir = 2.6;    // pilot: baut M3 masuk langsung ke plastik
kepala_baut = 6.4;
tiang = 6.5;
gantungan = true;     // 2 lubang kunci untuk digantung di sekrup dinding

$fn = 48;

// ---------- Turunan ----------
W = 106;  H = 117.5;                         // ruang isi
Z_PAPAN = ruang_kabel;                       // belakang papan
Z_MUKA = Z_PAPAN + tebal_papan;              // muka papan (sisi komponen)
Z_LCD = Z_MUKA + lcd_dari_papan;             // belakang PCB LCD
Z_LCD_DEPAN = Z_LCD + tebal_pcb_lcd;
Z_LAYAR = Z_MUKA + layar_dari_papan;         // permukaan layar
Z_PANEL = Z_LAYAR + celah_layar;             // muka dalam panel depan
Z_ATAS = Z_PANEL + panel;
Z_BELAKANG = -tutup;

D_X0 = -celah;  D_X1 = W + celah;  D_Y0 = -celah;  D_Y1 = H + celah;            // rongga
L_X0 = D_X0 - dinding;  L_X1 = D_X1 + dinding_kanan;  L_Y0 = D_Y0 - dinding;  L_Y1 = D_Y1 + dinding;

function di(x, y) = [x, H - y];

// ---------- Posisi (diukur) ----------
LUBANG_LCD = [[3.25, 3.25], [3.25, 52.75]];   // 2 lubang kiri LCD (Ø4,5, tepi 1 mm)
JENDELA = [8.5, 3, 84.5, 54.5];               // layar aktif 75 x 50,5 di (9;3,5) + 0,5
ZONA_KARTU = [31, 57, 68, 99.5];              // area antena RC522 (37 x 46), bagian di bawah jendela
TENGAH_KARTU = [49.5, 78];
USB = [83.25, 14.75 + Z_MUKA];                // tengah colokan (x, z)
TOMBOL = [["EN", [91, 113]], ["BOOT", [76, 113]]];
LED = [8, 76.5];
BUZZER = [27.4, 15.5];
BAUT = [[2.5, 64], [2.5, 112]];               // sisi kiri, di bawah LCD (kosong)
PASAK_PAPAN = [[22, 115.5], [101.5, 115.5]];  // menyangga belakang papan di baris bawah yang kosong
GANTUNG = [[25, 22], [85, 22]];
SAKU_ESP32 = [68.8, 62, 98, 118];             // panel ditipiskan di atas ESP32 + USB-C

// ---------- Alat bantu ----------
module kotak(x0, y0, x1, y1, z0, z1, r = 0) {
    a = di(x0, y1); b = di(x1, y0);
    rr = max(0.01, r);
    translate([0, 0, z0]) hull() for (x = [a[0] + rr, b[0] - rr], y = [a[1] + rr, b[1] - rr])
        translate([x, y, 0]) cylinder(r = rr, h = z1 - z0);
}
module di_titik(p) { translate([di(p[0], p[1])[0], di(p[0], p[1])[1], 0]) children(); }
module kotak2d(x0, y0, x1, y1, r) {
    translate(di(x0, y1)) offset(r = r) offset(delta = -r) square([x1 - x0, y1 - y0]);
}

module luar(z0, z1) { kotak(L_X0, L_Y0, L_X1, L_Y1, z0, z1, bulat); }
module rongga(z0, z1) { kotak(D_X0, D_Y0, D_X1, D_Y1, z0, z1, 0.5); }

// ---------- Bagian depan ----------
module depan() {
    difference() {
        union() {
            difference() {
                // sudut depan dimiringkan 0,8 mm
                hull() { luar(Z_BELAKANG, Z_ATAS - 0.8); kotak(L_X0 + 0.8, L_Y0 + 0.8, L_X1 - 0.8, L_Y1 - 0.8, Z_ATAS - 0.01, Z_ATAS, bulat - 0.8); }
                rongga(Z_BELAKANG - 1, Z_PANEL);
            }
            // tiang baut tutup
            for (p = BAUT) di_titik(p) cylinder(d = tiang, h = Z_PANEL + 0.01);
            // bantalan penjepit LCD dari depan (di lubang kiri LCD)
            for (p = LUBANG_LCD) di_titik(p) translate([0, 0, Z_LCD_DEPAN + 0.1]) cylinder(d = 4, h = Z_PANEL - Z_LCD_DEPAN - 0.09);
        }
        for (p = BAUT) di_titik(p) translate([0, 0, -1]) cylinder(d = lubang_ulir, h = 13);
        // jendela LCD, tepi miring ke luar
        hull() {
            kotak(JENDELA[0], JENDELA[1], JENDELA[2], JENDELA[3], Z_PANEL - 1, Z_ATAS - 1, 0.8);
            kotak(JENDELA[0] - 1.2, JENDELA[1] - 1.2, JENDELA[2] + 1.2, JENDELA[3] + 1.2, Z_ATAS + 0.2, Z_ATAS + 0.21, 1.5);
        }
        // panel ditipiskan di atas ESP32 (modul & USB-C setinggi layar)
        kotak(SAKU_ESP32[0], SAKU_ESP32[1], SAKU_ESP32[2], SAKU_ESP32[3], Z_PANEL - 1, Z_PANEL + 1, 1);
        // colokan USB-C di dinding bawah (12 x 7), tembus sampai muka depan
        kotak(USB[0] - 6, H - 1, USB[0] + 6, L_Y1 + 1, USB[1] - 3.5, Z_ATAS + 1, 1);
        // tombol EN & BOOT: lubang jarum, tekan dengan klip kertas
        for (t = TOMBOL) di_titik(t[1]) translate([0, 0, Z_PANEL - 2]) cylinder(d = 2.2, h = panel + 4, $fn = 20);
        // LED 5 mm, dimasukkan dari dalam
        di_titik(LED) translate([0, 0, Z_PANEL - 1]) cylinder(d = 5.15, h = panel + 2);
        // lubang suara buzzer di dinding atas (antara papan dan LCD)
        for (x = [BUZZER[0] - 6 : 3 : BUZZER[0] + 6]) kotak(x - 0.8, L_Y0 - 1, x + 0.8, 1, Z_MUKA + 2, Z_LCD - 2, 0.7);
        // alur lidah tutup di dinding kanan
        kotak(D_X1 - 0.1, 22, D_X1 + 1.5, 98, -1.2, 0.2);
        // ukiran: tanda tempel kartu, EN, BOOT
        translate([0, 0, Z_ATAS - 0.6]) linear_extrude(1) {
            difference() {
                kotak2d(ZONA_KARTU[0], ZONA_KARTU[1], ZONA_KARTU[2], ZONA_KARTU[3], 3);
                kotak2d(ZONA_KARTU[0] + 1, ZONA_KARTU[1] + 1, ZONA_KARTU[2] - 1, ZONA_KARTU[3] - 1, 2);
            }
            translate(di(TENGAH_KARTU[0], TENGAH_KARTU[1] - 6)) rotate(90) gelombang();
            translate(di(TENGAH_KARTU[0], TENGAH_KARTU[1] + 8)) text("TEMPEL", size = 4.2, halign = "center", valign = "center", font = "Liberation Sans:style=Bold");
            translate(di(TENGAH_KARTU[0], TENGAH_KARTU[1] + 14)) text("KARTU", size = 4.2, halign = "center", valign = "center", font = "Liberation Sans:style=Bold");
            for (t = TOMBOL) translate(di(t[1][0], t[1][1] - 4)) text(t[0], size = 2.2, halign = "center", valign = "center", font = "Liberation Sans:style=Bold");
        }
    }
}

module gelombang() {
    // simbol nirkontak: 3 busur
    for (i = [1 : 3]) intersection() {
        difference() { circle(r = 2 + i * 2.2); circle(r = 1 + i * 2.2); }
        polygon([[0, 0], [12, 7], [12, -7]]);
    }
    circle(r = 1.2);
}

// ---------- Tutup belakang ----------
module belakang() {
    difference() {
        union() {
            kotak(D_X0 + 0.2, D_Y0 + 0.2, D_X1 - 0.2, D_Y1 - 0.2, Z_BELAKANG, 0, 0.3);
            // lidah kanan (masuk ke alur dinding kanan)
            kotak(D_X1 - 0.5, 25, D_X1 + 1.1, 95, -1, 0);
            // penjepit LCD dari belakang (di lubang kiri LCD)
            for (p = LUBANG_LCD) di_titik(p) cylinder(d = 5, h = Z_LCD - 0.2);
            // pasak penyangga papan
            for (p = PASAK_PAPAN) di_titik(p) cylinder(d = 3.5, h = Z_PAPAN - 0.1);
            // rumah kepala sekrup gantungan
            if (gantungan) for (p = GANTUNG) kotak(p[0] - 7, p[1] - 9, p[0] + 7, p[1] + 8, 0, 3.8, 2);
        }
        for (p = BAUT) di_titik(p) {
            translate([0, 0, Z_BELAKANG - 1]) cylinder(d = lubang_baut, h = tutup + 2);
            translate([0, 0, Z_BELAKANG - 0.01]) cylinder(d1 = kepala_baut, d2 = lubang_baut, h = (kepala_baut - lubang_baut) / 2);
        }
        // lubang kunci: kepala sekrup masuk lingkaran besar, lalu casing diturunkan
        if (gantungan) for (p = GANTUNG) {
            di_titik(p + [0, 4]) translate([0, 0, Z_BELAKANG - 1]) cylinder(d = 8.5, h = tutup + 2);
            hull() for (y = [4, -4]) di_titik(p + [0, y]) translate([0, 0, Z_BELAKANG - 1]) cylinder(d = 4.2, h = tutup + 2);
            kotak(p[0] - 5, p[1] - 7, p[0] + 5, p[1] + 6, -0.01, 2.6, 1);
        }
        // tulisan di tutup (dicerminkan supaya terbaca dari belakang)
        di_titik([53, 70]) translate([0, 0, Z_BELAKANG - 0.01]) mirror([1, 0, 0]) linear_extrude(0.5)
            text("ATAS ^", size = 5, halign = "center", valign = "center", font = "Liberation Sans:style=Bold");
    }
}

// ---------- Isi casing (untuk rakitan & cek tabrakan) ----------
module isi(i) {
    if (i == 0) kotak(16, 4.5, 106, 117.5, Z_PAPAN, Z_MUKA);                                   // papan
    if (i == 1) kotak(0, 0, 100, 56, Z_LCD, Z_LCD_DEPAN);                                      // PCB LCD
    if (i == 2) kotak(7, 1.5, 86, 56, Z_LCD_DEPAN, Z_LAYAR);                                   // layar
    if (i == 3) kotak(98, 1.5, 103, 34.5, Z_MUKA, Z_LCD);                                      // header LCD
    if (i == 4) kotak(28.1, 56.4, 68.1, 115.2, Z_MUKA + 8.5, Z_MUKA + 12.1);                   // RC522
    if (i == 5) kotak(69.3, 64, 97.3, 115.5, Z_MUKA, Z_MUKA + 13);                             // ESP32 + soket
    if (i == 6) kotak(71.3, 64, 95.3, 89.5, Z_MUKA + 13, Z_MUKA + 16.3);                       // modul WROOM
    if (i == 7) kotak(78.5, 111.5, 88, 117.5, Z_MUKA + 13, Z_MUKA + 16.5);                     // USB-C
    if (i == 8) translate([di(BUZZER[0], 0)[0], di(0, BUZZER[1])[1], Z_MUKA]) cylinder(r = 6, h = 9.5);
    if (i == 9) translate([di(LED[0], 0)[0], di(0, LED[1])[1], Z_PANEL - 9]) cylinder(r = 2.9, h = 8.9); // badan LED
}
NAMA_ISI = ["papan dot matrix", "PCB LCD", "layar LCD", "header LCD", "RC522", "ESP32 + soket",
            "modul WROOM", "USB-C", "buzzer", "badan LED"];

module rakit() {
    // panel depan tidak ditampilkan supaya isi terlihat
    color("SaddleBrown") belakang();
    color("Green") isi(0);
    color("SteelBlue") { isi(1); isi(3); }
    color("Black") isi(2);
    color("RoyalBlue") isi(4);
    color("DimGray") { isi(5); isi(6); }
    color("Silver") isi(7);
    color("Black") isi(8);
    color("Red") isi(9);
}

// cek tabrakan: openscad -D 'bagian="cek"' -D item=N  -> kosong = aman
item = 0;
// STL langsung dalam posisi cetak: depan = muka depan menempel di bed, belakang = muka luar tutup di bed
if (bagian == "depan") translate([0, 0, Z_ATAS]) rotate([0, 180, 0]) depan();
else if (bagian == "belakang") translate([0, 0, tutup]) belakang();
else if (bagian == "lihat-depan") depan();
else if (bagian == "rakit") rakit();
else if (bagian == "cek") { echo(NAMA = NAMA_ISI[item]); intersection() { union() { depan(); belakang(); } isi(item); } }
