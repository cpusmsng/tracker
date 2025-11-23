<?php
declare(strict_types=1);

// api.php â€“ jednotnÃ© API pre frontend (JSON)

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

date_default_timezone_set('Europe/Bratislava');

// (optional) config.php â€“ naÄÃ­tanie .env a helperov
$cfg = __DIR__ . '/config.php';
if (is_file($cfg)) {
    require_once $cfg;
}

if (!function_exists('db')) {
    function db(): PDO {
        // jednoduchÃ© .env
        $env = __DIR__ . '/.env';
        if (is_file($env) && is_readable($env)) {
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line !== '' && $line[0] !== '#' && strpos($line, '=') !== false) {
                    [$k,$v] = array_map('trim', explode('=', $line, 2));
                    if ($k !== '') putenv("$k=$v");
                }
            }
        }
        $path = getenv('SQLITE_PATH') ?: (__DIR__ . '/tracker_database.sqlite');
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        return $pdo;
    }
}

function respond($data, int $code = 200): void {
    http_response_code($code);
    echo is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function qparam(string $name, ?string $default=null): ?string {
    if (isset($_GET[$name])) return trim((string)$_GET[$name]);
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ($argv as $arg) if (preg_match('/^'.$name.'=(.*)$/', $arg, $m)) return $m[1];
    }
    return $default;
}
function json_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function norm_mac(string $mac): string {
    $m = strtoupper(preg_replace('/[^0-9A-F]/', '', $mac));
    return implode(':', str_split($m, 2));
}

// Helper funkcia na konverziu UTC timestamp na lokÃ¡lny Äas
function utc_to_local(string $utcTimestamp): string {
    $dt = new DateTimeImmutable($utcTimestamp, new DateTimeZone('UTC'));
    $dt = $dt->setTimezone(new DateTimeZone('Europe/Bratislava'));
    return $dt->format(DateTime::ATOM);
}

try { $pdo = db(); } catch (Throwable $e) { respond(['ok'=>false, 'error'=>'DB: '.$e->getMessage()], 500); }

$action = qparam('action', 'root');

if ($action === 'root') {
    $meters    = getenv('METERS') ?: 50;
    $minutes   = getenv('MINUTES') ?: 30;
    $precision = getenv('PRECISION') ?: 6;
    $bucketMin = getenv('BUCKET_MIN') ?: 30;
    respond([
        'ok'=>true,
        'service'=>'tracker-api',
        'meters'=>(int)$meters,
        'minutes'=>(int)$minutes,
        'precision'=>(int)$precision,
        'bucketMin'=>(int)$bucketMin
    ]);
}

if ($action === 'get_last_position') {
    $row = $pdo->query("
        SELECT id, timestamp, latitude, longitude, source
        FROM tracker_data
        ORDER BY timestamp DESC
        LIMIT 1
    ")->fetch();
    if (!$row) respond([]);
    
    respond([
        'id'        => (int)$row['id'],
        'timestamp' => utc_to_local($row['timestamp']),
        'latitude'  => (float)$row['latitude'],
        'longitude' => (float)$row['longitude'],
        'source'    => (string)$row['source'],
    ]);
}

if ($action === 'get_history') {
    // OPRAVA: PouÅ¾Ã­vateÄ¾ zadÃ¡ dÃ¡tum v lokÃ¡lnom Äase, musÃ­me ho konvertovaÅ¥ na UTC rozsah
    $localDate = qparam('date') ?: (new DateTimeImmutable('now', new DateTimeZone('Europe/Bratislava')))->format('Y-m-d');
    
    // Vytvor UTC rozsah pre danÃ½ lokÃ¡lny deÅˆ
    $startLocal = new DateTimeImmutable($localDate . ' 00:00:00', new DateTimeZone('Europe/Bratislava'));
    $endLocal = new DateTimeImmutable($localDate . ' 23:59:59', new DateTimeZone('Europe/Bratislava'));
    
    $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare("
        SELECT id, timestamp, latitude, longitude, source
        FROM tracker_data
        WHERE timestamp BETWEEN :start AND :end
        ORDER BY timestamp ASC
    ");
    $stmt->execute([':start' => $startUtc, ':end' => $endUtc]);
    
    $out = [];
    while ($r = $stmt->fetch()) {
        $out[] = [
            'id'=>(int)$r['id'],
            'timestamp'=>utc_to_local($r['timestamp']),
            'latitude'=>(float)$r['latitude'],
            'longitude'=>(float)$r['longitude'],
            'source'=>(string)$r['source'],
        ];
    }
    respond($out);
}

if ($action === 'get_history_range') {
    $since = qparam('since'); 
    $until = qparam('until');
    
    if (!$since || !$until) {
        $dtUntil = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dtSince = $dtUntil->sub(new DateInterval('P1D'));
    } else {
        // PredpokladÃ¡me Å¾e since/until sÃº v lokÃ¡lnom Äase, konvertujeme na UTC
        $dtSince = new DateTimeImmutable($since, new DateTimeZone('Europe/Bratislava'));
        $dtUntil = new DateTimeImmutable($until, new DateTimeZone('Europe/Bratislava'));
        $dtSince = $dtSince->setTimezone(new DateTimeZone('UTC'));
        $dtUntil = $dtUntil->setTimezone(new DateTimeZone('UTC'));
    }
    
    $stmt = $pdo->prepare("
        SELECT id, timestamp, latitude, longitude, source
        FROM tracker_data
        WHERE timestamp BETWEEN :since AND :until
        ORDER BY timestamp ASC
    ");
    $stmt->execute([
        ':since'=>$dtSince->format('Y-m-d H:i:s'),
        ':until'=>$dtUntil->format('Y-m-d H:i:s'),
    ]);
    
    $out = [];
    while ($r = $stmt->fetch()) {
        $out[] = [
            'id'=>(int)$r['id'],
            'timestamp'=>utc_to_local($r['timestamp']),
            'latitude'=>(float)$r['latitude'],
            'longitude'=>(float)$r['longitude'],
            'source'=>(string)$r['source'],
        ];
    }
    respond($out);
}

// NOVÃ ENDPOINT: refetch_day
if ($action === 'refetch_day' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $j = json_body();
    $date = trim((string)($j['date'] ?? ''));
    
    if ($date === '') {
        respond(['ok'=>false, 'error'=>'Missing date parameter'], 400);
    }
    
    // ValidÃ¡cia dÃ¡tumu
    try {
        $dt = new DateTimeImmutable($date);
        $dateStr = $dt->format('Y-m-d');
    } catch (Throwable $e) {
        respond(['ok'=>false, 'error'=>'Invalid date format'], 400);
    }
    
    $scriptPath = __DIR__ . '/fetch_data.php';
    $logFile = __DIR__ . '/fetch.log';
    
    if (!is_file($scriptPath)) {
        respond(['ok'=>false, 'error'=>'fetch_data.php not found'], 500);
    }
    
    // Najprv skÃºs PHP_CLI_PATH z .env (priamo bez kontrol kvÃ´li open_basedir)
    $envPhp = getenv('PHP_CLI_PATH');
    if ($envPhp && $envPhp !== '') {
        $phpBin = $envPhp;
    } else {
        // Fallback na 'php' v PATH (funguje aj s open_basedir)
        $phpBin = 'php';
    }
    
    // Spusti proces na pozadÃ­ s parametrom --refetch-date
    $cmd = sprintf(
        '%s %s --refetch-date=%s >> %s 2>&1 &',
        escapeshellarg($phpBin),
        escapeshellarg($scriptPath),
        escapeshellarg($dateStr),
        escapeshellarg($logFile)
    );
    
    // ZapÃ­Å¡ info do logu pred spustenÃ­m
    $logMsg = sprintf(
        "[%s] REFETCH REQUEST: date=%s php=%s cmd=%s\n",
        (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
        $dateStr,
        $phpBin,
        $cmd
    );
    file_put_contents($logFile, $logMsg, FILE_APPEND);
    
    // Spusti bez kontroly return code (kvÃ´li background procesu)
    exec($cmd);
    
    respond([
        'ok' => true,
        'message' => "Refetch started for date: $dateStr",
        'date' => $dateStr,
        'php_binary' => $phpBin,
        'check_log' => 'Check fetch.log for progress'
    ]);
}

if ($action === 'get_ibeacons') {
    try {
        $q = $pdo->query("SELECT id, name, mac_address, latitude, longitude
                          FROM ibeacon_locations
                          ORDER BY id ASC");
        $out = [];
        while ($r = $q->fetch()) {
            $out[] = [
                'id'=>(int)$r['id'],
                'name'=>(string)($r['name'] ?? ''),
                'mac_address'=>(string)($r['mac_address'] ?? ''),
                'latitude'=>isset($r['latitude']) ? (float)$r['latitude'] : null,
                'longitude'=>isset($r['longitude']) ? (float)$r['longitude'] : null,
            ];
        }
        respond($out);
    } catch (Throwable $e) {
        respond([]);
    }
}

if ($action === 'upsert_ibeacon' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $j = json_body();
    $name = trim((string)($j['name'] ?? ''));
    $mac  = trim((string)($j['mac']  ?? ''));
    $lat  = isset($j['latitude'])  ? (float)$j['latitude']  : null;
    $lng  = isset($j['longitude']) ? (float)$j['longitude'] : null;
    if ($name === '' || $mac === '' || $lat === null || $lng === null) {
        respond(['ok'=>false,'error'=>'Missing name/mac/lat/lng'], 400);
    }
    $mac = norm_mac($mac);
    $stmt = $pdo->prepare("
        INSERT INTO ibeacon_locations (name, mac_address, latitude, longitude)
        VALUES (:n,:m,:la,:lo)
        ON CONFLICT(mac_address) DO UPDATE SET
          name=excluded.name,
          latitude=excluded.latitude,
          longitude=excluded.longitude
    ");
    $stmt->execute([':n'=>$name, ':m'=>$mac, ':la'=>$lat, ':lo'=>$lng]);
    respond(['ok'=>true]);
}

if ($action === 'delete_ibeacon') {
    $id  = qparam('id');
    $mac = qparam('mac');
    if (!$id && !$mac) {
        $j = json_body();
        $id  = $id  ?: (isset($j['id'])  ? (string)$j['id']  : null);
        $mac = $mac ?: (isset($j['mac']) ? (string)$j['mac'] : null);
    }
    if (!$id && !$mac) respond(['ok'=>false,'error'=>'Missing id or mac'], 400);

    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM ibeacon_locations WHERE id = :id");
        $stmt->execute([':id'=>(int)$id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM ibeacon_locations WHERE mac_address = :m");
        $stmt->execute([':m'=>norm_mac($mac)]);
    }
    respond(['ok'=>true]);
}

if ($action === 'get_device_status') {
    try {
        // AUTO-MIGRATION: Skontroluj či existuje stará tabuľka so zlým názvom stĺpca
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='device_status'");
        $tableExists = $stmt->fetch();
        
        if ($tableExists) {
            // Tabuľka existuje - skontroluj stĺpce
            $stmt = $pdo->query("PRAGMA table_info(device_status)");
            $columns = $stmt->fetchAll();
            
            $hasDataUploadTime = false;
            $hasLatestMessageTime = false;
            
            foreach ($columns as $col) {
                if ($col['name'] === 'data_upload_time') $hasDataUploadTime = true;
                if ($col['name'] === 'latest_message_time') $hasLatestMessageTime = true;
            }
            
            // Ak má starý názov stĺpca, zmigruj
            if ($hasDataUploadTime && !$hasLatestMessageTime) {
                // Backup dát
                $data = $pdo->query("SELECT * FROM device_status")->fetchAll();
                
                // Recreate tabuľku s novým názvom stĺpca
                $pdo->exec("DROP TABLE device_status");
                
                $pdo->exec("
                    CREATE TABLE device_status (
                        device_eui TEXT PRIMARY KEY,
                        battery_state INTEGER,
                        latest_message_time TEXT,
                        online_status INTEGER,
                        last_update TEXT NOT NULL,
                        CONSTRAINT battery_state_check CHECK (battery_state IS NULL OR battery_state IN (0, 1)),
                        CONSTRAINT online_status_check CHECK (online_status IS NULL OR online_status IN (0, 1))
                    )
                ");
                
                // Restore dát
                if (!empty($data)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO device_status (device_eui, battery_state, latest_message_time, online_status, last_update)
                        VALUES (:eui, :battery, :msg_time, :online, :updated)
                    ");
                    
                    foreach ($data as $row) {
                        $stmt->execute([
                            ':eui' => $row['device_eui'],
                            ':battery' => $row['battery_state'],
                            ':msg_time' => $row['data_upload_time'] ?? null,
                            ':online' => $row['online_status'],
                            ':updated' => $row['last_update']
                        ]);
                    }
                }
            }
        } else {
            // Tabuľka neexistuje - vytvor ju
            $pdo->exec("
                CREATE TABLE device_status (
                    device_eui TEXT PRIMARY KEY,
                    battery_state INTEGER,
                    latest_message_time TEXT,
                    online_status INTEGER,
                    last_update TEXT NOT NULL,
                    CONSTRAINT battery_state_check CHECK (battery_state IS NULL OR battery_state IN (0, 1)),
                    CONSTRAINT online_status_check CHECK (online_status IS NULL OR online_status IN (0, 1))
                )
            ");
        }
        
        $eui = getenv('SENSECAP_DEVICE_EUI') ?: '2CF7F1C064900C0C';
        $aid = getenv('SENSECAP_ACCESS_ID') ?: '';
        $akey = getenv('SENSECAP_ACCESS_KEY') ?: '';
        
        // VÅ½DY volaj SenseCAP API pre aktuÃ¡lny status 
        // Toto je dÃ´leÅ¾itÃ© hlavne pre latest_message_time - chceme najnovÅ¡Ã­ Äas z API, nie z cache
        $apiData = null;
        if ($aid && $akey) {
            $url = 'https://sensecap.seeed.cc/openapi/view_device_running_status';
            $payload = json_encode(['device_euis' => [$eui]], JSON_UNESCAPED_SLASHES);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $aid . ':' . $akey,
                CURLOPT_TIMEOUT => 5, // Quick timeout
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json; charset=utf-8',
                    'Accept: application/json'
                ]
            ]);
            
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($body !== false && $httpCode === 200) {
                $json = json_decode($body, true);
                if (is_array($json) && ($json['code'] ?? '') === '0') {
                    $devices = $json['data'] ?? [];
                    if (!empty($devices) && is_array($devices)) {
                        $device = $devices[0] ?? null;
                        if ($device) {
                            $apiData = [
                                'battery_state' => isset($device['battery_status']) ? (int)$device['battery_status'] : null,
                                'latest_message_time' => $device['latest_message_time'] ?? null,
                                'online_status' => isset($device['online_status']) ? (int)$device['online_status'] : null
                            ];
                            
                            // UloÅ¾ do DB ako cache
                            if ($apiData['battery_state'] !== null || $apiData['latest_message_time'] !== null || $apiData['online_status'] !== null) {
                                $stmt = $pdo->prepare("
                                    INSERT INTO device_status (device_eui, battery_state, latest_message_time, online_status, last_update)
                                    VALUES (:eui, :battery, :upload_time, :online, :updated)
                                    ON CONFLICT(device_eui) DO UPDATE SET
                                        battery_state = excluded.battery_state,
                                        latest_message_time = excluded.latest_message_time,
                                        online_status = excluded.online_status,
                                        last_update = excluded.last_update
                                ");
                                $stmt->execute([
                                    ':eui' => $eui,
                                    ':battery' => $apiData['battery_state'],
                                    ':upload_time' => $apiData['latest_message_time'],
                                    ':online' => $apiData['online_status'],
                                    ':updated' => (new DateTimeImmutable())->format('Y-m-d H:i:s')
                                ]);
                            }
                        }
                    }
                }
            }
        }
        
        // Ak API volanie zlyhalo, pouÅ¾i cache z DB
        if ($apiData === null) {
            $stmt = $pdo->prepare("SELECT battery_state, latest_message_time, online_status, last_update FROM device_status WHERE device_eui = :eui");
            $stmt->execute([':eui' => $eui]);
            $row = $stmt->fetch();
            
            if ($row) {
                $apiData = [
                    'battery_state' => $row['battery_state'] !== null ? (int)$row['battery_state'] : null,
                    'latest_message_time' => $row['latest_message_time'],
                    'online_status' => $row['online_status'] !== null ? (int)$row['online_status'] : null,
                    'from_cache' => true
                ];
            }
        }
        
        if ($apiData === null) {
            respond([
                'ok' => true,
                'battery_state' => null,
                'latest_message_time' => null,
                'last_update' => null,
                'message' => 'No device data available'
            ]);
        }
        
        // Parse latest_message_time if it exists
        $dataUploadTime = null;
        if ($apiData['latest_message_time']) {
            try {
                $dt = new DateTimeImmutable($apiData['latest_message_time']);
                $dataUploadTime = $dt->format(DateTime::ATOM);
            } catch (Throwable $e) {
                $dataUploadTime = $apiData['latest_message_time'];
            }
        }
        
        respond([
            'ok' => true,
            'battery_state' => $apiData['battery_state'],
            'latest_message_time' => $dataUploadTime,
            'online_status' => $apiData['online_status'] ?? null,
            'from_cache' => $apiData['from_cache'] ?? false
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
    }
}

respond(['ok'=>false,'error'=>'unknown action'], 400);