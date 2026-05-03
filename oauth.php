<?php
/**
 * Google OAuth handler
 *
 * Actions:
 * - login: redirect user to Google OAuth consent screen.
 * - callback: exchange authorization code for tokens and store profile in session.
 * - logout: clear OAuth session.
 * - status: return JSON auth state for SPA/debugging.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/maintenance.php';

session_start();

maintenance_api_block_if_enabled();

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'status';

try {
    switch ($action) {
        case 'login':
            oauth_login();
            break;

        case 'callback':
            oauth_callback();
            break;

        case 'logout':
            oauth_logout();
            break;

        case 'status':
            oauth_status();
            break;

        default:
            json_response(['ok' => false, 'error' => 'UNKNOWN_ACTION'], 404);
    }
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'OAUTH_ERROR',
        'message' => 'Không đăng nhập Google được. Kiểm tra OAuth Client ID, Client Secret và Redirect URI.',
    ], 500);
}

function oauth_login(): void
{
    $oauthCfg = current_oauth_config();
    ensure_oauth_config($oauthCfg);

    $state = bin2hex(random_bytes(24));
    $_SESSION['oauth_state'] = $state;

    $params = [
        'client_id'             => $oauthCfg['client_id'],
        'redirect_uri'          => oauth_redirect_uri(),
        'response_type'         => 'code',
        'scope'                 => GOOGLE_OAUTH_SCOPES,
        'access_type'           => 'offline',
        'prompt'                => 'consent',
        'include_granted_scopes' => 'true',
        'state'                 => $state,
    ];

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    exit;
}

function oauth_callback(): void
{
    $oauthCfg = current_oauth_config();
    ensure_oauth_config($oauthCfg);

    $state = isset($_GET['state']) ? (string) $_GET['state'] : '';
    $expected = isset($_SESSION['oauth_state']) ? (string) $_SESSION['oauth_state'] : '';
    unset($_SESSION['oauth_state']);

    if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
        json_response(['ok' => false, 'error' => 'INVALID_OAUTH_STATE'], 400);
    }

    $code = isset($_GET['code']) ? (string) $_GET['code'] : '';
    if ($code === '' || strlen($code) > 4096) {
        json_response(['ok' => false, 'error' => 'OAUTH_CODE_MISSING'], 400);
    }

    $tokens = exchange_code_for_tokens($code);
    if (!isset($tokens['access_token'])) {
        json_response(['ok' => false, 'error' => 'TOKEN_EXCHANGE_FAILED'], 502);
    }

    $profile = fetch_google_profile((string) $tokens['access_token']);
    session_regenerate_id(true);

    $_SESSION['google_oauth'] = [
        'access_token' => (string) $tokens['access_token'],
        'refresh_token' => isset($tokens['refresh_token']) ? (string) $tokens['refresh_token'] : '',
        'expires_at' => time() + max(60, (int) ($tokens['expires_in'] ?? 3600) - 60),
        'scope' => (string) ($tokens['scope'] ?? GOOGLE_OAUTH_SCOPES),
        'token_type' => (string) ($tokens['token_type'] ?? 'Bearer'),
        'profile' => [
            'name' => sanitize_oauth_text((string) ($profile['name'] ?? 'Google User'), 120),
            'email' => sanitize_oauth_text((string) ($profile['email'] ?? ''), 160),
            'picture' => sanitize_oauth_url((string) ($profile['picture'] ?? '')),
        ],
    ];

    header('Location: index.php');
    exit;
}

function oauth_logout(): void
{
    unset($_SESSION['google_oauth'], $_SESSION['oauth_state']);
    header('Location: index.php');
    exit;
}

function oauth_status(): void
{
    $auth       = oauth_session();
    $keyIndex   = get_active_key_index();
    $isPersonal = is_personal_key_index($keyIndex);

    json_response([
        'ok'            => true,
        'authenticated' => $auth !== null,
        'profile'       => $auth['profile'] ?? null,
        'expiresAt'     => $auth['expires_at'] ?? null,
        'keyIndex'      => $keyIndex,
        'isPersonalKey' => $isPersonal,
    ]);
}

function exchange_code_for_tokens(string $code): array
{
    $oauthCfg = current_oauth_config();
    return post_form_json('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => $oauthCfg['client_id'],
        'client_secret' => $oauthCfg['client_secret'],
        'redirect_uri'  => oauth_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
}

function fetch_google_profile(string $accessToken): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => API_TIMEOUT_SECONDS,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nAuthorization: Bearer " . $accessToken . "\r\n",
        ],
    ]);

    $raw = file_get_contents('https://www.googleapis.com/oauth2/v3/userinfo', false, $context);
    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function post_form_json(string $url, array $params): array
{
    $body = http_build_query($params);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => API_TIMEOUT_SECONDS,
            'ignore_errors' => true,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => $body,
        ],
    ]);

    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function oauth_redirect_uri(): string
{
    if (GOOGLE_REDIRECT_URI !== '') {
        return GOOGLE_REDIRECT_URI;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/oauth.php'))), '/');
    $base = $dir === '' || $dir === '.' ? '' : $dir;

    return $scheme . '://' . $host . $base . '/oauth.php?action=callback';
}

function oauth_session(): ?array
{
    $auth = $_SESSION['google_oauth'] ?? null;
    if (!is_array($auth)) {
        return null;
    }

    if ((int) ($auth['expires_at'] ?? 0) <= time()) {
        unset($_SESSION['google_oauth']);
        return null;
    }

    return $auth;
}

/**
 * Lấy OAuth config của key đang active.
 * Nếu key đang dùng là Community (index >= PERSONAL_KEY_COUNT) → trả về mảng rỗng.
 */
function current_oauth_config(): array
{
    $index = get_active_key_index();
    return get_oauth_config_for_index($index);
}

function ensure_oauth_config(array $cfg = []): void
{
    $clientId     = (string) ($cfg['client_id'] ?? '');
    $clientSecret = (string) ($cfg['client_secret'] ?? '');

    if (
        $clientId === ''
        || $clientSecret === ''
        || $clientId === 'PUT_YOUR_GOOGLE_CLIENT_ID_HERE'
        || $clientSecret === 'PUT_YOUR_GOOGLE_CLIENT_SECRET_HERE'
    ) {
        json_response([
            'ok'      => false,
            'error'   => 'OAUTH_CONFIG_MISSING',
            'message' => 'API key đang dùng là Community hoặc chưa cấu hình OAuth. Hãy chọn API key cá nhân và cấu hình client_id/client_secret trong config.php.',
        ], 500);
    }
}

function sanitize_oauth_text(string $value, int $maxLength): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

function sanitize_oauth_url(string $url): string
{
    if ($url === '' || strlen($url) > 500) {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        return '';
    }

    return $url;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
