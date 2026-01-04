<?php
declare(strict_types=1);

// api.php â€“ jednotnÃ© API pre frontend (JSON)

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

header('Content-Type: application/json; charset=utf-8');

// CORS headers - support credentials for SSO cookies
$allowedOrigins = [
    'https://tracker.bagron.eu',
    'https://bagron.eu',
    'http://localhost:8080',
    'http://localhost:8090',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    // Fallback for same-origin requests (no Origin header)
    header('Access-Control-Allow-Origin: https://tracker.bagron.eu');
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-API-Key');

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

// API authentication middleware (2-tier: Docker network, family-office token validation)
$authMiddleware = __DIR__ . '/middleware/api_auth.php';
if (is_file($authMiddleware)) {
    require_once $authMiddleware;
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

// Helper function to get log file path - same logic as fetch_data.php
function get_log_file_path(): string {
    $envPath = getenv('LOG_FILE');
    if ($envPath && $envPath !== '') {
        return $envPath;
    }
    // Docker default path
    if (is_dir('/var/log/tracker')) {
        return '/var/log/tracker/fetch.log';
    }
    // Local fallback
    return __DIR__ . '/fetch.log';
}

// ========== SSO AUTHENTICATION ==========

function isSSOEnabled(): bool {
    $enabled = getenv('SSO_ENABLED') ?: 'false';
    return strtolower($enabled) === 'true' || $enabled === '1';
}

function getAuthApiUrl(): string {
    return getenv('AUTH_API_URL') ?: 'http://family-office:3001';
}

function getLoginUrl(): string {
    return getenv('LOGIN_URL') ?: 'https://bagron.eu/login';
}

function validateSSOSession(): ?array {
    $sessionId = $_COOKIE['session'] ?? null;
    if (!$sessionId) {
        return null;
    }

    $authUrl = getAuthApiUrl() . '/api/auth/me';

    $ch = curl_init($authUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Cookie: session=' . $sessionId,
            'Accept: application/json'
        ],
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['user'])) {
        return null;
    }

    return [
        'id' => $data['user']['id'] ?? null,
        'email' => $data['user']['email'] ?? null,
        'name' => $data['user']['name'] ?? null,
        'isAdmin' => $data['isAdmin'] ?? false,
    ];
}

function ssoLogout(): void {
    $sessionId = $_COOKIE['session'] ?? null;
    if ($sessionId) {
        $authUrl = getAuthApiUrl() . '/api/auth/logout';

        $ch = curl_init($authUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Cookie: session=' . $sessionId,
                'Accept: application/json'
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // Clear the session cookie
    setcookie('session', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '.bagron.eu',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
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

// Health check endpoint for Docker
// GET /api.php?action=health or GET /api/health
if ($action === 'health') {
    try {
        // Test database connection
        $pdo->query("SELECT 1");

        // Get version from VERSION file
        $versionFile = __DIR__ . '/VERSION';
        $version = is_file($versionFile) ? trim(file_get_contents($versionFile)) : '1.0.0';

        // Get system uptime in seconds (from /proc/uptime)
        $uptime = 0;
        if (is_file('/proc/uptime')) {
            $uptimeData = file_get_contents('/proc/uptime');
            if ($uptimeData !== false) {
                $uptime = (int)explode(' ', $uptimeData)[0];
            }
        }

        respond([
            'status' => 'ok',
            'version' => $version,
            'uptime' => $uptime,
            'timestamp' => date('c'),
            'database' => 'connected'
        ]);
    } catch (Throwable $e) {
        respond([
            'status' => 'error',
            'error' => 'Database connection failed'
        ], 503);
    }
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
    $logFile = get_log_file_path();
    
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

// ========== AUTHENTICATION ==========

// SSO: Get current user
if ($action === 'auth_me') {
    if (isSSOEnabled()) {
        $sessionCookie = $_COOKIE['session'] ?? null;
        $user = validateSSOSession();
        if ($user) {
            respond([
                'ok' => true,
                'authenticated' => true,
                'method' => 'sso',
                'user' => $user
            ]);
        } else {
            respond([
                'ok' => true,
                'authenticated' => false,
                'method' => 'sso',
                'loginUrl' => getLoginUrl(),
                'debug' => [
                    'hasCookie' => $sessionCookie !== null,
                    'cookieLength' => $sessionCookie ? strlen($sessionCookie) : 0,
                    'allCookies' => array_keys($_COOKIE),
                    'authApiUrl' => getAuthApiUrl()
                ]
            ]);
        }
    } else {
        // PIN mode - return auth config info
        respond([
            'ok' => true,
            'authenticated' => false,
            'method' => 'pin'
        ]);
    }
}

// SSO: Logout
if ($action === 'auth_logout') {
    if (isSSOEnabled()) {
        ssoLogout();
        respond([
            'ok' => true,
            'message' => 'Logged out',
            'redirectUrl' => getLoginUrl()
        ]);
    } else {
        respond([
            'ok' => true,
            'message' => 'PIN mode - client should clear session'
        ]);
    }
}

// Get auth configuration
if ($action === 'auth_config') {
    respond([
        'ok' => true,
        'ssoEnabled' => isSSOEnabled(),
        'loginUrl' => isSSOEnabled() ? getLoginUrl() : null
    ]);
}

// ========== PIN SECURITY ==========

if ($action === 'verify_pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $pin = isset($j['pin']) ? (string)$j['pin'] : '';

        if ($pin === '') {
            respond(['ok' => false, 'error' => 'PIN is required'], 400);
        }

        // Načítaj PIN hash z .env
        $storedPinHash = getenv('ACCESS_PIN_HASH');

        // Ak nie je nastavený PIN hash, vytvor default (1234)
        if (!$storedPinHash || $storedPinHash === '') {
            $storedPinHash = hash('sha256', '1234');
        }

        // Over PIN
        $pinHash = hash('sha256', $pin);

        if ($pinHash === $storedPinHash) {
            respond(['ok' => true, 'message' => 'PIN verified']);
        } else {
            // Pridaj malé delay pre brute-force protection
            usleep(500000); // 500ms delay
            respond(['ok' => false, 'error' => 'Invalid PIN'], 401);
        }
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'PIN verification failed: ' . $e->getMessage()], 500);
    }
}

// ========== SETTINGS MANAGEMENT ==========

if ($action === 'get_settings') {
    try {
        $settings = [
            'hysteresis_meters' => (int)(getenv('HYSTERESIS_METERS') ?: 50),
            'hysteresis_minutes' => (int)(getenv('HYSTERESIS_MINUTES') ?: 30),
            'unique_precision' => (int)(getenv('UNIQUE_PRECISION') ?: 6),
            'unique_bucket_minutes' => (int)(getenv('UNIQUE_BUCKET_MINUTES') ?: 30),
            'mac_cache_max_age_days' => (int)(getenv('MAC_CACHE_MAX_AGE_DAYS') ?: 3600),
            'google_force' => getenv('GOOGLE_FORCE') ?: '0',
            'log_level' => strtolower(getenv('LOG_LEVEL') ?: 'info'),
            'fetch_frequency_minutes' => (int)(getenv('FETCH_FREQUENCY_MINUTES') ?: 5),
            'smart_refetch_frequency_minutes' => (int)(getenv('SMART_REFETCH_FREQUENCY_MINUTES') ?: 30),
            'smart_refetch_days' => (int)(getenv('SMART_REFETCH_DAYS') ?: 7)
        ];

        respond([
            'ok' => true,
            'data' => $settings
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to load settings: ' . $e->getMessage()], 500);
    }
}

if ($action === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();

        // Validácia vstupných dát
        $hysteresisMeters = isset($j['hysteresis_meters']) ? (int)$j['hysteresis_meters'] : null;
        $hysteresisMinutes = isset($j['hysteresis_minutes']) ? (int)$j['hysteresis_minutes'] : null;
        $uniquePrecision = isset($j['unique_precision']) ? (int)$j['unique_precision'] : null;
        $uniqueBucketMinutes = isset($j['unique_bucket_minutes']) ? (int)$j['unique_bucket_minutes'] : null;
        $macCacheMaxAgeDays = isset($j['mac_cache_max_age_days']) ? (int)$j['mac_cache_max_age_days'] : null;
        $googleForce = isset($j['google_force']) ? (string)$j['google_force'] : null;
        $logLevel = isset($j['log_level']) ? strtolower(trim((string)$j['log_level'])) : null;
        $fetchFrequencyMinutes = isset($j['fetch_frequency_minutes']) ? (int)$j['fetch_frequency_minutes'] : null;
        $smartRefetchFrequencyMinutes = isset($j['smart_refetch_frequency_minutes']) ? (int)$j['smart_refetch_frequency_minutes'] : null;
        $smartRefetchDays = isset($j['smart_refetch_days']) ? (int)$j['smart_refetch_days'] : null;

        // Validácia hraničných hodnôt
        if ($hysteresisMeters !== null && ($hysteresisMeters < 10 || $hysteresisMeters > 500)) {
            respond(['ok' => false, 'error' => 'hysteresis_meters must be between 10 and 500'], 400);
        }
        if ($hysteresisMinutes !== null && ($hysteresisMinutes < 5 || $hysteresisMinutes > 180)) {
            respond(['ok' => false, 'error' => 'hysteresis_minutes must be between 5 and 180'], 400);
        }
        if ($uniquePrecision !== null && ($uniquePrecision < 4 || $uniquePrecision > 8)) {
            respond(['ok' => false, 'error' => 'unique_precision must be between 4 and 8'], 400);
        }
        if ($uniqueBucketMinutes !== null && ($uniqueBucketMinutes < 5 || $uniqueBucketMinutes > 180)) {
            respond(['ok' => false, 'error' => 'unique_bucket_minutes must be between 5 and 180'], 400);
        }
        if ($macCacheMaxAgeDays !== null && ($macCacheMaxAgeDays < 1 || $macCacheMaxAgeDays > 7200)) {
            respond(['ok' => false, 'error' => 'mac_cache_max_age_days must be between 1 and 7200'], 400);
        }
        if ($googleForce !== null && !in_array($googleForce, ['0', '1'], true)) {
            respond(['ok' => false, 'error' => 'google_force must be "0" or "1"'], 400);
        }
        if ($logLevel !== null && !in_array($logLevel, ['error', 'info', 'debug'], true)) {
            respond(['ok' => false, 'error' => 'log_level must be "error", "info", or "debug"'], 400);
        }
        if ($fetchFrequencyMinutes !== null && ($fetchFrequencyMinutes < 1 || $fetchFrequencyMinutes > 60)) {
            respond(['ok' => false, 'error' => 'fetch_frequency_minutes must be between 1 and 60'], 400);
        }
        if ($smartRefetchFrequencyMinutes !== null && ($smartRefetchFrequencyMinutes < 5 || $smartRefetchFrequencyMinutes > 1440)) {
            respond(['ok' => false, 'error' => 'smart_refetch_frequency_minutes must be between 5 and 1440'], 400);
        }
        if ($smartRefetchDays !== null && ($smartRefetchDays < 1 || $smartRefetchDays > 30)) {
            respond(['ok' => false, 'error' => 'smart_refetch_days must be between 1 and 30'], 400);
        }

        // Načítaj aktuálny .env súbor
        $envPath = __DIR__ . '/.env';
        if (!is_file($envPath)) {
            respond(['ok' => false, 'error' => '.env file not found'], 500);
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            respond(['ok' => false, 'error' => 'Failed to read .env file'], 500);
        }

        // Mapping medzi JSON kľúčmi a ENV premennými
        $settingsMap = [
            'hysteresis_meters' => 'HYSTERESIS_METERS',
            'hysteresis_minutes' => 'HYSTERESIS_MINUTES',
            'unique_precision' => 'UNIQUE_PRECISION',
            'unique_bucket_minutes' => 'UNIQUE_BUCKET_MINUTES',
            'mac_cache_max_age_days' => 'MAC_CACHE_MAX_AGE_DAYS',
            'google_force' => 'GOOGLE_FORCE',
            'log_level' => 'LOG_LEVEL',
            'fetch_frequency_minutes' => 'FETCH_FREQUENCY_MINUTES',
            'smart_refetch_frequency_minutes' => 'SMART_REFETCH_FREQUENCY_MINUTES',
            'smart_refetch_days' => 'SMART_REFETCH_DAYS'
        ];

        $newSettings = [];
        foreach ($settingsMap as $jsonKey => $envKey) {
            if (isset($j[$jsonKey])) {
                $newSettings[$envKey] = $j[$jsonKey];
            }
        }

        // Aktualizuj riadky v .env
        $updated = false;
        $newLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Ponechaj prázdne riadky a komentáre
            if ($trimmed === '' || $trimmed[0] === '#') {
                $newLines[] = $line;
                continue;
            }

            // Parsuj KEY=VALUE
            if (strpos($line, '=') !== false) {
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                $key = trim($key);

                // Ak je to nastavenie ktoré chceme aktualizovať
                if (isset($newSettings[$key])) {
                    $newLines[] = "$key=" . $newSettings[$key];
                    unset($newSettings[$key]); // Odstráň zo zoznamu pridávaných
                    $updated = true;
                } else {
                    $newLines[] = $line;
                }
            } else {
                $newLines[] = $line;
            }
        }

        // Pridaj nastavenia ktoré ešte neboli v súbore
        foreach ($newSettings as $key => $value) {
            $newLines[] = "$key=$value";
            $updated = true;
        }

        // Zapíš späť do .env
        if ($updated) {
            $result = file_put_contents($envPath, implode("\n", $newLines) . "\n");
            if ($result === false) {
                respond(['ok' => false, 'error' => 'Failed to write .env file'], 500);
            }
        }

        respond([
            'ok' => true,
            'message' => 'Settings saved successfully'
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to save settings: ' . $e->getMessage()], 500);
    }
}

if ($action === 'reset_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $envPath = __DIR__ . '/.env';
        if (!is_file($envPath)) {
            respond(['ok' => false, 'error' => '.env file not found'], 500);
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            respond(['ok' => false, 'error' => 'Failed to read .env file'], 500);
        }

        // Default hodnoty
        $defaults = [
            'HYSTERESIS_METERS' => '50',
            'HYSTERESIS_MINUTES' => '30',
            'UNIQUE_PRECISION' => '6',
            'UNIQUE_BUCKET_MINUTES' => '30',
            'MAC_CACHE_MAX_AGE_DAYS' => '3600',
            'GOOGLE_FORCE' => '0',
            'LOG_LEVEL' => 'info',
            'SMART_REFETCH_FREQUENCY_MINUTES' => '30',
            'SMART_REFETCH_DAYS' => '7'
        ];

        // Aktualizuj riadky v .env
        $newLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Ponechaj prázdne riadky a komentáre
            if ($trimmed === '' || $trimmed[0] === '#') {
                $newLines[] = $line;
                continue;
            }

            // Parsuj KEY=VALUE
            if (strpos($line, '=') !== false) {
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                $key = trim($key);

                // Ak je to nastavenie ktoré chceme resetovať
                if (isset($defaults[$key])) {
                    $newLines[] = "$key=" . $defaults[$key];
                    unset($defaults[$key]); // Odstráň zo zoznamu pridávaných
                } else {
                    $newLines[] = $line;
                }
            } else {
                $newLines[] = $line;
            }
        }

        // Pridaj nastavenia ktoré ešte neboli v súbore
        foreach ($defaults as $key => $value) {
            $newLines[] = "$key=$value";
        }

        // Zapíš späť do .env
        $result = file_put_contents($envPath, implode("\n", $newLines) . "\n");
        if ($result === false) {
            respond(['ok' => false, 'error' => 'Failed to write .env file'], 500);
        }

        respond([
            'ok' => true,
            'message' => 'All settings reset to defaults'
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to reset settings: ' . $e->getMessage()], 500);
    }
}

// ========== POSITION EDITING ==========

// Get position detail for editing
if ($action === 'get_position_detail') {
    try {
        $id = (int)qparam('id');
        if (!$id) {
            respond(['ok' => false, 'error' => 'Position ID is required'], 400);
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, timestamp, latitude, longitude, source, raw_wifi_macs, primary_mac FROM tracker_data WHERE CAST(id AS INTEGER) = ?');
        $stmt->execute([$id]);
        $position = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$position) {
            respond(['ok' => false, 'error' => 'Position not found'], 404);
        }

        // Map to expected field names for frontend
        $result = [
            'id' => (int)$position['id'],
            'timestamp' => utc_to_local($position['timestamp']),
            'lat' => (float)$position['latitude'],
            'lng' => (float)$position['longitude'],
            'source' => $position['source'],
            'all_macs' => $position['raw_wifi_macs'],
            'primary_mac' => $position['primary_mac']
        ];

        respond([
            'ok' => true,
            'data' => $result
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to get position detail: ' . $e->getMessage()], 500);
    }
}

// Update position coordinates
if ($action === 'update_position' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();

        $id = isset($j['id']) ? (int)$j['id'] : null;
        $newLat = isset($j['lat']) ? (float)$j['lat'] : null;
        $newLng = isset($j['lng']) ? (float)$j['lng'] : null;

        if (!$id) {
            respond(['ok' => false, 'error' => 'Position ID is required'], 400);
        }
        if ($newLat === null || $newLng === null) {
            respond(['ok' => false, 'error' => 'New coordinates (lat, lng) are required'], 400);
        }

        // Validate coordinate ranges
        if ($newLat < -90 || $newLat > 90) {
            respond(['ok' => false, 'error' => 'Latitude must be between -90 and 90'], 400);
        }
        if ($newLng < -180 || $newLng > 180) {
            respond(['ok' => false, 'error' => 'Longitude must be between -180 and 180'], 400);
        }

        $pdo = db();

        // First get the current position to check it exists
        $stmt = $pdo->prepare('SELECT id, raw_wifi_macs, source FROM tracker_data WHERE CAST(id AS INTEGER) = ?');
        $stmt->execute([$id]);
        $position = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$position) {
            respond(['ok' => false, 'error' => 'Position not found'], 404);
        }

        // Update the position
        $stmt = $pdo->prepare('UPDATE tracker_data SET latitude = ?, longitude = ? WHERE CAST(id AS INTEGER) = ?');
        $stmt->execute([$newLat, $newLng, $id]);

        // If this was a WiFi position, also update the mac_locations cache
        $macAddress = $position['raw_wifi_macs'];
        if ($macAddress && strpos($position['source'], 'wifi') !== false) {
            $stmt = $pdo->prepare('UPDATE mac_locations SET lat = ?, lng = ?, updated_at = datetime(\'now\') WHERE mac = ?');
            $stmt->execute([$newLat, $newLng, $macAddress]);
        }

        respond([
            'ok' => true,
            'message' => 'Position updated successfully'
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to update position: ' . $e->getMessage()], 500);
    }
}

// Invalidate MAC address cache (remove coordinates for a MAC)
if ($action === 'invalidate_mac' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();

        $mac = isset($j['mac']) ? trim((string)$j['mac']) : null;
        $positionId = isset($j['position_id']) ? (int)$j['position_id'] : null;

        if (!$mac) {
            respond(['ok' => false, 'error' => 'MAC address is required'], 400);
        }

        $pdo = db();

        // Mark the MAC as negative in the cache (no location)
        $stmt = $pdo->prepare('UPDATE mac_locations SET lat = NULL, lng = NULL, negative = 1, updated_at = datetime(\'now\') WHERE mac = ?');
        $stmt->execute([$mac]);

        // If mac_locations didn't have this MAC, insert it as negative
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO mac_locations (mac, lat, lng, negative, created_at, updated_at) VALUES (?, NULL, NULL, 1, datetime(\'now\'), datetime(\'now\'))');
            $stmt->execute([$mac]);
        }

        // Clear coordinates from all tracker_data entries with this MAC
        $stmt = $pdo->prepare('UPDATE tracker_data SET latitude = NULL, longitude = NULL WHERE raw_wifi_macs = ?');
        $stmt->execute([$mac]);
        $affectedPositions = $stmt->rowCount();

        respond([
            'ok' => true,
            'message' => "MAC cache invalidated. Affected positions: $affectedPositions"
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to invalidate MAC: ' . $e->getMessage()], 500);
    }
}

// Get MAC address history (last 20 records where this MAC was used)
if ($action === 'get_mac_history') {
    try {
        $mac = qparam('mac');
        if (!$mac) {
            respond(['ok' => false, 'error' => 'MAC address is required'], 400);
        }

        $pdo = db();
        // Search by primary_mac (exact) OR raw_wifi_macs (LIKE) for backward compatibility
        $stmt = $pdo->prepare('
            SELECT id, timestamp, latitude, longitude, source, primary_mac, raw_wifi_macs
            FROM tracker_data
            WHERE primary_mac = ? OR raw_wifi_macs LIKE ?
            ORDER BY timestamp DESC
            LIMIT 20
        ');
        $stmt->execute([$mac, '%' . $mac . '%']);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $history = [];
        foreach ($records as $r) {
            $history[] = [
                'id' => (int)$r['id'],
                'timestamp' => utc_to_local($r['timestamp']),
                'lat' => $r['latitude'] ? (float)$r['latitude'] : null,
                'lng' => $r['longitude'] ? (float)$r['longitude'] : null,
                'source' => $r['source'],
                'primary_mac' => $r['primary_mac']
            ];
        }

        respond([
            'ok' => true,
            'data' => $history
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to get MAC history: ' . $e->getMessage()], 500);
    }
}

// ========== MAC MANAGEMENT ==========

// Get all MAC locations with filtering
if ($action === 'get_mac_locations') {
    try {
        $pdo = db();

        $type = qparam('type') ?: 'no-coords'; // no-coords, with-coords, all
        $search = qparam('search');
        $dateFrom = qparam('date_from');
        $dateTo = qparam('date_to');
        $sortBy = qparam('sort') ?: 'date';
        $sortDir = qparam('dir') ?: 'desc';

        $where = [];
        $params = [];

        if ($type === 'no-coords') {
            $where[] = '(latitude IS NULL OR longitude IS NULL)';
        } elseif ($type === 'with-coords') {
            $where[] = '(latitude IS NOT NULL AND longitude IS NOT NULL)';
        }

        if ($search) {
            $where[] = 'mac_address LIKE ?';
            $params[] = '%' . $search . '%';
        }

        if ($dateFrom) {
            $where[] = 'last_queried >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $where[] = 'last_queried <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $validSorts = ['mac' => 'mac_address', 'lat' => 'latitude', 'lng' => 'longitude', 'date' => 'last_queried'];
        $orderCol = $validSorts[$sortBy] ?? 'last_queried';
        $orderDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT mac_address, latitude, longitude, last_queried FROM mac_locations $whereClause ORDER BY $orderCol $orderDir LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($records as $r) {
            $data[] = [
                'mac' => $r['mac_address'],
                'lat' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
                'lng' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
                'last_queried' => $r['last_queried'] ? utc_to_local($r['last_queried']) : null
            ];
        }

        respond(['ok' => true, 'data' => $data]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to get MAC locations: ' . $e->getMessage()], 500);
    }
}

// Update MAC location coordinates
if ($action === 'update_mac_coordinates' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $mac = isset($j['mac']) ? trim((string)$j['mac']) : null;
        $lat = isset($j['lat']) ? (float)$j['lat'] : null;
        $lng = isset($j['lng']) ? (float)$j['lng'] : null;

        if (!$mac) {
            respond(['ok' => false, 'error' => 'MAC address is required'], 400);
        }

        $pdo = db();
        $stmt = $pdo->prepare('UPDATE mac_locations SET latitude = ?, longitude = ?, last_queried = datetime("now") WHERE mac_address = ?');
        $stmt->execute([$lat, $lng, $mac]);

        if ($stmt->rowCount() === 0) {
            // Insert if not exists
            $stmt = $pdo->prepare('INSERT INTO mac_locations (mac_address, latitude, longitude, last_queried) VALUES (?, ?, ?, datetime("now"))');
            $stmt->execute([$mac, $lat, $lng]);
        }

        respond(['ok' => true, 'message' => 'MAC coordinates updated']);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to update MAC coordinates: ' . $e->getMessage()], 500);
    }
}

// Batch refetch days for positions with specific primary_mac
if ($action === 'batch_refetch_days' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $dates = isset($j['dates']) ? (array)$j['dates'] : [];
        $mac = isset($j['mac']) ? trim((string)$j['mac']) : null;

        if (empty($dates) && !$mac) {
            respond(['ok' => false, 'error' => 'Either dates array or MAC address is required'], 400);
        }

        $pdo = db();
        $refetchedDays = [];

        // If MAC is provided, get all days where this MAC was the primary
        if ($mac && empty($dates)) {
            $stmt = $pdo->prepare("SELECT DISTINCT DATE(timestamp) as day FROM tracker_data WHERE primary_mac = ?");
            $stmt->execute([$mac]);
            while ($r = $stmt->fetch()) {
                $dates[] = $r['day'];
            }
        }

        // Delete positions for these days and queue refetch
        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

            // Delete positions for this day
            $stmt = $pdo->prepare("DELETE FROM tracker_data WHERE DATE(timestamp) = ?");
            $stmt->execute([$date]);
            $deleted = $stmt->rowCount();

            // Insert into refetch queue (if table exists)
            try {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO refetch_state (date, status, reason) VALUES (?, 'pending', 'manual_mac_invalidation')");
                $stmt->execute([$date]);
            } catch (Throwable $e) {
                // refetch_state table might not exist, ignore
            }

            $refetchedDays[] = ['date' => $date, 'deleted' => $deleted];
        }

        respond([
            'ok' => true,
            'message' => 'Days queued for refetch',
            'days' => $refetchedDays
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to batch refetch: ' . $e->getMessage()], 500);
    }
}

// ========== LOG VIEWER ==========

if ($action === 'get_logs') {
    try {
        $logFile = get_log_file_path();
        $limit = min((int)(qparam('limit') ?: 200), 2000);
        $offset = (int)(qparam('offset') ?: 0);
        $level = qparam('level'); // error, info, debug, or null for all
        $search = qparam('search');
        $order = qparam('order') ?: 'desc'; // asc or desc (default newest first)

        if (!is_file($logFile)) {
            respond([
                'ok' => true,
                'data' => [
                    'logs' => [],
                    'total' => 0,
                    'file' => basename($logFile),
                    'file_exists' => false,
                    'tried_path' => $logFile,
                    'env_log_file' => getenv('LOG_FILE') ?: 'not set'
                ]
            ]);
        }

        // Read file and parse lines
        $allLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($allLines === false) {
            respond(['ok' => false, 'error' => 'Failed to read log file'], 500);
        }

        $totalLinesRead = count($allLines);

        // Parse log lines with format: [timestamp] [LEVEL] message
        $parsedLogs = [];
        $unmatchedLines = [];
        // Updated pattern to handle timezone offsets (both + and -)
        $logPattern = '/^\[([\d\-T:+\-]+)\]\s*\[([A-Z]+)\]\s*(.*)$/';

        foreach ($allLines as $lineNum => $line) {
            if (preg_match($logPattern, $line, $matches)) {
                $logEntry = [
                    'timestamp' => $matches[1],
                    'level' => strtolower($matches[2]),
                    'message' => $matches[3]
                ];

                // Filter by level if specified
                if ($level !== null && $logEntry['level'] !== strtolower($level)) {
                    continue;
                }

                // Filter by search term if specified
                if ($search !== null && $search !== '') {
                    if (stripos($logEntry['message'], $search) === false &&
                        stripos($logEntry['timestamp'], $search) === false) {
                        continue;
                    }
                }

                $parsedLogs[] = $logEntry;
            } else if (count($unmatchedLines) < 5 && trim($line) !== '') {
                // Collect a few unmatched lines for debugging
                $unmatchedLines[] = ['line' => $lineNum + 1, 'content' => substr($line, 0, 150)];
            }
        }

        $total = count($parsedLogs);

        // Sort by timestamp
        usort($parsedLogs, function($a, $b) use ($order) {
            $cmp = strcmp($a['timestamp'], $b['timestamp']);
            return $order === 'desc' ? -$cmp : $cmp;
        });

        // Apply pagination
        $paginatedLogs = array_slice($parsedLogs, $offset, $limit);

        // Get newest and oldest timestamps for debugging
        $newestTs = null;
        $oldestTs = null;
        if (!empty($parsedLogs)) {
            $timestamps = array_column($parsedLogs, 'timestamp');
            sort($timestamps);
            $oldestTs = $timestamps[0] ?? null;
            $newestTs = $timestamps[count($timestamps) - 1] ?? null;
        }

        respond([
            'ok' => true,
            'data' => [
                'logs' => $paginatedLogs,
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit,
                'file' => basename($logFile),
                'file_path' => $logFile,
                'file_exists' => true,
                'file_size' => filesize($logFile),
                'debug' => [
                    'total_lines_read' => $totalLinesRead,
                    'lines_matched' => count($parsedLogs) + ($level !== null || $search !== null ? 0 : 0), // before filtering
                    'lines_after_filter' => $total,
                    'unmatched_samples' => $unmatchedLines,
                    'newest_timestamp' => $newestTs,
                    'oldest_timestamp' => $oldestTs
                ]
            ]
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to read logs: ' . $e->getMessage()], 500);
    }
}

if ($action === 'get_log_stats') {
    try {
        $logFile = get_log_file_path();
        $days = min((int)(qparam('days') ?: 7), 90);

        if (!is_file($logFile)) {
            respond([
                'ok' => true,
                'data' => [
                    'file_exists' => false,
                    'counts' => ['error' => 0, 'info' => 0, 'debug' => 0],
                    'google_api_calls' => 0,
                    'positions_inserted' => 0,
                    'perimeter_alerts' => 0,
                    'fetch_runs' => 0
                ]
            ]);
        }

        $allLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($allLines === false) {
            respond(['ok' => false, 'error' => 'Failed to read log file'], 500);
        }

        // Calculate cutoff date for filtering
        $cutoffDate = (new DateTimeImmutable())->sub(new DateInterval("P{$days}D"))->format('Y-m-d');

        $stats = [
            'counts' => ['error' => 0, 'info' => 0, 'debug' => 0],
            'google_api_calls' => 0,
            'google_api_success' => 0,
            'google_api_failed' => 0,
            'positions_inserted' => 0,
            'cache_hits' => 0,
            'ibeacon_hits' => 0,
            'perimeter_alerts' => 0,
            'fetch_runs' => 0,
            'total_lines' => count($allLines),
            'lines_in_range' => 0
        ];

        $logPattern = '/^\[([\d\-T:+]+)\]\s*\[([A-Z]+)\]\s*(.*)$/';

        foreach ($allLines as $line) {
            if (preg_match($logPattern, $line, $matches)) {
                $timestamp = $matches[1];
                $level = strtolower($matches[2]);
                $message = $matches[3];

                // Filter by date range
                $lineDate = substr($timestamp, 0, 10);
                if ($lineDate < $cutoffDate) {
                    continue;
                }

                $stats['lines_in_range']++;

                // Count by level
                if (isset($stats['counts'][$level])) {
                    $stats['counts'][$level]++;
                }

                // Count specific events
                if (strpos($message, 'GOOGLE REQUEST:') !== false) {
                    $stats['google_api_calls']++;
                }
                if (strpos($message, 'GOOGLE SUCCESS:') !== false) {
                    $stats['google_api_success']++;
                }
                if (strpos($message, 'GOOGLE ERROR:') !== false || strpos($message, 'GOOGLE FAILED') !== false) {
                    $stats['google_api_failed']++;
                }
                if (strpos($message, 'INSERT SUCCESS:') !== false) {
                    $stats['positions_inserted']++;
                }
                if (strpos($message, 'USING CACHE:') !== false || strpos($message, 'CACHE [+] HIT:') !== false) {
                    $stats['cache_hits']++;
                }
                if (strpos($message, 'IBEACON MATCH') !== false) {
                    $stats['ibeacon_hits']++;
                }
                if (strpos($message, 'PERIMETER BREACH DETECTED:') !== false) {
                    $stats['perimeter_alerts']++;
                }
                if ($message === '=== FETCH START ===') {
                    $stats['fetch_runs']++;
                }
            }
        }

        $stats['file_exists'] = true;
        $stats['file_size'] = filesize($logFile);
        $stats['days_filter'] = $days;
        $stats['cutoff_date'] = $cutoffDate;

        respond([
            'ok' => true,
            'data' => $stats
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to get log stats: ' . $e->getMessage()], 500);
    }
}

if ($action === 'clear_logs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $logFile = get_log_file_path();

        if (!is_file($logFile)) {
            respond(['ok' => true, 'message' => 'Log file does not exist']);
        }

        // Backup old log with timestamp
        $backupFile = $logFile . '.' . date('Y-m-d_H-i-s') . '.bak';
        if (!copy($logFile, $backupFile)) {
            respond(['ok' => false, 'error' => 'Failed to backup log file'], 500);
        }

        // Clear the log file
        if (file_put_contents($logFile, '') === false) {
            respond(['ok' => false, 'error' => 'Failed to clear log file'], 500);
        }

        respond([
            'ok' => true,
            'message' => 'Log file cleared',
            'backup' => basename($backupFile)
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to clear logs: ' . $e->getMessage()], 500);
    }
}

// ========== PERIMETER ZONES MANAGEMENT ==========

// Create perimeters table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS perimeters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            polygon TEXT NOT NULL,
            alert_on_enter INTEGER DEFAULT 1,
            alert_on_exit INTEGER DEFAULT 1,
            notification_email TEXT,
            is_active INTEGER DEFAULT 1,
            color TEXT DEFAULT '#ff6b6b',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");

    // Create perimeter_emails table for multiple emails per perimeter
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS perimeter_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            perimeter_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            alert_on_enter INTEGER DEFAULT 1,
            alert_on_exit INTEGER DEFAULT 1,
            FOREIGN KEY (perimeter_id) REFERENCES perimeters(id) ON DELETE CASCADE
        )
    ");

    // Create perimeter_alerts table for tracking sent notifications
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS perimeter_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            perimeter_id INTEGER NOT NULL,
            alert_type TEXT NOT NULL,
            position_id INTEGER,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            sent_at TEXT NOT NULL,
            email_sent INTEGER DEFAULT 0,
            FOREIGN KEY (perimeter_id) REFERENCES perimeters(id) ON DELETE CASCADE
        )
    ");
} catch (Throwable $e) {
    // Tables might already exist
}

if ($action === 'get_perimeters') {
    try {
        $q = $pdo->query("SELECT id, name, polygon, alert_on_enter, alert_on_exit, notification_email, is_active, color, created_at, updated_at
                          FROM perimeters
                          ORDER BY id ASC");
        $out = [];
        while ($r = $q->fetch()) {
            $perimeterId = (int)$r['id'];

            // Get emails for this perimeter
            $emailStmt = $pdo->prepare("SELECT id, email, alert_on_enter, alert_on_exit FROM perimeter_emails WHERE perimeter_id = :pid ORDER BY id ASC");
            $emailStmt->execute([':pid' => $perimeterId]);
            $emails = [];
            while ($e = $emailStmt->fetch()) {
                $emails[] = [
                    'id' => (int)$e['id'],
                    'email' => $e['email'],
                    'alert_on_enter' => (bool)$e['alert_on_enter'],
                    'alert_on_exit' => (bool)$e['alert_on_exit']
                ];
            }

            // Backwards compatibility: if no emails in new table but has notification_email, migrate it
            if (empty($emails) && !empty($r['notification_email'])) {
                $emails[] = [
                    'id' => null,
                    'email' => $r['notification_email'],
                    'alert_on_enter' => (bool)$r['alert_on_enter'],
                    'alert_on_exit' => (bool)$r['alert_on_exit']
                ];
            }

            $out[] = [
                'id' => $perimeterId,
                'name' => (string)$r['name'],
                'polygon' => json_decode($r['polygon'], true),
                'alert_on_enter' => (bool)$r['alert_on_enter'],
                'alert_on_exit' => (bool)$r['alert_on_exit'],
                'notification_email' => $r['notification_email'], // Keep for backwards compatibility
                'emails' => $emails,
                'is_active' => (bool)$r['is_active'],
                'color' => $r['color'] ?? '#ff6b6b',
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at']
            ];
        }
        respond(['ok' => true, 'data' => $out]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'save_perimeter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $id = isset($j['id']) ? (int)$j['id'] : null;
        $name = trim((string)($j['name'] ?? ''));
        $polygon = $j['polygon'] ?? [];
        $alertOnEnter = isset($j['alert_on_enter']) ? (int)(bool)$j['alert_on_enter'] : 1;
        $alertOnExit = isset($j['alert_on_exit']) ? (int)(bool)$j['alert_on_exit'] : 1;
        $notificationEmail = trim((string)($j['notification_email'] ?? ''));
        $emails = $j['emails'] ?? []; // New: array of {email, alert_on_enter, alert_on_exit}
        $isActive = isset($j['is_active']) ? (int)(bool)$j['is_active'] : 1;
        $color = trim((string)($j['color'] ?? '#ff6b6b'));

        if ($name === '') {
            respond(['ok' => false, 'error' => 'Názov perimetra je povinný'], 400);
        }
        if (!is_array($polygon) || count($polygon) < 3) {
            respond(['ok' => false, 'error' => 'Perimeter musí mať aspoň 3 body'], 400);
        }

        // Validate emails array
        foreach ($emails as $idx => $emailEntry) {
            $em = trim($emailEntry['email'] ?? '');
            if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                respond(['ok' => false, 'error' => 'Neplatná e-mailová adresa: ' . $em], 400);
            }
        }

        $polygonJson = json_encode($polygon);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $pdo->beginTransaction();

        if ($id) {
            // Update existing
            $stmt = $pdo->prepare("
                UPDATE perimeters SET
                    name = :name,
                    polygon = :polygon,
                    alert_on_enter = :enter,
                    alert_on_exit = :exit,
                    notification_email = :email,
                    is_active = :active,
                    color = :color,
                    updated_at = :updated
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':polygon' => $polygonJson,
                ':enter' => $alertOnEnter,
                ':exit' => $alertOnExit,
                ':email' => $notificationEmail ?: null,
                ':active' => $isActive,
                ':color' => $color,
                ':updated' => $now
            ]);
            $perimeterId = $id;
        } else {
            // Insert new
            $stmt = $pdo->prepare("
                INSERT INTO perimeters (name, polygon, alert_on_enter, alert_on_exit, notification_email, is_active, color, created_at, updated_at)
                VALUES (:name, :polygon, :enter, :exit, :email, :active, :color, :created, :updated)
            ");
            $stmt->execute([
                ':name' => $name,
                ':polygon' => $polygonJson,
                ':enter' => $alertOnEnter,
                ':exit' => $alertOnExit,
                ':email' => $notificationEmail ?: null,
                ':active' => $isActive,
                ':color' => $color,
                ':created' => $now,
                ':updated' => $now
            ]);
            $perimeterId = (int)$pdo->lastInsertId();
        }

        // Update emails: delete old, insert new
        $pdo->prepare("DELETE FROM perimeter_emails WHERE perimeter_id = :pid")->execute([':pid' => $perimeterId]);

        $emailInsert = $pdo->prepare("
            INSERT INTO perimeter_emails (perimeter_id, email, alert_on_enter, alert_on_exit)
            VALUES (:pid, :email, :enter, :exit)
        ");
        foreach ($emails as $emailEntry) {
            $em = trim($emailEntry['email'] ?? '');
            if ($em !== '') {
                $emailInsert->execute([
                    ':pid' => $perimeterId,
                    ':email' => $em,
                    ':enter' => isset($emailEntry['alert_on_enter']) ? (int)(bool)$emailEntry['alert_on_enter'] : 1,
                    ':exit' => isset($emailEntry['alert_on_exit']) ? (int)(bool)$emailEntry['alert_on_exit'] : 1
                ]);
            }
        }

        $pdo->commit();

        respond(['ok' => true, 'message' => $id ? 'Perimeter aktualizovaný' : 'Perimeter vytvorený', 'id' => $perimeterId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'delete_perimeter') {
    try {
        $id = qparam('id');
        if (!$id) {
            $j = json_body();
            $id = isset($j['id']) ? (int)$j['id'] : null;
        }
        if (!$id) {
            respond(['ok' => false, 'error' => 'Missing perimeter id'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM perimeters WHERE id = :id");
        $stmt->execute([':id' => (int)$id]);

        // Also delete related alerts
        $stmt = $pdo->prepare("DELETE FROM perimeter_alerts WHERE perimeter_id = :id");
        $stmt->execute([':id' => (int)$id]);

        respond(['ok' => true, 'message' => 'Perimeter zmazaný']);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'toggle_perimeter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $id = isset($j['id']) ? (int)$j['id'] : null;
        $isActive = isset($j['is_active']) ? (int)(bool)$j['is_active'] : 0;

        if (!$id) {
            respond(['ok' => false, 'error' => 'Missing perimeter id'], 400);
        }

        $stmt = $pdo->prepare("UPDATE perimeters SET is_active = :active, updated_at = :updated WHERE id = :id");
        $stmt->execute([
            ':id' => $id,
            ':active' => $isActive,
            ':updated' => (new DateTimeImmutable())->format('Y-m-d H:i:s')
        ]);

        respond(['ok' => true, 'message' => $isActive ? 'Perimeter aktivovaný' : 'Perimeter deaktivovaný']);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'get_perimeter_alerts') {
    try {
        $perimeterId = qparam('perimeter_id');
        $limit = (int)(qparam('limit') ?: 50);

        $sql = "SELECT pa.*, p.name as perimeter_name
                FROM perimeter_alerts pa
                LEFT JOIN perimeters p ON pa.perimeter_id = p.id";

        if ($perimeterId) {
            $sql .= " WHERE pa.perimeter_id = :pid";
        }
        $sql .= " ORDER BY pa.sent_at DESC LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        if ($perimeterId) {
            $stmt->bindValue(':pid', (int)$perimeterId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        while ($r = $stmt->fetch()) {
            $out[] = [
                'id' => (int)$r['id'],
                'perimeter_id' => (int)$r['perimeter_id'],
                'perimeter_name' => $r['perimeter_name'],
                'alert_type' => $r['alert_type'],
                'latitude' => (float)$r['latitude'],
                'longitude' => (float)$r['longitude'],
                'sent_at' => $r['sent_at'],
                'email_sent' => (bool)$r['email_sent']
            ];
        }
        respond(['ok' => true, 'data' => $out]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// Check position against perimeters (for manual testing)
if ($action === 'check_perimeter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $lat = isset($j['latitude']) ? (float)$j['latitude'] : null;
        $lng = isset($j['longitude']) ? (float)$j['longitude'] : null;

        if ($lat === null || $lng === null) {
            respond(['ok' => false, 'error' => 'Missing latitude or longitude'], 400);
        }

        // Point-in-polygon algorithm (ray casting)
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

        $stmt = $pdo->query("SELECT id, name, polygon, alert_on_enter, alert_on_exit FROM perimeters WHERE is_active = 1");
        $results = [];

        while ($r = $stmt->fetch()) {
            $polygon = json_decode($r['polygon'], true);
            $isInside = point_in_polygon($lat, $lng, $polygon);
            $results[] = [
                'perimeter_id' => (int)$r['id'],
                'perimeter_name' => $r['name'],
                'is_inside' => $isInside,
                'alert_on_enter' => (bool)$r['alert_on_enter'],
                'alert_on_exit' => (bool)$r['alert_on_exit']
            ];
        }

        respond(['ok' => true, 'data' => $results]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// Get email settings for perimeter alerts
if ($action === 'get_perimeter_email_settings') {
    try {
        $settings = [
            'email_service_url' => getenv('EMAIL_SERVICE_URL') ?: 'http://email-service:3004/send',
            'email_api_key_set' => (getenv('EMAIL_API_KEY') !== false && getenv('EMAIL_API_KEY') !== ''),
            'default_from_email' => getenv('EMAIL_FROM') ?: 'tracker@bagron.eu',
            'default_from_name' => getenv('EMAIL_FROM_NAME') ?: 'Family Tracker'
        ];
        respond(['ok' => true, 'data' => $settings]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// Test email sending
if ($action === 'test_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $testEmail = trim($j['email'] ?? '');

        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            respond(['ok' => false, 'error' => 'Neplatná e-mailová adresa'], 400);
        }

        $emailServiceUrl = getenv('EMAIL_SERVICE_URL') ?: 'http://email-service:3004/send';
        // Append /send if URL doesn't end with it
        if (!str_ends_with($emailServiceUrl, '/send')) {
            $emailServiceUrl = rtrim($emailServiceUrl, '/') . '/send';
        }
        $apiKeyRaw = getenv('EMAIL_API_KEY');
        $apiKey = $apiKeyRaw !== false ? $apiKeyRaw : '';
        $fromEmail = getenv('EMAIL_FROM') ?: 'tracker@bagron.eu';
        $fromName = getenv('EMAIL_FROM_NAME') ?: 'Family Tracker';

        // Debug: show if API key is being read
        $apiKeyDebug = $apiKeyRaw === false ? 'NOT_SET' : (empty($apiKey) ? 'EMPTY' : 'SET(' . strlen($apiKey) . ' chars)');

        if (!function_exists('curl_init')) {
            respond(['ok' => false, 'error' => 'PHP curl extension nie je nainštalovaná'], 500);
        }

        $htmlBody = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: Arial, sans-serif; background-color: #f5f5f5; padding: 20px; }
                .container { max-width: 500px; margin: 0 auto; background: white; border-radius: 8px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                h1 { color: #4CAF50; margin-top: 0; }
                p { color: #333; line-height: 1.6; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>✓ Test úspešný!</h1>
                <p>Toto je testovací e-mail z Family Tracker aplikácie.</p>
                <p>Ak vidíte túto správu, e-mailové notifikácie sú správne nakonfigurované.</p>
                <p><strong>Čas odoslania:</strong> ' . date('d.m.Y H:i:s') . '</p>
                <div class="footer">
                    Family Tracker - Perimeter Alert System
                </div>
            </div>
        </body>
        </html>';

        $payload = [
            'to_email' => $testEmail,
            'subject' => 'Test e-mailovej služby - Family Tracker',
            'html_body' => $htmlBody,
            'from_email' => $fromEmail,
            'from_name' => $fromName
        ];

        // Only include api_key if configured
        if (!empty($apiKey)) {
            $payload['api_key'] = $apiKey;
        }

        $ch = curl_init($emailServiceUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            respond(['ok' => false, 'error' => 'Chyba pripojenia k email službe: ' . $curlError, 'debug' => ['url' => $emailServiceUrl]], 500);
        }

        $responseData = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            respond(['ok' => true, 'message' => 'Testovací e-mail bol odoslaný na ' . $testEmail, 'debug' => ['http_code' => $httpCode, 'response' => $responseData, 'api_key_status' => $apiKeyDebug]]);
        } else {
            respond(['ok' => false, 'error' => 'Email služba vrátila chybu (HTTP ' . $httpCode . ')', 'debug' => ['http_code' => $httpCode, 'response' => $responseData, 'url' => $emailServiceUrl, 'api_key_status' => $apiKeyDebug]], 500);
        }

    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// ========== N8N / EXTERNAL API ENDPOINTS ==========
// These endpoints can be used by n8n workflows to read data from the tracker
// 2-tier authentication: Docker network → Family-office validation

/**
 * Verify API key using 2-tier authentication system
 *
 * Flow:
 * 1. TRUST_DOCKER_NETWORK=true && IP is 172.x.x.x / 10.x.x.x? → Allow (internal services)
 * 2. Validate via family-office POST /api/admin/validate-key → Allow if valid
 *
 * Falls back to legacy N8N_API_KEY check for backwards compatibility
 *
 * @return bool True if authenticated
 */
function verify_n8n_api_key(): bool {
    // Use new 2-tier authentication if middleware is loaded
    if (function_exists('authenticateApiRequest')) {
        $auth = authenticateApiRequest();
        if ($auth !== null) {
            // Store auth info for access in endpoint handlers
            $GLOBALS['api_auth'] = $auth;
            return true;
        }
        return false;
    }

    // Legacy fallback: N8N_API_KEY
    $apiKey = getenv('N8N_API_KEY') ?: '';
    if (empty($apiKey)) {
        // No API key configured = public access (be careful!)
        return true;
    }

    // Check header first, then query param
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    return hash_equals($apiKey, $providedKey);
}

// GET /api.php?action=n8n_status
// Returns: current device status, last position, battery info
if ($action === 'n8n_status') {
    if (!verify_n8n_api_key()) {
        respond(['ok' => false, 'error' => 'Invalid or missing API key'], 401);
    }

    try {
        // Get device status
        $deviceStatus = null;
        $stmt = $pdo->query("SELECT * FROM device_status ORDER BY last_update DESC LIMIT 1");
        if ($r = $stmt->fetch()) {
            $deviceStatus = [
                'device_eui' => $r['device_eui'],
                'battery_status' => $r['battery_status'] === 1 ? 'good' : ($r['battery_status'] === 0 ? 'low' : 'unknown'),
                'online_status' => $r['online_status'] === 1 ? 'online' : 'offline',
                'latest_message_time' => $r['latest_message_time'],
                'last_update' => $r['last_update']
            ];
        }

        // Get last position
        $lastPosition = null;
        $stmt = $pdo->query("SELECT * FROM tracker_data ORDER BY timestamp DESC LIMIT 1");
        if ($r = $stmt->fetch()) {
            $lastPosition = [
                'latitude' => (float)$r['latitude'],
                'longitude' => (float)$r['longitude'],
                'timestamp' => $r['timestamp'],
                'source' => $r['source'],
                'maps_url' => "https://www.google.com/maps?q={$r['latitude']},{$r['longitude']}"
            ];
        }

        respond([
            'ok' => true,
            'data' => [
                'device' => $deviceStatus,
                'last_position' => $lastPosition,
                'generated_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP')
            ]
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// GET /api.php?action=n8n_positions&date=YYYY-MM-DD&limit=100
// Returns: positions for a specific date or recent positions
if ($action === 'n8n_positions') {
    if (!verify_n8n_api_key()) {
        respond(['ok' => false, 'error' => 'Invalid or missing API key'], 401);
    }

    try {
        $date = qparam('date');
        $limit = min((int)(qparam('limit') ?: 100), 1000);

        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            // Get positions for specific date
            $stmt = $pdo->prepare("
                SELECT id, timestamp, latitude, longitude, source
                FROM tracker_data
                WHERE DATE(timestamp) = :date
                ORDER BY timestamp ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':date', $date, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            // Get recent positions
            $stmt = $pdo->prepare("
                SELECT id, timestamp, latitude, longitude, source
                FROM tracker_data
                ORDER BY timestamp DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        $positions = [];
        while ($r = $stmt->fetch()) {
            $positions[] = [
                'id' => (int)$r['id'],
                'timestamp' => $r['timestamp'],
                'latitude' => (float)$r['latitude'],
                'longitude' => (float)$r['longitude'],
                'source' => $r['source'],
                'maps_url' => "https://www.google.com/maps?q={$r['latitude']},{$r['longitude']}"
            ];
        }

        respond([
            'ok' => true,
            'data' => [
                'positions' => $positions,
                'count' => count($positions),
                'date_filter' => $date ?: null,
                'generated_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP')
            ]
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// GET /api.php?action=n8n_perimeters
// Returns: all perimeters with current position status (inside/outside)
if ($action === 'n8n_perimeters') {
    if (!verify_n8n_api_key()) {
        respond(['ok' => false, 'error' => 'Invalid or missing API key'], 401);
    }

    try {
        // Get last position
        $lastPos = null;
        $stmt = $pdo->query("SELECT latitude, longitude, timestamp FROM tracker_data ORDER BY timestamp DESC LIMIT 1");
        if ($r = $stmt->fetch()) {
            $lastPos = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 'timestamp' => $r['timestamp']];
        }

        // Get perimeters
        $stmt = $pdo->query("SELECT id, name, polygon, is_active, color FROM perimeters ORDER BY id ASC");
        $perimeters = [];
        while ($r = $stmt->fetch()) {
            $polygon = json_decode($r['polygon'], true);
            $isInside = false;

            // Check if current position is inside polygon
            if ($lastPos && $r['is_active'] && is_array($polygon) && count($polygon) >= 3) {
                $isInside = point_in_polygon_simple($lastPos['lat'], $lastPos['lng'], $polygon);
            }

            // Get email count
            $emailStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM perimeter_emails WHERE perimeter_id = :pid");
            $emailStmt->execute([':pid' => $r['id']]);
            $emailCount = (int)$emailStmt->fetchColumn();

            $perimeters[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'is_active' => (bool)$r['is_active'],
                'color' => $r['color'],
                'point_count' => is_array($polygon) ? count($polygon) : 0,
                'email_count' => $emailCount,
                'current_status' => $lastPos ? ($isInside ? 'inside' : 'outside') : 'unknown'
            ];
        }

        respond([
            'ok' => true,
            'data' => [
                'perimeters' => $perimeters,
                'last_position' => $lastPos,
                'generated_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP')
            ]
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// GET /api.php?action=n8n_alerts&limit=50&days=7
// Returns: recent perimeter alerts
if ($action === 'n8n_alerts') {
    if (!verify_n8n_api_key()) {
        respond(['ok' => false, 'error' => 'Invalid or missing API key'], 401);
    }

    try {
        $limit = min((int)(qparam('limit') ?: 50), 500);
        $days = min((int)(qparam('days') ?: 7), 90);

        $stmt = $pdo->prepare("
            SELECT pa.id, pa.perimeter_id, p.name as perimeter_name, pa.alert_type,
                   pa.latitude, pa.longitude, pa.sent_at, pa.email_sent
            FROM perimeter_alerts pa
            LEFT JOIN perimeters p ON pa.perimeter_id = p.id
            WHERE pa.sent_at >= datetime('now', '-' || :days || ' days')
            ORDER BY pa.sent_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $alerts = [];
        while ($r = $stmt->fetch()) {
            $alerts[] = [
                'id' => (int)$r['id'],
                'perimeter_id' => (int)$r['perimeter_id'],
                'perimeter_name' => $r['perimeter_name'],
                'alert_type' => $r['alert_type'],
                'latitude' => (float)$r['latitude'],
                'longitude' => (float)$r['longitude'],
                'timestamp' => $r['sent_at'],
                'email_sent' => (bool)$r['email_sent'],
                'maps_url' => "https://www.google.com/maps?q={$r['latitude']},{$r['longitude']}"
            ];
        }

        respond([
            'ok' => true,
            'data' => [
                'alerts' => $alerts,
                'count' => count($alerts),
                'days_filter' => $days,
                'generated_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP')
            ]
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// GET /api.php?action=n8n_events&type=entered|exited|all&limit=20&since=YYYY-MM-DDTHH:MM:SS
// Returns: perimeter entry/exit events for webhooks and automation
if ($action === 'n8n_events') {
    if (!verify_n8n_api_key()) {
        respond(['ok' => false, 'error' => 'Invalid or missing API key'], 401);
    }

    try {
        $type = qparam('type') ?: 'all'; // entered, exited, all
        $limit = min((int)(qparam('limit') ?: 20), 100);
        $since = qparam('since'); // ISO datetime string

        $sql = "
            SELECT pa.id, pa.perimeter_id, p.name as perimeter_name, pa.alert_type,
                   pa.latitude, pa.longitude, pa.sent_at, pa.email_sent
            FROM perimeter_alerts pa
            LEFT JOIN perimeters p ON pa.perimeter_id = p.id
            WHERE 1=1
        ";
        $params = [];

        // Filter by event type
        if ($type === 'entered') {
            $sql .= " AND pa.alert_type = 'entered'";
        } elseif ($type === 'exited') {
            $sql .= " AND pa.alert_type = 'exited'";
        }

        // Filter by time (for polling-based triggers)
        if ($since) {
            $sql .= " AND pa.sent_at > :since";
            $params[':since'] = $since;
        }

        $sql .= " ORDER BY pa.sent_at DESC LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $events = [];
        while ($r = $stmt->fetch()) {
            $events[] = [
                'event_id' => (int)$r['id'],
                'event_type' => $r['alert_type'], // 'entered' or 'exited'
                'perimeter_id' => (int)$r['perimeter_id'],
                'perimeter_name' => $r['perimeter_name'],
                'location' => [
                    'latitude' => (float)$r['latitude'],
                    'longitude' => (float)$r['longitude'],
                    'maps_url' => "https://www.google.com/maps?q={$r['latitude']},{$r['longitude']}"
                ],
                'timestamp' => $r['sent_at'],
                'email_sent' => (bool)$r['email_sent']
            ];
        }

        // Get the newest event timestamp for "since" parameter in next poll
        $latestTimestamp = !empty($events) ? $events[0]['timestamp'] : null;

        respond([
            'ok' => true,
            'data' => [
                'events' => $events,
                'count' => count($events),
                'latest_timestamp' => $latestTimestamp,
                'filter' => [
                    'type' => $type,
                    'since' => $since,
                    'limit' => $limit
                ],
                'generated_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP')
            ]
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// Helper function for simple point-in-polygon check (for n8n endpoints)
function point_in_polygon_simple(float $lat, float $lng, array $polygon): bool {
    $n = count($polygon);
    if ($n < 3) return false;

    $inside = false;
    $j = $n - 1;

    for ($i = 0; $i < $n; $j = $i++) {
        $yi = $polygon[$i]['lat'];
        $xi = $polygon[$i]['lng'];
        $yj = $polygon[$j]['lat'];
        $xj = $polygon[$j]['lng'];

        if ((($yi > $lat) !== ($yj > $lat)) &&
            ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
    }

    return $inside;
}

// GET /api.php?action=api_auth_check
// Returns: current authentication status (for debugging/testing)
if ($action === 'api_auth_check') {
    $auth = function_exists('authenticateApiRequest') ? authenticateApiRequest() : null;
    $clientIp = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $config = [
        'trust_docker_network' => strtolower(getenv('TRUST_DOCKER_NETWORK') ?: 'false') === 'true',
        'internal_api_key_set' => !empty(getenv('INTERNAL_API_KEY')),
        'subdomain_name' => getenv('SUBDOMAIN_NAME') ?: 'tracker',
        'auth_api_url' => getenv('AUTH_API_URL') ?: 'http://family-office:3001',
        'n8n_api_key_set' => !empty(getenv('N8N_API_KEY')),
    ];

    respond([
        'ok' => true,
        'authenticated' => $auth !== null,
        'auth_info' => $auth ? [
            'method' => $auth['method'] ?? 'unknown',
            'via' => $auth['via'] ?? 'unknown',
            'name' => $auth['name'] ?? 'unknown',
            'user_id' => $auth['user_id'] ?? null,
            'user_email' => $auth['user_email'] ?? null,
            'scopes' => $auth['scopes'] ?? [],
            'permissions' => $auth['permissions'] ?? []
        ] : null,
        'client_ip' => $clientIp,
        'is_docker_network' => function_exists('isDockerNetwork') ? isDockerNetwork($clientIp) : false,
        'config' => $config,
        'middleware_loaded' => function_exists('authenticateApiRequest')
    ]);
}

respond(['ok'=>false,'error'=>'unknown action'], 400);