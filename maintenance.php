<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function maintenance_flag_path(): string
{
    return CACHE_DIR . DIRECTORY_SEPARATOR . 'maintenance.json';
}

function maintenance_history_path(): string
{
    return CACHE_DIR . DIRECTORY_SEPARATOR . 'maintenance.log';
}

function maintenance_state(): array
{
    $defaults = [
        'enabled' => false,
        'updatedAt' => null,
        'updatedBy' => '',
        'reason' => '',
        'expectedMinutes' => 0,
        'startedAt' => null,
        'elapsedSeconds' => 0,
        'remainingSeconds' => 0,
    ];

    $path = maintenance_flag_path();
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $enabled = (bool) ($decoded['enabled'] ?? false);
    $updatedAt = maintenance_clean_text((string) ($decoded['updatedAt'] ?? ''));
    $updatedBy = maintenance_clean_text((string) ($decoded['updatedBy'] ?? ''));
    $reason = maintenance_clean_text((string) ($decoded['reason'] ?? ''));
    $expectedMinutes = max(0, (int) ($decoded['expectedMinutes'] ?? 0));
    $startedAtRaw = (string) ($decoded['startedAt'] ?? $updatedAt ?? '');
    $startedTimestamp = $startedAtRaw !== '' ? strtotime($startedAtRaw) : false;
    $elapsedSeconds = ($enabled && $startedTimestamp) ? max(0, time() - (int) $startedTimestamp) : 0;
    $remainingSeconds = ($enabled && $expectedMinutes > 0) ? max(0, ($expectedMinutes * 60) - $elapsedSeconds) : 0;

    return [
        'enabled' => $enabled,
        'updatedAt' => $updatedAt,
        'updatedBy' => $updatedBy,
        'reason' => $reason,
        'expectedMinutes' => $expectedMinutes,
        'startedAt' => $startedTimestamp ? gmdate('c', $startedTimestamp) : null,
        'elapsedSeconds' => $elapsedSeconds,
        'remainingSeconds' => $remainingSeconds,
    ];
}

function maintenance_is_enabled(): bool
{
    return (bool) (maintenance_state()['enabled'] ?? false);
}

function maintenance_set_enabled(bool $enabled, string $actor = 'admin', string $reason = '', int $expectedMinutes = 0): array
{
    maintenance_ensure_cache_dir();

    $state = [
        'enabled' => $enabled,
        'updatedAt' => gmdate('c'),
        'updatedBy' => maintenance_clean_text($actor),
        'reason' => maintenance_clean_text($reason),
        'expectedMinutes' => max(0, $expectedMinutes),
        'startedAt' => $enabled ? gmdate('c') : null,
    ];

    file_put_contents(
        maintenance_flag_path(),
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    maintenance_append_history($enabled ? 'enabled' : 'disabled', $actor, $state['reason'], $state['expectedMinutes']);
    return $state;
}

function maintenance_ensure_cache_dir(): void
{
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
}

function maintenance_append_history(string $action, string $actor, string $reason = '', int $expectedMinutes = 0): void
{
    $line = json_encode([
        'time' => gmdate('c'),
        'action' => maintenance_clean_text($action),
        'actor' => maintenance_clean_text($actor),
        'reason' => maintenance_clean_text($reason),
        'expectedMinutes' => max(0, $expectedMinutes),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($line !== false) {
        file_put_contents(maintenance_history_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function maintenance_history_entries(int $limit = 50): array
{
    $path = maintenance_history_path();
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $lines = array_slice(array_reverse($lines), 0, max(1, $limit));
    $entries = [];

    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }

        $entries[] = [
            'time' => maintenance_clean_text((string) ($decoded['time'] ?? '')),
            'action' => maintenance_clean_text((string) ($decoded['action'] ?? '')),
            'actor' => maintenance_clean_text((string) ($decoded['actor'] ?? '')),
            'reason' => maintenance_clean_text((string) ($decoded['reason'] ?? '')),
            'expectedMinutes' => max(0, (int) ($decoded['expectedMinutes'] ?? 0)),
        ];
    }

    return $entries;
}

function maintenance_password_matches(string $input): bool
{
    $input = trim($input);
    if ($input === '') {
        return false;
    }

    if (defined('MAINTENANCE_PASSWORD_HASH') && MAINTENANCE_PASSWORD_HASH !== '' && MAINTENANCE_PASSWORD_HASH !== 'PUT_YOUR_MAINTENANCE_PASSWORD_HASH_HERE') {
        if (str_starts_with(MAINTENANCE_PASSWORD_HASH, '$')) {
            return password_verify($input, MAINTENANCE_PASSWORD_HASH);
        }

        return hash_equals(MAINTENANCE_PASSWORD_HASH, $input);
    }

    if (defined('MAINTENANCE_PASSWORD') && MAINTENANCE_PASSWORD !== '' && MAINTENANCE_PASSWORD !== 'change-me') {
        return hash_equals(MAINTENANCE_PASSWORD, $input);
    }

    return false;
}

function maintenance_clean_text(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return strlen($value) > 500 ? substr($value, 0, 500) : $value;
}

function maintenance_format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' giờ';
    }
    if ($minutes > 0 || $hours > 0) {
        $parts[] = $minutes . ' phút';
    }
    $parts[] = $secs . ' giây';

    return implode(' ', $parts);
}

function maintenance_admin_is_unlocked(): bool
{
    $until = (int) ($_SESSION['maintenance_admin_until'] ?? 0);
    return $until > time();
}

function maintenance_unlock_admin(int $hours = 12): void
{
    $_SESSION['maintenance_admin_until'] = time() + max(1, $hours) * 3600;
}

function maintenance_lock_admin(): void
{
    unset($_SESSION['maintenance_admin_until']);
}

function maintenance_block_if_enabled(): void
{
    if (maintenance_is_enabled()) {
        render_maintenance_page();
        exit;
    }
}

function maintenance_api_block_if_enabled(): void
{
    if (maintenance_is_enabled()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: 3600');
        echo json_encode([
            'ok' => false,
            'error' => 'MAINTENANCE_MODE',
            'message' => 'Hệ thống đang bảo trì. Mở status.php để bật/tắt chế độ.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function render_maintenance_page(): void
{
    $state = maintenance_state();
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 3600');
    ?>
    <!doctype html>
    <html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Đang bảo trì</title>
        <style>
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0f0f0f; color: #f2f4f8; font: 16px/1.5 system-ui, sans-serif; padding: 24px; }
            .box { max-width: 560px; width: 100%; border: 1px solid #303030; border-radius: 12px; background: #1b1b1b; padding: 24px; }
            h1 { margin: 0 0 8px; font-size: 28px; }
            p { margin: 0 0 12px; color: #aaa; }
            a { color: #3ea6ff; text-decoration: none; }
            .meta { margin-top: 14px; padding-top: 14px; border-top: 1px solid #303030; color: #b6b6b6; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>Hệ thống đang bảo trì</h1>
            <p>Trang này tạm thời dừng để cập nhật. Bạn có thể kiểm tra trạng thái tại trang Status.</p>
            <?php if (($state['reason'] ?? '') !== ''): ?>
                <p><strong>Lý do:</strong> <?= htmlspecialchars((string) $state['reason'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <div class="meta">
                <div><strong>Đã diễn ra:</strong> <?= maintenance_format_duration((int) ($state['elapsedSeconds'] ?? 0)) ?></div>
                <div><strong>Dự kiến:</strong> <?= (int) ($state['expectedMinutes'] ?? 0) > 0 ? maintenance_format_duration((int) $state['expectedMinutes'] * 60) : 'Chưa đặt' ?></div>
            </div>
            <p><a href="status.php">Mở Status</a></p>
        </div>
    </body>
    </html>
    <?php
}
