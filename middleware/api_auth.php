<?php
declare(strict_types=1);

/**
 * 2-Tier API Authentication Middleware
 *
 * Authentication flow:
 * 1. TRUST_DOCKER_NETWORK=true && IP is 172.x.x.x / 10.x.x.x? → Allow (internal services, n8n)
 * 2. Validate via family-office: POST /api/admin/validate-key → Allow if valid
 *
 * Environment variables:
 * - AUTH_API_URL: URL to family-office (default: http://family-office:3001)
 * - TRUST_DOCKER_NETWORK: true = allow requests from Docker network without auth
 * - INTERNAL_API_KEY: Shared key for communication with family-office
 */

// Configuration getters
function getApiAuthConfig(): array {
    return [
        'auth_api_url' => getenv('AUTH_API_URL') ?: 'http://family-office:3001',
        'internal_api_key' => getenv('INTERNAL_API_KEY') ?: null,
        'trust_docker_network' => strtolower(getenv('TRUST_DOCKER_NETWORK') ?: 'false') === 'true',
        'service_name' => 'tracker'
    ];
}

/**
 * Check if IP address is from Docker network (trusted internal network)
 * Docker bridge networks typically use 172.x.x.x ranges
 */
function isDockerNetwork(?string $ip): bool {
    if (empty($ip)) {
        return false;
    }

    // Remove ::ffff: prefix for IPv4-mapped IPv6 addresses
    $cleanIp = preg_replace('/^::ffff:/', '', $ip);

    // Check for Docker/internal network ranges
    return str_starts_with($cleanIp, '172.') ||
           str_starts_with($cleanIp, '10.') ||
           $cleanIp === '127.0.0.1' ||
           $cleanIp === 'localhost';
}

/**
 * Get client IP address
 */
function getClientIp(): ?string {
    // Check for forwarded IP first (if behind proxy)
    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    if ($forwardedFor) {
        // Take the first IP in the list (original client)
        $ips = array_map('trim', explode(',', $forwardedFor));
        return $ips[0] ?? null;
    }

    // Fall back to direct connection IP
    return $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? null;
}

/**
 * Validate API key against family-office service
 */
function validateApiKeyWithFamilyOffice(string $apiKey, array $config): ?array {
    if (empty($apiKey)) {
        return null;
    }

    $url = rtrim($config['auth_api_url'], '/') . '/api/admin/validate-key';

    $ch = curl_init($url);

    $headers = ['Content-Type: application/json'];
    if (!empty($config['internal_api_key'])) {
        $headers[] = 'X-Internal-Api-Key: ' . $config['internal_api_key'];
    }

    $payload = json_encode([
        'apiKey' => $apiKey,
        'service' => $config['service_name']
    ]);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("API key validation error: $error");
        return null;
    }

    if ($httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['valid']) || $data['valid'] !== true) {
        return null;
    }

    return [
        'valid' => true,
        'name' => $data['name'] ?? 'family-office-key',
        'permissions' => $data['permissions'] ?? ['read', 'write']
    ];
}

/**
 * Main authentication function - returns authentication result or null on failure
 *
 * @return array|null Returns auth info on success, null on failure
 *         ['name' => string, 'permissions' => array, 'method' => string]
 */
function authenticateApiRequest(): ?array {
    $config = getApiAuthConfig();
    $clientIp = getClientIp();

    // Tier 1: Trust Docker network - allow without API key
    if ($config['trust_docker_network'] && isDockerNetwork($clientIp)) {
        return [
            'name' => 'docker-network',
            'permissions' => ['read', 'write'],
            'method' => 'docker-network',
            'client_ip' => $clientIp
        ];
    }

    // Get API key from header or query param
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;

    // Tier 2: Validate via family-office
    $keyData = validateApiKeyWithFamilyOffice($apiKey, $config);

    if ($keyData) {
        return [
            'name' => $keyData['name'],
            'permissions' => $keyData['permissions'],
            'method' => 'family-office',
            'client_ip' => $clientIp
        ];
    }

    return null;
}

/**
 * Require API authentication - responds with 401 if not authenticated
 *
 * @return array Auth info if authenticated
 */
function requireApiAuth(): array {
    $auth = authenticateApiRequest();

    if ($auth === null) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'API key required',
            'hint' => 'Provide X-API-Key header or api_key query parameter'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $auth;
}

/**
 * Check if request has specific permission
 */
function hasPermission(array $auth, string $permission): bool {
    return in_array($permission, $auth['permissions'] ?? [], true);
}

/**
 * Backwards compatible function - replaces verify_n8n_api_key()
 * Returns true if authenticated, false otherwise (with optional 401 response)
 */
function verify_api_key_v2(bool $respond401OnFailure = true): bool {
    $auth = authenticateApiRequest();

    if ($auth !== null) {
        // Store auth info in global for access in endpoint handlers
        $GLOBALS['api_auth'] = $auth;
        return true;
    }

    if ($respond401OnFailure) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Invalid or missing API key'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    return false;
}
