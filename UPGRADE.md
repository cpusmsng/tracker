# Upgrade Guide: Multi-Device Support

Tento dokument popisuje postup nasadenia multi-device podpory pre GPS Tracker.

## Prehľad zmien

### Čo sa mení

| Oblasť | Zmena |
|---------|-------|
| **Databáza** | Nové tabuľky `devices`, `device_perimeters`. Nový stĺpec `device_id` v `tracker_data`, `perimeters`, `perimeter_state`, `perimeter_alerts`. Migrácia `refetch_state` na zložený PK `(date, device_id)`. |
| **Backend** | `fetch_data.php` iteruje cez všetky aktívne zariadenia. `smart_refetch_v2.php` per-device analýza. Nové API endpointy pre CRUD zariadení a device perimetrov. |
| **Frontend** | Device bar s prepínaním All/Single/Compare. Správa zariadení v menu. Farebné trasy podľa zariadenia. Device perimeter management. |
| **Konfigurácia** | Nová voliteľná premenná `DEVICE_NAME` v `.env`. |

### Spätná kompatibilita

- Všetky existujúce dáta sa priradia k predvolenému zariadeniu (id=1)
- Existujúce API volania fungujú bez zmien (device_id je voliteľný parameter)
- Ak je len 1 zariadenie, UI sa správa rovnako ako predtým (device bar je skrytý)
- `.env` nevyžaduje žiadne povinné zmeny

---

## Rýchly postup (Quick Upgrade)

```bash
# 1. Stiahnuť zmeny
cd /path/to/tracker
git fetch origin
git checkout main
git pull origin main  # alebo merge PR

# 2. Spustiť nasadzovací skript
./deploy_multi_device.sh
```

Hotovo. Skript automaticky:
- Zálohuje databázu
- Rebuildne Docker image
- Reštartuje kontajner
- Entrypoint spustí migráciu
- Overí výsledok

---

## Detailný postup (krok po kroku)

### Krok 1: Príprava

```bash
# Prejsť do adresára projektu
cd /path/to/tracker

# Overiť aktuálny stav
./deploy.sh status

# Skontrolovať voľné miesto (záloha DB)
df -h .
```

### Krok 2: Záloha databázy

```bash
# Automatická záloha cez deploy.sh
./deploy.sh backup

# ALEBO manuálna záloha
mkdir -p backups
docker cp gps-tracker:/var/www/html/data/tracker_database.sqlite \
    backups/pre_multidevice_$(date +%Y%m%d_%H%M%S).sqlite

# Overiť zálohu
ls -la backups/
sqlite3 backups/pre_multidevice_*.sqlite "SELECT COUNT(*) FROM tracker_data;"
```

### Krok 3: Stiahnuť nový kód

```bash
# Ak ešte PR nie je zmergovaný
git fetch origin
git checkout main
git pull origin main

# Overiť, že nové súbory existujú
ls -la migrate_multi_device.php deploy_multi_device.sh
```

### Krok 4: Voliteľné - aktualizovať .env

```bash
# Pridať meno predvoleného zariadenia (voliteľné)
echo 'DEVICE_NAME=Tracker' >> .env

# Overiť
grep DEVICE_NAME .env
```

Ak `DEVICE_NAME` nie je nastavený, použije sa `"Predvolené zariadenie"`.

### Krok 5: Preview migrácie (dry-run)

```bash
# Zobraziť, čo migrácia urobí, bez zmien
./deploy_multi_device.sh --dry-run

# ALEBO manuálne v kontajneri
docker exec -u www-data gps-tracker \
    php /var/www/html/migrate_multi_device.php --dry-run
```

Výstup ukáže:
- Ktoré tabuľky sa vytvoria
- Ktoré stĺpce sa pridajú
- Koľko riadkov sa aktualizuje

### Krok 6: Nasadenie

**Varianta A: Automatické (odporúčané)**
```bash
./deploy_multi_device.sh
```

**Varianta B: Manuálne**
```bash
# 1. Rebuild Docker image
docker compose -f docker-compose.yml -f docker-compose.prod.yml build

# 2. Restart (entrypoint automaticky spustí migráciu)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# 3. Sledovať logy
docker compose logs -f --tail=50
```

**Varianta C: Len migrácia (bez rebuild)**
```bash
# Ak už máte nový kód v kontajneri
./deploy_multi_device.sh --migrate-only

# ALEBO manuálne
docker exec -u www-data gps-tracker \
    php /var/www/html/migrate_multi_device.php
```

### Krok 7: Overenie

```bash
# Automatické overenie
./deploy.sh status

# Skontrolovať databázu
docker exec gps-tracker sqlite3 /var/www/html/data/tracker_database.sqlite \
    "SELECT * FROM devices;"

docker exec gps-tracker sqlite3 /var/www/html/data/tracker_database.sqlite \
    "SELECT COUNT(*) as total, COUNT(device_id) as with_device FROM tracker_data;"

# Skontrolovať API
PORT=$(grep TRACKER_PORT .env | cut -d= -f2)
curl -s "http://localhost:${PORT:-8080}/api.php?action=health" | python3 -m json.tool

# Skontrolovať logy
docker exec gps-tracker tail -20 /var/log/tracker/fetch.log
```

### Krok 8: Testovanie v prehliadači

1. Otvoriť tracker v prehliadači
2. Prihlásiť sa PIN-om
3. Overiť, že sa zobrazuje mapa s dátami
4. Otvoriť Menu > **Zariadenia** - mala by byť 1 predvolená device
5. Otvoriť Menu > **Perimeter zariadení** - zatiaľ prázdne
6. Ak máte len 1 zariadenie, device bar je skrytý (OK)

---

## Pridanie nového zariadenia

### Cez UI

1. Menu > **Zariadenia**
2. Kliknúť **Pridať zariadenie**
3. Vyplniť:
   - **Názov**: napr. "Fido Tracker"
   - **Device EUI**: skopírovať zo SenseCAP konzoly
   - **Farba**: vybrať farbu pre mapu
   - **Aktívne**: zaškrtnúť
4. **Uložiť**

### Cez API

```bash
curl -X POST "http://localhost:8080/api.php?action=save_device" \
    -H "Content-Type: application/json" \
    -d '{
        "name": "Fido Tracker",
        "device_eui": "2CF7F1C044300ABC",
        "color": "#ff6b6b",
        "is_active": true
    }'
```

### Cez SQLite

```bash
docker exec gps-tracker sqlite3 /var/www/html/data/tracker_database.sqlite \
    "INSERT INTO devices (name, device_eui, color, is_active)
     VALUES ('Fido Tracker', '2CF7F1C044300ABC', '#ff6b6b', 1);"
```

Po pridaní zariadenia sa dáta začnú zbierať pri ďalšom cron cykle (do 5 min).

---

## Rollback

Ak niečo nefunguje, návrat je jednoduchý:

### Automatický rollback

```bash
./deploy_multi_device.sh --rollback
```

### Manuálny rollback

```bash
# 1. Zastaviť kontajner
docker compose down

# 2. Obnoviť zálohu
BACKUP=$(ls -t backups/pre_multidevice_*.sqlite | head -1)
docker run --rm \
    -v gps-tracker-data:/data \
    -v "$(pwd)/backups:/backup:ro" \
    alpine cp "/backup/$(basename $BACKUP)" /data/tracker_database.sqlite

# 3. Vrátiť kód na predchádzajúcu verziu
git checkout main~1

# 4. Rebuild a restart
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

### Len databáza (bez zmeny kódu)

Nový kód je spätne kompatibilný, takže aj so starou DB bude fungovať.
Ale ak chcete čistú starej DB:

```bash
# Nájsť poslednú zálohu
ls -la backups/pre_multidevice_*.sqlite

# Obnoviť
./deploy.sh restore backups/pre_multidevice_XXXXXXXX_XXXXXX.sqlite
```

---

## Riešenie problémov

### Migrácia zlyhala

```bash
# Pozrieť logy
docker compose logs -f --tail=100

# Spustiť migráciu manuálne s debug výstupom
docker exec -u www-data gps-tracker \
    php /var/www/html/migrate_multi_device.php --force

# Ak je DB poškodená
./deploy_multi_device.sh --rollback
```

### Žiadne dáta pre nové zariadenie

```bash
# Overiť, že zariadenie je aktívne
docker exec gps-tracker sqlite3 /var/www/html/data/tracker_database.sqlite \
    "SELECT id, name, device_eui, is_active FROM devices;"

# Manuálne spustiť fetch
docker exec -u www-data gps-tracker php /var/www/html/fetch_data.php

# Pozrieť fetch logy
docker exec gps-tracker tail -50 /var/log/tracker/fetch.log
```

### Device bar sa nezobrazuje

Device bar je skrytý ak je len 1 zariadenie. Toto je zámerné.
Pridajte ďalšie zariadenie cez Menu > Zariadenia.

### Migrácia beží znova pri každom reštarte

`entrypoint.sh` volá `migrate_multi_device.php` pri každom štarte,
ale skript detekuje existujúcu `devices` tabuľku a preskočí migráciu.
Toto je bezpečné a nezaberá čas.

---

## Technické detaily migrácie

### Čo migrate_multi_device.php robí

1. **Záloha** databázy (`.backup_YYYY-MM-DD_HHMMSS`)
2. **CREATE TABLE** `devices` - register zariadení
3. **CREATE TABLE** `device_perimeters` - kruhové geofence
4. **INSERT** default device z `SENSECAP_DEVICE_EUI` / `DEVICE_NAME`
5. **ALTER TABLE** `tracker_data` ADD `device_id` + UPDATE existujúcich riadkov
6. **ALTER TABLE** `perimeters` ADD `device_id`
7. **ALTER TABLE** `perimeter_state` ADD `device_id`
8. **ALTER TABLE** `perimeter_alerts` ADD `device_id`
9. **Migrate** `refetch_state` na zložený PK `(date, device_id)`:
   - Rename old table → create new → copy data → drop old
10. **CREATE INDEX** na nové stĺpce

### Idempotentnosť

Skript je **idempotentný** - bezpečné ho spustiť viackrát:
- `CREATE TABLE IF NOT EXISTS` - preskočí ak existuje
- Kontroluje existenciu stĺpcov pred `ALTER TABLE`
- `INSERT OR IGNORE` pre default device
- `CREATE INDEX IF NOT EXISTS`

### Čas migrácie

| Veľkosť DB | Odhadovaný čas |
|-------------|-----------------|
| < 10 MB     | < 1 sekunda     |
| 10-100 MB   | 1-5 sekúnd      |
| 100-500 MB  | 5-30 sekúnd     |
| > 500 MB    | 30+ sekúnd      |

Počas migrácie je tracker nedostupný (downtime = čas migrácie + restart).

---

## Súbory pridané/zmenené

### Nové súbory

| Súbor | Účel |
|-------|------|
| `migrate_multi_device.php` | Migračný skript databázy |
| `deploy_multi_device.sh` | Nasadzovací skript pre upgrade |
| `UPGRADE.md` | Tento dokument |

### Zmenené súbory

| Súbor | Zmena |
|-------|-------|
| `api.php` | Device CRUD endpointy, device_id filter v existujúcich endpointoch |
| `fetch_data.php` | Multi-device loop, device_id v INSERT |
| `smart_refetch_v2.php` | Per-device analýza a refetch |
| `app.js` | Device bar, management UI, comparison mode, colored polylines |
| `index.html` | Device bar div, management overlays, hamburger menu items |
| `style.css` | CSS pre device bar, cards, comparison panel, responsive |
| `docker/entrypoint.sh` | Nové tabuľky v init schéme, auto-migrácia |
| `.env.example` | DEVICE_NAME premenná |
| `ARCHITECTURE.md` | Aktualizovaná ER schéma a popisy tabuliek |
