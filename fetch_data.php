<?php
declare(strict_types=1);
/**
 * fetch_data.php – CRON/CLI
 * - GNSS (4197/4198) + Wi-Fi (5001) + BT iBeacon (5002) + Battery (5003)
 * - Podporuje refetch režim pre konkrétny deň: --refetch-date=YYYY-MM-DD
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); echo "Not found\n"; exit; }
date_default_timezone_set('Europe/Bratislava');

// Load .env FIRST before reading any environment variables
function load_env_early(string $path): void {
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line,'=')===false) continue;
        [$k,$v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, " \t\n\r\0\x0B\"'"); // Strip quotes
        if ($k !== '') putenv("$k=$v");
    }
}
load_env_early(__DIR__.'/.env');

// Application URL for email links (falls back to Google Maps if not set)
define('TRACKER_APP_URL', getenv('TRACKER_APP_URL') ?: '');

// Battery alert configuration
define('BATTERY_ALERT_ENABLED', in_array(strtolower(getenv('BATTERY_ALERT_ENABLED') ?: 'false'), ['true', '1', 'yes']));
define('BATTERY_ALERT_EMAIL', getenv('BATTERY_ALERT_EMAIL') ?: '');

// Log file path - from env, or Docker default, or local fallback
$LOG_FILE = getenv('LOG_FILE') ?: (is_dir('/var/log/tracker') ? '/var/log/tracker/fetch.log' : __DIR__ . '/fetch.log');
const FETCH_LOCK_FILE = __DIR__ . '/fetch_data.lock';

// LOG LEVEL: "error", "info", "debug" (from env or default to "info")
$LOG_LEVEL = strtolower(getenv('LOG_LEVEL') ?: 'info');
$LOG_LEVELS = ['error' => 0, 'info' => 1, 'debug' => 2];
$CURRENT_LOG_LEVEL = $LOG_LEVELS[$LOG_LEVEL] ?? 1;

// DEBUG MODE + REFETCH MODE
$DEBUG_MODE = false;
$REFETCH_MODE = false;
$REFETCH_DATE = null;
$REFETCH_BREACHES = []; // Collect breaches for summary email in refetch mode
$LOG_DEVICE_PREFIX = ''; // Set per-device during processing loop

function logl(string $msg, string $level = 'debug'): void {
    global $LOG_FILE, $CURRENT_LOG_LEVEL, $LOG_LEVELS, $DEBUG_MODE, $LOG_DEVICE_PREFIX;
    $msgLevel = $LOG_LEVELS[$level] ?? 2;

    // Always log if DEBUG_MODE is on, otherwise check level
    if (!$DEBUG_MODE && $msgLevel > $CURRENT_LOG_LEVEL) return;

    $ts = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    $levelTag = strtoupper($level);
    $prefix = $LOG_DEVICE_PREFIX ? "[$LOG_DEVICE_PREFIX] " : '';
    file_put_contents($LOG_FILE, "[$ts] [$levelTag] {$prefix}$msg\n", FILE_APPEND);
}

function error_log_custom(string $msg): void {
    logl($msg, 'error');
}

function info_log(string $msg): void {
    logl($msg, 'info');
}

function debug_log(string $msg): void {
    logl($msg, 'debug');
}

function db(): PDO {
    $path = getenv('SQLITE_PATH') ?: (__DIR__ . '/tracker_database.sqlite');
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    return $pdo;
}

function norm_mac(string $mac): string {
    $m = preg_replace('/[^0-9A-F]/', '', strtoupper($mac));
    return implode(':', str_split($m, 2));
}

function http_get_json_basic(string $url, string $aid, string $akey, int &$httpCode = 0, string &$err = '') {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $aid . ':' . $akey,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json']
    ]);
    $body = curl_exec($ch);
    if ($body === false) { $err = curl_error($ch); curl_close($ch); return [null, null]; }
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);
    return [$json, $body];
}

function extract_iso($x): ?string {
    if (is_string($x)) {
        $s = trim($x);
        if ($s !== '') return $s;
        return null;
    }
    if (is_array($x)) {
        foreach (['timestamp','ts','time'] as $k) {
            if (isset($x[$k]) && is_string($x[$k]) && trim($x[$k])!=='') return trim($x[$k]);
        }
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($x));
        foreach ($it as $v) {
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $v)) return trim($v);
        }
    }
    return null;
}

function sensecap_fetch_basic(string $devEui, int $measurementId, string $aid, string $akey, int $limit, int $timeStartMs, int $timeEndMs): array {
    $url = sprintf(
        'https://sensecap.seeed.cc/openapi/list_telemetry_data?device_eui=%s&measurement_id=%d&channel_index=1&limit=%d&time_start=%d&time_end=%d',
        rawurlencode($devEui), 
        $measurementId, 
        $limit,
        $timeStartMs,
        $timeEndMs
    );
    
    $http = 0; $err = '';
    [$json, $raw] = http_get_json_basic($url, $aid, $akey, $http, $err);
    
    if ($err) { 
        debug_log("SenseCAP ERR m=$measurementId http=$http err=$err"); 
        return []; 
    }
    if (!is_array($json)) { 
        debug_log("SenseCAP BADJSON m=$measurementId http=$http body=".substr((string)$raw,0,200)); 
        return []; 
    }
    if (($json['code'] ?? '') !== '0') { 
        debug_log("SenseCAP APICODE m=$measurementId code=".($json['code']??'').' body='.substr(json_encode($json),0,200)); 
        return []; 
    }

    $list = $json['data']['list'] ?? [];
    if (!is_array($list) || count($list) < 2) return [];
    
    $values = $list[1] ?? [];
    
    if (is_array($values) && count($values) === 1 && is_array($values[0])) {
        $values = $values[0];
    }

    $out = [];
    foreach ($values as $row) {
        if (!is_array($row) || count($row) < 2) continue;
        $payload = $row[0];
        $iso = extract_iso($row[1]) ?: extract_iso($row);
        if (!$iso) {
            debug_log("WARN: missing ISO m=$measurementId row=".substr(json_encode($row),0,160));
            continue;
        }
        $out[] = ['payload'=>$payload, 'iso'=>$iso];
    }
    return $out;
}

function fetch_device_running_status(string $devEui, string $aid, string $akey): array {
    $url = 'https://sensecap.seeed.cc/openapi/view_device_running_status';
    $payload = json_encode(['device_euis' => [$devEui]], JSON_UNESCAPED_SLASHES);
    
    debug_log("DEVICE STATUS API: Fetching device running status for $devEui");
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $aid . ':' . $akey,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json'
        ]
    ]);
    
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($body === false || $httpCode !== 200) {
        debug_log("DEVICE STATUS API: HTTP error $httpCode" . ($curlError ? " - $curlError" : ""));
        return ['battery_status' => null, 'latest_message_time' => null, 'online_status' => null];
    }
    
    debug_log("DEVICE STATUS API: Raw response - " . substr($body, 0, 500));
    
    $json = json_decode($body, true);
    
    if (!is_array($json)) {
        debug_log("DEVICE STATUS API: Invalid JSON response");
        return ['battery_status' => null, 'latest_message_time' => null, 'online_status' => null];
    }
    
    if (($json['code'] ?? '') !== '0') {
        debug_log("DEVICE STATUS API: API error code=" . ($json['code'] ?? 'unknown') . " msg=" . ($json['msg'] ?? 'none'));
        return ['battery_status' => null, 'latest_message_time' => null, 'online_status' => null];
    }
    
    $devices = $json['data'] ?? [];
    if (empty($devices) || !is_array($devices)) {
        debug_log("DEVICE STATUS API: No device data in response");
        return ['battery_status' => null, 'latest_message_time' => null, 'online_status' => null];
    }
    
    $device = $devices[0] ?? null;
    if (!$device) {
        debug_log("DEVICE STATUS API: No device in array");
        return ['battery_status' => null, 'latest_message_time' => null, 'online_status' => null];
    }
    
    $batteryStatus = isset($device['battery_status']) ? (int)$device['battery_status'] : null;
    // OPRAVA: Správny názov poľa z API je latest_message_time
    $latestMessageTime = $device['latest_message_time'] ?? null;
    $onlineStatus = isset($device['online_status']) ? (int)$device['online_status'] : null;
    
    debug_log("DEVICE STATUS API: Success - battery_status=" . ($batteryStatus !== null ? $batteryStatus : 'null') . 
              " (" . ($batteryStatus === 1 ? 'GOOD' : ($batteryStatus === 0 ? 'LOW' : 'N/A')) . ")" .
              " latest_message_time=" . ($latestMessageTime ?? 'null') . 
              " online_status=" . ($onlineStatus !== null ? $onlineStatus : 'null'));
    
    return [
        'battery_status' => $batteryStatus,
        'latest_message_time' => $latestMessageTime,
        'online_status' => $onlineStatus
    ];
}

function last_db_ts(PDO $pdo, ?int $deviceId = null): ?DateTimeImmutable {
    if ($deviceId !== null) {
        $stmt = $pdo->prepare("SELECT MAX(timestamp) AS mx FROM tracker_data WHERE device_id = :did");
        $stmt->execute([':did' => $deviceId]);
        $r = $stmt->fetch();
    } else {
        $r = $pdo->query("SELECT MAX(timestamp) AS mx FROM tracker_data")->fetch();
    }
    if (!$r || !$r['mx']) return null;
    $dt = new DateTimeImmutable((string)$r['mx'], new DateTimeZone('UTC'));
    debug_log("LAST_DB_TS: ".$dt->format('Y-m-d\TH:i:s.u\Z')." UTC" . ($deviceId ? " (device=$deviceId)" : ""));
    return $dt;
}

function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371000.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlmb = deg2rad($lon2 - $lon1);
    $a = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dlmb/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

function get_last_position(PDO $pdo, ?string $beforeDate = null, ?int $deviceId = null): ?array {
    if ($beforeDate && $deviceId !== null) {
        $stmt = $pdo->prepare("SELECT latitude, longitude, source FROM tracker_data WHERE timestamp < :d AND device_id = :did ORDER BY timestamp DESC LIMIT 1");
        $stmt->execute([':d' => $beforeDate, ':did' => $deviceId]);
        $r = $stmt->fetch();
    } else if ($beforeDate) {
        $stmt = $pdo->prepare("SELECT latitude, longitude, source FROM tracker_data WHERE timestamp < :d ORDER BY timestamp DESC LIMIT 1");
        $stmt->execute([':d' => $beforeDate]);
        $r = $stmt->fetch();
    } else if ($deviceId !== null) {
        $stmt = $pdo->prepare("SELECT latitude, longitude, source FROM tracker_data WHERE device_id = :did ORDER BY timestamp DESC LIMIT 1");
        $stmt->execute([':did' => $deviceId]);
        $r = $stmt->fetch();
    } else {
        $r = $pdo->query("SELECT latitude, longitude, source FROM tracker_data ORDER BY timestamp DESC LIMIT 1")->fetch();
    }
    if (!$r) return null;
    return ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 'source' => (string)$r['source']];
}

function insert_position_with_hysteresis(
    PDO $pdo,
    string $iso,
    float $lat,
    float $lng,
    string $source,
    int $hysteresisMeters,
    ?array &$lastInsertedPos,
    ?string $rawMacs = null,
    ?string $primaryMac = null,
    ?int $deviceId = null
): array {
    
    $lastPos = $lastInsertedPos;
    
    $dist = null;
    
    $isIBeacon = ($source === 'ibeacon');
    $prevWasIBeacon = ($lastPos && isset($lastPos['source']) && $lastPos['source'] === 'ibeacon');
    
    $skipHysteresis = $isIBeacon && !$prevWasIBeacon;
    
    if (!$skipHysteresis && $lastPos) {
        $dist = haversine($lastPos['lat'], $lastPos['lng'], $lat, $lng);
        
        if ($isIBeacon && $prevWasIBeacon) {
            debug_log("    HYSTERESIS CHECK: iBeacon→iBeacon distance=".round($dist, 1)."m (threshold={$hysteresisMeters}m)");
        } else {
            debug_log("    HYSTERESIS CHECK: distance=".round($dist, 1)."m from last (threshold={$hysteresisMeters}m)");
        }
        
        if ($dist < $hysteresisMeters) {
            return ['inserted' => false, 'reason' => 'hysteresis', 'distance' => round($dist, 1)];
        }
    } else if ($skipHysteresis) {
        $prevSource = $lastPos['source'] ?? 'none';
        debug_log("    HYSTERESIS CHECK: SKIPPED (iBeacon priority over $prevSource)");
        if ($lastPos) {
            $dist = haversine($lastPos['lat'], $lastPos['lng'], $lat, $lng);
            debug_log("    Distance from last position: ".round($dist, 1)."m (ignored due to iBeacon priority)");
        }
    } else {
        debug_log("    HYSTERESIS CHECK: no previous position, will insert");
    }
    
    $dt = new DateTimeImmutable($iso);
    $dt = $dt->setTimezone(new DateTimeZone('UTC'));
    $ts = $dt->format('Y-m-d H:i:s');
    
    try {
        $stmt = $pdo->prepare("INSERT INTO tracker_data (timestamp, latitude, longitude, source, raw_wifi_macs, primary_mac, device_id) VALUES (:t,:la,:lo,:s,:macs,:pmac,:did)");
        $stmt->execute([':t'=>$ts, ':la'=>$lat, ':lo'=>$lng, ':s'=>$source, ':macs'=>$rawMacs, ':pmac'=>$primaryMac, ':did'=>$deviceId]);

        $lastInsertedPos = ['lat' => $lat, 'lng' => $lng, 'source' => $source];

        debug_log("    INSERT SUCCESS: $source @ ($lat, $lng)" . ($primaryMac ? " [Primary: $primaryMac]" : "") . ($rawMacs ? " [All MACs: $rawMacs]" : ""));
        return ['inserted' => true, 'reason' => 'ok', 'distance' => $dist !== null ? round($dist, 1) : null];
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        debug_log("    DB EXCEPTION: $errorMsg");
        return ['inserted' => false, 'reason' => 'db_error', 'error' => $errorMsg];
    }
}

function upsert_mac_location(PDO $pdo, string $mac, float $lat, float $lng, string $iso): bool {
    $mac = norm_mac($mac);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO mac_locations (mac_address, latitude, longitude, last_queried)
            VALUES (:m,:la,:lo,:ts)
            ON CONFLICT(mac_address) DO UPDATE SET
                latitude=excluded.latitude, longitude=excluded.longitude, last_queried=excluded.last_queried
        ");
        $stmt->execute([':m'=>$mac, ':la'=>$lat, ':lo'=>$lng, ':ts'=>$iso]);
        return true;
    } catch (Throwable $e) {
        debug_log("    UPSERT_MAC_LOCATION ERROR: Failed to cache $mac - ".$e->getMessage());
        return false;
    }
}

function cache_failed_mac(PDO $pdo, string $mac, string $iso): bool {
    $mac = norm_mac($mac);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO mac_locations (mac_address, latitude, longitude, last_queried)
            VALUES (:m, NULL, NULL, :ts)
            ON CONFLICT(mac_address) DO UPDATE SET last_queried=excluded.last_queried
        ");
        $stmt->execute([':m'=>$mac, ':ts'=>$iso]);
        debug_log("    CACHE_FAILED_MAC: Successfully cached negative result for $mac");
        return true;
    } catch (Throwable $e) {
        debug_log("    CACHE_FAILED_MAC ERROR: Failed to cache $mac - ".$e->getMessage());
        return false;
    }
}

function is_valid_mac(string $mac): bool {
    $mac = strtoupper(str_replace(':', '', $mac));
    if (strlen($mac) !== 12 || !ctype_xdigit($mac)) return false;
    $invalid = ['000000000000', 'FFFFFFFFFFFF'];
    return !in_array($mac, $invalid, true);
}

function google_geolocate(string $apiKey, array $aps, int $minAps, string $timestamp, int $maxAps = 6, int $rssiFloor = -95): ?array {
    if (!$apiKey || empty($aps)) {
        debug_log("  GOOGLE SKIP: apiKey=".($apiKey?'SET':'EMPTY')." aps_count=".count($aps));
        return null;
    }
    
    debug_log("  GOOGLE: timestamp=$timestamp original_aps=".count($aps)." rssi_floor={$rssiFloor}dBm");
    
    usort($aps, fn($a,$b) => (($b['rssi']??-200) <=> ($a['rssi']??-200)));
    $filtered = [];
    $invalidCount = 0;
    $weakCount = 0;
    
    foreach ($aps as $ap) {
        if (empty($ap['mac'])) continue;
        
        if (!is_valid_mac($ap['mac'])) {
            debug_log("    SKIP invalid MAC: ".$ap['mac']);
            $invalidCount++;
            continue;
        }
        
        $rssi = (int)($ap['rssi'] ?? -200);
        if ($rssi < $rssiFloor) {
            debug_log("    SKIP weak signal: ".$ap['mac']." RSSI={$rssi}dBm");
            $weakCount++;
            continue;
        }
        
        $filtered[] = ['macAddress'=>norm_mac($ap['mac']), 'signalStrength'=>$rssi];
        if (count($filtered) >= $maxAps) break;
    }
    
    debug_log("  FILTER RESULT: valid=".count($filtered)." invalid=$invalidCount weak=$weakCount minRequired=$minAps");
    
    if (count($filtered) < $minAps) {
        debug_log("  GOOGLE SKIP: not enough valid APs (have ".count($filtered).", need $minAps)");
        return null;
    }
    
    debug_log("  GOOGLE REQUEST: sending ".count($filtered)." APs to Google API");
    
    $url = 'https://www.googleapis.com/geolocation/v1/geolocate?key=' . rawurlencode($apiKey);
    $payload = json_encode(['considerIp'=>false, 'wifiAccessPoints'=>$filtered], JSON_UNESCAPED_SLASHES);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    
    if ($body === false) { 
        $err = curl_error($ch);
        debug_log("  GOOGLE CURL ERROR: $err");
        curl_close($ch); 
        return null; 
    }
    
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); 
    curl_close($ch);
    
    debug_log("  GOOGLE RESPONSE: http=$http body=".substr($body, 0, 300));
    
    $j = json_decode($body, true);
    if ($http !== 200 || isset($j['error'])) { 
        $errorDetail = isset($j['error']) ? json_encode($j['error']) : 'unknown';
        debug_log("  GOOGLE ERROR: http=$http error=$errorDetail");
        return null; 
    }
    
    if (isset($j['location']['lat'],$j['location']['lng'])) {
        $acc = isset($j['accuracy']) ? (float)$j['accuracy'] : null;
        debug_log("  GOOGLE SUCCESS: lat=".$j['location']['lat']." lng=".$j['location']['lng']." accuracy=".($acc??'null')."m");
        return [ (float)$j['location']['lat'], (float)$j['location']['lng'], $acc ];
    }
    
    debug_log("  GOOGLE UNEXPECTED: no location in response");
    return null;
}

// ============== PERIMETER ZONE DETECTION ==============

function point_in_polygon(float $lat, float $lng, array $polygon): bool {
    $n = count($polygon);
    if ($n < 3) return false;

    $inside = false;
    $j = $n - 1;

    for ($i = 0; $i < $n; $j = $i++) {
        $xi = $polygon[$i]['lng'];
        $yi = $polygon[$i]['lat'];
        $xj = $polygon[$j]['lng'];
        $yj = $polygon[$j]['lat'];

        if ((($yi > $lat) !== ($yj > $lat)) &&
            ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
    }

    return $inside;
}

function load_active_perimeters(PDO $pdo, ?int $deviceId = null): array {
    $perimeters = [];
    try {
        // Check if device_id column exists
        $hasDeviceIdCol = false;
        $cols = $pdo->query("PRAGMA table_info(perimeters)");
        while ($c = $cols->fetch()) {
            if ($c['name'] === 'device_id') { $hasDeviceIdCol = true; break; }
        }

        // Load perimeters: global (device_id IS NULL) + device-specific
        if ($hasDeviceIdCol && $deviceId !== null) {
            $stmt = $pdo->prepare("
                SELECT id, name, polygon, alert_on_enter, alert_on_exit, notification_email, device_id
                FROM perimeters
                WHERE is_active = 1 AND (device_id IS NULL OR device_id = :did)
            ");
            $stmt->execute([':did' => $deviceId]);
        } elseif ($hasDeviceIdCol) {
            $stmt = $pdo->query("
                SELECT id, name, polygon, alert_on_enter, alert_on_exit, notification_email, device_id
                FROM perimeters
                WHERE is_active = 1
            ");
        } else {
            $stmt = $pdo->query("
                SELECT id, name, polygon, alert_on_enter, alert_on_exit, notification_email
                FROM perimeters
                WHERE is_active = 1
            ");
        }
        while ($r = $stmt->fetch()) {
            $pid = (int)$r['id'];

            // Load emails for this perimeter
            $emailStmt = $pdo->prepare("
                SELECT email, alert_on_enter, alert_on_exit
                FROM perimeter_emails
                WHERE perimeter_id = :pid
            ");
            $emailStmt->execute([':pid' => $pid]);
            $emails = [];
            while ($e = $emailStmt->fetch()) {
                $emails[] = [
                    'email' => $e['email'],
                    'alert_on_enter' => (bool)$e['alert_on_enter'],
                    'alert_on_exit' => (bool)$e['alert_on_exit']
                ];
            }

            // Backwards compatibility: if no emails in new table, use legacy field
            if (empty($emails) && !empty($r['notification_email'])) {
                $emails[] = [
                    'email' => $r['notification_email'],
                    'alert_on_enter' => (bool)$r['alert_on_enter'],
                    'alert_on_exit' => (bool)$r['alert_on_exit']
                ];
            }

            $perDeviceId = ($hasDeviceIdCol && isset($r['device_id']) && $r['device_id'] !== null) ? (int)$r['device_id'] : null;
            $perimeters[] = [
                'id' => $pid,
                'name' => $r['name'],
                'polygon' => json_decode($r['polygon'], true),
                'alert_on_enter' => (bool)$r['alert_on_enter'],
                'alert_on_exit' => (bool)$r['alert_on_exit'],
                'notification_email' => $r['notification_email'],
                'device_id' => $perDeviceId,
                'emails' => $emails
            ];

            // Log email configuration for each perimeter
            $emailCount = count($emails);
            $enterCount = 0;
            $exitCount = 0;
            foreach ($emails as $em) {
                if ($em['alert_on_enter']) $enterCount++;
                if ($em['alert_on_exit']) $exitCount++;
            }
            $deviceLabel = $perDeviceId !== null ? "device=$perDeviceId" : 'global';
            info_log("PERIMETER '{$r['name']}' (id=$pid, $deviceLabel): emails=$emailCount, alert_on_enter=$enterCount, alert_on_exit=$exitCount");
        }
        info_log("PERIMETERS: loaded ".count($perimeters)." active zones" . ($deviceId !== null ? " for device_id=$deviceId" : ""));
    } catch (Throwable $e) {
        info_log("PERIMETERS ERROR: ".$e->getMessage());
    }
    return $perimeters;
}

function get_position_perimeter_states(float $lat, float $lng, array $perimeters): array {
    $states = [];
    foreach ($perimeters as $p) {
        $states[$p['id']] = point_in_polygon($lat, $lng, $p['polygon']);
    }
    return $states;
}

function check_perimeter_breaches(
    PDO $pdo,
    float $lat,
    float $lng,
    string $timestamp,
    array $perimeters,
    array $currentStates,
    array $previousStates,
    ?string $deviceName = null
): array {
    $breaches = [];

    foreach ($perimeters as $p) {
        $pid = $p['id'];
        $wasInside = isset($previousStates[$pid]) ? $previousStates[$pid] : null;
        $isInside = $currentStates[$pid];

        // Skip if no previous state (first position)
        if ($wasInside === null) {
            info_log("    PERIMETER '{$p['name']}': first position (no previous state), inside=".($isInside?'YES':'NO'));
            continue;
        }

        // Check if any email wants to receive enter alerts
        $anyWantsEnter = false;
        $anyWantsExit = false;
        $emails = $p['emails'] ?? [];
        foreach ($emails as $e) {
            if ($e['alert_on_enter']) $anyWantsEnter = true;
            if ($e['alert_on_exit']) $anyWantsExit = true;
        }
        // Fallback to legacy perimeter settings if no emails defined
        if (empty($emails)) {
            $anyWantsEnter = $p['alert_on_enter'];
            $anyWantsExit = $p['alert_on_exit'];
        }

        // Log state transition
        $stateChanged = ($wasInside !== $isInside);
        if ($stateChanged) {
            info_log("    PERIMETER '{$p['name']}': STATE CHANGE wasInside=".($wasInside?'YES':'NO')." isInside=".($isInside?'YES':'NO')." anyWantsEnter=".($anyWantsEnter?'YES':'NO')." anyWantsExit=".($anyWantsExit?'YES':'NO'));
        }

        // Detect breach
        if (!$wasInside && $isInside && $anyWantsEnter) {
            // ENTERED the zone
            $breaches[] = [
                'perimeter' => $p,
                'type' => 'enter',
                'lat' => $lat,
                'lng' => $lng,
                'timestamp' => $timestamp,
                'device_name' => $deviceName
            ];
            info_log("    PERIMETER BREACH DETECTED: ENTERED '{$p['name']}'" . ($deviceName ? " (device: $deviceName)" : ""));
        } elseif ($wasInside && !$isInside && $anyWantsExit) {
            // EXITED the zone
            $breaches[] = [
                'perimeter' => $p,
                'type' => 'exit',
                'lat' => $lat,
                'lng' => $lng,
                'timestamp' => $timestamp,
                'device_name' => $deviceName
            ];
            info_log("    PERIMETER BREACH DETECTED: EXITED '{$p['name']}'" . ($deviceName ? " (device: $deviceName)" : ""));
        } elseif ($stateChanged) {
            // State changed but alert not configured
            if (!$wasInside && $isInside && !$anyWantsEnter) {
                info_log("    PERIMETER '{$p['name']}': ENTERED but alert_on_enter=NO - skipping notification");
            } elseif ($wasInside && !$isInside && !$anyWantsExit) {
                info_log("    PERIMETER '{$p['name']}': EXITED but alert_on_exit=NO - skipping notification");
            }
        }
    }

    return $breaches;
}

function send_perimeter_alert_emails(array $breach): int {
    $p = $breach['perimeter'];
    $emails = $p['emails'] ?? [];
    $breachType = $breach['type']; // 'enter' or 'exit'
    $deviceName = $breach['device_name'] ?? null;

    info_log("    SENDING EMAIL for breach: type=$breachType perimeter='{$p['name']}' device='" . ($deviceName ?? 'unknown') . "' emails_count=".count($emails));

    if (empty($emails)) {
        info_log("    EMAIL SKIP: no emails configured for '{$p['name']}'");
        return 0;
    }

    $emailServiceUrl = getenv('EMAIL_SERVICE_URL') ?: 'http://email-service:3004/send';
    // Append /send if URL doesn't end with it
    if (!str_ends_with($emailServiceUrl, '/send')) {
        $emailServiceUrl = rtrim($emailServiceUrl, '/') . '/send';
    }
    $apiKey = getenv('EMAIL_API_KEY') ?: '';
    $fromEmail = getenv('EMAIL_FROM') ?: 'tracker@bagron.eu';
    $fromName = getenv('EMAIL_FROM_NAME') ?: 'Family Tracker';

    $alertType = $breachType === 'enter' ? 'VSTUP DO ZÓNY' : 'OPUSTENIE ZÓNY';
    $deviceLabel = $deviceName ? " ($deviceName)" : '';
    $subject = "Family Tracker: $alertType - {$p['name']}$deviceLabel";

    // Format timestamp for display
    try {
        $dt = new DateTimeImmutable($breach['timestamp'], new DateTimeZone('UTC'));
        $dt = $dt->setTimezone(new DateTimeZone('Europe/Bratislava'));
        $formattedTime = $dt->format('d.m.Y H:i:s');
        $dateStr = $dt->format('Y-m-d');
    } catch (Throwable $e) {
        $formattedTime = $breach['timestamp'];
        $dateStr = date('Y-m-d');
    }

    // Use tracker app URL if configured, otherwise fall back to Google Maps
    info_log("ZONE ALERT EMAIL - TRACKER_APP_URL: '" . (defined('TRACKER_APP_URL') ? TRACKER_APP_URL : 'NOT DEFINED') . "'");
    if (defined('TRACKER_APP_URL') && TRACKER_APP_URL !== '') {
        $mapsUrl = TRACKER_APP_URL . "?lat={$breach['lat']}&lng={$breach['lng']}&date={$dateStr}";
        info_log("ZONE ALERT EMAIL - Using tracker URL: $mapsUrl");
    } else {
        $mapsUrl = "https://www.google.com/maps?q={$breach['lat']},{$breach['lng']}";
        info_log("ZONE ALERT EMAIL - Using Google Maps (fallback): $mapsUrl");
    }

    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: " . ($breachType === 'enter' ? '#10b981' : '#ef4444') . "; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
            .content { background: #f8fafc; padding: 20px; border: 1px solid #e5e5e5; border-top: none; border-radius: 0 0 8px 8px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; color: #555; }
            .btn { display: inline-block; padding: 12px 24px; background: #0ea5e9; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
            .footer { margin-top: 20px; font-size: 12px; color: #888; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2 style='margin:0;'>$alertType</h2>
                <p style='margin:5px 0 0;opacity:0.9;'>Family Tracker</p>
            </div>
            <div class='content'>" .
                ($deviceName ? "
                <div class='info-row'>
                    <span class='label'>Zariadenie:</span> $deviceName
                </div>" : "") . "
                <div class='info-row'>
                    <span class='label'>Zóna:</span> {$p['name']}
                </div>
                <div class='info-row'>
                    <span class='label'>Čas:</span> $formattedTime
                </div>
                <div class='info-row'>
                    <span class='label'>Poloha:</span> {$breach['lat']}, {$breach['lng']}
                </div>
                <a href='$mapsUrl' class='btn'>Zobraziť na mape</a>
                <div class='footer'>
                    Táto správa bola automaticky vygenerovaná systémom Family Tracker.
                </div>
            </div>
        </div>
    </body>
    </html>";

    $sentCount = 0;

    // Send to each email that has the appropriate alert type enabled
    foreach ($emails as $emailEntry) {
        $toEmail = $emailEntry['email'] ?? '';
        if (!$toEmail) continue;

        // Check if this email should receive this alert type
        $shouldReceive = ($breachType === 'enter' && $emailEntry['alert_on_enter']) ||
                         ($breachType === 'exit' && $emailEntry['alert_on_exit']);

        if (!$shouldReceive) {
            info_log("    EMAIL SKIP: $toEmail - alert type '$breachType' not enabled for this recipient (alert_on_enter=".($emailEntry['alert_on_enter']?'1':'0').", alert_on_exit=".($emailEntry['alert_on_exit']?'1':'0').")");
            continue;
        }

        $payloadData = [
            'to_email' => $toEmail,
            'subject' => $subject,
            'html_body' => $htmlBody,
            'from_email' => $fromEmail,
            'from_name' => $fromName
        ];
        // Only include api_key if configured
        if (!empty($apiKey)) {
            $payloadData['api_key'] = $apiKey;
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        info_log("    EMAIL SENDING: to=$toEmail url=$emailServiceUrl api_key=" . (!empty($apiKey) ? 'SET' : 'NOT_SET'));

        $ch = curl_init($emailServiceUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            info_log("    EMAIL ERROR: to=$toEmail curl error - $curlError");
            continue;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            info_log("    EMAIL SUCCESS: to=$toEmail http=$httpCode");
            $sentCount++;
        } else {
            info_log("    EMAIL ERROR: to=$toEmail http=$httpCode response=".substr($response, 0, 200));
        }
    }

    return $sentCount;
}

// Backwards compatibility wrapper
function send_perimeter_alert_email(array $breach): bool {
    return send_perimeter_alert_emails($breach) > 0;
}

/**
 * Send summary email with all breaches from refetch operation
 * Returns number of emails sent
 */
function send_refetch_summary_email(array $breaches, string $refetchDate): int {
    if (empty($breaches)) {
        debug_log("SUMMARY EMAIL: No breaches to report");
        return 0;
    }

    $emailServiceUrl = getenv('EMAIL_SERVICE_URL') ?: 'http://email-service:3004/send';
    // Append /send if URL doesn't end with it
    if (!str_ends_with($emailServiceUrl, '/send')) {
        $emailServiceUrl = rtrim($emailServiceUrl, '/') . '/send';
    }
    $apiKey = getenv('EMAIL_API_KEY') ?: '';
    $fromEmail = getenv('EMAIL_FROM') ?: 'tracker@bagron.eu';
    $fromName = getenv('EMAIL_FROM_NAME') ?: 'Family Tracker';

    // Collect all unique emails that should receive this summary
    $recipientEmails = [];
    foreach ($breaches as $breach) {
        $p = $breach['perimeter'];
        $emails = $p['emails'] ?? [];
        $breachType = $breach['type'];

        foreach ($emails as $emailEntry) {
            $toEmail = $emailEntry['email'] ?? '';
            if (!$toEmail) continue;

            $shouldReceive = ($breachType === 'enter' && $emailEntry['alert_on_enter']) ||
                             ($breachType === 'exit' && $emailEntry['alert_on_exit']);

            if ($shouldReceive) {
                if (!isset($recipientEmails[$toEmail])) {
                    $recipientEmails[$toEmail] = [];
                }
                $recipientEmails[$toEmail][] = $breach;
            }
        }
    }

    if (empty($recipientEmails)) {
        debug_log("SUMMARY EMAIL: No recipients configured for any breach");
        return 0;
    }

    $subject = "Family Tracker: Súhrn udalostí z $refetchDate (" . count($breaches) . " udalostí)";

    // Check if any breach has device info
    $hasDeviceInfo = false;
    foreach ($breaches as $b) {
        if (!empty($b['device_name'])) { $hasDeviceInfo = true; break; }
    }

    // Build HTML table with all breaches
    $tableRows = '';
    foreach ($breaches as $breach) {
        $p = $breach['perimeter'];
        $alertType = $breach['type'] === 'enter' ? 'VSTUP' : 'VÝSTUP';
        $alertColor = $breach['type'] === 'enter' ? '#10b981' : '#ef4444';

        try {
            $dt = new DateTimeImmutable($breach['timestamp'], new DateTimeZone('UTC'));
            $dt = $dt->setTimezone(new DateTimeZone('Europe/Bratislava'));
            $formattedTime = $dt->format('H:i:s');
            $dateStr = $dt->format('Y-m-d');
        } catch (Throwable $e) {
            $formattedTime = $breach['timestamp'];
            $dateStr = date('Y-m-d');
        }

        // Use tracker app URL if configured, otherwise fall back to Google Maps
        if (defined('TRACKER_APP_URL') && TRACKER_APP_URL !== '') {
            $mapsUrl = TRACKER_APP_URL . "?lat={$breach['lat']}&lng={$breach['lng']}&date={$dateStr}";
        } else {
            $mapsUrl = "https://www.google.com/maps?q={$breach['lat']},{$breach['lng']}";
        }

        $breachDeviceName = $breach['device_name'] ?? '';
        $tableRows .= "
        <tr>
            <td style='padding:10px;border-bottom:1px solid #e5e5e5;'>$formattedTime</td>" .
            ($hasDeviceInfo ? "
            <td style='padding:10px;border-bottom:1px solid #e5e5e5;'>$breachDeviceName</td>" : "") . "
            <td style='padding:10px;border-bottom:1px solid #e5e5e5;'>{$p['name']}</td>
            <td style='padding:10px;border-bottom:1px solid #e5e5e5;'>
                <span style='display:inline-block;padding:4px 10px;background:$alertColor;color:white;border-radius:4px;font-size:12px;font-weight:bold;'>$alertType</span>
            </td>
            <td style='padding:10px;border-bottom:1px solid #e5e5e5;'>
                <a href='$mapsUrl' style='color:#0ea5e9;text-decoration:none;'>Mapa</a>
            </td>
        </tr>";
    }

    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { max-width: 650px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 8px 8px 0 0; }
            .content { background: #fff; padding: 20px; border: 1px solid #e5e5e5; border-top: none; border-radius: 0 0 8px 8px; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th { background: #f8fafc; padding: 12px 10px; text-align: left; border-bottom: 2px solid #e5e5e5; font-size: 13px; color: #555; }
            .footer { margin-top: 25px; padding-top: 20px; border-top: 1px solid #e5e5e5; font-size: 12px; color: #888; }
            .info-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 12px 15px; margin-bottom: 15px; font-size: 14px; color: #1e40af; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2 style='margin:0;'>Súhrn udalostí</h2>
                <p style='margin:8px 0 0;opacity:0.9;font-size:15px;'>Refetch operácia - $refetchDate</p>
            </div>
            <div class='content'>
                <div class='info-box'>
                    Pri spätnom spracovaní dát bolo detekovaných <strong>" . count($breaches) . " udalostí</strong> prekročenia perimetra.
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Čas</th>" .
                            ($hasDeviceInfo ? "
                            <th>Zariadenie</th>" : "") . "
                            <th>Zóna</th>
                            <th>Typ</th>
                            <th>Poloha</th>
                        </tr>
                    </thead>
                    <tbody>
                        $tableRows
                    </tbody>
                </table>

                <div class='footer'>
                    Táto správa bola automaticky vygenerovaná systémom Family Tracker.<br>
                    Udalosti boli detekované počas refetch operácie, nie v reálnom čase.
                </div>
            </div>
        </div>
    </body>
    </html>";

    $sentCount = 0;

    // Send to each recipient with their relevant breaches
    foreach ($recipientEmails as $toEmail => $recipientBreaches) {
        $payloadData = [
            'to_email' => $toEmail,
            'subject' => $subject,
            'html_body' => $htmlBody,
            'from_email' => $fromEmail,
            'from_name' => $fromName
        ];
        // Only include api_key if configured
        if (!empty($apiKey)) {
            $payloadData['api_key'] = $apiKey;
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        debug_log("SUMMARY EMAIL SENDING: to=$toEmail breaches=" . count($recipientBreaches) . " api_key=" . (!empty($apiKey) ? 'SET' : 'NOT_SET'));

        $ch = curl_init($emailServiceUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            debug_log("SUMMARY EMAIL ERROR: to=$toEmail curl error - $curlError");
            continue;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            debug_log("SUMMARY EMAIL SUCCESS: to=$toEmail http=$httpCode");
            $sentCount++;
        } else {
            debug_log("SUMMARY EMAIL ERROR: to=$toEmail http=$httpCode response=".substr($response, 0, 200));
        }
    }

    return $sentCount;
}

function record_perimeter_alert(PDO $pdo, array $breach, bool $emailSent): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO perimeter_alerts (perimeter_id, alert_type, latitude, longitude, sent_at, email_sent)
            VALUES (:pid, :type, :lat, :lng, :sent, :email)
        ");
        $stmt->execute([
            ':pid' => $breach['perimeter']['id'],
            ':type' => $breach['type'],
            ':lat' => $breach['lat'],
            ':lng' => $breach['lng'],
            ':sent' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':email' => $emailSent ? 1 : 0
        ]);
        debug_log("    ALERT RECORDED: perimeter_id={$breach['perimeter']['id']} type={$breach['type']}");
    } catch (Throwable $e) {
        debug_log("    ALERT RECORD ERROR: ".$e->getMessage());
    }
}

// ============== BATTERY ALERT ==============

/**
 * Send battery low alert email
 * Returns true if email was sent successfully
 */
function send_battery_alert_email(PDO $pdo, ?string $deviceName = null): bool {
    if (!BATTERY_ALERT_ENABLED || !BATTERY_ALERT_EMAIL) {
        debug_log("BATTERY ALERT: Skipped - not enabled or no email configured");
        return false;
    }

    $emailServiceUrl = getenv('EMAIL_SERVICE_URL') ?: 'http://email-service:3004/send';
    if (!str_ends_with($emailServiceUrl, '/send')) {
        $emailServiceUrl = rtrim($emailServiceUrl, '/') . '/send';
    }
    $apiKey = getenv('EMAIL_API_KEY') ?: '';
    $fromEmail = getenv('EMAIL_FROM') ?: 'tracker@bagron.eu';
    $fromName = getenv('EMAIL_FROM_NAME') ?: 'Family Tracker';

    $deviceLabel = $deviceName ? " ($deviceName)" : '';
    $subject = "Family Tracker: Nízka batéria trackera$deviceLabel";

    $formattedTime = (new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Bratislava'))->format('d.m.Y H:i:s');

    // Build tracker URL if available
    $trackerUrl = TRACKER_APP_URL ?: '';
    $trackerButton = $trackerUrl
        ? "<a href='$trackerUrl' class='btn'>Otvoriť tracker</a>"
        : '';

    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #f59e0b; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
            .content { background: #f8fafc; padding: 20px; border: 1px solid #e5e5e5; border-top: none; border-radius: 0 0 8px 8px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; color: #555; }
            .btn { display: inline-block; padding: 12px 24px; background: #0ea5e9; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
            .footer { margin-top: 20px; font-size: 12px; color: #888; }
            .warning-icon { font-size: 48px; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='warning-icon'>&#9888;</div>
                <h2 style='margin:0;'>Nízka batéria trackera</h2>
                <p style='margin:5px 0 0;opacity:0.9;'>Family Tracker</p>
            </div>
            <div class='content'>" .
                ($deviceName ? "
                <div class='info-row'>
                    <span class='label'>Zariadenie:</span> $deviceName
                </div>" : "") . "
                <div class='info-row'>
                    <span class='label'>Stav:</span> Batéria trackera je takmer vybitá
                </div>
                <div class='info-row'>
                    <span class='label'>Čas:</span> $formattedTime
                </div>
                <p style='margin-top: 15px; color: #b45309;'>
                    <strong>Odporúčanie:</strong> Čo najskôr nabite tracker, aby ste neprerušili sledovanie polohy.
                </p>
                $trackerButton
                <div class='footer'>
                    Táto správa bola automaticky vygenerovaná systémom Family Tracker.
                </div>
            </div>
        </div>
    </body>
    </html>";

    $payloadData = [
        'to_email' => BATTERY_ALERT_EMAIL,
        'subject' => $subject,
        'html_body' => $htmlBody,
        'from_email' => $fromEmail,
        'from_name' => $fromName
    ];
    if (!empty($apiKey)) {
        $payloadData['api_key'] = $apiKey;
    }
    $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    info_log("BATTERY ALERT: Sending email to " . BATTERY_ALERT_EMAIL);

    $ch = curl_init($emailServiceUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        info_log("BATTERY ALERT ERROR: curl error - $curlError");
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        info_log("BATTERY ALERT SUCCESS: Email sent to " . BATTERY_ALERT_EMAIL);

        // Record that we sent an alert to avoid spam
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS battery_alert_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sent_at TEXT NOT NULL,
                    email TEXT NOT NULL
                )
            ");
            $stmt = $pdo->prepare("INSERT INTO battery_alert_log (sent_at, email) VALUES (:sent, :email)");
            $stmt->execute([
                ':sent' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':email' => BATTERY_ALERT_EMAIL
            ]);
        } catch (Throwable $e) {
            debug_log("BATTERY ALERT: Failed to log alert - " . $e->getMessage());
        }

        return true;
    } else {
        info_log("BATTERY ALERT ERROR: http=$httpCode response=" . substr($response, 0, 200));
        return false;
    }
}

/**
 * Check if we should send a battery alert (not sent in last 24 hours)
 */
function should_send_battery_alert(PDO $pdo): bool {
    try {
        // Create table if not exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS battery_alert_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sent_at TEXT NOT NULL,
                email TEXT NOT NULL
            )
        ");

        // Check last alert time
        $cutoff = (new DateTimeImmutable())->sub(new DateInterval('PT24H'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM battery_alert_log WHERE sent_at > :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
        $result = $stmt->fetch();

        $shouldSend = ($result['cnt'] ?? 0) == 0;
        debug_log("BATTERY ALERT CHECK: last 24h alerts = " . ($result['cnt'] ?? 0) . ", should_send = " . ($shouldSend ? 'YES' : 'NO'));

        return $shouldSend;
    } catch (Throwable $e) {
        debug_log("BATTERY ALERT CHECK ERROR: " . $e->getMessage());
        return true; // Send if we can't check
    }
}

function load_ibeacon_map(PDO $pdo): array {
    $map = [];
    try {
        $q = $pdo->query("SELECT mac_address, latitude, longitude FROM ibeacon_locations");
        while ($r = $q->fetch()) {
            $map[norm_mac($r['mac_address'])] = [ (float)$r['latitude'], (float)$r['longitude'] ];
        }
        debug_log("IBEACON MAP: loaded ".count($map)." beacons".($map ? ': '.implode(', ', array_keys($map)) : ''));
    } catch (Throwable $e) {
        debug_log("IBEACON MAP: table not found or error - ".$e->getMessage());
    }
    return $map;
}

function load_mac_cache(PDO $pdo, int $maxAgeDays): array {
    $cache = [];
    try {
        $cutoff = (new DateTimeImmutable())->sub(new DateInterval("P{$maxAgeDays}D"))->format('Y-m-d H:i:s');
        debug_log("MAC CACHE: loading entries newer than $cutoff (max_age={$maxAgeDays}d)");
        
        $stmt = $pdo->prepare("
            SELECT mac_address, latitude, longitude, last_queried 
            FROM mac_locations 
            WHERE last_queried >= :cutoff
        ");
        $stmt->execute([':cutoff' => $cutoff]);
        
        $positive = 0;
        $negative = 0;
        
        while ($r = $stmt->fetch()) {
            $mac = norm_mac($r['mac_address']);
            
            if ($r['latitude'] !== null && $r['longitude'] !== null) {
                $cache[$mac] = [(float)$r['latitude'], (float)$r['longitude']];
                $positive++;
            } else {
                $cache[$mac] = null;
                $negative++;
            }
        }
        
        debug_log("MAC CACHE: loaded ".count($cache)." entries (positive=$positive, negative=$negative)");
    } catch (Throwable $e) {
        debug_log("MAC CACHE ERROR: ".$e->getMessage());
    }
    return $cache;
}

function clean_refetch_day(PDO $pdo, DateTimeImmutable $dayStart, DateTimeImmutable $dayEnd, ?int $deviceId = null): int {
    try {
        $sql = "DELETE FROM tracker_data WHERE timestamp BETWEEN :start AND :end";
        $params = [
            ':start' => $dayStart->format('Y-m-d H:i:s'),
            ':end' => $dayEnd->format('Y-m-d H:i:s')
        ];
        if ($deviceId !== null) {
            $sql .= " AND device_id = :did";
            $params[':did'] = $deviceId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $deletedCount = $stmt->rowCount();
        info_log("CLEAN REFETCH: Deleted $deletedCount existing records for date range");
        return $deletedCount;
    } catch (Throwable $e) {
        info_log("CLEAN REFETCH ERROR: " . $e->getMessage());
        return 0;
    }
}

function parse_cli_args(array $argv): array {
    $result = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--refetch-date=(.+)$/', $arg, $m)) {
            $result['refetch_date'] = $m[1];
        }
    }
    return $result;
}

// ============== HLAVNÝ SKRIPT ==============

info_log('=== FETCH START ===');

// Lock file pre collision avoidance
$lockFp = @fopen(FETCH_LOCK_FILE, 'c');
if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
    register_shutdown_function(function() use ($lockFp) {
        if ($lockFp) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            @unlink(FETCH_LOCK_FILE);
        }
    });
} else {
    info_log('Another fetch_data.php instance is running, exiting');
    exit(0);
}

// ============== FREQUENCY CHECK ==============
// Check if enough time has passed since last run (skip for refetch mode)
$cliArgs = parse_cli_args($argv);
$isRefetchMode = isset($cliArgs['refetch_date']);

if (!$isRefetchMode) {
    $fetchFrequencyMinutes = (int)(getenv('FETCH_FREQUENCY_MINUTES') ?: 5);
    $lastRunFile = __DIR__ . '/data/.fetch_last_run';

    // Ensure data directory exists
    if (!is_dir(__DIR__ . '/data')) {
        @mkdir(__DIR__ . '/data', 0755, true);
    }

    $now = time();
    $lastRunTime = 0;

    if (is_file($lastRunFile)) {
        $lastRunTime = (int)file_get_contents($lastRunFile);
    }

    $elapsedMinutes = ($now - $lastRunTime) / 60;

    if ($elapsedMinutes < $fetchFrequencyMinutes) {
        debug_log(sprintf(
            'FREQUENCY CHECK: Only %.1f minutes elapsed since last run (required: %d min), skipping',
            $elapsedMinutes,
            $fetchFrequencyMinutes
        ));
        exit(0);
    }

    // Update last run time
    file_put_contents($lastRunFile, (string)$now);
    debug_log(sprintf(
        'FREQUENCY CHECK: %.1f minutes elapsed since last run (required: %d min), proceeding',
        $elapsedMinutes,
        $fetchFrequencyMinutes
    ));
}
// ============================================

try {
    // .env is already loaded at the top of the file via load_env_early()
    // $cliArgs already parsed above for frequency check

    if ($isRefetchMode) {
        $REFETCH_MODE = true;
        $REFETCH_DATE = $cliArgs['refetch_date'];
        info_log("REFETCH MODE: Enabled for date $REFETCH_DATE");
        $DEBUG_MODE = true;
    }

    $debugEnv = getenv('DEBUG_MODE');
    if ($debugEnv !== false && in_array(strtolower($debugEnv), ['true', '1', 'yes'])) {
        $DEBUG_MODE = true;
        info_log('DEBUG MODE: ENABLED');
    }

    $AID  = getenv('SENSECAP_ACCESS_ID') ?: '';
    $AKEY = getenv('SENSECAP_ACCESS_KEY') ?: '';
    $GKEY = getenv('GOOGLE_API_KEY') ?: '';

    // Measurement IDs
    $MEAS_GNSS_LON        = (int)(getenv('MEAS_GNSS_LON')        ?: 4197);
    $MEAS_GNSS_LAT        = (int)(getenv('MEAS_GNSS_LAT')        ?: 4198);
    $MEAS_WIFI_MAC        = (int)(getenv('MEAS_WIFI_MAC')        ?: 5001);
    $MEAS_BT_IBEACON_MAC  = (int)(getenv('MEAS_BT_IBEACON_MAC')  ?: 5002);
    $MEAS_BATTERY         = (int)(getenv('MEAS_BATTERY')         ?: 5003);

    $WIFI_MIN_APS = (int) (getenv('WIFI_MIN_APS') ?: 1);
    $MAX_APS      = (int) (getenv('WIFI_MAX_APS') ?: 6);
    $RSSI_FLOOR   = (int) (getenv('WIFI_MIN_RSSI') ?: -95);
    $HYSTERESIS_METERS = (int) (getenv('HYSTERESIS_METERS') ?: 50);
    $MAC_CACHE_MAX_AGE = (int) (getenv('MAC_CACHE_MAX_AGE_DAYS') ?: 365);

    debug_log("CONFIG: WIFI_MIN_APS=$WIFI_MIN_APS MAX_APS=$MAX_APS RSSI_FLOOR={$RSSI_FLOOR}dBm HYSTERESIS={$HYSTERESIS_METERS}m MAC_CACHE_AGE={$MAC_CACHE_MAX_AGE}d");

    if (!$AID || !$AKEY) {
        info_log('ERROR: Missing SenseCAP API credentials (ACCESS_ID/ACCESS_KEY)');
        exit(0);
    }

    $pdo = db();

    // Multi-device support: Load active devices from DB, fallback to .env EUI
    $activeDevices = [];
    try {
        $tables = [];
        $tq = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='devices'");
        while ($tr = $tq->fetch()) $tables[] = $tr['name'];

        if (in_array('devices', $tables)) {
            $dq = $pdo->query("SELECT id, name, device_eui FROM devices WHERE is_active = 1 ORDER BY id ASC");
            while ($dr = $dq->fetch()) {
                $activeDevices[] = ['id' => (int)$dr['id'], 'name' => $dr['name'], 'eui' => $dr['device_eui']];
            }
        }
    } catch (Throwable $e) {
        debug_log("DEVICES: Could not load from DB: " . $e->getMessage());
    }

    // Fallback: use .env EUI if no devices in DB
    if (empty($activeDevices)) {
        $envEui = getenv('SENSECAP_DEVICE_EUI') ?: '';
        if ($envEui === '') {
            info_log('ERROR: No active devices in DB and no SENSECAP_DEVICE_EUI in .env');
            exit(0);
        }
        $activeDevices[] = ['id' => null, 'name' => 'Default', 'eui' => $envEui];
        info_log("DEVICES: Using .env EUI fallback: $envEui");
    } else {
        info_log("DEVICES: Found " . count($activeDevices) . " active device(s): " . implode(', ', array_map(fn($d) => "{$d['name']} ({$d['eui']})", $activeDevices)));
    }

    // Process each device
    $totalInsertedAll = 0;
    foreach ($activeDevices as $deviceInfo) {
    $EUI = $deviceInfo['eui'];
    $DEVICE_ID = $deviceInfo['id'];
    $DEVICE_NAME = $deviceInfo['name'];
    $LOG_DEVICE_PREFIX = $DEVICE_NAME;
    info_log("=== PROCESSING DEVICE: $DEVICE_NAME (EUI=$EUI, ID=" . ($DEVICE_ID ?? 'null') . ") ===");
    
    $lastDb = null;
    $refetchDayStart = null;
    $refetchDayEnd = null;
    $timeStartMs = 0;
    $timeEndMs = 0;
    
    if ($REFETCH_MODE && $REFETCH_DATE) {
        try {
            $refetchDayStart = new DateTimeImmutable($REFETCH_DATE . ' 00:00:00', new DateTimeZone('Europe/Bratislava'));
            $refetchDayEnd = new DateTimeImmutable($REFETCH_DATE . ' 23:59:59', new DateTimeZone('Europe/Bratislava'));
            $refetchDayStart = $refetchDayStart->setTimezone(new DateTimeZone('UTC'));
            $refetchDayEnd = $refetchDayEnd->setTimezone(new DateTimeZone('UTC'));
            
            $timeStartMs = (int)($refetchDayStart->getTimestamp() * 1000);
            $timeEndMs = (int)($refetchDayEnd->getTimestamp() * 1000);
            
            info_log("REFETCH RANGE: " . $refetchDayStart->format('Y-m-d H:i:s') . " to " . $refetchDayEnd->format('Y-m-d H:i:s') . " UTC");
            info_log("REFETCH TIME (ms): $timeStartMs to $timeEndMs");
            
            $GNSS_LIMIT = 20000;
            $SENSECAP_LIMIT = 10000;
            
            info_log("REFETCH: using high limits (gnss=$GNSS_LIMIT wifi/bt=$SENSECAP_LIMIT)");

            // CLEAN REFETCH: Delete existing records for this day and device
            $deletedCount = clean_refetch_day($pdo, $refetchDayStart, $refetchDayEnd, $DEVICE_ID);
            if ($deletedCount > 0) {
                info_log("REFETCH: Successfully deleted $deletedCount old records");
            }

        } catch (Throwable $e) {
            info_log("ERROR: Invalid refetch date format: $REFETCH_DATE");
            exit(1);
        }
    } else {
        $lastDb = last_db_ts($pdo, $DEVICE_ID);

        if ($lastDb) {
            if ($DEVICE_ID !== null) {
                $stmtLast = $pdo->prepare("SELECT latitude, longitude FROM tracker_data WHERE device_id = :did ORDER BY timestamp DESC LIMIT 1");
                $stmtLast->execute([':did' => $DEVICE_ID]);
                $lastRow = $stmtLast->fetch();
            } else {
                $lastRow = $pdo->query("SELECT latitude, longitude FROM tracker_data ORDER BY timestamp DESC LIMIT 1")->fetch();
            }
            info_log(sprintf(
                'Last known: %s @ (%.6f, %.6f)',
                $lastDb->format('Y-m-d H:i:s'),
                (float)$lastRow['latitude'],
                (float)$lastRow['longitude']
            ));
            
            $timeStartMs = (int)($lastDb->getTimestamp() * 1000);
        } else {
            info_log('Last known: NONE (empty database)');
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $sevenDaysAgo = $now->sub(new DateInterval('P7D'));
            $timeStartMs = (int)($sevenDaysAgo->getTimestamp() * 1000);
        }
        
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $timeEndMs = (int)($now->getTimestamp() * 1000);
        
        debug_log("NORMAL MODE TIME RANGE (ms): $timeStartMs to $timeEndMs");
        
        $GNSS_LIMIT = 20000;
        $SENSECAP_LIMIT = 10000;
    }

    debug_log("FETCH LIMITS: gnss=$GNSS_LIMIT wifi/bt=$SENSECAP_LIMIT");
    
    $gnssLon = sensecap_fetch_basic($EUI, $MEAS_GNSS_LON, $AID, $AKEY, $GNSS_LIMIT, $timeStartMs, $timeEndMs);
    $gnssLat = sensecap_fetch_basic($EUI, $MEAS_GNSS_LAT, $AID, $AKEY, $GNSS_LIMIT, $timeStartMs, $timeEndMs);
    $wifiRaw = sensecap_fetch_basic($EUI, $MEAS_WIFI_MAC, $AID, $AKEY, $SENSECAP_LIMIT, $timeStartMs, $timeEndMs);
    $btRaw   = sensecap_fetch_basic($EUI, $MEAS_BT_IBEACON_MAC, $AID, $AKEY, $SENSECAP_LIMIT, $timeStartMs, $timeEndMs);
    
    // Battery status z view_device_running_status API (lepšie ako measurement IDs)
    $deviceStatus = fetch_device_running_status($EUI, $AID, $AKEY);
    
    info_log('SENSECAP FETCH: lon='.count($gnssLon).' lat='.count($gnssLat).' wifi='.count($wifiRaw).' bt='.count($btRaw).' battery_status='.($deviceStatus['battery_status'] !== null ? $deviceStatus['battery_status'] : 'N/A').' (limits: gnss='.$GNSS_LIMIT.' wifi/bt='.$SENSECAP_LIMIT.')');

    // GNSS zlúčenie
    $lonByIso = [];
    foreach ($gnssLon as $r) {
        $p = $r['payload']; $lon = (is_array($p) && isset($p[0])) ? (float)$p[0] : (float)$p;
        $lonByIso[$r['iso']] = $lon;
    }
    $gnssBuckets = [];
    foreach ($gnssLat as $r) {
        $p = $r['payload']; $lat = (is_array($p) && isset($p[0])) ? (float)$p[0] : (float)$p;
        $iso = $r['iso']; 
        if (!isset($lonByIso[$iso])) continue;
        $gnssBuckets[$iso] = ['lat'=>$lat, 'lng'=>$lonByIso[$iso]];
    }

    // Wi-Fi buckets
    $wifiBuckets = [];
    foreach ($wifiRaw as $r) {
        $p = $r['payload'];
        if (!is_array($p)) continue;
        
        $list = [];
        foreach ($p as $ap) {
            if (!is_array($ap) || !isset($ap['mac'])) continue;
            $list[] = ['mac'=>norm_mac((string)$ap['mac']), 'rssi'=>(int)($ap['rssi'] ?? -200)];
        }
        
        if (!empty($list)) {
            $wifiBuckets[] = ['iso'=>$r['iso'], 'aps'=>$list];
            debug_log("WIFI BUCKET: ts=".$r['iso']." count=".count($list));
        }
    }

    // BT iBeacon buckets
    $btBuckets = [];
    foreach ($btRaw as $r) {
        $p = $r['payload'];
        if (!is_array($p)) continue;
        
        $list = [];
        foreach ($p as $beacon) {
            if (!is_array($beacon) || !isset($beacon['mac'])) continue;
            $list[] = ['mac'=>norm_mac((string)$beacon['mac']), 'rssi'=>(int)($beacon['rssi'] ?? -200)];
        }
        
        if (!empty($list)) {
            $btBuckets[] = ['iso'=>$r['iso'], 'beacons'=>$list];
            debug_log("BT BUCKET: ts=".$r['iso']." count=".count($list));
        }
    }

    debug_log('MERGED BUCKETS: gnss='.count($gnssBuckets).' wifi='.count($wifiBuckets).' bt='.count($btBuckets));

    $ibeaconMap = load_ibeacon_map($pdo);
    $macCache = load_mac_cache($pdo, $MAC_CACHE_MAX_AGE);

    // Load active perimeters for breach detection (global + device-specific)
    $activePerimeters = load_active_perimeters($pdo, $DEVICE_ID);
    $previousPerimeterStates = [];
    // NOTE: $previousPerimeterStates will be initialized AFTER $lastInsertedPos is set below

    $tsSet = [];
    foreach (array_keys($gnssBuckets) as $iso) $tsSet[$iso] = true;
    foreach ($wifiBuckets as $w) $tsSet[$w['iso']] = true;
    foreach ($btBuckets as $b) $tsSet[$b['iso']] = true;
    $allTs = array_keys($tsSet);
    sort($allTs);

    debug_log('PROCESSING: total_timestamps='.count($allTs));
    
    if ($REFETCH_MODE && $refetchDayStart && $refetchDayEnd) {
        $inRangeCount = 0;
        foreach ($allTs as $iso) {
            try {
                $isoObj = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
                if ($isoObj >= $refetchDayStart && $isoObj <= $refetchDayEnd) {
                    $inRangeCount++;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        info_log("REFETCH: Found $inRangeCount timestamps in target date range (out of ".count($allTs)." total)");
    }

    $inserted=0; $skipped=0; $googled=0; $ibeaconHits=0; $cacheHits=0; $cachedFails=0;
    $hysteresisSkips=0; $dbErrors=0;
    $perimeterAlerts=0; $emailsSent=0;

    // Helper function to check perimeters after successful insert
    $checkPerimetersAfterInsert = function(float $lat, float $lng, string $iso) use ($pdo, $activePerimeters, &$previousPerimeterStates, &$perimeterAlerts, &$emailsSent, $DEVICE_NAME) {
        global $REFETCH_MODE, $REFETCH_BREACHES;
        if (empty($activePerimeters)) return;

        $currentStates = get_position_perimeter_states($lat, $lng, $activePerimeters);
        $breaches = check_perimeter_breaches($pdo, $lat, $lng, $iso, $activePerimeters, $currentStates, $previousPerimeterStates, $DEVICE_NAME);

        foreach ($breaches as $breach) {
            if ($REFETCH_MODE) {
                // In refetch mode, collect breaches for summary email
                $REFETCH_BREACHES[] = $breach;
                record_perimeter_alert($pdo, $breach, false); // Mark as not yet sent
                $perimeterAlerts++;
                debug_log("    BREACH COLLECTED for summary: {$breach['type']} '{$breach['perimeter']['name']}'");
            } else {
                // Normal mode: send individual email
                $emailSent = send_perimeter_alert_email($breach);
                record_perimeter_alert($pdo, $breach, $emailSent);
                $perimeterAlerts++;
                if ($emailSent) $emailsSent++;
            }
        }

        // Update previous states for next iteration
        $previousPerimeterStates = $currentStates;
    };

    if ($REFETCH_MODE && $refetchDayStart) {
        $lastInsertedPos = get_last_position($pdo, $refetchDayStart->format('Y-m-d H:i:s'), $DEVICE_ID);
        if ($lastInsertedPos) {
            debug_log("REFETCH: Last position before refetch day: ({$lastInsertedPos['lat']}, {$lastInsertedPos['lng']})");
        }
    } else {
        $lastInsertedPos = get_last_position($pdo, null, $DEVICE_ID);
    }

    // FIX: Initialize perimeter states from last known position AFTER $lastInsertedPos is set
    // This ensures that when a new day starts, we detect exit from zones where the tracker
    // was stationary the previous day
    if ($lastInsertedPos && !empty($activePerimeters)) {
        $previousPerimeterStates = get_position_perimeter_states(
            $lastInsertedPos['lat'],
            $lastInsertedPos['lng'],
            $activePerimeters
        );
        debug_log("PERIMETER INIT: initialized states from last position ({$lastInsertedPos['lat']}, {$lastInsertedPos['lng']})");

        // Log which perimeters the tracker was inside at the last known position
        foreach ($previousPerimeterStates as $pid => $isInside) {
            if ($isInside) {
                $pName = '';
                foreach ($activePerimeters as $p) {
                    if ($p['id'] === $pid) { $pName = $p['name']; break; }
                }
                debug_log("PERIMETER INIT: was INSIDE '$pName' (id=$pid) at last position");
            }
        }
    } else if (empty($lastInsertedPos)) {
        debug_log("PERIMETER INIT: no previous position - first positions will not trigger breach");
    }

    foreach ($allTs as $iso) {
        try {
            $isoObj = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            debug_log("TS $iso: SKIP - invalid timestamp");
            $skipped++; continue;
        }
        
        if ($REFETCH_MODE && $refetchDayStart && $refetchDayEnd) {
            if ($isoObj < $refetchDayStart || $isoObj > $refetchDayEnd) {
                debug_log("TS $iso: SKIP - outside refetch range");
                $skipped++;
                continue;
            }
        } else {
            if ($lastDb && $isoObj <= $lastDb) {
                debug_log("TS $iso: SKIP - already in DB");
                $skipped++; 
                continue; 
            }
        }

        debug_log("TS $iso: PROCESSING");

        // Priorita 1: GNSS
        if (isset($gnssBuckets[$iso])) {
            $lat = $gnssBuckets[$iso]['lat']; $lng = $gnssBuckets[$iso]['lng'];
            debug_log("  -> GNSS available: lat=$lat lng=$lng");
            $result = insert_position_with_hysteresis($pdo, $iso, $lat, $lng, 'gnss', $HYSTERESIS_METERS, $lastInsertedPos, null, null, $DEVICE_ID);
            if ($result['inserted']) {
                $inserted++;
                $checkPerimetersAfterInsert($lat, $lng, $iso);
            } else {
                $skipped++;
                if ($result['reason'] === 'hysteresis') $hysteresisSkips++;
                if ($result['reason'] === 'db_error') $dbErrors++;
            }
            continue;
        }

        // Priorita 2: BT iBeacon match
        $btForTs = null;
        foreach ($btBuckets as $b) {
            if ($b['iso'] === $iso) {
                $btForTs = $b['beacons'];
                break;
            }
        }

        if ($btForTs && count($btForTs) > 0) {
            debug_log("  -> BT: ".count($btForTs)." beacons available");

            $matchedLatLng = null;
            $matchedMac = null;
            foreach ($btForTs as $beacon) {
                $mac = $beacon['mac'];
                if (isset($ibeaconMap[$mac])) {
                    $matchedLatLng = $ibeaconMap[$mac];
                    $matchedMac = $mac;
                    break;
                }
            }

            if ($matchedLatLng) {
                debug_log("  -> IBEACON MATCH (BT): MAC=$matchedMac");
                $result = insert_position_with_hysteresis($pdo, $iso, $matchedLatLng[0], $matchedLatLng[1], 'ibeacon', $HYSTERESIS_METERS, $lastInsertedPos, null, null, $DEVICE_ID);
                if ($result['inserted']) {
                    $inserted++; $ibeaconHits++;
                    $checkPerimetersAfterInsert($matchedLatLng[0], $matchedLatLng[1], $iso);
                } else {
                    $skipped++;
                    if ($result['reason'] === 'hysteresis') $hysteresisSkips++;
                    if ($result['reason'] === 'db_error') $dbErrors++;
                }
                continue;
            }
        }

        // Priorita 3: Wi-Fi
        $wifiForTs = null;
        foreach ($wifiBuckets as $w) {
            if ($w['iso'] === $iso) { 
                $wifiForTs = $w['aps']; 
                break; 
            }
        }
        
        if (!$wifiForTs || count($wifiForTs) === 0) { 
            debug_log("  -> NO WIFI APs");
            $skipped++; 
            continue; 
        }

        debug_log("  -> WIFI: ".count($wifiForTs)." APs available");

        // Wi-Fi môže tiež obsahovať iBeacony (fallback)
        $matchedLatLng = null;
        foreach ($wifiForTs as $ap) {
            $mac = $ap['mac'];
            if (isset($ibeaconMap[$mac])) { 
                $matchedLatLng = $ibeaconMap[$mac]; 
                break; 
            }
        }
        
        if ($matchedLatLng) {
            debug_log("  -> IBEACON MATCH (Wi-Fi fallback)");
            $result = insert_position_with_hysteresis($pdo, $iso, $matchedLatLng[0], $matchedLatLng[1], 'ibeacon', $HYSTERESIS_METERS, $lastInsertedPos, null, null, $DEVICE_ID);
            if ($result['inserted']) {
                $inserted++; $ibeaconHits++;
                $checkPerimetersAfterInsert($matchedLatLng[0], $matchedLatLng[1], $iso);
            } else {
                $skipped++;
                if ($result['reason'] === 'hysteresis') $hysteresisSkips++;
                if ($result['reason'] === 'db_error') $dbErrors++;
            }
            continue;
        }

        // MAC cache check
        debug_log("  -> CACHE CHECK: looking up ".count($wifiForTs)." MACs");
        
        $positiveCache = null;
        $negativeCacheMacs = [];
        $unknownAps = [];
        
        foreach ($wifiForTs as $ap) {
            $mac = $ap['mac'];
            
            if (array_key_exists($mac, $macCache)) {
                if ($macCache[$mac] !== null) {
                    if ($positiveCache === null) {
                        [$lat, $lng] = $macCache[$mac];
                        $positiveCache = ['mac' => $mac, 'lat' => $lat, 'lng' => $lng];
                        debug_log("    CACHE [+] HIT: $mac");
                    }
                } else {
                    $negativeCacheMacs[] = $mac;
                    debug_log("    CACHE [-] HIT: $mac");
                }
            } else {
                $unknownAps[] = $ap;
                debug_log("    CACHE MISS: $mac");
            }
        }
        
        if ($positiveCache !== null) {
            debug_log("  -> USING CACHE: MAC=".$positiveCache['mac']);
            $allMacs = implode(',', array_map(fn($ap) => $ap['mac'], $wifiForTs));
            $primaryMac = $positiveCache['mac'];
            $result = insert_position_with_hysteresis($pdo, $iso, $positiveCache['lat'], $positiveCache['lng'], 'wifi-cache', $HYSTERESIS_METERS, $lastInsertedPos, $allMacs, $primaryMac, $DEVICE_ID);
            if ($result['inserted']) {
                $inserted++; $cacheHits++;
                $checkPerimetersAfterInsert($positiveCache['lat'], $positiveCache['lng'], $iso);
            } else {
                $skipped++;
                if ($result['reason'] === 'hysteresis') $hysteresisSkips++;
                if ($result['reason'] === 'db_error') $dbErrors++;
            }
            continue;
        }
        
        if (count($negativeCacheMacs) > 0) {
            $cachedFails += count($negativeCacheMacs);
            debug_log("  -> NEGATIVE CACHE: ".count($negativeCacheMacs)." MACs previously failed");
        }
        
        if (count($unknownAps) === 0) {
            debug_log("  -> NO UNKNOWN MACs - skipping Google API");
            $skipped++;
            continue;
        }

        // Google fallback
        $geo = google_geolocate($GKEY, $unknownAps, $WIFI_MIN_APS, $iso, $MAX_APS, $RSSI_FLOOR);
        if ($geo) {
            [$lat,$lng,$acc] = $geo;
            $allMacs = implode(',', array_map(fn($ap) => $ap['mac'], $wifiForTs));
            $result = insert_position_with_hysteresis($pdo, $iso, $lat, $lng, 'wifi-google', $HYSTERESIS_METERS, $lastInsertedPos, $allMacs, null, $DEVICE_ID);
            if ($result['inserted']) {
                $inserted++;
                $checkPerimetersAfterInsert($lat, $lng, $iso);
            } else {
                $skipped++;
                if ($result['reason'] === 'hysteresis') $hysteresisSkips++;
                if ($result['reason'] === 'db_error') $dbErrors++;
            }
            foreach ($unknownAps as $ap) {
                if (upsert_mac_location($pdo, $ap['mac'], $lat, $lng, $iso)) {
                    $macCache[$ap['mac']] = [$lat, $lng];
                }
            }
            $googled++;
        } else {
            debug_log("  -> GOOGLE FAILED - caching negative results");
            foreach ($unknownAps as $ap) {
                if (is_valid_mac($ap['mac'])) {
                    if (cache_failed_mac($pdo, $ap['mac'], $iso)) {
                        $macCache[$ap['mac']] = null;
                    }
                }
            }
            $skipped++;
        }
    }

    // ================================================================
    // BATTERY STATE & LATEST MESSAGE TIME - Update device status
    // ================================================================
    if ($deviceStatus['battery_status'] !== null || $deviceStatus['latest_message_time'] !== null || $deviceStatus['online_status'] !== null) {
        try {
            // Vytvor tabuľku ak neexistuje (používame latest_message_time ako názov stĺpca)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS device_status (
                    device_eui TEXT PRIMARY KEY,
                    battery_state INTEGER,
                    latest_message_time TEXT,
                    online_status INTEGER,
                    last_update TEXT NOT NULL,
                    CONSTRAINT battery_state_check CHECK (battery_state IS NULL OR battery_state IN (0, 1)),
                    CONSTRAINT online_status_check CHECK (online_status IS NULL OR online_status IN (0, 1))
                )
            ");
            
            // OPRAVA: Vždy aktualizuj všetky hodnoty bez COALESCE
            $stmt = $pdo->prepare("
                INSERT INTO device_status (device_eui, battery_state, latest_message_time, online_status, last_update)
                VALUES (:eui, :battery, :latest_msg_time, :online, :updated)
                ON CONFLICT(device_eui) DO UPDATE SET
                    battery_state = excluded.battery_state,
                    latest_message_time = excluded.latest_message_time,
                    online_status = excluded.online_status,
                    last_update = excluded.last_update
            ");
            $stmt->execute([
                ':eui' => $EUI,
                ':battery' => $deviceStatus['battery_status'],
                ':latest_msg_time' => $deviceStatus['latest_message_time'],
                ':online' => $deviceStatus['online_status'],
                ':updated' => (new DateTimeImmutable())->format('Y-m-d H:i:s')
            ]);
            
            $batteryText = $deviceStatus['battery_status'] === 1 ? 'good' : ($deviceStatus['battery_status'] === 0 ? 'low' : 'unknown');
            $latestMsgTime = $deviceStatus['latest_message_time'] ?? 'null';
            $onlineText = $deviceStatus['online_status'] === 1 ? 'ONLINE' : ($deviceStatus['online_status'] === 0 ? 'OFFLINE' : 'unknown');
            
            info_log("DEVICE STATUS SAVED: battery=$batteryText latest_message_time=$latestMsgTime online=$onlineText");

            // Check if battery is low and send alert if enabled (only in normal mode, not refetch)
            if (!$REFETCH_MODE && $deviceStatus['battery_status'] === 0) {
                info_log("BATTERY STATUS: LOW - checking if alert should be sent");
                if (should_send_battery_alert($pdo)) {
                    $alertSent = send_battery_alert_email($pdo, $DEVICE_NAME);
                    if ($alertSent) {
                        info_log("BATTERY ALERT: Email sent successfully");
                    }
                } else {
                    info_log("BATTERY ALERT: Skipped - alert already sent in last 24 hours");
                }
            }
        } catch (Throwable $e) {
            debug_log("DEVICE STATUS ERROR: " . $e->getMessage());
        }
    } else {
        debug_log("DEVICE STATUS: No data received from API");
    }

    $mode = $REFETCH_MODE ? "REFETCH($REFETCH_DATE)" : "NORMAL";
    info_log(sprintf(
        'SUMMARY [%s] [%s]: fetched=%d (gnss_lon=%d gnss_lat=%d wifi=%d bt=%d) | buckets: gnss=%d wifi=%d bt=%d | inserted=%d skipped=%d (hysteresis=%d db_errors=%d) | google_calls=%d ibeacon_hits=%d cache_hits=%d cached_fails=%d | perimeter_alerts=%d emails_sent=%d',
        $mode,
        $DEVICE_NAME,
        count($gnssLon) + count($gnssLat) + count($wifiRaw) + count($btRaw),
        count($gnssLon),
        count($gnssLat),
        count($wifiRaw),
        count($btRaw),
        count($gnssBuckets),
        count($wifiBuckets),
        count($btBuckets),
        $inserted,
        $skipped,
        $hysteresisSkips,
        $dbErrors,
        $googled,
        $ibeaconHits,
        $cacheHits,
        $cachedFails,
        $perimeterAlerts,
        $emailsSent
    ));

    $totalInsertedAll += $inserted;

    // Send summary email if in refetch mode and there are breaches
    if ($REFETCH_MODE && !empty($REFETCH_BREACHES)) {
        info_log("REFETCH SUMMARY: Sending summary email for " . count($REFETCH_BREACHES) . " breach(es)");
        $summaryEmailsSent = send_refetch_summary_email($REFETCH_BREACHES, $REFETCH_DATE);
        info_log("REFETCH SUMMARY: Sent $summaryEmailsSent summary email(s)");
    }

    info_log("=== DEVICE $DEVICE_NAME DONE ===");
    $LOG_DEVICE_PREFIX = '';

    } // end foreach activeDevices

    info_log("=== FETCH END (total inserted across all devices: $totalInsertedAll) ===");

} catch (Throwable $e) {
    info_log('FATAL: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
    debug_log('Stack trace: '.$e->getTraceAsString());
}
