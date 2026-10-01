// Pengaturan TFT_eSPI: layar saja. Touch memakai jalur SPI terpisah (lihat tes_touch.ino).
// LCD: SCK=18, SDI=23, CS=5, DC=16 (bukan 2), RESET=4. SDO LCD tidak disambung.

#define USER_SETUP_ID 101
#define ILI9488_DRIVER

#define TFT_MISO -1   // tidak dipakai layar
#define TFT_MOSI 23
#define TFT_SCLK 18
#define TFT_CS    5
#define TFT_DC   16
#define TFT_RST   4

#define LOAD_GLCD
#define LOAD_FONT2
#define LOAD_FONT4
#define LOAD_FONT6
#define LOAD_FONT7
#define LOAD_FONT8
#define LOAD_GFXFF
#define SMOOTH_FONT

#define SPI_FREQUENCY  27000000
