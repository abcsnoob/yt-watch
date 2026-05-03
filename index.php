<?php
declare(strict_types=1);
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/quota.php';
session_start();

maintenance_block_if_enabled();
  $quota = get_quota_stats(); 
  // Chá»n mÃ u sáº¯c dá»±a trÃªn pháº§n trÄƒm cÃ²n láº¡i
  $barColor = $quota['percent'] > 50 ? '#28a745' : ($quota['percent'] > 20 ? '#ffc107' : '#dc3545');
$googleAuth = $_SESSION['google_oauth'] ?? null;
if (is_array($googleAuth) && (int) ($googleAuth['expires_at'] ?? 0) <= time()) {
    unset($_SESSION['google_oauth']);
    $googleAuth = null;
}
$googleProfile = is_array($googleAuth ?? null) ? ($googleAuth['profile'] ?? null) : null;
$googleName    = is_array($googleProfile) ? (string) ($googleProfile['name'] ?? '') : '';
$googlePicture = is_array($googleProfile) ? (string) ($googleProfile['picture'] ?? '') : '';

// Kiá»ƒm tra key Ä‘ang dÃ¹ng cÃ³ pháº£i personal khÃ´ng (Ä‘á»ƒ hiá»‡n/áº©n nÃºt Ä‘Äƒng nháº­p)
$activeKeyIndex = get_active_key_index();
$isPersonalKey  = is_personal_key_index($activeKeyIndex);

// Náº¿u Ä‘ang dÃ¹ng Community key mÃ  váº«n cÃ³ session Ä‘Äƒng nháº­p â†’ Ä‘Äƒng xuáº¥t
if (!$isPersonalKey && $googleAuth !== null) {
    unset($_SESSION['google_oauth']);
    $googleAuth    = null;
    $googleName    = '';
    $googlePicture = '';
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title>YouTube Personal Viewer</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0f0f0f;
            --panel: #1b1b1b;
            --panel-2: #272727;
            --text: #f2f4f8;
            --muted: #aaa;
            --line: #303030;
            --accent: #ff0033;
            --accent-2: #3ea6ff;
            --danger: #ffb86b;
            --radius: 8px;
            --max: 1240px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 15px/1.5 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        button, input {
            font: inherit;
        }

        button {
            cursor: pointer;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(15, 15, 15, .98);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(12px);
        }

        .topbar-inner {
            max-width: var(--max);
            margin: 0 auto;
            padding: 12px 16px;
            display: grid;
            grid-template-columns: auto minmax(160px, 640px) auto;
            gap: 14px;
            align-items: center;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--text);
            text-decoration: none;
            font-weight: 800;
            white-space: nowrap;
            letter-spacing: 0;
        }

        .mark {
            width: 32px;
            height: 22px;
            display: grid;
            place-items: center;
            border-radius: 6px;
            background: var(--accent);
            color: white;
            font-size: 13px;
        }

        .search-form {
            display: flex;
            min-width: 0;
        }

        .search-input {
            width: 100%;
            min-width: 0;
            height: 40px;
            padding: 0 13px;
            border: 1px solid var(--line);
            border-right: 0;
            border-radius: var(--radius) 0 0 var(--radius);
            background: #121212;
            color: var(--text);
            outline: none;
        }

        .search-input:focus {
            border-color: var(--accent-2);
        }

        .search-button, .nav-button {
            border: 1px solid var(--line);
            background: var(--panel-2);
            color: var(--text);
        }

        .search-button {
            width: 48px;
            border-radius: 0 var(--radius) var(--radius) 0;
            font-size: 18px;
        }

        .nav {
            display: grid;
            gap: 6px;
        }

        .nav-button,
        .sidebar-link,
        .auth-button {
            min-height: 40px;
            padding: 0 13px;
            border-radius: var(--radius);
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
        }

        .top-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
        }

        .nav-button[aria-current="page"] {
            border-color: var(--accent);
            color: white;
        }

        .auth-button {
            border: 1px solid var(--accent-2);
            background: transparent;
            color: var(--accent-2);
            white-space: nowrap;
            justify-content: center;
        }

        .auth-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--panel-2);
        }

        .app-shell {
            max-width: calc(var(--max) + 220px);
            margin: 0 auto;
            display: grid;
            grid-template-columns: 220px minmax(0, 1fr);
            gap: 18px;
            padding: 18px 16px 40px;
        }

        .sidebar {
            position: sticky;
            top: 73px;
            align-self: start;
            min-height: calc(100vh - 96px);
            border-right: 1px solid var(--line);
            padding-right: 12px;
        }

        .sidebar-section {
            display: grid;
            gap: 6px;
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }

        .sidebar-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            padding: 0 13px 4px;
            text-transform: uppercase;
        }

        .sidebar-link {
            border: 1px solid transparent;
            background: transparent;
            color: var(--text);
        }

        .sidebar-link:hover,
        .nav-button:hover {
            background: var(--panel-2);
        }

        .layout {
            min-width: 0;
        }

        .status {
            min-height: 26px;
            color: var(--muted);
            margin-bottom: 12px;
        }

        .status.error {
            color: var(--danger);
        }

        .watch {
            display: none;
            margin-bottom: 20px;
        }

        .watch.active {
            display: block;
        }

        .player-wrap {
            aspect-ratio: 16 / 9;
            background: #000;
            border: 0;
            border-radius: 12px;
            overflow: hidden;
        }

        .player-wrap iframe {
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
        }

        .watch-title {
            margin: 12px 0 0;
            font-size: 22px;
            line-height: 1.3;
        }

        .watch-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 390px;
            gap: 22px;
            align-items: start;
        }

        .watch-main {
            min-width: 0;
        }

        .watch-side {
            min-width: 0;
        }

        .channel-info {
            margin-top: 14px;
            padding: 14px 0;
            display: grid;
            grid-template-columns: 52px minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .channel-info .avatar {
            width: 48px;
            height: 48px;
        }

        .channel-name {
            margin: 0;
            font-size: 15px;
            line-height: 1.3;
        }

        .channel-stats,
        .watch-stats {
            color: var(--muted);
            font-size: 13px;
        }

        .channel-description {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .subscribe-button {
            height: 38px;
            padding: 0 14px;
            border-radius: var(--radius);
            background: var(--text);
            color: var(--bg);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            white-space: nowrap;
            border: 0;
        }

        .subscribe-button:hover {
            background: #ddd;
        }

        .panel-title {
            margin: 20px 0 12px;
            font-size: 18px;
            line-height: 1.3;
        }

        .comment-list,
        .recommendation-list {
            display: grid;
            gap: 14px;
        }

        .comment {
            display: grid;
            grid-template-columns: 38px minmax(0, 1fr);
            gap: 10px;
        }

        .comment-author {
            margin: 0 0 3px;
            font-size: 13px;
            font-weight: 800;
            color: inherit;
            text-decoration: none;
        }
        .comment-author[href]:hover {
            text-decoration: underline;
            color: var(--accent, #3ea6ff);
        }
        .comment-box {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
        }
        .comment-box .avatar {
            flex-shrink: 0;
        }
        .comment-box-inner {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .comment-textarea {
            width: 100%;
            min-height: 60px;
            background: #1e2330;
            border: 1px solid #3a3f55;
            border-radius: 6px;
            color: #e8eaf6;
            font-size: 14px;
            padding: 10px 12px;
            resize: vertical;
            box-sizing: border-box;
            font-family: inherit;
            transition: border-color .2s;
        }
        .comment-textarea:focus {
            outline: none;
            border-color: var(--accent, #3ea6ff);
        }
        .comment-box-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .comment-submit-btn {
            background: var(--accent, #3ea6ff);
            color: #000;
            border: none;
            border-radius: 18px;
            padding: 7px 18px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .comment-submit-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }
        .reply-box {
            margin-top: 8px;
            padding-left: 48px;
        }
        .comment-reply-btn {
            background: none;
            border: none;
            color: var(--muted);
            font-size: 12px;
            cursor: pointer;
            padding: 2px 0;
            margin-top: 4px;
        }
        .comment-reply-btn:hover { color: #e8eaf6; }
        .comment-actions { display: flex; gap: 12px; align-items: center; }
        .comment-box {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
        }
        .comment-box .avatar {
            flex-shrink: 0;
        }
        .comment-box-inner {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .comment-textarea {
            width: 100%;
            min-height: 60px;
            background: #1e2330;
            border: 1px solid #3a3f55;
            border-radius: 6px;
            color: #e8eaf6;
            font-size: 14px;
            padding: 10px 12px;
            resize: vertical;
            box-sizing: border-box;
            font-family: inherit;
            transition: border-color .2s;
        }
        .comment-textarea:focus {
            outline: none;
            border-color: var(--accent, #3ea6ff);
        }
        .comment-box-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .comment-submit-btn {
            background: var(--accent, #3ea6ff);
            color: #000;
            border: none;
            border-radius: 18px;
            padding: 7px 18px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .comment-submit-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }
        .reply-box {
            margin-top: 8px;
            padding-left: 48px;
        }
        .comment-reply-btn {
            background: none;
            border: none;
            color: var(--muted);
            font-size: 12px;
            cursor: pointer;
            padding: 2px 0;
            margin-top: 4px;
        }
        .comment-reply-btn:hover { color: #e8eaf6; }
        .comment-actions { display: flex; gap: 12px; align-items: center; }

        .comment-text {
            margin: 0;
            color: #d7dbe3;
            font-size: 14px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .comment-meta {
            color: var(--muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .recommendation-card {
            display: grid;
            grid-template-columns: 150px minmax(0, 1fr);
            gap: 10px;
        }

        .recommendation-card .video-title {
            -webkit-line-clamp: 3;
        }

        .channel-header {
            display: none;
            margin-bottom: 22px;
        }

        .channel-header.active {
            display: block;
        }

        .channel-banner {
            width: 100%;
            aspect-ratio: 6 / 1;
            min-height: 120px;
            max-height: 220px;
            border-radius: 12px;
            background: linear-gradient(135deg, #272727, #111);
            background-size: cover;
            background-position: center;
            margin-bottom: 18px;
        }

        .channel-profile {
            display: grid;
            grid-template-columns: 96px minmax(0, 1fr) auto;
            gap: 18px;
            align-items: center;
        }

        .channel-profile .avatar {
            width: 96px;
            height: 96px;
            font-size: 24px;
        }

        .channel-profile-title {
            margin: 0;
            font-size: 30px;
            line-height: 1.15;
        }

        .channel-profile-meta {
            margin-top: 6px;
            color: var(--muted);
            font-size: 14px;
        }

        .channel-profile-description {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px 14px;
        }

        .grid.shorts-grid {
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .shorts-card .thumb {
            aspect-ratio: 9 / 16;
            object-fit: cover;
        }

        .video-card {
            min-width: 0;
        }

        .watch-link {
            display: block;
            color: var(--text);
            text-decoration: none;
        }

        .thumb {
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            display: block;
            background: var(--panel);
            border-radius: var(--radius);
        }

        .meta {
            display: grid;
            grid-template-columns: 38px minmax(0, 1fr);
            gap: 10px;
            padding-top: 10px;
        }

        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--panel-2);
            color: var(--text);
            font-size: 12px;
            font-weight: 800;
            border: 1px solid var(--line);
            text-transform: uppercase;
            overflow: hidden;
            flex: 0 0 auto;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .video-title {
            margin: 0;
            font-size: 14px;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .channel-line {
            margin-top: 5px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            color: var(--muted);
            font-size: 13px;
        }

        .channel-link {
            color: var(--muted);
            text-decoration: none;
        }

        .channel-link:hover {
            color: var(--text);
        }

        .sentinel {
            height: 1px;
        }

        .loader {
            display: none;
            color: var(--muted);
            text-align: center;
            padding: 18px 0;
        }

        .loader.active {
            display: block;
        }

        .load-more {
            display: none;
            margin: 18px auto 0;
            height: 42px;
            padding: 0 18px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel-2);
            color: var(--text);
            font-weight: 700;
        }

        .load-more.active {
            display: block;
        }

        .load-more:disabled {
            cursor: wait;
            opacity: .65;
        }

        .watch-load-more {
            display: none;
            width: 100%;
            margin-top: 14px;
        }

        .watch-load-more.active {
            display: inline-flex;
        }

        .trending-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .filter-select {
            height: 38px;
            padding: 0 12px;
            border-radius: var(--radius);
            border: 1px solid var(--line);
            background: var(--panel-2);
            color: var(--text);
            font: inherit;
            font-size: 14px;
            cursor: pointer;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--accent-2);
        }

        .playlist-header {
            margin-bottom: 18px;
            padding: 16px;
            background: var(--panel);
            border-radius: 12px;
            border: 1px solid var(--line);
        }

        .playlist-header h2 {
            margin: 0 0 6px;
            font-size: 20px;
        }

        .playlist-header p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .playlist-card {
            min-width: 0;
        }

        .playlist-thumb-wrap {
            position: relative;
            border-radius: var(--radius);
            overflow: hidden;
            background: var(--panel);
        }

        .playlist-count-badge {
            position: absolute;
            bottom: 0;
            right: 0;
            background: rgba(0,0,0,.85);
            color: white;
            font-size: 12px;
            font-weight: 800;
            padding: 4px 8px;
            border-radius: var(--radius) 0 0 0;
        }

        .activity-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 99px;
            margin-top: 4px;
            background: var(--panel-2);
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .activity-badge.upload { background: rgba(255,0,51,.15); color: #ff6680; }
        .activity-badge.like { background: rgba(62,166,255,.15); color: #3ea6ff; }

        @media (max-width: 980px) {
            .app-shell {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: static;
                min-height: 0;
                border-right: 0;
                border-bottom: 1px solid var(--line);
                padding: 0 0 12px;
            }

            .nav {
                grid-template-columns: repeat(5, max-content);
                overflow-x: auto;
                padding-bottom: 4px;
            }

            .sidebar-section {
                grid-template-columns: repeat(2, max-content);
                overflow-x: auto;
            }

            .sidebar-label {
                grid-column: 1 / -1;
            }

            .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }

            .grid.shorts-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .watch-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .topbar-inner {
                grid-template-columns: 1fr auto;
            }

            .search-form {
                grid-column: 1 / -1;
                grid-row: 2;
            }

            .top-actions {
                justify-content: flex-end;
            }

            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px 12px;
            }

            .grid.shorts-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .channel-profile {
                grid-template-columns: 72px minmax(0, 1fr);
            }

            .channel-profile .avatar {
                width: 72px;
                height: 72px;
                font-size: 18px;
            }

            .channel-profile-title {
                font-size: 23px;
            }

            .channel-profile .subscribe-button {
                grid-column: 1 / -1;
                width: max-content;
            }

            .channel-info {
                grid-template-columns: 48px minmax(0, 1fr);
            }

            .subscribe-button {
                grid-column: 1 / -1;
            }

            .recommendation-card {
                grid-template-columns: 128px minmax(0, 1fr);
            }
        }

        @media (max-width: 460px) {
            .layout {
                padding-left: 10px;
                padding-right: 10px;
            }

            .topbar-inner {
                padding-left: 10px;
                padding-right: 10px;
            }

            .brand span:last-child {
                display: none;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .grid.shorts-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a href="/" class="brand" data-link data-route="feed" aria-label="Trang chá»§">
                <span class="mark">▶</span>
                <span>YouTube Cá nhân</span>
            </a>
            <form class="search-form" id="searchForm" role="search">
                <input class="search-input" id="searchInput" type="search" name="q" maxlength="120" autocomplete="off" placeholder="Tìm kiếm">
                <button class="search-button" type="submit" aria-label="Tìm kiếm">🔍</button>
            </form>
            <div class="top-actions">
                <?php if ($googleName !== ''): ?>
                    <a class="auth-button" href="oauth.php?action=logout" title="Đăng xuất Google">
                        <?php if ($googlePicture !== ''): ?>
                            <img class="auth-avatar" src="<?= htmlspecialchars($googlePicture, ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <?php endif; ?>
                        <span><?= htmlspecialchars($googleName, ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php elseif ($isPersonalKey): ?>
                    <a class="auth-button" href="oauth.php?action=login">Đăng nhập Google</a>
                <?php else: ?>
                    <span class="auth-button community-label" title="Đang dùng API Community - không cần đăng nhập">🌟 Community</span>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="app-shell">
        <aside class="sidebar" aria-label="Sidebar">
            <nav class="nav" aria-label="Điều hướng chính">
                <button class="nav-button" type="button" data-route="trending">Xu hướng</button>
                <button class="nav-button" type="button" data-route="subscriptions">Kênh đăng ký</button>
                <button class="nav-button" type="button" data-route="personal">Đề xuất riêng</button>
                <button class="nav-button" type="button" data-route="liked">Video đã thích</button>
                <button class="nav-button" type="button" data-route="history">Lịch sử xem</button>
                <button class="nav-button" type="button" data-route="playlists">Playlist của tôi</button>
                <button class="nav-button" type="button" data-route="myvideos">Video của tôi</button>
            </nav>
            <div class="sidebar-section" aria-label="Liên kết Google">
                <div class="sidebar-label">Google</div>
                <a class="sidebar-link" href="https://studio.youtube.com/" target="_blank" rel="noopener noreferrer">YouTube Studio</a>
                <a class="sidebar-link" href="https://myaccount.google.com/" target="_blank" rel="noopener noreferrer">Tài khoản Google</a>
                <a class="sidebar-link" href="status.php">Trạng thái hệ thống</a>
                <a class="sidebar-link" href="contribute.php">Đóng góp những API Key của bạn</a>
            </div>

<div class="quota-container" style="margin: 20px 0; font-family: sans-serif;">
    <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 14px;">
        <span><strong>API Quota còn lại:</strong> <?php echo $quota['remaining']; ?>/<?php echo $quota['total']; ?> Keys</span>
        <span><?php echo $quota['percent']; ?>%</span>
    </div>
    <div style="background: #e9ecef; border-radius: 10px; height: 12px; overflow: hidden; width: 100%;">
        <div style="
            width: <?php echo $quota['percent']; ?>%; 
            background-color: <?php echo $barColor; ?>; 
            height: 100%; 
            transition: width 0.5s ease;">
        </div>
    </div>
    <small style="color: #6c757d; font-size: 11px;">Tự động làm mới mỗi lúc 00:00 UTC hàng ngày</small>
</div>
        </aside>

        <main class="layout">
        <div id="status" class="status" role="status" aria-live="polite"></div>

        <!-- Trending controls -->
        <div id="trendingControls" class="trending-controls" style="display:none" aria-label="Bộ lọc Xu hướng">
            <select id="regionSelect" class="filter-select" aria-label="Chọn khu vực">
                <option value="VN">Việt Nam</option>
                <option value="US">Hoa Kỳ</option>
                <option value="JP">Nhật Bản</option>
                <option value="KR">🇰🇷 Hàn Quốc</option>
                <option value="GB">🇬🇧 Anh</option>
                <option value="FR">🇫🇷 Pháp</option>
                <option value="DE">🇩🇪 Đức</option>
                <option value="IN">🇮🇳 Ấn Độ</option>
                <option value="BR">🇧🇷 Brazil</option>
                <option value="TH">🇹🇭 Thái Lan</option>
            </select>
            <select id="categorySelect" class="filter-select" aria-label="Danh mục">
                <option value="">Tất cả danh mục</option>
            </select>
        </div>

        <!-- Playlist header -->
        <div id="playlistHeader" class="playlist-header" style="display:none"></div>

        <section id="watch" class="watch" aria-label="Xem video">
            <div class="watch-layout">
                <div class="watch-main">
                    <div class="player-wrap" id="playerWrap"></div>
                    <h1 class="watch-title" id="watchTitle"></h1>
                    <div class="watch-stats" id="watchStats"></div>
                    <div class="channel-info" id="channelInfo"></div>
                    <section aria-label="Bình luận">
                        <h2 class="panel-title">Bình luận</h2>
                        <div id="commentBox" class="comment-box" style="display:none">
                            <div class="avatar" id="commentBoxAvatar" aria-hidden="true"></div>
                            <div class="comment-box-inner">
                                <textarea id="commentTextarea" class="comment-textarea" placeholder="Thêm bình luận..." maxlength="10000" rows="2"></textarea>
                                <div class="comment-box-actions">
                                    <button id="commentSubmitBtn" class="comment-submit-btn" type="button" disabled>Bình luận</button>
                                </div>
                            </div>
                        </div>
                        <div id="comments" class="comment-list"></div>
                        <button id="loadCommentsButton" class="load-more watch-load-more" type="button">Tải thêm bình luận</button>
                    </section>
                </div>
                <aside class="watch-side" aria-label="Đề xuất">
                    <h2 class="panel-title">Đề xuất</h2>
                    <div id="recommendations" class="recommendation-list"></div>
                    <button id="loadRecommendationsButton" class="load-more watch-load-more" type="button">Tải thêm đề xuất</button>
                </aside>
            </div>
        </section>

        <section id="channelHeader" class="channel-header" aria-label="Thông tin kênh"></section>
        <section id="results" class="grid" aria-label="Danh sách video"></section>
        <button id="loadMoreButton" class="load-more" type="button">Tải thêm</button>
        <div id="loader" class="loader">Đang tải thêm...</div>
        <div id="sentinel" class="sentinel" aria-hidden="true"></div>
        </main>
    </div>

    <script src="/assets/js/app.js" defer></script>
</body>
</html>
