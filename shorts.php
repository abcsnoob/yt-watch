<?php
declare(strict_types=1);
require_once __DIR__ . '/maintenance.php';
session_start();

maintenance_block_if_enabled();

$googleAuth = $_SESSION['google_oauth'] ?? null;
if (is_array($googleAuth) && (int) ($googleAuth['expires_at'] ?? 0) <= time()) {
    unset($_SESSION['google_oauth']);
    $googleAuth = null;
}
$googleProfile = is_array($googleAuth ?? null) ? ($googleAuth['profile'] ?? null) : null;
$googleName = is_array($googleProfile) ? (string) ($googleProfile['name'] ?? '') : '';
$googlePicture = is_array($googleProfile) ? (string) ($googleProfile['picture'] ?? '') : '';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Smart Shorts Viewer — YouTube Personal Viewer</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0f0f0f;
            --panel: #1b1b1b;
            --panel-2: #272727;
            --text: #f2f4f8;
            --line: #303030;
            --accent: #ff0033;
            --accent-2: #3ea6ff;
            --radius: 14px;
        }

        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, sans-serif; overflow: hidden; }

        .topbar {
            height: 56px; position: sticky; top: 0; z-index: 100;
            background: rgba(15,15,15,.98); border-bottom: 1px solid var(--line);
            backdrop-filter: blur(12px); display: flex; align-items: center; padding: 0 16px;
        }

        .shorts-viewport {
            height: calc(100dvh - 56px); overflow-y: scroll;
            scroll-snap-type: y mandatory; scroll-behavior: smooth;
        }

        .short-slide {
            height: calc(100dvh - 56px); scroll-snap-align: start;
            display: flex; align-items: center; justify-content: center;
            gap: 30px; padding: 20px;
        }

        /* PLAYER WRAP CO GIÃN THÔNG MINH */
        .short-player-wrap {
            position: relative; height: 100%; max-height: 850px;
            background: #000; border-radius: var(--radius); overflow: hidden;
            flex-shrink: 0; transition: all 0.4s ease;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
            display: flex; align-items: center; justify-content: center;
        }

        .is-portrait { aspect-ratio: 9 / 16; width: auto; }
        .is-landscape { aspect-ratio: 16 / 9; width: 100%; max-width: 1000px; }

        iframe, .short-thumb { width: 100%; height: 100%; border: 0; object-fit: contain; }

        .play-overlay {
            position: absolute; inset: 0; display: flex; align-items: center;
            justify-content: center; background: rgba(0,0,0,.2); cursor: pointer; z-index: 5;
        }

        .play-btn {
            width: 70px; height: 70px; border-radius: 50%;
            background: rgba(255,255,255,.15); backdrop-filter: blur(5px);
            border: 2px solid rgba(255,255,255,0.6);
            display: flex; align-items: center; justify-content: center; font-size: 28px;
        }

        .short-info { max-width: 320px; display: flex; flex-direction: column; gap: 15px; }
        .badge-related { background: var(--panel-2); padding: 4px 8px; border-radius: 4px; font-size: 11px; color: var(--accent-2); font-weight: bold; }

        @media (max-width: 900px) {
            .short-slide { flex-direction: column; padding: 10px; }
            .short-player-wrap { height: 60%; width: 100%; }
            .short-info { width: 100%; max-width: none; height: 35%; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div style="display:flex; justify-content:space-between; width:100%; align-items:center;">
            <a href="shorts.php" style="color:inherit; text-decoration:none; font-weight:800; font-size:18px;">▶ SHORTS PRO</a>
            <div style="display:flex; gap:15px;">
                <a href="/" style="color:var(--text); text-decoration:none; font-size:13px;">← Trang chủ</a>
            </div>
        </div>
    </header>

    <div id="shortsContainer" class="shorts-viewport"></div>

    <script>
    (function () {
        'use strict';
        const container = document.getElementById('shortsContainer');
        let loading = false;
        let nextPageToken = '';
        let seedChannelId = ''; // Lưu ID kênh của video gốc

        const urlParams = new URLSearchParams(window.location.search);
        const priorityId = urlParams.get('v');

        async function init() {
            if (priorityId) {
                // 1. Render ngay lập tức video được yêu cầu
                renderSlide({ videoId: priorityId, title: 'Đang tải...', channelTitle: '' }, true);
                
                // 2. Lấy thông tin chi tiết video đó để biết Channel ID hoặc chủ đề
                await fetchSeedContext(priorityId);
            }
            
            // 3. Tải các video cùng kênh hoặc liên quan
            await loadRelated();
            setupScrollObserver();
        }

        async function fetchSeedContext(vId) {
            try {
                const res = await fetch(`api.php?action=videoDetails&videoId=${vId}`);
                const data = await res.json();
                if (data.ok && data.item) {
                    seedChannelId = data.item.channelId;
                    // Cập nhật lại tiêu đề cho slide đầu tiên
                    const firstTitle = container.querySelector('.short-title');
                    if (firstTitle) firstTitle.textContent = data.item.title;
                }
            } catch (e) { console.error("Không lấy được context video"); }
        }

        async function loadRelated() {
            if (loading) return;
            loading = true;
            try {
                // Tùy chỉnh API call: Nếu có seedChannelId thì ưu tiên cùng kênh
                let apiUrl = `api.php?action=shorts&pageToken=${nextPageToken}`;
                if (seedChannelId) {
                    apiUrl += `&channelId=${seedChannelId}`; // Lấy cùng kênh
                } else if (priorityId) {
                    apiUrl += `&relatedToVideoId=${priorityId}`; // Hoặc cùng chủ đề
                }

                const res = await fetch(apiUrl);
                const data = await res.json();
                
                if (data.ok) {
                    nextPageToken = data.nextPageToken || '';
                    data.items.forEach(item => {
                        if (item.videoId !== priorityId) renderSlide(item);
                    });
                }
            } catch (e) { console.error("Lỗi tải đề xuất"); }
            loading = false;
        }

        function renderSlide(item, isPriority = false) {
            const slide = document.createElement('div');
            slide.className = 'short-slide';
            slide.dataset.videoId = item.videoId;

            const wrap = document.createElement('div');
            wrap.className = 'short-player-wrap is-portrait'; // Default

            // AUTO-STRETCH: Nhận diện ngang/dọc qua thumb
            const img = new Image();
            img.src = `https://img.youtube.com/vi/${item.videoId}/mqdefault.jpg`;
            img.onload = function() {
                if (this.width / this.height > 1.3) wrap.classList.replace('is-portrait', 'is-landscape');
            };

            const thumb = document.createElement('img');
            thumb.className = 'short-thumb';
            thumb.src = `https://img.youtube.com/vi/${item.videoId}/hqdefault.jpg`;

            const overlay = document.createElement('div');
            overlay.className = 'play-overlay';
            overlay.innerHTML = '<div class="play-btn">▶</div>';
            overlay.onclick = () => {
                const ifr = document.createElement('iframe');
                ifr.src = `https://www.youtube.com/embed/${item.videoId}?autoplay=1&rel=0&modestbranding=1`;
                ifr.allow = "autoplay; encrypted-media; fullscreen";
                wrap.replaceChildren(ifr);
            };

            wrap.append(thumb, overlay);

            const info = document.createElement('div');
            info.className = 'short-info';
            info.innerHTML = `
                ${isPriority ? '<span class="badge-related">ĐANG XEM</span>' : '<span class="badge-related">CÙNG CHỦ ĐỀ / KÊNH</span>'}
                <h3 class="short-title" style="margin:0">${item.title}</h3>
                <p style="color:var(--muted); margin:0; font-size:14px;">${item.channelTitle || ''}</p>
                <div style="display:flex; gap:10px; margin-top:10px;">
                    <button class="topbar-link" onclick="window.open('https://youtube.com/watch?v=${item.videoId}')" style="cursor:pointer; background:var(--panel-2); border:1px solid var(--line); color:white; padding:8px; border-radius:8px;">YouTube</button>
                    <button class="topbar-link" onclick="navigator.clipboard.writeText(window.location.href)" style="cursor:pointer; background:var(--panel-2); border:1px solid var(--line); color:white; padding:8px; border-radius:8px;">Copy Link</button>
                </div>
            `;

            slide.append(wrap, info);
            container.appendChild(slide);
        }

        function setupScrollObserver() {
            const obs = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const vId = entry.target.dataset.videoId;
                        history.replaceState(null, '', `?v=${vId}`);
                        if (entry.target === container.lastElementChild) loadRelated();
                    } else {
                        // Reset iframe về thumbnail khi trượt qua
                        const wrap = entry.target.querySelector('.short-player-wrap');
                        if (wrap.querySelector('iframe')) resetSlide(wrap, entry.target.dataset.videoId);
                    }
                });
            }, { threshold: 0.6 });

            const watch = () => container.querySelectorAll('.short-slide').forEach(s => obs.observe(s));
            watch();
            new MutationObserver(watch).observe(container, { childList: true });
        }

        function resetSlide(wrap, vId) {
            const thumb = document.createElement('img');
            thumb.src = `https://img.youtube.com/vi/${vId}/hqdefault.jpg`;
            thumb.className = 'short-thumb';
            const ov = document.createElement('div');
            ov.className = 'play-overlay';
            ov.innerHTML = '<div class="play-btn">▶</div>';
            ov.onclick = () => {
                const ifr = document.createElement('iframe');
                ifr.src = `https://www.youtube.com/embed/${vId}?autoplay=1`;
                wrap.replaceChildren(ifr);
            };
            wrap.replaceChildren(thumb, ov);
        }

        init();
    })();
    </script>
</body>
</html>