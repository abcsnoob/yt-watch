<?php
declare(strict_types=1);

require_once __DIR__ . '/maintenance.php';

session_start();

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'unlock') {
        $password = (string) ($_POST['password'] ?? '');
        if (maintenance_password_matches($password)) {
            maintenance_unlock_admin();
            $message = 'Đã mở khóa quản trị.';
        } else {
            $error = 'Sai mật khẩu.';
        }
    } elseif ($action === 'toggle' && maintenance_admin_is_unlocked()) {
        $enabled = maintenance_is_enabled();
        $reason = (string) ($_POST['reason'] ?? '');
        $expectedMinutes = (int) ($_POST['expectedMinutes'] ?? 0);
        $state = maintenance_set_enabled(!$enabled, 'status.php', $reason, $expectedMinutes);
        $message = $state['enabled'] ? 'Đã bật chế độ bảo trì.' : 'Đã tắt chế độ bảo trì.';
    } elseif ($action === 'lock') {
        maintenance_lock_admin();
        $message = 'Đã khóa phiên quản trị.';
    }
}

$state = maintenance_state();
$history = maintenance_history_entries(30);
$unlocked = maintenance_admin_is_unlocked();
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Status</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0f0f0f;
            --panel: #1b1b1b;
            --panel-2: #272727;
            --text: #f2f4f8;
            --muted: #aaa;
            --line: #303030;
            --accent: #3ea6ff;
            --danger: #ff7a7a;
            --ok: #5dd39e;
            --radius: 10px;
            --max: 960px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: radial-gradient(circle at top, #151515 0, #0f0f0f 60%);
            color: var(--text);
            font: 15px/1.5 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .wrap {
            max-width: var(--max);
            margin: 0 auto;
            padding: 28px 16px 48px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .title {
            margin: 0;
            font-size: 30px;
        }

        .subtitle {
            margin: 4px 0 0;
            color: var(--muted);
        }

        .grid {
            display: grid;
            grid-template-columns: 1.3fr .9fr;
            gap: 16px;
        }

        .card {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: rgba(27, 27, 27, .96);
            padding: 18px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            font-weight: 800;
            margin-bottom: 14px;
            background: var(--panel-2);
        }

        .status-pill.on {
            color: #fff;
            background: #8b1e2d;
        }

        .status-pill.off {
            color: #0e1b14;
            background: #b6ffd3;
        }

        .row {
            display: grid;
            gap: 10px;
        }

        label {
            font-weight: 700;
        }

        input[type="password"] {
            width: 100%;
            height: 44px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #121212;
            color: var(--text);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        button, .linkbtn {
            min-height: 42px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--panel-2);
            color: var(--text);
            font: inherit;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .primary {
            border-color: var(--accent);
            background: var(--accent);
            color: white;
        }

        .danger {
            border-color: var(--danger);
            color: #ffd6d6;
            background: transparent;
        }

        .msg {
            margin: 0 0 14px;
            padding: 12px 14px;
            border-radius: 8px;
            background: #12212c;
            color: #bfe3ff;
            border: 1px solid #26445b;
        }

        .msg.error {
            background: #2a1616;
            border-color: #5f2525;
            color: #ffc4c4;
        }

        .kv {
            display: grid;
            gap: 8px;
            font-size: 14px;
            color: var(--muted);
        }

        .kv strong {
            color: var(--text);
        }

        .history {
            display: grid;
            gap: 10px;
            margin-top: 10px;
        }

        .history-item {
            padding: 12px;
            border-radius: 8px;
            background: #141414;
            border: 1px solid var(--line);
        }

        .history-item .meta {
            color: var(--muted);
            font-size: 12px;
            margin-top: 4px;
        }

        @media (max-width: 760px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <div>
                <h1 class="title">Status</h1>
                <p class="subtitle">Bật tắt bảo trì và xem lịch sử thay đổi.</p>
            </div>
            <a class="linkbtn" href="index.php">Về homepage</a>
        </div>

        <?php if ($message !== ''): ?>
            <div class="msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="msg error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="grid">
            <section class="card">
                <div class="status-pill <?= $state['enabled'] ? 'on' : 'off' ?>">
                    <span><?= $state['enabled'] ? 'Bảo trì đang bật' : 'Bảo trì đang tắt' ?></span>
                </div>

                <div class="kv">
                    <div><strong>Cập nhật:</strong> <?= htmlspecialchars((string) ($state['updatedAt'] ?? 'Chưa có'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div><strong>Người cập nhật:</strong> <?= htmlspecialchars((string) ($state['updatedBy'] ?? 'Hệ thống'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div><strong>Lý do:</strong> <?= htmlspecialchars((string) ($state['reason'] ?? 'Chưa đặt'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div><strong>Đã diễn ra:</strong> <?= maintenance_format_duration((int) ($state['elapsedSeconds'] ?? 0)) ?></div>
                    <div><strong>Dự kiến:</strong> <?= (int) ($state['expectedMinutes'] ?? 0) > 0 ? maintenance_format_duration((int) $state['expectedMinutes'] * 60) : 'Chưa đặt' ?></div>
                    <div><strong>Còn lại:</strong> <?= (int) ($state['remainingSeconds'] ?? 0) > 0 ? maintenance_format_duration((int) $state['remainingSeconds']) : 'Không xác định' ?></div>
                    <div><strong>Trạng thái phiên quản trị:</strong> <?= $unlocked ? 'Đã mở khóa' : 'Đã khóa' ?></div>
                </div>

                <div class="actions">
                    <?php if ($unlocked): ?>
                        <form method="post" class="row" style="min-width: 100%;">
                            <input type="hidden" name="action" value="toggle">
                            <label for="reason">Lý do bảo trì</label>
                            <input id="reason" name="reason" type="text" maxlength="160" value="<?= htmlspecialchars((string) ($state['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ví dụ: cập nhật giao diện, sửa lỗi quota">
                            <label for="expectedMinutes">Thời gian dự kiến (phút)</label>
                            <input id="expectedMinutes" name="expectedMinutes" type="number" min="0" max="1440" value="<?= (int) ($state['expectedMinutes'] ?? 0) ?>" placeholder="0">
                            <button class="primary" type="submit"><?= $state['enabled'] ? 'Tắt bảo trì' : 'Bật bảo trì' ?></button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="action" value="lock">
                            <button class="danger" type="submit">Khóa phiên</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="row">
                            <input type="hidden" name="action" value="unlock">
                            <label for="password">Nhập password quản trị</label>
                            <input id="password" name="password" type="password" autocomplete="current-password" required>
                            <div class="actions">
                                <button class="primary" type="submit">Mở khóa</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="card">
                <h2 style="margin:0 0 10px;">Lịch sử bảo trì</h2>
                <div class="history">
                    <?php if (!$history): ?>
                        <div class="history-item">Chưa có thay đổi nào.</div>
                    <?php else: ?>
                        <?php foreach ($history as $item): ?>
                            <div class="history-item">
                                <div><strong><?= htmlspecialchars($item['action'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                                <div class="meta"><?= htmlspecialchars($item['time'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($item['actor'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if ((string) ($item['reason'] ?? '') !== ''): ?>
                                    <div class="meta">Lý do: <?= htmlspecialchars($item['reason'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if ((int) ($item['expectedMinutes'] ?? 0) > 0): ?>
                                    <div class="meta">Dự kiến: <?= maintenance_format_duration((int) $item['expectedMinutes'] * 60) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
</body>
</html>
