// Buzzer dan LED RGB. Semua pola berjalan tanpa delay: panggil feedbackUrus() di loop().
// LED mungkin tanpa resistor, jadi arus pin dikecilkan dan kecerahan dibatasi lewat PWM.
#pragma once
#include "driver/gpio.h"

struct Warna { uint8_t r, g, b; };   // 0..255 per kanal

// Kecerahan sengaja rendah (maks ~25%) supaya aman untuk LED tanpa resistor
const Warna LED_MATI   = {0, 0, 0};
const Warna LED_HIJAU  = {0, 64, 0};
const Warna LED_MERAH  = {64, 0, 0};
const Warna LED_BIRU   = {0, 0, 64};
const Warna LED_KUNING = {48, 36, 0};
const Warna LED_ORANYE = {64, 14, 0};
const Warna LED_PUTIH  = {40, 40, 40};
const Warna LED_SIAGA  = {0, 18, 18};   // napas pelan saat menunggu kartu

enum ModeLed { LED_TETAP, LED_NAPAS, LED_KEDIP, LED_TES };
ModeLed modeLed = LED_TETAP;
Warna warnaLed = LED_MATI;
uint32_t ledMulai = 0;

// ---------- LED ----------

void ledTulis(Warna w) {
  ledcWrite(PIN_LED_R, w.r);
  ledcWrite(PIN_LED_G, w.g);
  ledcWrite(PIN_LED_B, w.b);
}

bool warnaSama(Warna a, Warna b) { return a.r == b.r && a.g == b.g && a.b == b.b; }

void ledSetel(ModeLed mode, Warna w) {
  if (mode == modeLed && warnaSama(w, warnaLed)) return;   // sudah sama, animasi jangan diulang
  modeLed = mode;
  warnaLed = w;
  ledMulai = millis();
  if (mode == LED_TETAP) ledTulis(w);
}

void ledNyala(Warna w) { ledSetel(LED_TETAP, w); }
void ledNapas(Warna w) { ledSetel(LED_NAPAS, w); }
void ledKedip(Warna w) { ledSetel(LED_KEDIP, w); }

// ---------- Buzzer ----------

const uint16_t JEDA_BIP = 90;   // jeda antar bunyi (ms)
uint8_t bipSisa = 0;            // berapa bunyi lagi
uint16_t bipLama = 0;           // lama satu bunyi (ms)
bool bipNyala = false;
uint32_t bipWaktu = 0;          // kapan fase sekarang dimulai

// Bunyikan buzzer `kali` kali, masing-masing `lama` milidetik.
void bip(uint8_t kali, uint16_t lama) {
  if (!BUZZER_AKTIF || kali == 0) return;
  bipSisa = kali;
  bipLama = lama;
  bipNyala = true;
  bipWaktu = millis();
  digitalWrite(PIN_BUZZER, HIGH);
}

void buzzerUrus() {
  if (bipSisa == 0) return;
  uint32_t lewat = millis() - bipWaktu;
  if (bipNyala && lewat >= bipLama) {
    digitalWrite(PIN_BUZZER, LOW);
    bipNyala = false;
    bipSisa--;
    bipWaktu = millis();
  } else if (!bipNyala && lewat >= JEDA_BIP) {
    digitalWrite(PIN_BUZZER, HIGH);
    bipNyala = true;
    bipWaktu = millis();
  }
}

// Tunggu bunyi selesai. Dipakai sebelum menghubungi server (yang membuat program berhenti sebentar).
void bipTunggu() {
  while (bipSisa > 0) { buzzerUrus(); delay(1); }
}

// ---------- Tes buzzer & LED (dari menu) ----------

const Warna URUTAN_TES[] = {LED_MERAH, LED_HIJAU, LED_BIRU, LED_PUTIH};
const int JUMLAH_TES = 4;
const uint32_t LAMA_TES = 700;   // tiap warna (ms)

void feedbackTes() {
  Serial.println("Tes buzzer & LED: merah, hijau, biru, putih");
  modeLed = LED_TES;
  ledMulai = millis();
  warnaLed = LED_MATI;
}

// ---------- Dipanggil terus dari loop() ----------

void feedbackUrus() {
  buzzerUrus();
  static uint32_t terakhir = 0;          // LED cukup diperbarui tiap 30 ms
  if (millis() - terakhir < 30) return;
  terakhir = millis();
  uint32_t t = millis() - ledMulai;

  if (modeLed == LED_NAPAS) {
    // naik-turun pelan, satu putaran 3 detik, tidak pernah benar-benar mati
    uint32_t fase = t % 3000;
    uint32_t tingkat = fase < 1500 ? fase : 3000 - fase;   // 0..1500
    uint32_t skala = 150 + tingkat * 850 / 1500;             // 150..1000 (per seribu)
    ledTulis({(uint8_t)(warnaLed.r * skala / 1000),
              (uint8_t)(warnaLed.g * skala / 1000),
              (uint8_t)(warnaLed.b * skala / 1000)});
  } else if (modeLed == LED_KEDIP) {
    ledTulis((t % 1000) < 250 ? warnaLed : LED_MATI);
  } else if (modeLed == LED_TES) {
    int langkah = t / LAMA_TES;
    static int langkahTadi = -1;
    if (langkah == langkahTadi) return;
    langkahTadi = langkah;
    if (langkah < JUMLAH_TES) {
      ledTulis(URUTAN_TES[langkah]);
      bip(1, 80);
    } else {
      langkahTadi = -1;
      ledTulis(LED_MATI);
      modeLed = LED_TETAP;
      warnaLed = LED_MATI;
      bip(2, 60);
      Serial.println("Tes buzzer & LED selesai");
    }
  }
}

void feedbackMulai() {
  pinMode(PIN_BUZZER, OUTPUT);
  digitalWrite(PIN_BUZZER, LOW);

  const int pinLed[] = {PIN_LED_R, PIN_LED_G, PIN_LED_B};
  for (int pin : pinLed) {
    ledcAttach(pin, 5000, 8);                                          // PWM 5 kHz, 0..255
    ledcWrite(pin, 0);
    gpio_set_drive_capability((gpio_num_t)pin, GPIO_DRIVE_CAP_0);      // arus pin paling kecil
  }
}
