<?php
declare(strict_types=1);

/**
 * CONFIG – načítanie .env a definícia konštánt
 *
 * Umiestnenie: /volume1/web/tracker/config.php
 * Po načítaní tohto súboru MUSÍ fungovať:
 *   getenv('GOOGLE_API_KEY')
 */

// ---------- Základ ----------
date_default_timezone_set('Europe/Bratislava');

// ---------- Loader .env (server aj CLI) ----------
if (!function_exists('tracker_load_env')) {
    function tracker_load_env(string $path): void {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // KEY=VALUE (ponechajme '=' v hodnote ak je len prvé)
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $k = trim($k);
            // odstráň okrajové úvodzovky a whitespace
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if ($k === '') {
                continue;
            }
            // nastav do process environment (aby fungoval getenv())
            putenv("$k=$v");
            // aj do $_ENV pre prípadný iný kód
            $_ENV[$k] = $v;
        }
    }
}

// načítaj .env z koreňového priečinka aplikácie
tracker_load_env(__DIR__ . '/.env');

// ---------- Konštanty z .env (s rozumnými defaultmi) ----------

// SenseCAP prístupy a nastavenia
define('SENSECAP_ACCESS_ID',        getenv('SENSECAP_ACCESS_ID')        ?: '');
define('SENSECAP_ACCESS_KEY',       getenv('SENSECAP_ACCESS_KEY')       ?: '');
define('SENSECAP_DEVICE_EUI',       getenv('SENSECAP_DEVICE_EUI')       ?: '');
define('SENSECAP_CHANNEL_INDEX', (int)(getenv('SENSECAP_CHANNEL_INDEX') ?: 1));
define('SENSECAP_LIMIT',        (int)(getenv('SENSECAP_LIMIT')         ?: 50));

// Measurement IDs (používame tieto ID v SenseCAP)
define('MEAS_WIFI_MAC',         (int)(getenv('MEAS_WIFI_MAC')          ?: 5001)); // Wi-Fi MACs
define('MEAS_BT_IBEACON_MAC',   (int)(getenv('MEAS_BT_IBEACON_MAC')    ?: 5002)); // BT iBeacon MACs
define('MEAS_GNSS_LON',         (int)(getenv('MEAS_GNSS_LON')          ?: 4197)); // GNSS Longitude
define('MEAS_GNSS_LAT',         (int)(getenv('MEAS_GNSS_LAT')          ?: 4198)); // GNSS Latitude

// Hysteréza + uniq bucket
define('HYSTERESIS_METERS',        (int)(getenv('HYSTERESIS_METERS')        ?: 50));
define('HYSTERESIS_MINUTES',       (int)(getenv('HYSTERESIS_MINUTES')       ?: 30));
define('UNIQUE_PRECISION',         (int)(getenv('UNIQUE_PRECISION')         ?: 6));
define('UNIQUE_BUCKET_MINUTES',    (int)(getenv('UNIQUE_BUCKET_MINUTES')    ?: 30));

// Wi-Fi cache a Google nastavenia
define('MAC_CACHE_MAX_AGE_DAYS',   (int)(getenv('MAC_CACHE_MAX_AGE_DAYS')   ?: 3600));
define('GOOGLE_FORCE',                  getenv('GOOGLE_FORCE')               ?: '0');  // '1' = vynútiť Google
define('GOOGLE_API_KEY',                getenv('GOOGLE_API_KEY')             ?: '');

// (voliteľné) ďalšie technické nastavenia
define('TRACKER_DB_PATH', __DIR__ . '/tracker_database.sqlite');
