#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// =============================================
// 1. KONFIGURASI PIN SENSOR
// =============================================
#define PH_PIN        35  // Sensor pH di GPIO 35
#define TURBIDITY_PIN 34  // Sensor Turbidity di GPIO 34

// =============================================
// 2. KONFIGURASI WIFI & SERVER
// =============================================
const char* ssid     = "daffaganteng";        // GANTI dengan WiFi Anda
const char* password = "kutilanak";           // GANTI dengan password WiFi Anda
const String serverName = "http://fauzan.underwaterdrone.my.id/api.php";

// =============================================
// 3. INISIALISASI OBJEK & VARIABEL
// =============================================
LiquidCrystal_I2C lcd(0x27, 16, 2);

float phValue      = 7.0;
float turbidityNTU = 0.0;
int   batteryPct   = 100;  // Baterai dummy (ganti dengan sensor baterai bila ada)

unsigned long lastSendTime  = 0;
unsigned long lastReadTime  = 0;
const unsigned long SEND_INTERVAL = 10000; // Kirim data tiap 10 detik
const unsigned long READ_INTERVAL = 1000;  // Baca sensor tiap 1 detik

// =============================================
// 4. KALIBRASI SENSOR
// =============================================
// --- pH Calibration ---
// Lakukan kalibrasi 2 titik menggunakan larutan buffer pH 4.0 dan pH 7.0
// Ukur tegangan saat sensor dicelup ke masing-masing buffer, lalu isi nilai di bawah:
float phVoltageAt4  = 3.00; // Tegangan (V) saat sensor di buffer pH 4.0 -- UKUR & GANTI
float phVoltageAt7  = 2.50; // Tegangan (V) saat sensor di buffer pH 7.0 -- UKUR & GANTI
// Slope dan intercept dihitung otomatis dari 2 titik di atas

// --- Turbidity Calibration ---
float clearWaterVoltage = 1.59; // Tegangan air jernih (hasil pengukuran Anda)
const float TURBIDITY_MAX_NTU   = 3000.0; // Batas atas NTU sesuai sensor
const float TURBIDITY_FACTOR    = 500.0;  // Faktor konversi (sesuaikan)

// =============================================
// 5. PROTOTYPES
// =============================================
float readPH();
float readTurbidity();
void  updateLCD(float ph, float ntu, int bat, bool wifiOk);
bool  sendToServer(float ph, float ntu, int bat);
bool  reconnectWiFi();

// =============================================
// SETUP
// =============================================
void setup() {
  Serial.begin(115200);

  // Setup ADC
  analogReadResolution(12); // 0-4095 (12-bit)
  analogSetAttenuation(ADC_11db); // Rentang 0-3.3V untuk semua pin

  // Setup LCD
  lcd.init();
  lcd.backlight();
  lcdPrint("CHRONO Drone", "Connecting WiFi");

  // Koneksi WiFi
  Serial.println("\n=== CHRONO Drone System ===");
  Serial.printf("Menghubungkan ke WiFi: %s\n", ssid);

  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 30) {
    delay(500);
    Serial.print(".");
    attempts++;
    lcd.setCursor(0, 1);
    lcd.printf("Attempt %d       ", attempts);
  }

  if (WiFi.status() == WL_CONNECTED) {
    String ip = WiFi.localIP().toString();
    Serial.printf("\nWiFi Connected! IP: %s\n", ip.c_str());
    lcdPrint("WiFi Connected!", ip.substring(0, 16));
    delay(2000);
  } else {
    Serial.println("\nWiFi Gagal! Cek SSID/Password.");
    lcdPrint("WiFi Gagal!", "Cek Password");
    delay(3000);
  }

  lcd.clear();
}

// =============================================
// LOOP
// =============================================
void loop() {
  unsigned long now = millis();

  // --- A. BACA SENSOR (tiap READ_INTERVAL) ---
  if (now - lastReadTime >= READ_INTERVAL) {
    lastReadTime = now;

    phValue      = readPH();
    turbidityNTU = readTurbidity();

    // Log ke Serial
    Serial.printf("\n=== SENSOR DATA ===\n");
    Serial.printf("pH       : %.2f\n", phValue);
    Serial.printf("Turbidity: %.0f NTU\n", turbidityNTU);
    Serial.printf("Battery  : %d%%\n", batteryPct);
    Serial.printf("WiFi     : %s\n", WiFi.status() == WL_CONNECTED ? "OK" : "PUTUS");

    // Update LCD
    updateLCD(phValue, turbidityNTU, batteryPct, WiFi.status() == WL_CONNECTED);
  }

  // --- B. KIRIM KE SERVER (tiap SEND_INTERVAL) ---
  if (now - lastSendTime >= SEND_INTERVAL) {
    lastSendTime = now;

    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("WiFi putus, mencoba reconnect...");
      reconnectWiFi();
    }

    if (WiFi.status() == WL_CONNECTED) {
      bool ok = sendToServer(phValue, turbidityNTU, batteryPct);
      lcd.setCursor(8, 1);
      lcd.print(ok ? " OK  " : " ERR ");
    }
  }
}

// =============================================
// FUNGSI: Baca pH (sampling + kalibrasi 2 titik)
// =============================================
float readPH() {
  // Hitung slope & intercept dari 2 titik kalibrasi
  // slope = (pH2 - pH1) / (V2 - V1)
  // intercept: pH = slope * V + b  =>  b = pH1 - slope * V1
  float slope     = (7.0 - 4.0) / (phVoltageAt7 - phVoltageAt4);
  float intercept = 4.0 - slope * phVoltageAt4;

  // Sampling ADC (20 sampel, ambil rata-rata)
  long adcSum = 0;
  for (int i = 0; i < 20; i++) {
    adcSum += analogRead(PH_PIN);
    delay(5);
  }
  float voltage = (adcSum / 20.0) * 3.3 / 4095.0;

  Serial.printf("  [pH] ADC avg: %.0f | Volt: %.3f V\n", adcSum / 20.0, voltage);

  // Konversi ke pH
  float ph = slope * voltage + intercept;

  // Batasi ke 0–14
  ph = constrain(ph, 0.0, 14.0);
  return ph;
}

// =============================================
// FUNGSI: Baca Turbidity (sampling + konversi NTU)
// =============================================
float readTurbidity() {
  // Sampling ADC (20 sampel, ambil rata-rata)
  long adcSum = 0;
  for (int i = 0; i < 20; i++) {
    adcSum += analogRead(TURBIDITY_PIN);
    delay(5);
  }
  float voltage = (adcSum / 20.0) * 3.3 / 4095.0;

  Serial.printf("  [Turb] ADC avg: %.0f | Volt: %.3f V\n", adcSum / 20.0, voltage);

  float ntu = 0.0;
  if (voltage < clearWaterVoltage) {
    // Semakin rendah tegangan = semakin keruh
    float diff = clearWaterVoltage - voltage;
    ntu = diff * TURBIDITY_FACTOR;
    ntu = constrain(ntu, 0.0, TURBIDITY_MAX_NTU);
  }
  return ntu;
}

// =============================================
// FUNGSI: Tampilkan ke LCD (16x2)
// =============================================
void updateLCD(float ph, float ntu, int bat, bool wifiOk) {
  // Baris 0: "pH:7.1  B:100%"
  lcd.setCursor(0, 0);
  lcd.printf("pH:%-4.1f  B:%-3d%%", ph, bat);

  // Baris 1: "Trb:1234N W/X"
  // "Trb:" = 4 char, nilai NTU max 4 digit, "N " = 2 char, " W" atau " X" = 2 char = 12 total, aman
  lcd.setCursor(0, 1);
  lcd.printf("Trb:%-4.0fN  %s  ", ntu, wifiOk ? "W" : "X");
}

// =============================================
// FUNGSI: Versi helper LCD 2 baris
// =============================================
void lcdPrint(const char* line1, const char* line2) {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(line1);
  lcd.setCursor(0, 1);
  lcd.print(line2);
}

void lcdPrint(const char* line1, const String& line2) {
  lcdPrint(line1, line2.c_str());
}

// =============================================
// FUNGSI: Kirim data ke server via HTTP GET
// =============================================
bool sendToServer(float ph, float ntu, int bat) {
  HTTPClient http;

  String url = serverName
             + "?kualitas_air=" + String(ph, 2)
             + "&tahan="        + String(ntu, 2)
             + "&daya_listrik=" + String(bat);

  Serial.printf("Kirim ke server: %s\n", url.c_str());

  http.begin(url);
  http.setTimeout(8000); // Timeout 8 detik

  int httpCode = http.GET();
  bool success = false;

  if (httpCode > 0) {
    String response = http.getString();
    Serial.printf("HTTP %d | Response: %s\n", httpCode, response.substring(0, 60).c_str());
    success = (httpCode == 200);
  } else {
    Serial.printf("Error HTTP: %d (%s)\n", httpCode, http.errorToString(httpCode).c_str());
  }

  http.end();
  return success;
}

// =============================================
// FUNGSI: Reconnect WiFi
// =============================================
bool reconnectWiFi() {
  WiFi.disconnect();
  WiFi.begin(ssid, password);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("\nReconnect berhasil! IP: %s\n", WiFi.localIP().toString().c_str());
    return true;
  } else {
    Serial.println("\nReconnect gagal.");
    return false;
  }
}
