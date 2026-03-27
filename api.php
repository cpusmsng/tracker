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
        require_once __DIR__ . '/config.php';
        return get_pdo();
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

// Helper function for distance calculation (Haversine formula)
function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadius = 6371000; // meters
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLng = deg2rad($lng2 - $lng1);

    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
         cos($lat1Rad) * cos($lat2Rad) *
         sin($deltaLng / 2) * sin($deltaLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
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

// ========== USER AUTHENTICATION SYSTEM ==========

/**
 * Validate user session token. Returns user data or null.
 */
function validateUserSession(PDO $pdo, ?string $token): ?array {
    if (!$token) return null;

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT us.user_id, us.expires_at, u.username, u.email, u.display_name, u.role, u.is_active
        FROM user_sessions us
        JOIN users u ON us.user_id = u.id
        WHERE us.token_hash = :hash AND us.expires_at > NOW() AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([':hash' => $tokenHash]);
    $row = $stmt->fetch();
    if (!$row) return null;

    return [
        'id' => (int)$row['user_id'],
        'username' => $row['username'],
        'email' => $row['email'],
        'display_name' => $row['display_name'],
        'role' => $row['role'],
        'is_admin' => $row['role'] === 'admin',
    ];
}

/**
 * Get current authenticated user from session token (header or query).
 * Returns user data or null. Does NOT enforce auth.
 */
function getCurrentUser(PDO $pdo): ?array {
    // Check X-Auth-Token header first, then query param
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_GET['auth_token'] ?? null;
    if (!$token) return null;
    return validateUserSession($pdo, $token);
}

/**
 * Require user authentication. Returns user data or responds 401.
 */
function requireUser(PDO $pdo): array {
    $user = getCurrentUser($pdo);
    if (!$user) {
        respond(['ok' => false, 'error' => 'Authentication required', 'auth_required' => true], 401);
    }
    return $user;
}

/**
 * Require admin role. Returns user data or responds 403.
 */
function requireAdmin(PDO $pdo): array {
    $user = requireUser($pdo);
    if (!$user['is_admin']) {
        respond(['ok' => false, 'error' => 'Admin access required'], 403);
    }
    return $user;
}

/**
 * Get device IDs accessible by user. Admin sees all, regular user sees assigned only.
 */
function getUserDeviceIds(PDO $pdo, array $user): array {
    if ($user['is_admin']) {
        $rows = $pdo->query("SELECT id FROM devices ORDER BY id")->fetchAll();
        return array_map(fn($r) => (int)$r['id'], $rows);
    }
    $stmt = $pdo->prepare("SELECT device_id FROM user_devices WHERE user_id = :uid ORDER BY device_id");
    $stmt->execute([':uid' => $user['id']]);
    return array_map(fn($r) => (int)$r['device_id'], $stmt->fetchAll());
}

/**
 * Check if user has access to a specific device.
 */
function userCanAccessDevice(PDO $pdo, array $user, int $deviceId): bool {
    if ($user['is_admin']) return true;
    $stmt = $pdo->prepare("SELECT 1 FROM user_devices WHERE user_id = :uid AND device_id = :did LIMIT 1");
    $stmt->execute([':uid' => $user['id'], ':did' => $deviceId]);
    return (bool)$stmt->fetch();
}

/**
 * Build SQL IN clause for user's devices. Returns [sql_fragment, params].
 */
function userDeviceFilter(PDO $pdo, array $user, string $column = 'td.device_id'): array {
    $ids = getUserDeviceIds($pdo, $user);
    if (empty($ids)) {
        return ["$column IN (-1)", []]; // No devices = no results
    }
    $placeholders = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $key = ":udev$i";
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    return ["$column IN (" . implode(',', $placeholders) . ")", $params];
}

/**
 * Check if user auth mode is enabled (vs legacy PIN-only mode).
 * When USER_AUTH_ENABLED=true, the system uses username/password auth.
 * Legacy PIN/SSO modes still work as fallback.
 */
function isUserAuthEnabled(): bool {
    $v = getenv('USER_AUTH_ENABLED') ?: 'true';
    return strtolower($v) === 'true' || $v === '1';
}

try { $pdo = db(); } catch (Throwable $e) { respond(['ok'=>false, 'error'=>'DB: '.$e->getMessage()], 500); }

$action = qparam('action', 'root');

// Resolve current user (may be null for unauthenticated endpoints)
$currentUser = null;
if (isUserAuthEnabled()) {
    $currentUser = getCurrentUser($pdo);
}

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

// Fetch status - diagnostic info about data fetching
if ($action === 'fetch_status') {
    try {
        $deviceId = qparam('device_id');

        // Last tracker_data record per device
        if ($deviceId) {
            $stmt = $pdo->prepare("SELECT MAX(timestamp) as last_ts, COUNT(*) as today_count FROM tracker_data WHERE device_id = :did AND timestamp >= (NOW() AT TIME ZONE 'UTC' - INTERVAL '24 hours')");
            $stmt->execute([':did' => (int)$deviceId]);
            $row = $stmt->fetch();

            $stmtAll = $pdo->prepare("SELECT MAX(timestamp) as last_ts FROM tracker_data WHERE device_id = :did");
            $stmtAll->execute([':did' => (int)$deviceId]);
            $rowAll = $stmtAll->fetch();

            // WiFi scans count for today
            $wifiToday = 0;
            try {
                $stmtWs = $pdo->prepare("SELECT COUNT(*) FROM wifi_scans WHERE device_id = :did AND timestamp >= (NOW() AT TIME ZONE 'UTC' - INTERVAL '24 hours')");
                $stmtWs->execute([':did' => (int)$deviceId]);
                $wifiToday = (int)$stmtWs->fetchColumn();
            } catch (Throwable $e) {}
        } else {
            $row = $pdo->query("SELECT MAX(timestamp) as last_ts, COUNT(*) as today_count FROM tracker_data WHERE timestamp >= (NOW() AT TIME ZONE 'UTC' - INTERVAL '24 hours')")->fetch();
            $rowAll = $pdo->query("SELECT MAX(timestamp) as last_ts FROM tracker_data")->fetch();
            $wifiToday = 0;
            try {
                $wifiToday = (int)$pdo->query("SELECT COUNT(*) FROM wifi_scans WHERE timestamp >= (NOW() AT TIME ZONE 'UTC' - INTERVAL '24 hours')")->fetchColumn();
            } catch (Throwable $e) {}
        }

        // Check last fetch run time from file
        $lastRunFile = __DIR__ . '/data/.fetch_last_run';
        $lastRunTime = null;
        if (is_file($lastRunFile)) {
            $ts = (int)file_get_contents($lastRunFile);
            if ($ts > 0) {
                $lastRunTime = (new DateTimeImmutable("@$ts"))->setTimezone(new DateTimeZone('Europe/Bratislava'))->format('Y-m-d H:i:s');
            }
        }

        respond([
            'ok' => true,
            'last_record' => $rowAll['last_ts'] ? utc_to_local($rowAll['last_ts']) : null,
            'records_last_24h' => (int)($row['today_count'] ?? 0),
            'wifi_scans_last_24h' => $wifiToday,
            'last_fetch_run' => $lastRunTime,
            'server_time' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Bratislava')))->format('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'get_last_position') {
    $deviceId = qparam('device_id');

    // Build user device filter
    $userFilter = '';
    $userParams = [];
    if ($currentUser) {
        [$userFilter, $userParams] = userDeviceFilter($pdo, $currentUser);
        $userFilter = " AND $userFilter";
    }

    if ($deviceId) {
        $stmt = $pdo->prepare("
            SELECT td.id, td.timestamp, td.latitude, td.longitude, td.source, td.device_id,
                   d.name as device_name, d.color as device_color
            FROM tracker_data td
            LEFT JOIN devices d ON td.device_id = d.id
            WHERE td.device_id = :did $userFilter
            ORDER BY td.timestamp DESC
            LIMIT 1
        ");
        $stmt->execute(array_merge([':did' => (int)$deviceId], $userParams));
        $row = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare("
            SELECT td.id, td.timestamp, td.latitude, td.longitude, td.source, td.device_id,
                   d.name as device_name, d.color as device_color
            FROM tracker_data td
            LEFT JOIN devices d ON td.device_id = d.id
            WHERE 1=1 $userFilter
            ORDER BY td.timestamp DESC
            LIMIT 1
        ");
        $stmt->execute($userParams);
        $row = $stmt->fetch();
    }
    if (!$row) respond([]);

    respond([
        'id'          => (int)$row['id'],
        'timestamp'   => utc_to_local($row['timestamp']),
        'latitude'    => (float)$row['latitude'],
        'longitude'   => (float)$row['longitude'],
        'source'      => (string)$row['source'],
        'device_id'   => $row['device_id'] ? (int)$row['device_id'] : null,
        'device_name' => $row['device_name'] ?? null,
        'device_color'=> $row['device_color'] ?? null,
    ]);
}

if ($action === 'get_history') {
    $deviceId = qparam('device_id');
    // OPRAVA: PouÅ¾Ã­vateÄ¾ zadÃ¡ dÃ¡tum v lokÃ¡lnom Äase, musÃ­me ho konvertovaÅ¥ na UTC rozsah
    $localDate = qparam('date') ?: (new DateTimeImmutable('now', new DateTimeZone('Europe/Bratislava')))->format('Y-m-d');
    
    // Vytvor UTC rozsah pre danÃ½ lokÃ¡lny deÅˆ
    $startLocal = new DateTimeImmutable($localDate . ' 00:00:00', new DateTimeZone('Europe/Bratislava'));
    $endLocal = new DateTimeImmutable($localDate . ' 23:59:59', new DateTimeZone('Europe/Bratislava'));
    
    $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    
    $sql = "
        SELECT td.id, td.timestamp, td.latitude, td.longitude, td.source, td.device_id,
               d.name as device_name, d.color as device_color
        FROM tracker_data td
        LEFT JOIN devices d ON td.device_id = d.id
        WHERE td.timestamp BETWEEN :start AND :end
    ";
    $params = [':start' => $startUtc, ':end' => $endUtc];
    if ($deviceId) {
        $sql .= " AND td.device_id = :did";
        $params[':did'] = (int)$deviceId;
    }
    // User device filter
    if ($currentUser) {
        [$uf, $up] = userDeviceFilter($pdo, $currentUser);
        $sql .= " AND $uf";
        $params = array_merge($params, $up);
    }
    $sql .= " ORDER BY td.timestamp ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    while ($r = $stmt->fetch()) {
        $out[] = [
            'id'=>(int)$r['id'],
            'timestamp'=>utc_to_local($r['timestamp']),
            'latitude'=>(float)$r['latitude'],
            'longitude'=>(float)$r['longitude'],
            'source'=>(string)$r['source'],
            'device_id'=>$r['device_id'] ? (int)$r['device_id'] : null,
            'device_name'=>$r['device_name'] ?? null,
            'device_color'=>$r['device_color'] ?? null,
        ];
    }
    respond($out);
}

if ($action === 'get_history_range') {
    $since = qparam('since');
    $until = qparam('until');
    $deviceId = qparam('device_id');

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
    
    $sql = "
        SELECT td.id, td.timestamp, td.latitude, td.longitude, td.source, td.device_id,
               d.name as device_name, d.color as device_color
        FROM tracker_data td
        LEFT JOIN devices d ON td.device_id = d.id
        WHERE td.timestamp BETWEEN :since AND :until
    ";
    $params = [
        ':since'=>$dtSince->format('Y-m-d H:i:s'),
        ':until'=>$dtUntil->format('Y-m-d H:i:s'),
    ];
    if ($deviceId) {
        $sql .= " AND td.device_id = :did";
        $params[':did'] = (int)$deviceId;
    }
    // User device filter
    if ($currentUser) {
        [$uf, $up] = userDeviceFilter($pdo, $currentUser);
        $sql .= " AND $uf";
        $params = array_merge($params, $up);
    }
    $sql .= " ORDER BY td.timestamp ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    while ($r = $stmt->fetch()) {
        $out[] = [
            'id'=>(int)$r['id'],
            'timestamp'=>utc_to_local($r['timestamp']),
            'latitude'=>(float)$r['latitude'],
            'longitude'=>(float)$r['longitude'],
            'source'=>(string)$r['source'],
            'device_id'=>$r['device_id'] ? (int)$r['device_id'] : null,
            'device_name'=>$r['device_name'] ?? null,
            'device_color'=>$r['device_color'] ?? null,
        ];
    }
    respond($out);
}

// NOVÃ ENDPOINT: refetch_day
// Raw sensor data browser (like SenseCAP portal's "Sensor Node - Data" view)
if ($action === 'get_raw_records' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $deviceId     = qparam('device_id');
    $localDate    = qparam('date');
    $page         = max(1, (int)(qparam('page') ?? '1'));
    $perPage      = max(1, min(200, (int)(qparam('per_page') ?? '50')));
    $sourceFilter = qparam('source_filter');

    // Check if wifi_scans table exists
    $hasWifiScans = false;
    try {
        $pdo->query("SELECT 1 FROM wifi_scans LIMIT 0");
        $hasWifiScans = true;
    } catch (Throwable $e) {}

    // Date range in UTC
    $utcStart = null; $utcEnd = null;
    if ($localDate) {
        $startLocal = new DateTimeImmutable($localDate . ' 00:00:00', new DateTimeZone('Europe/Bratislava'));
        $endLocal   = new DateTimeImmutable($localDate . ' 23:59:59', new DateTimeZone('Europe/Bratislava'));
        $utcStart   = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $utcEnd     = $endLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    // Build unified query using UNION of tracker_data (all sources) and wifi_scans (unresolved only)
    if ($hasWifiScans) {
        // Part 1: ALL records from tracker_data (GNSS, iBeacon, WiFi-resolved)
        // Part 2: UNRESOLVED WiFi scans from wifi_scans (no tracker_data link)
        $whereTd  = [];
        $whereWs  = ['ws.resolved = 0'];
        $paramsTd = [];
        $paramsWs = [];

        if ($deviceId) {
            $whereTd[]          = 'td.device_id = :did';
            $paramsTd[':did']   = (int)$deviceId;
            $whereWs[]          = 'ws.device_id = :did2';
            $paramsWs[':did2']  = (int)$deviceId;
        }

        if ($utcStart && $utcEnd) {
            $whereTd[]            = 'td.timestamp BETWEEN :start AND :end';
            $paramsTd[':start']   = $utcStart;
            $paramsTd[':end']     = $utcEnd;
            $whereWs[]            = 'ws.timestamp BETWEEN :start2 AND :end2';
            $paramsWs[':start2']  = $utcStart;
            $paramsWs[':end2']    = $utcEnd;
        }

        if ($sourceFilter && $sourceFilter !== 'all') {
            if ($sourceFilter === 'unresolved') {
                // Special filter: show only unresolved wifi scans
                // Keep ws.resolved = 0 (already set above)
                $whereTd[] = '0 = 1'; // No tracker_data for unresolved
            } else if (str_starts_with($sourceFilter, 'wifi')) {
                // WiFi filter - show from both tracker_data (resolved wifi) and wifi_scans (unresolved)
                $whereTd[]          = 'td.source = :src';
                $paramsTd[':src']   = $sourceFilter;
                // Unresolved wifi_scans stay (no extra filter needed)
            } else {
                // Non-wifi filter (gnss, ibeacon) - only tracker_data
                $whereTd[]          = 'td.source = :src';
                $paramsTd[':src']   = $sourceFilter;
                // Exclude unresolved wifi_scans
                $whereWs[] = '0 = 1';
            }
        }

        $whereTdSql = $whereTd ? 'WHERE ' . implode(' AND ', $whereTd) : '';
        $whereWsSql = 'WHERE ' . implode(' AND ', $whereWs);

        // Count total
        $countSql = "
            SELECT (SELECT COUNT(*) FROM tracker_data td $whereTdSql)
                 + (SELECT COUNT(*) FROM wifi_scans ws $whereWsSql)
        ";
        $countStmt = $pdo->prepare($countSql);
        foreach ($paramsTd as $k => $v) $countStmt->bindValue($k, $v);
        foreach ($paramsWs as $k => $v) $countStmt->bindValue($k, $v);
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        $offset     = ($page - 1) * $perPage;

        // Fetch unified records
        // For tracker_data WiFi records, LEFT JOIN wifi_scans to get macs_json
        $sql = "
            SELECT 'tracker' as record_type, td.id, td.timestamp, td.latitude, td.longitude, td.source,
                   td.device_id, td.raw_wifi_macs, td.primary_mac,
                   d.name as device_name,
                   wsj.macs_json as macs_json,
                   COALESCE(wsj.mac_count, 0) as mac_count,
                   1 as resolved
            FROM tracker_data td
            LEFT JOIN devices d ON td.device_id = d.id
            LEFT JOIN wifi_scans wsj ON wsj.tracker_data_id = td.id
            $whereTdSql
            UNION ALL
            SELECT 'wifi_scan' as record_type, ws.id, ws.timestamp, ws.latitude, ws.longitude, ws.source,
                   ws.device_id, NULL as raw_wifi_macs, NULL as primary_mac,
                   d.name as device_name, ws.macs_json, ws.mac_count, ws.resolved
            FROM wifi_scans ws
            LEFT JOIN devices d ON ws.device_id = d.id
            $whereWsSql
            ORDER BY timestamp DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($paramsTd as $k => $v) $stmt->bindValue($k, $v);
        foreach ($paramsWs as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } else {
        // Fallback: no wifi_scans table yet, use original logic
        $where  = [];
        $params = [];

        if ($deviceId) {
            $where[]        = 'td.device_id = :did';
            $params[':did'] = (int)$deviceId;
        }
        if ($utcStart && $utcEnd) {
            $where[]          = 'td.timestamp BETWEEN :start AND :end';
            $params[':start'] = $utcStart;
            $params[':end']   = $utcEnd;
        }
        if ($sourceFilter && $sourceFilter !== 'all') {
            $where[]           = 'td.source = :src';
            $params[':src']    = $sourceFilter;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tracker_data td $whereSql");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        $offset     = ($page - 1) * $perPage;

        $sql = "
            SELECT 'tracker' as record_type, td.id, td.timestamp, td.latitude, td.longitude, td.source,
                   td.device_id, td.raw_wifi_macs, td.primary_mac,
                   d.name as device_name, NULL as macs_json, 0 as mac_count, 1 as resolved
            FROM tracker_data td
            LEFT JOIN devices d ON td.device_id = d.id
            $whereSql
            ORDER BY td.timestamp DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    }

    // Collect all MAC addresses for batch lookup
    $allMacs = [];
    foreach ($rows as $r) {
        if (!empty($r['raw_wifi_macs'])) {
            foreach (explode(',', $r['raw_wifi_macs']) as $mac) {
                $mac = trim($mac);
                if ($mac !== '') $allMacs[$mac] = true;
            }
        }
        if (!empty($r['macs_json'])) {
            $parsed = json_decode($r['macs_json'], true);
            if (is_array($parsed)) {
                foreach ($parsed as $ap) {
                    if (!empty($ap['mac'])) $allMacs[$ap['mac']] = true;
                }
            }
        }
    }

    // Batch lookup mac_locations
    $macQueried = [];
    if ($allMacs) {
        $placeholders = implode(',', array_fill(0, count($allMacs), '?'));
        $mlStmt = $pdo->prepare("SELECT mac_address, latitude, longitude, last_queried FROM mac_locations WHERE mac_address IN ($placeholders)");
        $mlStmt->execute(array_keys($allMacs));
        while ($ml = $mlStmt->fetch()) {
            $macQueried[$ml['mac_address']] = [
                'has_location' => ($ml['latitude'] !== null && $ml['longitude'] !== null),
                'lat'          => $ml['latitude'] !== null ? (float)$ml['latitude'] : null,
                'lng'          => $ml['longitude'] !== null ? (float)$ml['longitude'] : null,
                'last_queried' => $ml['last_queried'] ? utc_to_local($ml['last_queried']) : null,
            ];
        }
    }

    // Build output
    $out = [];
    foreach ($rows as $r) {
        $recordType = $r['record_type'] ?? 'tracker';

        $record = [
            'id'            => (int)$r['id'],
            'record_type'   => $recordType,
            'timestamp'     => utc_to_local($r['timestamp']),
            'latitude'      => $r['latitude'] !== null ? (float)$r['latitude'] : null,
            'longitude'     => $r['longitude'] !== null ? (float)$r['longitude'] : null,
            'source'        => $r['source'] ?: null,
            'resolved'      => (bool)$r['resolved'],
            'device_id'     => $r['device_id'] ? (int)$r['device_id'] : null,
            'device_name'   => $r['device_name'] ?? null,
            'raw_wifi_macs' => $r['raw_wifi_macs'] ?: null,
            'primary_mac'   => $r['primary_mac'] ?: null,
            'mac_count'     => (int)($r['mac_count'] ?? 0),
            'mac_details'   => null,
        ];

        // Build mac_details from raw_wifi_macs (tracker_data) or macs_json (wifi_scans)
        if ($recordType === 'wifi_scan' && !empty($r['macs_json'])) {
            $parsed = json_decode($r['macs_json'], true);
            if (is_array($parsed)) {
                $macDetails = [];
                foreach ($parsed as $ap) {
                    $mac = $ap['mac'] ?? '';
                    if ($mac === '') continue;
                    $info = $macQueried[$mac] ?? null;
                    $macDetails[] = [
                        'mac'          => $mac,
                        'rssi'         => (int)($ap['rssi'] ?? -200),
                        'queried'      => $info !== null,
                        'has_location' => $info['has_location'] ?? false,
                        'lat'          => $info['lat'] ?? null,
                        'lng'          => $info['lng'] ?? null,
                        'last_queried' => $info['last_queried'] ?? null,
                    ];
                }
                $record['mac_details'] = $macDetails;
                $record['mac_count'] = count($macDetails);
            }
        } else if (!empty($r['raw_wifi_macs'])) {
            $macDetails = [];
            foreach (explode(',', $r['raw_wifi_macs']) as $mac) {
                $mac = trim($mac);
                if ($mac === '') continue;
                $info = $macQueried[$mac] ?? null;
                $macDetails[] = [
                    'mac'          => $mac,
                    'queried'      => $info !== null,
                    'has_location' => $info['has_location'] ?? false,
                    'lat'          => $info['lat'] ?? null,
                    'lng'          => $info['lng'] ?? null,
                    'last_queried' => $info['last_queried'] ?? null,
                ];
            }
            $record['mac_details'] = $macDetails;
        }

        $out[] = $record;
    }

    respond([
        'ok'          => true,
        'records'     => $out,
        'pagination'  => [
            'total_count' => $totalCount,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ],
    ]);
}

// GET /api.php?action=get_record_macs&id=X - Get MAC details for a specific record
if ($action === 'get_record_macs') {
    try {
        $id = qparam('id');
        if (!$id) {
            respond(['ok' => false, 'error' => 'Record ID is required'], 400);
        }

        $stmt = $pdo->prepare("SELECT raw_wifi_macs, primary_mac FROM tracker_data WHERE id = ?");
        $stmt->execute([(int)$id]);
        $record = $stmt->fetch();

        if (!$record) {
            respond(['ok' => false, 'error' => 'Record not found'], 404);
        }

        $macs = [];
        if (!empty($record['raw_wifi_macs'])) {
            $macList = array_filter(array_map('trim', explode(',', $record['raw_wifi_macs'])));

            if (!empty($macList)) {
                $placeholders = implode(',', array_fill(0, count($macList), '?'));
                $mlStmt = $pdo->prepare("SELECT mac_address, latitude, longitude, last_queried FROM mac_locations WHERE mac_address IN ($placeholders)");
                $mlStmt->execute(array_values($macList));
                $macInfo = [];
                while ($ml = $mlStmt->fetch()) {
                    $macInfo[$ml['mac_address']] = $ml;
                }

                foreach ($macList as $mac) {
                    $info = $macInfo[$mac] ?? null;
                    $macs[] = [
                        'mac' => $mac,
                        'is_primary' => ($mac === $record['primary_mac']),
                        'has_coords' => ($info && $info['latitude'] !== null && $info['longitude'] !== null),
                        'negative' => ($info && $info['latitude'] === null && $info['longitude'] === null),
                        'lat' => $info && $info['latitude'] !== null ? (float)$info['latitude'] : null,
                        'lng' => $info && $info['longitude'] !== null ? (float)$info['longitude'] : null,
                        'last_queried' => $info && $info['last_queried'] ? utc_to_local($info['last_queried']) : null,
                    ];
                }
            }
        }

        respond(['ok' => true, 'data' => ['macs' => $macs, 'primary_mac' => $record['primary_mac']]]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// GET /api.php?action=get_wifi_scan_macs&id=X - Get MAC details for a wifi_scan record
if ($action === 'get_wifi_scan_macs') {
    try {
        $id = qparam('id');
        if (!$id) respond(['ok' => false, 'error' => 'Scan ID is required'], 400);

        $stmt = $pdo->prepare("SELECT macs_json FROM wifi_scans WHERE id = ?");
        $stmt->execute([(int)$id]);
        $scan = $stmt->fetch();
        if (!$scan) respond(['ok' => false, 'error' => 'Scan not found'], 404);

        $parsed = json_decode($scan['macs_json'], true);
        if (!is_array($parsed)) respond(['ok' => true, 'macs' => []]);

        // Batch lookup mac_locations
        $macAddresses = array_filter(array_map(fn($ap) => $ap['mac'] ?? '', $parsed));
        $macInfo = [];
        if ($macAddresses) {
            $ph = implode(',', array_fill(0, count($macAddresses), '?'));
            $ml = $pdo->prepare("SELECT mac_address, latitude, longitude, last_queried FROM mac_locations WHERE mac_address IN ($ph)");
            $ml->execute(array_values($macAddresses));
            while ($r = $ml->fetch()) $macInfo[$r['mac_address']] = $r;
        }

        $macs = [];
        foreach ($parsed as $ap) {
            $mac = $ap['mac'] ?? '';
            if ($mac === '') continue;
            $info = $macInfo[$mac] ?? null;
            $macs[] = [
                'mac'          => $mac,
                'rssi'         => (int)($ap['rssi'] ?? -200),
                'queried'      => $info !== null,
                'has_location' => ($info && $info['latitude'] !== null && $info['longitude'] !== null),
                'lat'          => $info && $info['latitude'] !== null ? (float)$info['latitude'] : null,
                'lng'          => $info && $info['longitude'] !== null ? (float)$info['longitude'] : null,
                'last_queried' => $info && $info['last_queried'] ? utc_to_local($info['last_queried']) : null,
            ];
        }
        respond(['ok' => true, 'macs' => $macs]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// POST /api.php?action=retry_google_wifi_scan - Retry Google API for a wifi_scan
if ($action === 'retry_google_wifi_scan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $scanId = (int)($j['scan_id'] ?? 0);
        if (!$scanId) respond(['ok' => false, 'error' => 'scan_id is required'], 400);

        $stmt = $pdo->prepare("SELECT id, macs_json, device_id, timestamp FROM wifi_scans WHERE id = ?");
        $stmt->execute([$scanId]);
        $scan = $stmt->fetch();
        if (!$scan) respond(['ok' => false, 'error' => 'Scan not found'], 404);

        $parsed = json_decode($scan['macs_json'], true);
        if (!is_array($parsed) || empty($parsed)) respond(['ok' => false, 'error' => 'No MACs in scan']);

        $GKEY = getenv('GOOGLE_API_KEY') ?: '';
        if (!$GKEY) respond(['ok' => false, 'error' => 'Google API key not configured']);

        // Build WiFi access points for Google
        $wifiAps = [];
        foreach ($parsed as $ap) {
            $mac = strtoupper(str_replace('-', ':', $ap['mac'] ?? ''));
            if (strlen($mac) !== 17) continue;
            $wifiAps[] = [
                'macAddress'         => $mac,
                'signalStrength'     => (int)($ap['rssi'] ?? -70),
                'channel'            => 0,
                'signalToNoiseRatio' => 0,
            ];
        }

        if (count($wifiAps) < 2) respond(['ok' => false, 'error' => 'Need at least 2 valid MACs']);

        // Sort by signal strength, take top 6
        usort($wifiAps, fn($a, $b) => $b['signalStrength'] <=> $a['signalStrength']);
        $wifiAps = array_slice($wifiAps, 0, 6);

        // Call Google Geolocation API
        $payload = json_encode(['wifiAccessPoints' => $wifiAps]);
        $ch = curl_init("https://www.googleapis.com/geolocation/v1/geolocate?key=$GKEY");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resp) {
            respond(['ok' => true, 'latitude' => null, 'longitude' => null, 'message' => 'Google API returned no result']);
        }

        $geoResult = json_decode($resp, true);
        $lat = $geoResult['location']['lat'] ?? null;
        $lng = $geoResult['location']['lng'] ?? null;
        $acc = $geoResult['accuracy'] ?? null;

        if ($lat === null || $lng === null) {
            respond(['ok' => true, 'latitude' => null, 'longitude' => null, 'message' => 'No location in response']);
        }

        // Update wifi_scan as resolved
        $upd = $pdo->prepare("UPDATE wifi_scans SET resolved = 1, latitude = :lat, longitude = :lng, source = 'wifi-google' WHERE id = :id");
        $upd->execute([':lat' => $lat, ':lng' => $lng, ':id' => $scanId]);

        // Create tracker_data entry so position appears on map and in history
        $allMacs = [];
        $primaryMac = null;
        foreach ($parsed as $ap) {
            $mac = strtoupper(str_replace('-', ':', $ap['mac'] ?? ''));
            if (strlen($mac) === 17) $allMacs[] = $mac;
        }
        if (!empty($allMacs)) $primaryMac = $allMacs[0];

        $ins = $pdo->prepare("
            INSERT INTO tracker_data (timestamp, latitude, longitude, source, raw_wifi_macs, primary_mac, device_id)
            VALUES (:ts, :lat, :lng, 'wifi-google', :macs, :pmac, :did)
        ");
        $ins->execute([
            ':ts'   => $scan['timestamp'],
            ':lat'  => $lat,
            ':lng'  => $lng,
            ':macs' => implode(',', $allMacs),
            ':pmac' => $primaryMac,
            ':did'  => $scan['device_id'],
        ]);
        $tdId = (int)$pdo->lastInsertId();

        // Link wifi_scan to the new tracker_data entry
        $link = $pdo->prepare("UPDATE wifi_scans SET tracker_data_id = :tid WHERE id = :id");
        $link->execute([':tid' => $tdId, ':id' => $scanId]);

        // Update mac_locations for all MACs in this scan
        $macsUpdated = 0;
        foreach ($parsed as $ap) {
            $mac = strtoupper(str_replace('-', ':', $ap['mac'] ?? ''));
            if (strlen($mac) !== 17) continue;
            $upsert = $pdo->prepare("
                INSERT INTO mac_locations (mac_address, latitude, longitude, last_queried)
                VALUES (:m, :la, :lo, :ts)
                ON CONFLICT(mac_address) DO UPDATE SET latitude=excluded.latitude, longitude=excluded.longitude, last_queried=excluded.last_queried
            ");
            $upsert->execute([':m' => $mac, ':la' => $lat, ':lo' => $lng, ':ts' => $scan['timestamp']]);
            $macsUpdated++;
        }

        respond([
            'ok'           => true,
            'latitude'     => (float)$lat,
            'longitude'    => (float)$lng,
            'accuracy'     => $acc ? (float)$acc : null,
            'macs_updated' => $macsUpdated,
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'refetch_day' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $j = json_body();
    $date = trim((string)($j['date'] ?? ''));
    $deviceId = isset($j['device_id']) ? (int)$j['device_id'] : null;
    
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
    
    // Spusti proces na pozadí s parametrom --refetch-date
    $deviceArg = $deviceId ? ' --device-ids=' . escapeshellarg((string)$deviceId) : '';
    $cmd = sprintf(
        '%s %s --refetch-date=%s%s >> %s 2>&1 &',
        escapeshellarg($phpBin),
        escapeshellarg($scriptPath),
        escapeshellarg($dateStr),
        $deviceArg,
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
        $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'device_status'");
        $tableExists = $stmt->fetch();
        
        if ($tableExists) {
            // Tabuľka existuje - skontroluj stĺpce
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'device_status'");
            $columns = $stmt->fetchAll();

            $hasDataUploadTime = false;
            $hasLatestMessageTime = false;

            foreach ($columns as $col) {
                if ($col['column_name'] === 'data_upload_time') $hasDataUploadTime = true;
                if ($col['column_name'] === 'latest_message_time') $hasLatestMessageTime = true;
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
        'userAuthEnabled' => isUserAuthEnabled(),
        'loginUrl' => isSSOEnabled() ? getLoginUrl() : null
    ]);
}

// ========== USER AUTHENTICATION ENDPOINTS ==========

// POST /api.php?action=user_login
if ($action === 'user_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $username = trim((string)($j['username'] ?? ''));
        $password = (string)($j['password'] ?? '');

        if ($username === '' || $password === '') {
            respond(['ok' => false, 'error' => 'Meno a heslo sú povinné'], 400);
        }

        $stmt = $pdo->prepare("SELECT id, username, email, password_hash, display_name, role, is_active FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            usleep(500000); // 500ms brute-force protection
            respond(['ok' => false, 'error' => 'Nesprávne meno alebo heslo'], 401);
        }

        if (!(int)$user['is_active']) {
            respond(['ok' => false, 'error' => 'Účet je deaktivovaný'], 403);
        }

        // Create session token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("INSERT INTO user_sessions (user_id, token_hash, expires_at) VALUES (:uid, :hash, :exp)");
        $stmt->execute([':uid' => $user['id'], ':hash' => $tokenHash, ':exp' => $expiresAt]);

        // Update last login
        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")->execute([':id' => $user['id']]);

        // Clean expired sessions
        $pdo->exec("DELETE FROM user_sessions WHERE expires_at < NOW()");

        respond([
            'ok' => true,
            'token' => $token,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'role' => $user['role'],
                'is_admin' => $user['role'] === 'admin',
            ]
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Login failed: ' . $e->getMessage()], 500);
    }
}

// POST /api.php?action=user_logout
if ($action === 'user_logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? null;
        if ($token) {
            $tokenHash = hash('sha256', $token);
            $pdo->prepare("DELETE FROM user_sessions WHERE token_hash = :hash")->execute([':hash' => $tokenHash]);
        }
        respond(['ok' => true, 'message' => 'Odhlásený']);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// GET /api.php?action=user_me
if ($action === 'user_me') {
    if (isUserAuthEnabled()) {
        $user = getCurrentUser($pdo);
        if ($user) {
            $deviceIds = getUserDeviceIds($pdo, $user);
            respond([
                'ok' => true,
                'authenticated' => true,
                'method' => 'user_auth',
                'user' => array_merge($user, ['device_ids' => $deviceIds])
            ]);
        } else {
            respond([
                'ok' => true,
                'authenticated' => false,
                'method' => 'user_auth'
            ]);
        }
    } else {
        respond(['ok' => true, 'authenticated' => false, 'method' => 'pin']);
    }
}

// POST /api.php?action=user_change_password
if ($action === 'user_change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $user = requireUser($pdo);
        $j = json_body();
        $oldPassword = (string)($j['old_password'] ?? '');
        $newPassword = (string)($j['new_password'] ?? '');

        if ($newPassword === '' || strlen($newPassword) < 4) {
            respond(['ok' => false, 'error' => 'Nové heslo musí mať aspoň 4 znaky'], 400);
        }

        // Verify old password
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmt->execute([':id' => $user['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($oldPassword, $row['password_hash'])) {
            respond(['ok' => false, 'error' => 'Nesprávne staré heslo'], 401);
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id")
            ->execute([':hash' => $newHash, ':id' => $user['id']]);

        respond(['ok' => true, 'message' => 'Heslo zmenené']);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// ========== ADMIN: USER MANAGEMENT ==========

// GET /api.php?action=get_users (admin only)
if ($action === 'get_users') {
    try {
        $admin = requireAdmin($pdo);

        $rows = $pdo->query("
            SELECT u.id, u.username, u.email, u.display_name, u.role, u.is_active, u.last_login, u.created_at, u.updated_at,
                   COALESCE(
                       (SELECT string_agg(d.name || ' (' || d.device_eui || ')', ', ' ORDER BY d.name)
                        FROM user_devices ud JOIN devices d ON ud.device_id = d.id
                        WHERE ud.user_id = u.id), ''
                   ) as assigned_devices,
                   COALESCE(
                       (SELECT string_agg(CAST(ud.device_id AS TEXT), ',' ORDER BY ud.device_id)
                        FROM user_devices ud WHERE ud.user_id = u.id), ''
                   ) as device_ids
            FROM users u
            ORDER BY u.id ASC
        ")->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'username' => $r['username'],
                'email' => $r['email'],
                'display_name' => $r['display_name'],
                'role' => $r['role'],
                'is_active' => (bool)(int)$r['is_active'],
                'last_login' => $r['last_login'],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
                'assigned_devices' => $r['assigned_devices'],
                'device_ids' => $r['device_ids'] ? array_map('intval', explode(',', $r['device_ids'])) : [],
            ];
        }
        respond(['ok' => true, 'data' => $out]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// POST /api.php?action=save_user (admin only)
if ($action === 'save_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $admin = requireAdmin($pdo);
        $j = json_body();

        $id = isset($j['id']) ? (int)$j['id'] : null;
        $username = trim((string)($j['username'] ?? ''));
        $email = trim((string)($j['email'] ?? ''));
        $displayName = trim((string)($j['display_name'] ?? ''));
        $role = in_array($j['role'] ?? '', ['admin', 'user']) ? $j['role'] : 'user';
        $isActive = isset($j['is_active']) ? (int)(bool)$j['is_active'] : 1;
        $password = (string)($j['password'] ?? '');
        $deviceIds = isset($j['device_ids']) && is_array($j['device_ids']) ? $j['device_ids'] : null;

        if ($username === '') {
            respond(['ok' => false, 'error' => 'Používateľské meno je povinné'], 400);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['ok' => false, 'error' => 'Neplatná e-mailová adresa'], 400);
        }

        $pdo->beginTransaction();

        if ($id) {
            // Update existing user
            $sql = "UPDATE users SET username = :u, email = :e, display_name = :d, role = :r, is_active = :a, updated_at = NOW() WHERE id = :id";
            $params = [':u' => $username, ':e' => $email ?: null, ':d' => $displayName ?: null, ':r' => $role, ':a' => $isActive, ':id' => $id];
            $pdo->prepare($sql)->execute($params);

            // Update password if provided
            if ($password !== '') {
                if (strlen($password) < 4) {
                    $pdo->rollBack();
                    respond(['ok' => false, 'error' => 'Heslo musí mať aspoň 4 znaky'], 400);
                }
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")->execute([':hash' => $hash, ':id' => $id]);
            }
            $userId = $id;
        } else {
            // Create new user
            if ($password === '' || strlen($password) < 4) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Heslo musí mať aspoň 4 znaky'], 400);
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, display_name, role, is_active) VALUES (:u, :e, :p, :d, :r, :a)");
            $stmt->execute([':u' => $username, ':e' => $email ?: null, ':p' => $hash, ':d' => $displayName ?: null, ':r' => $role, ':a' => $isActive]);
            $userId = (int)$pdo->lastInsertId();
        }

        // Update device assignments if provided
        if ($deviceIds !== null) {
            $pdo->prepare("DELETE FROM user_devices WHERE user_id = :uid")->execute([':uid' => $userId]);
            $stmtAssign = $pdo->prepare("INSERT INTO user_devices (user_id, device_id) VALUES (:uid, :did) ON CONFLICT DO NOTHING");
            foreach ($deviceIds as $did) {
                $stmtAssign->execute([':uid' => $userId, ':did' => (int)$did]);
            }
        }

        $pdo->commit();
        respond(['ok' => true, 'message' => $id ? 'Používateľ aktualizovaný' : 'Používateľ vytvorený', 'id' => $userId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
        if (str_contains($msg, 'unique') || str_contains($msg, 'UNIQUE')) {
            respond(['ok' => false, 'error' => 'Používateľ s týmto menom alebo emailom už existuje'], 409);
        }
        respond(['ok' => false, 'error' => $msg], 500);
    }
}

// DELETE /api.php?action=delete_user&id=X (admin only)
if ($action === 'delete_user') {
    try {
        $admin = requireAdmin($pdo);
        $id = (int)(qparam('id') ?: 0);
        if (!$id) {
            $j = json_body();
            $id = isset($j['id']) ? (int)$j['id'] : 0;
        }
        if (!$id) respond(['ok' => false, 'error' => 'Missing user id'], 400);

        // Cannot delete yourself
        if ($id === $admin['id']) {
            respond(['ok' => false, 'error' => 'Nemôžete zmazať vlastný účet'], 400);
        }

        // Cannot delete last admin
        $adminCount = (int)$pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'admin' AND is_active = 1")->fetch()['c'];
        $targetUser = $pdo->prepare("SELECT role FROM users WHERE id = :id");
        $targetUser->execute([':id' => $id]);
        $target = $targetUser->fetch();
        if ($target && $target['role'] === 'admin' && $adminCount <= 1) {
            respond(['ok' => false, 'error' => 'Nemožno zmazať posledného administrátora'], 400);
        }

        $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
        respond(['ok' => true, 'message' => 'Používateľ zmazaný']);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// POST /api.php?action=toggle_user (admin only)
if ($action === 'toggle_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $admin = requireAdmin($pdo);
        $j = json_body();
        $id = isset($j['id']) ? (int)$j['id'] : 0;
        $isActive = isset($j['is_active']) ? (int)(bool)$j['is_active'] : 0;

        if (!$id) respond(['ok' => false, 'error' => 'Missing user id'], 400);

        // Cannot deactivate yourself
        if ($id === $admin['id'] && !$isActive) {
            respond(['ok' => false, 'error' => 'Nemôžete deaktivovať vlastný účet'], 400);
        }

        $pdo->prepare("UPDATE users SET is_active = :a, updated_at = NOW() WHERE id = :id")
            ->execute([':a' => $isActive, ':id' => $id]);

        // If deactivating, clear their sessions
        if (!$isActive) {
            $pdo->prepare("DELETE FROM user_sessions WHERE user_id = :uid")->execute([':uid' => $id]);
        }

        respond(['ok' => true, 'message' => $isActive ? 'Používateľ aktivovaný' : 'Používateľ deaktivovaný']);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// POST /api.php?action=assign_devices (admin only)
if ($action === 'assign_devices' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $admin = requireAdmin($pdo);
        $j = json_body();
        $userId = isset($j['user_id']) ? (int)$j['user_id'] : 0;
        $deviceIds = isset($j['device_ids']) && is_array($j['device_ids']) ? $j['device_ids'] : [];

        if (!$userId) respond(['ok' => false, 'error' => 'Missing user_id'], 400);

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM user_devices WHERE user_id = :uid")->execute([':uid' => $userId]);
        $stmt = $pdo->prepare("INSERT INTO user_devices (user_id, device_id) VALUES (:uid, :did) ON CONFLICT DO NOTHING");
        foreach ($deviceIds as $did) {
            $stmt->execute([':uid' => $userId, ':did' => (int)$did]);
        }
        $pdo->commit();

        respond(['ok' => true, 'message' => 'Zariadenia priradené', 'count' => count($deviceIds)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
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
            'smart_refetch_days' => (int)(getenv('SMART_REFETCH_DAYS') ?: 7),
            'battery_alert_enabled' => in_array(strtolower(getenv('BATTERY_ALERT_ENABLED') ?: 'false'), ['true', '1', 'yes']),
            'battery_alert_email' => getenv('BATTERY_ALERT_EMAIL') ?: '',
            'has_google_api' => !empty(getenv('GOOGLE_API_KEY'))
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
        $batteryAlertEnabled = isset($j['battery_alert_enabled']) ? ($j['battery_alert_enabled'] ? 'true' : 'false') : null;
        $batteryAlertEmail = isset($j['battery_alert_email']) ? trim((string)$j['battery_alert_email']) : null;

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
        if ($batteryAlertEmail !== null && $batteryAlertEmail !== '' && !filter_var($batteryAlertEmail, FILTER_VALIDATE_EMAIL)) {
            respond(['ok' => false, 'error' => 'battery_alert_email must be a valid email address'], 400);
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
            'smart_refetch_days' => 'SMART_REFETCH_DAYS',
            'battery_alert_enabled' => 'BATTERY_ALERT_ENABLED',
            'battery_alert_email' => 'BATTERY_ALERT_EMAIL'
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
        $rawMacs = $position['raw_wifi_macs'];
        if ($rawMacs && strpos($position['source'], 'wifi') !== false) {
            // Update all MACs from this position
            $macs = array_filter(array_map('trim', explode(',', $rawMacs)));
            foreach ($macs as $mac) {
                $stmt = $pdo->prepare('UPDATE mac_locations SET latitude = ?, longitude = ?, last_queried = NOW() WHERE mac_address = ?');
                $stmt->execute([$newLat, $newLng, $mac]);
            }
        }

        respond([
            'ok' => true,
            'message' => 'Position updated successfully'
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to update position: ' . $e->getMessage()], 500);
    }
}

// DELETE position coordinates (revert to unresolved or delete tracker_data entry)
if ($action === 'delete_position_coords' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $id = isset($j['id']) ? (int)$j['id'] : null;
        $recordType = $j['record_type'] ?? 'tracker';

        if (!$id) respond(['ok' => false, 'error' => 'Record ID is required'], 400);

        $pdo = db();

        if ($recordType === 'wifi_scan') {
            // For wifi_scans: revert to unresolved (keep the scan, just remove coordinates)
            $stmt = $pdo->prepare("UPDATE wifi_scans SET resolved = 0, latitude = NULL, longitude = NULL, source = NULL, tracker_data_id = NULL WHERE id = ?");
            $stmt->execute([$id]);

            // Also delete the linked tracker_data entry if exists
            $stmtTd = $pdo->prepare("DELETE FROM tracker_data WHERE id IN (SELECT tracker_data_id FROM wifi_scans WHERE id = ? AND tracker_data_id IS NOT NULL)");
            $stmtTd->execute([$id]);

            respond(['ok' => true, 'message' => 'WiFi scan reverted to unresolved']);
        } else {
            // For tracker_data: delete the record entirely
            $stmt = $pdo->prepare("DELETE FROM tracker_data WHERE id = ?");
            $stmt->execute([$id]);

            // Also unlink any wifi_scans that referenced this
            $stmtWs = $pdo->prepare("UPDATE wifi_scans SET resolved = 0, tracker_data_id = NULL WHERE tracker_data_id = ?");
            $stmtWs->execute([$id]);

            respond(['ok' => true, 'message' => 'Position deleted']);
        }
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
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
        $stmt = $pdo->prepare('UPDATE mac_locations SET latitude = NULL, longitude = NULL, last_queried = NOW() WHERE mac_address = ?');
        $stmt->execute([$mac]);

        // If mac_locations didn't have this MAC, insert it as negative
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare('INSERT INTO mac_locations (mac_address, latitude, longitude, last_queried) VALUES (?, NULL, NULL, NOW()) ON CONFLICT(mac_address) DO UPDATE SET latitude = NULL, longitude = NULL, last_queried = NOW()');
            $stmt->execute([$mac]);
        }

        // Delete tracker_data entries where this MAC is primary (NOT NULL constraint prevents nulling coords)
        $stmt = $pdo->prepare('DELETE FROM tracker_data WHERE primary_mac = ?');
        $stmt->execute([$mac]);
        $affectedPositions = $stmt->rowCount();

        // Also delete entries where this MAC appears in raw_wifi_macs
        $stmt = $pdo->prepare('DELETE FROM tracker_data WHERE raw_wifi_macs LIKE ? AND (primary_mac IS NULL OR primary_mac = ?)');
        $stmt->execute(['%' . $mac . '%', $mac]);
        $affectedPositions += $stmt->rowCount();

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
        $stmt = $pdo->prepare('UPDATE mac_locations SET latitude = ?, longitude = ?, last_queried = NOW() WHERE mac_address = ?');
        $stmt->execute([$lat, $lng, $mac]);

        if ($stmt->rowCount() === 0) {
            // Insert if not exists
            $stmt = $pdo->prepare('INSERT INTO mac_locations (mac_address, latitude, longitude, last_queried) VALUES (?, ?, ?, NOW())');
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
                $stmt = $pdo->prepare("INSERT INTO refetch_state (date, status, reason) VALUES (?, 'pending', 'manual_mac_invalidation') ON CONFLICT (date) DO UPDATE SET status = 'pending', reason = 'manual_mac_invalidation'");
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

// Retry Google Geolocation API for selected MACs
if ($action === 'retry_google_macs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $macs = isset($j['macs']) ? (array)$j['macs'] : [];

        if (empty($macs)) {
            respond(['ok' => false, 'error' => 'No MAC addresses provided'], 400);
        }

        $googleApiKey = getenv('GOOGLE_API_KEY') ?: '';
        if (!$googleApiKey) {
            respond(['ok' => false, 'error' => 'Google API key not configured'], 500);
        }

        $pdo = db();
        $results = [];
        $successCount = 0;
        $failedCount = 0;

        // For each selected MAC, find tracker_data records and get all MACs from that position
        foreach ($macs as $targetMac) {
            $targetMac = trim((string)$targetMac);
            if (!$targetMac) continue;

            // Find a position that contains this MAC (either as primary_mac or in raw_wifi_macs)
            $stmt = $pdo->prepare("
                SELECT raw_wifi_macs, timestamp
                FROM tracker_data
                WHERE source LIKE 'wifi%'
                  AND (primary_mac = ? OR raw_wifi_macs LIKE ?)
                ORDER BY timestamp DESC
                LIMIT 1
            ");
            $stmt->execute([$targetMac, '%' . $targetMac . '%']);
            $row = $stmt->fetch();

            if (!$row || empty($row['raw_wifi_macs'])) {
                $results[] = ['mac' => $targetMac, 'status' => 'no_data', 'message' => 'No WiFi data found for this MAC'];
                $failedCount++;
                continue;
            }

            // Parse all MACs from this position
            $allMacs = array_filter(array_map('trim', explode(',', $row['raw_wifi_macs'])));
            if (count($allMacs) < 1) {
                $results[] = ['mac' => $targetMac, 'status' => 'no_data', 'message' => 'No valid MACs found'];
                $failedCount++;
                continue;
            }

            // Build WiFi access points array for Google API
            // Since we don't have RSSI stored, use a reasonable default (-70 dBm)
            $wifiAccessPoints = [];
            foreach ($allMacs as $mac) {
                $mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac));
                if (strlen($mac) !== 12) continue;
                $formattedMac = implode(':', str_split($mac, 2));
                $wifiAccessPoints[] = [
                    'macAddress' => $formattedMac,
                    'signalStrength' => -70 // Default RSSI since we don't store it
                ];
            }

            if (count($wifiAccessPoints) < 1) {
                $results[] = ['mac' => $targetMac, 'status' => 'invalid_macs', 'message' => 'No valid MAC addresses to query'];
                $failedCount++;
                continue;
            }

            // Call Google Geolocation API
            $url = 'https://www.googleapis.com/geolocation/v1/geolocate?key=' . rawurlencode($googleApiKey);
            $payload = json_encode([
                'considerIp' => false,
                'wifiAccessPoints' => array_slice($wifiAccessPoints, 0, 6) // Max 6 APs
            ], JSON_UNESCAPED_SLASHES);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                $error = $response ? json_decode($response, true) : null;
                $errorMsg = isset($error['error']['message']) ? $error['error']['message'] : 'HTTP ' . $httpCode;
                $results[] = ['mac' => $targetMac, 'status' => 'api_error', 'message' => $errorMsg];
                $failedCount++;
                continue;
            }

            $data = json_decode($response, true);
            if (!isset($data['location']['lat']) || !isset($data['location']['lng'])) {
                $results[] = ['mac' => $targetMac, 'status' => 'no_location', 'message' => 'Google API returned no location'];
                $failedCount++;
                continue;
            }

            $lat = (float)$data['location']['lat'];
            $lng = (float)$data['location']['lng'];
            $accuracy = isset($data['accuracy']) ? (float)$data['accuracy'] : null;

            // Update mac_locations for ALL MACs that were used in this query
            foreach ($wifiAccessPoints as $ap) {
                $macAddr = $ap['macAddress'];
                $stmt = $pdo->prepare("
                    INSERT INTO mac_locations (mac_address, latitude, longitude, last_queried)
                    VALUES (?, ?, ?, NOW())
                    ON CONFLICT(mac_address) DO UPDATE SET
                        latitude = excluded.latitude,
                        longitude = excluded.longitude,
                        last_queried = excluded.last_queried
                ");
                $stmt->execute([$macAddr, $lat, $lng]);
            }

            $results[] = [
                'mac' => $targetMac,
                'status' => 'success',
                'lat' => $lat,
                'lng' => $lng,
                'accuracy' => $accuracy,
                'macs_updated' => count($wifiAccessPoints)
            ];
            $successCount++;
        }

        respond([
            'ok' => true,
            'results' => $results,
            'summary' => [
                'total' => count($macs),
                'success' => $successCount,
                'failed' => $failedCount
            ]
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to retry Google API: ' . $e->getMessage()], 500);
    }
}

// Test Google API and compare with cache coordinates
if ($action === 'test_google_api' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $positionId = isset($j['position_id']) ? (int)$j['position_id'] : null;

        if (!$positionId) {
            respond(['ok' => false, 'error' => 'Position ID is required'], 400);
        }

        $googleApiKey = getenv('GOOGLE_API_KEY') ?: '';
        if (!$googleApiKey) {
            respond(['ok' => false, 'error' => 'Google API key not configured'], 500);
        }

        $pdo = db();

        // Get position data
        $stmt = $pdo->prepare("SELECT * FROM tracker_data WHERE id = ?");
        $stmt->execute([$positionId]);
        $position = $stmt->fetch();

        if (!$position) {
            respond(['ok' => false, 'error' => 'Position not found'], 404);
        }

        if (empty($position['raw_wifi_macs'])) {
            respond(['ok' => false, 'error' => 'No WiFi MAC data for this position'], 400);
        }

        // Current (cache) coordinates
        $cacheLat = (float)$position['latitude'];
        $cacheLng = (float)$position['longitude'];

        // Parse all MACs from the position
        $allMacs = array_filter(array_map('trim', explode(',', $position['raw_wifi_macs'])));

        // Build WiFi access points array for Google API
        $wifiAccessPoints = [];
        foreach ($allMacs as $mac) {
            $mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac));
            if (strlen($mac) !== 12) continue;
            $formattedMac = implode(':', str_split($mac, 2));
            $wifiAccessPoints[] = [
                'macAddress' => $formattedMac,
                'signalStrength' => -70
            ];
        }

        if (count($wifiAccessPoints) < 1) {
            respond(['ok' => false, 'error' => 'No valid MAC addresses to query'], 400);
        }

        // Call Google Geolocation API
        $url = 'https://www.googleapis.com/geolocation/v1/geolocate?key=' . rawurlencode($googleApiKey);
        $payload = json_encode([
            'considerIp' => false,
            'wifiAccessPoints' => array_slice($wifiAccessPoints, 0, 6)
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $error = $response ? json_decode($response, true) : null;
            $errorMsg = isset($error['error']['message']) ? $error['error']['message'] : 'HTTP ' . $httpCode;
            respond(['ok' => false, 'error' => 'Google API error: ' . $errorMsg], 500);
        }

        $data = json_decode($response, true);
        if (!isset($data['location']['lat']) || !isset($data['location']['lng'])) {
            respond(['ok' => false, 'error' => 'Google API returned no location'], 500);
        }

        $googleLat = (float)$data['location']['lat'];
        $googleLng = (float)$data['location']['lng'];
        $accuracy = isset($data['accuracy']) ? (float)$data['accuracy'] : null;

        // Calculate distance between cache and Google coordinates
        $distance = haversineDistance($cacheLat, $cacheLng, $googleLat, $googleLng);

        respond([
            'ok' => true,
            'cache' => [
                'lat' => $cacheLat,
                'lng' => $cacheLng
            ],
            'google' => [
                'lat' => $googleLat,
                'lng' => $googleLng,
                'accuracy' => $accuracy
            ],
            'distance_meters' => round($distance, 1),
            'macs_used' => count($wifiAccessPoints)
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to test Google API: ' . $e->getMessage()], 500);
    }
}

// Delete MAC coordinates (set to NULL)
if ($action === 'delete_mac_coordinates' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $macs = isset($j['macs']) ? (array)$j['macs'] : [];

        if (empty($macs)) {
            respond(['ok' => false, 'error' => 'No MAC addresses provided'], 400);
        }

        $pdo = db();
        $deleted = 0;

        foreach ($macs as $mac) {
            $mac = trim((string)$mac);
            if (!$mac) continue;

            $stmt = $pdo->prepare("UPDATE mac_locations SET latitude = NULL, longitude = NULL, last_queried = NOW() WHERE mac_address = ?");
            $stmt->execute([$mac]);
            $deleted += $stmt->rowCount();
        }

        respond([
            'ok' => true,
            'message' => "Súradnice boli zmazané pre $deleted MAC adries",
            'deleted' => $deleted
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to delete MAC coordinates: ' . $e->getMessage()], 500);
    }
}

// ========== LOG VIEWER (streaming - line by line) ==========

if ($action === 'get_logs') {
    try {
        $logFile = get_log_file_path();
        $limit = min((int)(qparam('limit') ?: 200), 2000);
        $offset = (int)(qparam('offset') ?: 0);
        $level = qparam('level');
        $search = qparam('search');
        $order = qparam('order') ?: 'desc';
        $dateFrom = qparam('date_from');
        $dateTo = qparam('date_to');

        if ($dateFrom === null && $dateTo === null) {
            $dateFrom = (new DateTimeImmutable('now', new DateTimeZone('Europe/Bratislava')))->sub(new DateInterval('PT6H'))->format('Y-m-d\TH:i:s');
        }

        if (!is_file($logFile)) {
            respond(['ok' => true, 'data' => [
                'logs' => [], 'total' => 0, 'file' => basename($logFile),
                'file_exists' => false, 'date_from' => $dateFrom, 'date_to' => $dateTo
            ]]);
        }

        $logPattern = '/^\[([\d\-T:+\-]+)\]\s*\[([A-Z]+)\]\s*(.*)$/';
        $parsedLogs = [];
        $totalLinesRead = 0;

        $fh = fopen($logFile, 'r');
        if ($fh === false) {
            respond(['ok' => false, 'error' => 'Failed to open log file'], 500);
        }

        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;
            $totalLinesRead++;

            if (!preg_match($logPattern, $line, $matches)) continue;

            $ts = substr($matches[1], 0, 19);
            if ($dateFrom !== null && $ts < $dateFrom) continue;
            if ($dateTo !== null && $ts > $dateTo) continue;

            $lvl = strtolower($matches[2]);
            if ($level !== null && $lvl !== strtolower($level)) continue;

            $msg = $matches[3];
            if ($search !== null && $search !== '') {
                if (stripos($msg, $search) === false && stripos($matches[1], $search) === false) continue;
            }

            $parsedLogs[] = ['timestamp' => $matches[1], 'level' => $lvl, 'message' => $msg];
        }
        fclose($fh);

        $total = count($parsedLogs);

        usort($parsedLogs, function($a, $b) use ($order) {
            $cmp = strcmp($a['timestamp'], $b['timestamp']);
            return $order === 'desc' ? -$cmp : $cmp;
        });

        $paginatedLogs = array_slice($parsedLogs, $offset, $limit);

        respond(['ok' => true, 'data' => [
            'logs' => $paginatedLogs, 'total' => $total,
            'offset' => $offset, 'limit' => $limit,
            'file' => basename($logFile), 'file_exists' => true,
            'file_size' => filesize($logFile),
            'date_from' => $dateFrom, 'date_to' => $dateTo,
            'debug' => ['total_lines_read' => $totalLinesRead, 'lines_after_filter' => $total]
        ]]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to read logs: ' . $e->getMessage()], 500);
    }
}

if ($action === 'get_log_stats') {
    try {
        $logFile = get_log_file_path();
        $days = min((int)(qparam('days') ?: 7), 90);

        if (!is_file($logFile)) {
            respond(['ok' => true, 'data' => [
                'file_exists' => false,
                'counts' => ['error' => 0, 'info' => 0, 'debug' => 0],
                'google_api_calls' => 0, 'positions_inserted' => 0,
                'perimeter_alerts' => 0, 'fetch_runs' => 0
            ]]);
        }

        $cutoffDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Bratislava')))->sub(new DateInterval("P{$days}D"))->format('Y-m-d');

        $stats = [
            'counts' => ['error' => 0, 'info' => 0, 'debug' => 0],
            'google_api_calls' => 0, 'google_api_success' => 0, 'google_api_failed' => 0,
            'positions_inserted' => 0, 'cache_hits' => 0, 'ibeacon_hits' => 0,
            'perimeter_alerts' => 0, 'fetch_runs' => 0,
            'total_lines' => 0, 'lines_in_range' => 0
        ];

        $logPattern = '/^\[([\d\-T:+\-]+)\]\s*\[([A-Z]+)\]\s*(.*)$/';

        $fh = fopen($logFile, 'r');
        if ($fh === false) {
            respond(['ok' => false, 'error' => 'Failed to open log file'], 500);
        }

        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;
            $stats['total_lines']++;

            if (!preg_match($logPattern, $line, $matches)) continue;

            $lineDate = substr($matches[1], 0, 10);
            if ($lineDate < $cutoffDate) continue;

            $stats['lines_in_range']++;
            $level = strtolower($matches[2]);
            $message = $matches[3];

            if (isset($stats['counts'][$level])) $stats['counts'][$level]++;

            if (strpos($message, 'GOOGLE REQUEST:') !== false) $stats['google_api_calls']++;
            if (strpos($message, 'GOOGLE SUCCESS:') !== false) $stats['google_api_success']++;
            if (strpos($message, 'GOOGLE ERROR:') !== false || strpos($message, 'GOOGLE FAILED') !== false) $stats['google_api_failed']++;
            if (strpos($message, 'INSERT SUCCESS:') !== false) $stats['positions_inserted']++;
            if (strpos($message, 'USING CACHE:') !== false || strpos($message, 'CACHE [+] HIT:') !== false) $stats['cache_hits']++;
            if (strpos($message, 'IBEACON MATCH') !== false) $stats['ibeacon_hits']++;
            if (strpos($message, 'PERIMETER BREACH DETECTED:') !== false) $stats['perimeter_alerts']++;
            if ($message === '=== FETCH START ===') $stats['fetch_runs']++;
        }
        fclose($fh);

        $stats['file_exists'] = true;
        $stats['file_size'] = filesize($logFile);
        $stats['days_filter'] = $days;
        $stats['cutoff_date'] = $cutoffDate;

        respond(['ok' => true, 'data' => $stats]);
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

        // Clear the log file
        if (file_put_contents($logFile, '') === false) {
            respond(['ok' => false, 'error' => 'Failed to clear log file'], 500);
        }

        respond([
            'ok' => true,
            'message' => 'Log file cleared'
        ]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to clear logs: ' . $e->getMessage()], 500);
    }
}

if ($action === 'delete_old_logs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $beforeMonth = $j['before_month'] ?? null;

        if (!$beforeMonth || !preg_match('/^\d{4}-\d{2}$/', $beforeMonth)) {
            respond(['ok' => false, 'error' => 'Invalid before_month format. Use YYYY-MM.'], 400);
        }

        $logFile = get_log_file_path();
        if (!is_file($logFile)) {
            respond(['ok' => true, 'deleted' => 0, 'remaining' => 0]);
        }

        // Cutoff = first day of NEXT month (selected month is included in deletion)
        $cutoffObj = new DateTimeImmutable($beforeMonth . '-01');
        $nextMonth = $cutoffObj->modify('+1 month');
        $cutoffDate = $nextMonth->format('Y-m-d') . 'T00:00:00';

        $logPattern = '/^\[([\d\-T:+\-]+)\]\s*\[([A-Z]+)\]\s*(.*)$/';
        $tmpFile = $logFile . '.tmp';
        $deletedCount = 0;
        $keptCount = 0;

        $fhIn = fopen($logFile, 'r');
        $fhOut = fopen($tmpFile, 'w');
        if ($fhIn === false || $fhOut === false) {
            respond(['ok' => false, 'error' => 'Failed to open log file'], 500);
        }

        while (($line = fgets($fhIn)) !== false) {
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === '') continue;

            if (preg_match($logPattern, $trimmed, $matches)) {
                $ts = substr($matches[1], 0, 19);
                if ($ts < $cutoffDate) {
                    $deletedCount++;
                    continue;
                }
            }
            fwrite($fhOut, $trimmed . "\n");
            $keptCount++;
        }
        fclose($fhIn);
        fclose($fhOut);

        if ($deletedCount === 0) {
            unlink($tmpFile);
            respond(['ok' => true, 'deleted' => 0, 'remaining' => $keptCount]);
        }

        if (!rename($tmpFile, $logFile)) {
            unlink($tmpFile);
            respond(['ok' => false, 'error' => 'Failed to replace log file'], 500);
        }

        respond(['ok' => true, 'deleted' => $deletedCount, 'remaining' => $keptCount]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => 'Failed to delete old logs: ' . $e->getMessage()], 500);
    }
}

if ($action === 'get_log_date_range') {
    try {
        $logFile = get_log_file_path();
        if (!is_file($logFile)) {
            respond(['ok' => true, 'data' => ['oldest' => null, 'newest' => null, 'months' => new \stdClass()]]);
        }

        $logPattern = '/^\[([\d\-T:+\-]+)\]\s*\[([A-Z]+)\]\s*(.*)$/';
        $oldest = null;
        $newest = null;
        $months = [];

        $fh = fopen($logFile, 'r');
        if ($fh === false) {
            respond(['ok' => false, 'error' => 'Failed to open log file'], 500);
        }

        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;

            if (preg_match($logPattern, $line, $matches)) {
                $ts = substr($matches[1], 0, 19);
                if ($oldest === null || $ts < $oldest) $oldest = $ts;
                if ($newest === null || $ts > $newest) $newest = $ts;
                $month = substr($ts, 0, 7);
                if (!isset($months[$month])) $months[$month] = 0;
                $months[$month]++;
            }
        }
        fclose($fh);

        ksort($months);

        respond(['ok' => true, 'data' => [
            'oldest' => $oldest,
            'newest' => $newest,
            'months' => empty($months) ? new \stdClass() : $months
        ]]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// ========== PERIMETER ZONES MANAGEMENT ==========

// Create perimeters table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS perimeters (
            id SERIAL PRIMARY KEY,
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
            id SERIAL PRIMARY KEY,
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
            id SERIAL PRIMARY KEY,
            perimeter_id INTEGER NOT NULL,
            alert_type TEXT NOT NULL,
            position_id INTEGER,
            latitude DOUBLE PRECISION NOT NULL,
            longitude DOUBLE PRECISION NOT NULL,
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
        // Check if device_id column exists (migration may not have run yet)
        $hasDeviceId = false;
        $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'perimeters'");
        while ($c = $cols->fetch()) {
            if ($c['column_name'] === 'device_id') { $hasDeviceId = true; break; }
        }

        $q = $pdo->query("SELECT * FROM perimeters ORDER BY id ASC");
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
                'notification_email' => $r['notification_email'] ?? null,
                'emails' => $emails,
                'is_active' => (bool)$r['is_active'],
                'color' => $r['color'] ?? '#ff6b6b',
                'device_id' => ($hasDeviceId && isset($r['device_id']) && $r['device_id'] !== null) ? (int)$r['device_id'] : null,
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
        $deviceId = isset($j['device_id']) ? (int)$j['device_id'] : null;
        if ($deviceId === 0) $deviceId = null;

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

        // Check if device_id column exists
        $hasDeviceIdCol = false;
        $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'perimeters'");
        while ($c = $cols->fetch()) {
            if ($c['column_name'] === 'device_id') { $hasDeviceIdCol = true; break; }
        }

        $pdo->beginTransaction();

        if ($id) {
            // Update existing
            $sql = "UPDATE perimeters SET name = :name, polygon = :polygon, alert_on_enter = :enter, alert_on_exit = :exit, notification_email = :email, is_active = :active, color = :color, updated_at = :updated"
                 . ($hasDeviceIdCol ? ", device_id = :device_id" : "")
                 . " WHERE id = :id";
            $params = [
                ':id' => $id,
                ':name' => $name,
                ':polygon' => $polygonJson,
                ':enter' => $alertOnEnter,
                ':exit' => $alertOnExit,
                ':email' => $notificationEmail ?: null,
                ':active' => $isActive,
                ':color' => $color,
                ':updated' => $now
            ];
            if ($hasDeviceIdCol) $params[':device_id'] = $deviceId;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $perimeterId = $id;
        } else {
            // Insert new
            $fields = "name, polygon, alert_on_enter, alert_on_exit, notification_email, is_active, color, created_at, updated_at"
                    . ($hasDeviceIdCol ? ", device_id" : "");
            $placeholders = ":name, :polygon, :enter, :exit, :email, :active, :color, :created, :updated"
                          . ($hasDeviceIdCol ? ", :device_id" : "");
            $params = [
                ':name' => $name,
                ':polygon' => $polygonJson,
                ':enter' => $alertOnEnter,
                ':exit' => $alertOnExit,
                ':email' => $notificationEmail ?: null,
                ':active' => $isActive,
                ':color' => $color,
                ':created' => $now,
                ':updated' => $now
            ];
            if ($hasDeviceIdCol) $params[':device_id'] = $deviceId;
            $stmt = $pdo->prepare("INSERT INTO perimeters ($fields) VALUES ($placeholders)");
            $stmt->execute($params);
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

// GET /api.php?action=n8n_status&device_id=X
// Returns: current device status, last position, battery info
// If device_id specified, returns for that device. Otherwise returns all devices.
if ($action === 'n8n_status') {
    if (!verify_n8n_api_key()) {
        respond(['ok' => false, 'error' => 'Invalid or missing API key'], 401);
    }

    try {
        $deviceId = qparam('device_id');

        if ($deviceId) {
            // Single device status
            $stmtDev = $pdo->prepare("SELECT * FROM devices WHERE id = :did");
            $stmtDev->execute([':did' => (int)$deviceId]);
            $dev = $stmtDev->fetch();

            $deviceStatus = null;
            if ($dev) {
                $stmtStatus = $pdo->prepare("SELECT * FROM device_status WHERE device_eui = :eui LIMIT 1");
                $stmtStatus->execute([':eui' => $dev['device_eui']]);
                $r = $stmtStatus->fetch();
                if ($r) {
                    $deviceStatus = [
                        'device_eui' => $r['device_eui'],
                        'device_name' => $dev['name'],
                        'battery_status' => $r['battery_state'] === 1 ? 'good' : ($r['battery_state'] === 0 ? 'low' : 'unknown'),
                        'online_status' => $r['online_status'] === 1 ? 'online' : 'offline',
                        'latest_message_time' => $r['latest_message_time'],
                        'last_update' => $r['last_update']
                    ];
                }
            }

            $lastPosition = null;
            $stmtPos = $pdo->prepare("SELECT * FROM tracker_data WHERE device_id = :did ORDER BY timestamp DESC LIMIT 1");
            $stmtPos->execute([':did' => (int)$deviceId]);
            if ($r = $stmtPos->fetch()) {
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
        } else {
            // All devices status
            $devices = [];
            $stmtDevices = $pdo->query("SELECT * FROM devices WHERE is_active = 1 ORDER BY id");
            while ($dev = $stmtDevices->fetch()) {
                $stmtStatus = $pdo->prepare("SELECT * FROM device_status WHERE device_eui = :eui LIMIT 1");
                $stmtStatus->execute([':eui' => $dev['device_eui']]);
                $status = $stmtStatus->fetch();

                $stmtPos = $pdo->prepare("SELECT * FROM tracker_data WHERE device_id = :did ORDER BY timestamp DESC LIMIT 1");
                $stmtPos->execute([':did' => (int)$dev['id']]);
                $pos = $stmtPos->fetch();

                $devices[] = [
                    'id' => (int)$dev['id'],
                    'name' => $dev['name'],
                    'device_eui' => $dev['device_eui'],
                    'color' => $dev['color'],
                    'battery_status' => $status ? ($status['battery_state'] === 1 ? 'good' : ($status['battery_state'] === 0 ? 'low' : 'unknown')) : 'unknown',
                    'online_status' => $status ? ($status['online_status'] === 1 ? 'online' : 'offline') : 'unknown',
                    'last_position' => $pos ? [
                        'latitude' => (float)$pos['latitude'],
                        'longitude' => (float)$pos['longitude'],
                        'timestamp' => $pos['timestamp'],
                        'source' => $pos['source'],
                        'maps_url' => "https://www.google.com/maps?q={$pos['latitude']},{$pos['longitude']}"
                    ] : null
                ];
            }

            respond([
                'ok' => true,
                'data' => [
                    'devices' => $devices,
                    'count' => count($devices),
                    'generated_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP')
                ]
            ]);
        }
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// GET /api.php?action=n8n_positions&date=YYYY-MM-DD&limit=100&device_id=X
// Returns: positions for a specific date or recent positions
if ($action === 'n8n_positions') {
    if (!verify_n8n_api_key()) {
        respond(['ok' => false, 'error' => 'Invalid or missing API key'], 401);
    }

    try {
        $date = qparam('date');
        $limit = min((int)(qparam('limit') ?: 100), 1000);
        $deviceId = qparam('device_id');

        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $sql = "SELECT td.id, td.timestamp, td.latitude, td.longitude, td.source, td.device_id, d.name as device_name
                    FROM tracker_data td LEFT JOIN devices d ON td.device_id = d.id
                    WHERE DATE(td.timestamp) = :date";
            if ($deviceId) $sql .= " AND td.device_id = :did";
            $sql .= " ORDER BY td.timestamp ASC LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':date', $date, PDO::PARAM_STR);
            if ($deviceId) $stmt->bindValue(':did', (int)$deviceId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $sql = "SELECT td.id, td.timestamp, td.latitude, td.longitude, td.source, td.device_id, d.name as device_name
                    FROM tracker_data td LEFT JOIN devices d ON td.device_id = d.id";
            if ($deviceId) $sql .= " WHERE td.device_id = :did";
            $sql .= " ORDER BY td.timestamp DESC LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            if ($deviceId) $stmt->bindValue(':did', (int)$deviceId, PDO::PARAM_INT);
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
                'device_id' => $r['device_id'] ? (int)$r['device_id'] : null,
                'device_name' => $r['device_name'] ?? null,
                'maps_url' => "https://www.google.com/maps?q={$r['latitude']},{$r['longitude']}"
            ];
        }

        respond([
            'ok' => true,
            'data' => [
                'positions' => $positions,
                'count' => count($positions),
                'date_filter' => $date ?: null,
                'device_id' => $deviceId ? (int)$deviceId : null,
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
            WHERE pa.sent_at >= NOW() - INTERVAL '1 day' * :days
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

// ========== MULTI-DEVICE MANAGEMENT ==========

// Ensure devices table exists (auto-migration for existing installs)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS devices (
        id SERIAL PRIMARY KEY,
        name TEXT NOT NULL,
        device_eui TEXT NOT NULL UNIQUE,
        color TEXT NOT NULL DEFAULT '#3388ff',
        icon TEXT DEFAULT 'default',
        is_active INTEGER NOT NULL DEFAULT 1,
        notifications_enabled INTEGER DEFAULT 0,
        notification_email TEXT,
        notification_webhook TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS device_perimeters (
        id SERIAL PRIMARY KEY,
        device_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        latitude DOUBLE PRECISION NOT NULL,
        longitude DOUBLE PRECISION NOT NULL,
        radius_meters DOUBLE PRECISION NOT NULL DEFAULT 500,
        alert_on_enter INTEGER DEFAULT 0,
        alert_on_exit INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wifi_scans (
        id SERIAL PRIMARY KEY,
        timestamp TEXT NOT NULL,
        device_id INTEGER NOT NULL DEFAULT 1,
        macs_json TEXT NOT NULL,
        mac_count INTEGER NOT NULL DEFAULT 0,
        resolved INTEGER NOT NULL DEFAULT 0,
        latitude DOUBLE PRECISION,
        longitude DOUBLE PRECISION,
        source TEXT,
        tracker_data_id INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES devices(id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wifi_scans_device_ts ON wifi_scans(device_id, timestamp)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wifi_scans_resolved ON wifi_scans(resolved)");
} catch (Throwable $e) {
    // Tables may already exist
}

// GET /api.php?action=get_devices
if ($action === 'get_devices') {
    try {
        // Filter devices by user permissions
        if ($currentUser) {
            [$devFilter, $devParams] = userDeviceFilter($pdo, $currentUser, 'd.id');
            $stmt = $pdo->prepare("SELECT id, name, device_eui, color, icon, is_active, notifications_enabled, notification_email, notification_webhook, created_at, updated_at FROM devices d WHERE $devFilter ORDER BY id ASC");
            $stmt->execute($devParams);
            $q = $stmt;
        } else {
            $q = $pdo->query("SELECT id, name, device_eui, color, icon, is_active, notifications_enabled, notification_email, notification_webhook, created_at, updated_at FROM devices ORDER BY id ASC");
        }
        $out = [];
        while ($r = $q->fetch()) {
            // Get last position for this device
            $lastPos = null;
            $stmtPos = $pdo->prepare("SELECT timestamp, latitude, longitude, source FROM tracker_data WHERE device_id = :did ORDER BY timestamp DESC LIMIT 1");
            $stmtPos->execute([':did' => (int)$r['id']]);
            $pos = $stmtPos->fetch();
            if ($pos) {
                $lastPos = [
                    'timestamp' => utc_to_local($pos['timestamp']),
                    'latitude' => (float)$pos['latitude'],
                    'longitude' => (float)$pos['longitude'],
                    'source' => $pos['source']
                ];
            }

            // Get device status (battery, online)
            $deviceStatus = null;
            $stmtStatus = $pdo->prepare("SELECT battery_state, online_status, latest_message_time, last_update FROM device_status WHERE device_eui = :eui LIMIT 1");
            $stmtStatus->execute([':eui' => $r['device_eui']]);
            $status = $stmtStatus->fetch();
            if ($status) {
                $deviceStatus = [
                    'battery_state' => $status['battery_state'] !== null ? (int)$status['battery_state'] : null,
                    'online_status' => $status['online_status'] !== null ? (int)$status['online_status'] : null,
                    'latest_message_time' => $status['latest_message_time'],
                    'last_update' => $status['last_update']
                ];
            }

            $out[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'device_eui' => $r['device_eui'],
                'color' => $r['color'],
                'icon' => $r['icon'],
                'is_active' => (bool)(int)$r['is_active'],
                'notifications_enabled' => (bool)(int)$r['notifications_enabled'],
                'notification_email' => $r['notification_email'],
                'notification_webhook' => $r['notification_webhook'],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
                'last_position' => $lastPos,
                'status' => $deviceStatus
            ];
        }
        respond(['ok' => true, 'data' => $out]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// POST /api.php?action=save_device
if ($action === 'save_device' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $id = isset($j['id']) ? (int)$j['id'] : null;
        $name = trim((string)($j['name'] ?? ''));
        $deviceEui = trim((string)($j['device_eui'] ?? ''));
        $color = trim((string)($j['color'] ?? '#3388ff'));
        $icon = trim((string)($j['icon'] ?? 'default'));
        $isActive = isset($j['is_active']) ? (int)(bool)$j['is_active'] : 1;
        $notificationsEnabled = isset($j['notifications_enabled']) ? (int)(bool)$j['notifications_enabled'] : 0;
        $notificationEmail = trim((string)($j['notification_email'] ?? ''));
        $notificationWebhook = trim((string)($j['notification_webhook'] ?? ''));

        if ($name === '') {
            respond(['ok' => false, 'error' => 'Názov zariadenia je povinný'], 400);
        }
        if ($deviceEui === '') {
            respond(['ok' => false, 'error' => 'Device EUI je povinný'], 400);
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#3388ff';
        }
        if ($notificationEmail !== '' && !filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
            respond(['ok' => false, 'error' => 'Neplatná e-mailová adresa'], 400);
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE devices SET
                    name = :name,
                    device_eui = :eui,
                    color = :color,
                    icon = :icon,
                    is_active = :active,
                    notifications_enabled = :notif,
                    notification_email = :email,
                    notification_webhook = :webhook,
                    updated_at = :updated
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':eui' => $deviceEui,
                ':color' => $color,
                ':icon' => $icon,
                ':active' => $isActive,
                ':notif' => $notificationsEnabled,
                ':email' => $notificationEmail ?: null,
                ':webhook' => $notificationWebhook ?: null,
                ':updated' => $now
            ]);
            $deviceId = $id;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO devices (name, device_eui, color, icon, is_active, notifications_enabled, notification_email, notification_webhook, created_at, updated_at)
                VALUES (:name, :eui, :color, :icon, :active, :notif, :email, :webhook, :created, :updated)
            ");
            $stmt->execute([
                ':name' => $name,
                ':eui' => $deviceEui,
                ':color' => $color,
                ':icon' => $icon,
                ':active' => $isActive,
                ':notif' => $notificationsEnabled,
                ':email' => $notificationEmail ?: null,
                ':webhook' => $notificationWebhook ?: null,
                ':created' => $now,
                ':updated' => $now
            ]);
            $deviceId = (int)$pdo->lastInsertId();

            // Auto-assign new device to all admin users and the creating user
            $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
            $assignStmt = $pdo->prepare("INSERT INTO user_devices (user_id, device_id) VALUES (:uid, :did) ON CONFLICT DO NOTHING");
            foreach ($admins as $a) {
                $assignStmt->execute([':uid' => $a['id'], ':did' => $deviceId]);
            }
            if ($currentUser && !$currentUser['is_admin']) {
                $assignStmt->execute([':uid' => $currentUser['id'], ':did' => $deviceId]);
            }
        }

        respond(['ok' => true, 'message' => $id ? 'Zariadenie aktualizované' : 'Zariadenie vytvorené', 'id' => $deviceId]);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'UNIQUE constraint failed') !== false || strpos($msg, 'unique') !== false) {
            respond(['ok' => false, 'error' => 'Zariadenie s týmto EUI už existuje'], 409);
        }
        respond(['ok' => false, 'error' => $msg], 500);
    }
}

// DELETE /api.php?action=delete_device
if ($action === 'delete_device') {
    try {
        $id = qparam('id');
        if (!$id) {
            $j = json_body();
            $id = isset($j['id']) ? (int)$j['id'] : null;
        }
        if (!$id) {
            respond(['ok' => false, 'error' => 'Missing device id'], 400);
        }
        $id = (int)$id;

        // Check if it's the last device
        $count = (int)$pdo->query("SELECT COUNT(*) as c FROM devices")->fetch()['c'];
        if ($count <= 1) {
            respond(['ok' => false, 'error' => 'Nemožno zmazať posledné zariadenie'], 400);
        }

        $pdo->beginTransaction();

        // Delete associated tracker data
        $pdo->prepare("DELETE FROM tracker_data WHERE device_id = :did")->execute([':did' => $id]);
        // Delete device perimeters
        $pdo->prepare("DELETE FROM device_perimeters WHERE device_id = :did")->execute([':did' => $id]);
        // Delete device
        $pdo->prepare("DELETE FROM devices WHERE id = :id")->execute([':id' => $id]);

        $pdo->commit();
        respond(['ok' => true, 'message' => 'Zariadenie a súvisiace dáta zmazané']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// POST /api.php?action=toggle_device
if ($action === 'toggle_device' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $id = isset($j['id']) ? (int)$j['id'] : null;
        $isActive = isset($j['is_active']) ? (int)(bool)$j['is_active'] : 0;

        if (!$id) {
            respond(['ok' => false, 'error' => 'Missing device id'], 400);
        }

        $stmt = $pdo->prepare("UPDATE devices SET is_active = :active, updated_at = :updated WHERE id = :id");
        $stmt->execute([
            ':id' => $id,
            ':active' => $isActive,
            ':updated' => (new DateTimeImmutable())->format('Y-m-d H:i:s')
        ]);

        respond(['ok' => true, 'message' => $isActive ? 'Zariadenie aktivované' : 'Zariadenie deaktivované']);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// POST /api.php?action=send_device_command
if ($action === 'send_device_command' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $deviceId = isset($j['device_id']) ? (int)$j['device_id'] : null;
        $command = $j['command'] ?? '';

        if (!$deviceId) {
            respond(['ok' => false, 'error' => 'Missing device_id'], 400);
        }
        if (!in_array($command, ['buzzer_on', 'buzzer_off'], true)) {
            respond(['ok' => false, 'error' => 'Invalid command. Supported: buzzer_on, buzzer_off'], 400);
        }

        // Look up device EUI
        $stmt = $pdo->prepare("SELECT device_eui FROM devices WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $deviceId]);
        $device = $stmt->fetch();
        if (!$device) {
            respond(['ok' => false, 'error' => 'Device not found'], 404);
        }

        $eui = $device['device_eui'];
        $payloadHex = ($command === 'buzzer_on') ? '8201' : '8200';

        $aid = getenv('SENSECAP_ACCESS_ID') ?: '';
        $akey = getenv('SENSECAP_ACCESS_KEY') ?: '';
        if (!$aid || !$akey) {
            respond(['ok' => false, 'error' => 'SenseCAP credentials not configured'], 500);
        }

        $url = 'https://sensecap.seeed.cc/openapi/send_cmd';
        $payload = json_encode([
            'device_eui' => $eui,
            'port' => 5,
            'confirmed' => false,
            'payload_hex' => $payloadHex
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $aid . ':' . $akey,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json'
            ]
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            respond(['ok' => false, 'error' => 'SenseCAP API request failed: ' . $curlErr], 502);
        }
        if ($httpCode !== 200) {
            respond(['ok' => false, 'error' => 'SenseCAP API returned HTTP ' . $httpCode, 'detail' => $body], 502);
        }

        $json = json_decode($body, true);
        if (!is_array($json) || ($json['code'] ?? '') !== '0') {
            respond(['ok' => false, 'error' => 'SenseCAP API error: ' . ($json['msg'] ?? 'Unknown error')], 502);
        }

        // Try to get upload interval from device status for ETA
        $uploadInterval = null;
        $stmtStatus = $pdo->prepare("SELECT latest_message_time FROM device_status WHERE device_eui = :eui LIMIT 1");
        $stmtStatus->execute([':eui' => $eui]);
        $statusRow = $stmtStatus->fetch();

        $response = [
            'ok' => true,
            'message' => ($command === 'buzzer_on') ? 'Buzzer command sent' : 'Buzzer off command sent',
            'command' => $command,
            'device_eui' => $eui
        ];

        // Include upload interval from API response if available
        if (isset($json['data']['upload_interval'])) {
            $response['upload_interval_seconds'] = (int)$json['data']['upload_interval'];
        }

        respond($response);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// ========== DEVICE PERIMETERS (Circular Geofences) ==========

// GET /api.php?action=get_device_perimeters&device_id=X
if ($action === 'get_device_perimeters') {
    try {
        $deviceId = qparam('device_id');
        if ($deviceId) {
            $stmt = $pdo->prepare("SELECT dp.*, d.name as device_name, d.color as device_color FROM device_perimeters dp JOIN devices d ON dp.device_id = d.id WHERE dp.device_id = :did ORDER BY dp.id ASC");
            $stmt->execute([':did' => (int)$deviceId]);
        } else {
            $stmt = $pdo->query("SELECT dp.*, d.name as device_name, d.color as device_color FROM device_perimeters dp JOIN devices d ON dp.device_id = d.id ORDER BY dp.device_id, dp.id ASC");
        }
        $out = [];
        while ($r = $stmt->fetch()) {
            $out[] = [
                'id' => (int)$r['id'],
                'device_id' => (int)$r['device_id'],
                'device_name' => $r['device_name'],
                'device_color' => $r['device_color'],
                'name' => $r['name'],
                'latitude' => (float)$r['latitude'],
                'longitude' => (float)$r['longitude'],
                'radius_meters' => (float)$r['radius_meters'],
                'alert_on_enter' => (bool)(int)$r['alert_on_enter'],
                'alert_on_exit' => (bool)(int)$r['alert_on_exit'],
                'is_active' => (bool)(int)$r['is_active'],
                'created_at' => $r['created_at']
            ];
        }
        respond(['ok' => true, 'data' => $out]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// POST /api.php?action=save_device_perimeter
if ($action === 'save_device_perimeter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        $id = isset($j['id']) ? (int)$j['id'] : null;
        $deviceId = isset($j['device_id']) ? (int)$j['device_id'] : null;
        $name = trim((string)($j['name'] ?? ''));
        $lat = isset($j['latitude']) ? (float)$j['latitude'] : null;
        $lng = isset($j['longitude']) ? (float)$j['longitude'] : null;
        $radius = isset($j['radius_meters']) ? (float)$j['radius_meters'] : 500;
        $alertOnEnter = isset($j['alert_on_enter']) ? (int)(bool)$j['alert_on_enter'] : 0;
        $alertOnExit = isset($j['alert_on_exit']) ? (int)(bool)$j['alert_on_exit'] : 0;
        $isActive = isset($j['is_active']) ? (int)(bool)$j['is_active'] : 1;

        if (!$deviceId && !$id) {
            respond(['ok' => false, 'error' => 'device_id je povinný'], 400);
        }
        if ($name === '') {
            respond(['ok' => false, 'error' => 'Názov perimetra je povinný'], 400);
        }
        if ($lat === null || $lng === null) {
            respond(['ok' => false, 'error' => 'Súradnice sú povinné'], 400);
        }
        if ($radius < 10 || $radius > 50000) {
            respond(['ok' => false, 'error' => 'Polomer musí byť medzi 10 a 50000 metrov'], 400);
        }

        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE device_perimeters SET
                    name = :name,
                    latitude = :lat,
                    longitude = :lng,
                    radius_meters = :radius,
                    alert_on_enter = :enter,
                    alert_on_exit = :exit,
                    is_active = :active
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':lat' => $lat,
                ':lng' => $lng,
                ':radius' => $radius,
                ':enter' => $alertOnEnter,
                ':exit' => $alertOnExit,
                ':active' => $isActive
            ]);
            $perimeterId = $id;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO device_perimeters (device_id, name, latitude, longitude, radius_meters, alert_on_enter, alert_on_exit, is_active)
                VALUES (:did, :name, :lat, :lng, :radius, :enter, :exit, :active)
            ");
            $stmt->execute([
                ':did' => $deviceId,
                ':name' => $name,
                ':lat' => $lat,
                ':lng' => $lng,
                ':radius' => $radius,
                ':enter' => $alertOnEnter,
                ':exit' => $alertOnExit,
                ':active' => $isActive
            ]);
            $perimeterId = (int)$pdo->lastInsertId();
        }

        respond(['ok' => true, 'message' => $id ? 'Perimeter aktualizovaný' : 'Perimeter vytvorený', 'id' => $perimeterId]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// DELETE /api.php?action=delete_device_perimeter&id=X
if ($action === 'delete_device_perimeter') {
    try {
        $id = qparam('id');
        if (!$id) {
            $j = json_body();
            $id = isset($j['id']) ? (int)$j['id'] : null;
        }
        if (!$id) {
            respond(['ok' => false, 'error' => 'Missing perimeter id'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM device_perimeters WHERE id = :id");
        $stmt->execute([':id' => (int)$id]);

        respond(['ok' => true, 'message' => 'Perimeter zmazaný']);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

respond(['ok'=>false,'error'=>'unknown action'], 400);