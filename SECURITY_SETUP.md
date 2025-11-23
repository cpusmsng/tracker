# Bezpečnostné nastavenie Tracker aplikácie

## 🔒 PIN ochrana

Aplikácia je chránená 4-ciferným PIN kódom. Pri prvom spustení je predvolený PIN: **1234**

### Zmena PIN kódu

1. Vygenerujte SHA-256 hash vášho nového PIN kódu:

```bash
echo -n "VÁŠŠPIN" | sha256sum
```

Alebo použite online nástroj: https://emn178.github.io/online-tools/sha256.html

2. Pridajte hash do `.env` súboru:

```env
ACCESS_PIN_HASH=váš_vygenerovaný_hash
```

**Príklad pre PIN 1234:**
```env
ACCESS_PIN_HASH=03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4
```

### Bezpečnostné odporúčania

- **Nikdy nezdieľajte PIN kód**
- **Zmeňte predvolený PIN čo najskôr**
- **Nepoužívajte ľahko uhádnuteľné PIN kódy** (napr. 0000, 1111, 1234)
- **Autentifikácia je platná 24 hodín** (uložená v sessionStorage prehliadača)

## 🛡️ Ochrana citlivých súborov

V `.htaccess` sú nastavené tieto ochranné pravidlá:

### Blokované súbory (nedostupné cez web):
- `.env` - Všetky environment premenné
- `config.php` - Konfiguračný súbor
- `fetch_data.php` - CLI skript pre sťahovanie dát
- `smart_refetch_v2.php` - CLI skript pre smart refetch
- `*.sqlite` - Databázové súbory
- `*.log`, `*.txt` - Log súbory

### HTTP Security Headers:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`

## 🔐 API kľúče v .env

Citlivé údaje v `.env` súbore:

```env
# SenseCAP API
SENSECAP_ACCESS_ID=váš_access_id
SENSECAP_ACCESS_KEY=váš_access_key
SENSECAP_DEVICE_EUI=váš_device_eui

# Google Geolocation API
GOOGLE_API_KEY=váš_google_api_key

# PIN ochrana (SHA-256 hash)
ACCESS_PIN_HASH=váš_pin_hash
```

### Kontrola .env ochrany

Otvorte v prehliadači: `https://vaša-doména.sk/.env`

**Ak sa súbor stiahne alebo zobrazí** → .htaccess nefunguje správne!

**Ak dostanete 403 Forbidden** → Ochrana funguje správne! ✅

## 📱 Mobilná responzívnosť PIN obrazovky

PIN obrazovka je plne responzívna:
- **Desktop**: Veľká číselná klávesnica
- **Tablet**: Stredná klávesnica
- **Mobil**: Kompaktná klávesnica prispôsobená prstom

Podporuje:
- ✅ Dotykové ovládanie
- ✅ Fyzická klávesnica (0-9, Backspace, Enter)
- ✅ Vizuálnu spätnú väzbu
- ✅ Chybové hlášky

## ⚙️ Technické detaily

### Session Management
- Používa `sessionStorage` pre uchovanie autentifikácie
- Token je platný 24 hodín
- Po vypršaní je potrebné zadať PIN znovu
- Session sa zmaže pri zatvorení prehliadača

### Brute-Force Protection
- 500ms delay po každom nesprávnom pokuse
- Vizuálna spätná väzba pri chybe
- Automatické resetovanie po nesprávnom PIN

### Backend Validation
- PIN je hashovaný pomocou SHA-256
- Nikdy sa neposiela plaintext PIN
- Server kontroluje hash vs. uložený hash
- Rate limiting cez delay

## 🚨 Riešenie problémov

### PIN nefunguje
1. Skontrolujte konzolu prehliadača (F12)
2. Overte že `.env` obsahuje správny hash
3. Vyčistite sessionStorage: `sessionStorage.clear()`

### .env je prístupný cez web
1. Overte že `.htaccess` existuje v koreňovom priečinku
2. Skontrolujte že Apache má povolené `.htaccess` (AllowOverride All)
3. Pre nginx použite ekvivalentnú konfiguráciu

### Zabudnutý PIN
1. Vygenerujte nový hash pre PIN 1234:
   ```
   03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4
   ```
2. Upravte `.env`:
   ```
   ACCESS_PIN_HASH=03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4
   ```
3. Prihláste sa s PIN: 1234
4. Zmeňte PIN na nový

---

**Dôležité**: Toto je základná ochrana. Pre production použitie zvážte:
- HTTPS (SSL certifikát)
- Rate limiting na serveri
- IP whitelisting
- 2FA autentifikáciu
- Audit logy
