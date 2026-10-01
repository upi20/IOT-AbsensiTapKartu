// Cek tabrakan: casing (depan + belakang) diiris dengan model kasar isi casing, satu per satu.
// Dipakai oleh buat.sh: openscad -D 'item=N' -o hasil.stl cek_tabrakan.scad
// Hasil kosong (atau volume ~0, hanya bersentuhan) = tidak bertabrakan.
include <casing.scad>
bagian = "cek";
item = 0;

NAMA_ITEM = ["PCB", "LCD", "RC522 + komponennya", "ESP32 + komponennya", "colokan USB-C", "soket LCD",
             "soket RC522", "buzzer", "elko C1", "LED (ditinggikan 13 mm)", "elko C3"];

module blok(x0, y0, x1, y1, z0, z1) translate([x0, 100 - y1, z0]) cube([x1 - x0, y1 - y0, z1 - z0]);

module isi(i) {
    if (i == 0) blok(0, 0, 100, 100, Z_PCB, Z_PCB_ATAS);
    if (i == 1) blok(1, 1, 99, 57.34, Z_PCB_ATAS + tinggi_soket, Z_PCB_ATAS + tinggi_soket + tebal_lcd);
    if (i == 2) blok(DM ? 21.12 : 20, DM ? 58.36 : 59, DM ? 81.12 : 80, DM ? 98.36 : 99, Z_PCB_ATAS + tinggi_soket, Z_PCB_ATAS + tinggi_soket + 1.6 + 3.5);
    if (i == 3) blok(DM ? 0.74 : 0.5, USB_Y - 14, DM ? 52.24 : 52, USB_Y + 14, Z_ESP32_PCB - 3.5, Z_ESP32_PCB + tebal_pcb);
    if (i == 4) blok(-1.2, USB_Y - 4, 8, USB_Y + 4, Z_ESP32_PCB - 3.3, Z_ESP32_PCB);
    if (i == 5) blok(95.7, 12.0, 98.3, 47.0, Z_PCB_ATAS, Z_PCB_ATAS + tinggi_soket);
    if (i == 6) blok(DM ? 77.95 : 76.8, DM ? 69.8 : 70.5, DM ? 80.49 : 79.4, DM ? 90.1 : 90.8, Z_PCB_ATAS, Z_PCB_ATAS + tinggi_soket);
    if (i == 7) translate([BUZZER[0], 100 - BUZZER[1], Z_PCB_ATAS]) cylinder(r = 6.2, h = 9.5);
    if (i == 8) translate(DM ? [10.64, 100 - 88.86, Z_PCB_ATAS] : [10, 100 - 88, Z_PCB_ATAS]) cylinder(r = 4.2, h = 12.5);
    if (i == 9) translate([LED[0], 100 - LED[1], Z_PCB_ATAS]) cylinder(r = 2.6, h = 13);
    if (i == 10) translate(DM ? [95.73, 100 - 81.24, Z_PCB_ATAS] : [84.5, 100 - 84.3, Z_PCB_ATAS]) cylinder(r = 3.3, h = 11.5);
}

echo(NAMA = NAMA_ITEM[item]);
intersection() { union() { depan(); belakang(); } isi(item); }
