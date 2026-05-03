<?php
/**
 * YouTube Personal Viewer - configuration
 *
 * Index 0 & 1 = API cá nhân hóa (cần đăng nhập OAuth tương ứng)
 * Index 2+    = Community (không cần đăng nhập)
 */

// ── Đường dẫn cơ bản ────────────────────────────────────────────────────────
define('ROOT_DIR', __DIR__);
define('CACHE_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'cache');

// ── Nạp API Keys từ file JSON ───────────────────────────────────────────────
$apiKeyFile = ROOT_DIR . DIRECTORY_SEPARATOR . 'api-key.json';
$apiConfig = [];

if (file_exists($apiKeyFile)) {
    $apiConfig = json_decode((string) file_get_contents($apiKeyFile), true);
}

// Định nghĩa các hằng số từ dữ liệu JSON
define('YOUTUBE_API_KEYS', $apiConfig['api_keys'] ?? []);
define('PERSONAL_KEY_COUNT', $apiConfig['personal_key_count'] ?? 0);
define('GOOGLE_OAUTH_CONFIG', $apiConfig['oauth_configs'] ?? []);

// ── Hằng số cấu hình ────────────────────────────────────────────────────────
const YOUTUBE_API_BASE        = 'https://www.googleapis.com/youtube/v3';
const API_TIMEOUT_SECONDS     = 8;
const CACHE_TTL_SECONDS       = 86400;  // 24 giờ
const OAUTH_CACHE_TTL_SECONDS = 900;    // 15 phút

const DEFAULT_RESULTS_PER_PAGE = 12;
const MAX_RESULTS_PER_PAGE     = 25;

const CHANNEL_AVATAR_FALLBACK = 'initials';

const GOOGLE_REDIRECT_URI = '';
const GOOGLE_OAUTH_SCOPES = 'openid email profile https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/youtube.force-ssl';

const MAINTENANCE_PASSWORD      = 'qwerty12345qwerty12345qwerty12345%%%%%%%%%%%%%%%%%%%%%%%';
const MAINTENANCE_PASSWORD_HASH = 'PUT_YOUR_MAINTENANCE_PASSWORD_HASH_HERE';

// ── File lưu trạng thái exhausted keys ──────────────────────────────────────
define('QUOTA_EXHAUSTED_FILE', CACHE_DIR . DIRECTORY_SEPARATOR . 'exhausted_keys.json');

// ── Session key lưu index của API key đang active ───────────────────────────
const SESSION_ACTIVE_KEY_INDEX = 'active_api_key_index';

// ── Helper functions ─────────────────────────────────────────────────────────

/**
 * Đọc danh sách exhausted keys từ file (không reset - api.php tự quản lý UTC).
 */
function get_exhausted_keys_list(): array
{
    if (!is_file(QUOTA_EXHAUSTED_FILE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(QUOTA_EXHAUSTED_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * Trả về index của API key đang active (ưu tiên lấy từ session).
 * Tự chọn key đầu tiên chưa exhausted nếu session chưa có hoặc key bị exhausted.
 */
function get_active_key_index(): int
{
    $keys      = YOUTUBE_API_KEYS;
    $exhausted = get_exhausted_keys_list();

    if (isset($_SESSION[SESSION_ACTIVE_KEY_INDEX])) {
        $idx = (int) $_SESSION[SESSION_ACTIVE_KEY_INDEX];
        if (isset($keys[$idx]) && !in_array($keys[$idx], $exhausted, true)) {
            return $idx;
        }
    }

    foreach ($keys as $idx => $key) {
        if (!in_array($key, $exhausted, true)) {
            $_SESSION[SESSION_ACTIVE_KEY_INDEX] = $idx;
            return $idx;
        }
    }

    return 0; // fallback khi tất cả exhausted
}

/**
 * Kiểm tra index có phải là key cá nhân không.
 */
function is_personal_key_index(int $index): bool
{
    return $index < PERSONAL_KEY_COUNT;
}

/**
 * Trả về OAuth config (client_id, client_secret) theo index key.
 * Community key (index >= PERSONAL_KEY_COUNT) trả về mảng rỗng.
 */
function get_oauth_config_for_index(int $index): array
{
    $configs = GOOGLE_OAUTH_CONFIG;
    return $configs[$index] ?? [];
}
