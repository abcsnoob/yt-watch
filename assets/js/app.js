(function () {
    'use strict';

    const API_URL = '/api.php';
    const MAX_RESULTS = 12;
    const HISTORY_KEY = 'yt_personal_viewer_history';
    const VIDEO_ID_RE = /^[A-Za-z0-9_-]{11}$/;
    const CHANNEL_ID_RE = /^UC[A-Za-z0-9_-]{20,}$/;

    const state = {
        route: 'feed',
        query: '',
        channelId: '',
        videoId: '',
        playlistId: '',
        regionCode: 'VN',
        categoryId: '',
        title: '',
        nextPageToken: '',
        nextCommentToken: '',
        nextRecommendationToken: '',
        loading: false,
        commentsLoading: false,
        recommendationsLoading: false,
        channelHeaderRendered: false,
        historyOffset: 0,
        ended: false
    };

    const els = {
        form: document.getElementById('searchForm'),
        input: document.getElementById('searchInput'),
        status: document.getElementById('status'),
        results: document.getElementById('results'),
        channelHeader: document.getElementById('channelHeader'),
        loadMoreButton: document.getElementById('loadMoreButton'),
        loader: document.getElementById('loader'),
        sentinel: document.getElementById('sentinel'),
        watch: document.getElementById('watch'),
        playerWrap: document.getElementById('playerWrap'),
        watchTitle: document.getElementById('watchTitle'),
        watchStats: document.getElementById('watchStats'),
        channelInfo: document.getElementById('channelInfo'),
        comments: document.getElementById('comments'),
        recommendations: document.getElementById('recommendations'),
        loadCommentsButton: document.getElementById('loadCommentsButton'),
        loadRecommendationsButton: document.getElementById('loadRecommendationsButton'),
        commentBox: document.getElementById('commentBox'),
        commentBoxAvatar: document.getElementById('commentBoxAvatar'),
        commentTextarea: document.getElementById('commentTextarea'),
        commentSubmitBtn: document.getElementById('commentSubmitBtn'),
        navButtons: document.querySelectorAll('.nav-button[data-route]'),
        trendingControls: document.getElementById('trendingControls'),
        regionSelect: document.getElementById('regionSelect'),
        categorySelect: document.getElementById('categorySelect'),
        playlistHeader: document.getElementById('playlistHeader'),
    };

    function init() {
        bindEvents();
        setupInfiniteScroll();
        navigateFromLocation(false);
    }

    function bindEvents() {
        els.form.addEventListener('submit', function (event) {
            event.preventDefault();
            const query = sanitizeQuery(els.input.value);
            if (!query) {
                setStatus('Nhập từ khóa để tìm kiếm.', true);
                return;
            }
            goTo({ route: 'search', q: query });
        });

        els.navButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const route = button.dataset.route || 'feed';
                if (route === 'shorts-page') {
                    window.open('shorts.php', '_blank');
                    return;
                }
                goTo({ route: route });
            });
        });

        els.loadMoreButton.addEventListener('click', function () {
            if (state.route === 'history') {
                renderHistoryPage();
            } else {
                loadMore();
            }
        });

        els.loadCommentsButton.addEventListener('click', function () {
            loadMoreComments();
        });

        els.loadRecommendationsButton.addEventListener('click', function () {
            loadMoreRecommendations();
        });
        // Comment box: enable submit when text non-empty
        els.commentTextarea && els.commentTextarea.addEventListener('input', function () {
            els.commentSubmitBtn.disabled = this.value.trim() === '';
        });
        els.commentSubmitBtn && els.commentSubmitBtn.addEventListener('click', function () {
            postComment();
        });
        els.commentTextarea && els.commentTextarea.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                if (!els.commentSubmitBtn.disabled) postComment();
            }
        });

        // Comment box: enable submit when text non-empty
        els.commentTextarea && els.commentTextarea.addEventListener('input', function () {
            els.commentSubmitBtn.disabled = this.value.trim() === '';
        });

        els.commentSubmitBtn && els.commentSubmitBtn.addEventListener('click', function () {
            postComment();
        });

        els.commentTextarea && els.commentTextarea.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                if (!els.commentSubmitBtn.disabled) postComment();
            }
        });

        // Trending region/category selectors
        els.regionSelect && els.regionSelect.addEventListener('change', function () {
            state.regionCode = this.value;
            state.categoryId = '';
            els.categorySelect && (els.categorySelect.value = '');
            resetAndReload();
            loadCategories(this.value);
        });

        els.categorySelect && els.categorySelect.addEventListener('change', function () {
            state.categoryId = this.value;
            resetAndReload();
        });

        document.addEventListener('click', function (event) {
            const subscribeButton = event.target.closest('[data-subscribe-channel-id]');
            if (subscribeButton) {
                event.preventDefault();
                subscribeChannel(subscribeButton);
                return;
            }
            const replyBtn = event.target.closest('[data-reply-comment-id]');
            if (replyBtn) {
                event.preventDefault();
                toggleReplyBox(replyBtn);
                return;
            }
            const replySubmit = event.target.closest('[data-submit-reply]');
            if (replySubmit) {
                event.preventDefault();
                postReply(replySubmit);
                return;
            }

            const channelLink = event.target.closest('[data-channel-id]');
            if (channelLink) {
                event.preventDefault();
                goTo({ route: 'channel', channelId: channelLink.dataset.channelId });
                return;
            }

            const playlistLink = event.target.closest('[data-playlist-id]');
            if (playlistLink) {
                event.preventDefault();
                goTo({ route: 'playlist', playlistId: playlistLink.dataset.playlistId });
                return;
            }

            const watchLink = event.target.closest('[data-video-id]');
            if (watchLink) {
                event.preventDefault();
                goTo({
                    route: 'watch',
                    v: watchLink.dataset.videoId,
                    title: watchLink.dataset.title || ''
                });
            }
        });

        window.addEventListener('popstate', function () {
            navigateFromLocation(false);
        });
    }

    function resetAndReload() {
        state.nextPageToken = '';
        state.ended = false;
        state.loading = false;
        els.results.replaceChildren();
        setStatus('');
        loadMore();
    }

    function setupInfiniteScroll() {
        const observer = new IntersectionObserver(function (entries) {
            if (entries.some(entry => entry.isIntersecting)) {
                if (state.route === 'watch') {
                    loadMoreRecommendations();
                } else if (state.route === 'history') {
                    renderHistoryPage();
                } else {
                    loadMore();
                }
            }
        }, { rootMargin: '600px 0px' });

        observer.observe(els.sentinel);
    }

    function goTo(params) {
        const url = buildUrl(params);
        history.pushState({}, '', url);
        navigateFromLocation(true);
    }

function buildUrl(params) {
        const url = new URL(window.location.href);
        const baseUrl = url.origin + url.pathname.split('/').slice(0, -2).join('/'); // Giữ root path

        // Nếu là xem video, ưu tiên dùng cấu trúc /watch/ID
        if (params.route === 'watch' && params.v) {
            return `/watch/${params.v}`;
        }

        // Các trường hợp khác vẫn dùng query params nhưng xóa sạch param cũ trước
        url.search = '';
        if (params.route && params.route !== 'feed') {
            url.searchParams.set('view', params.route);
        }
        if (params.q) url.searchParams.set('q', params.q);
        if (params.channelId) url.searchParams.set('channelId', params.channelId);
        if (params.v) url.searchParams.set('v', params.v);
        if (params.playlistId) url.searchParams.set('playlistId', params.playlistId);
        if (params.regionCode) url.searchParams.set('regionCode', params.regionCode);
        if (params.title) url.searchParams.set('title', params.title);

        return url.pathname + url.search;
    }

function navigateFromLocation(scrollTop) {
        const params = new URLSearchParams(window.location.search);
        const pathSegments = window.location.pathname.split('/').filter(Boolean);
        
        // MẶC ĐỊNH LẤY TỪ QUERY PARAMS
        let view = params.get('view') || 'feed';
        let videoId = params.get('v') || '';

        // KIỂM TRA ĐƯỜNG DẪN KIỂU MỚI: /watch/ID
        // pathSegments[0] sẽ là "watch", pathSegments[1] sẽ là "ID"
        if (pathSegments[0] === 'watch' && pathSegments[1]) {
            view = 'watch';
            videoId = pathSegments[1];
        }

        state.route = ['feed', 'shorts', 'subscriptions', 'personal', 'liked', 'history', 'search', 'watch', 'channel', 'trending', 'shorts-page', 'playlists', 'playlist', 'myvideos', 'activities'].includes(view) ? view : 'feed';
        state.query = sanitizeQuery(params.get('q') || '');
        state.channelId = sanitizeChannelId(params.get('channelId') || '');
        state.videoId = sanitizeVideoId(videoId); // Sử dụng ID đã lấy từ Path hoặc Query
        state.playlistId = sanitizePlaylistId(params.get('playlistId') || '');
        state.regionCode = sanitizeRegionCode(params.get('regionCode') || 'VN');
        state.categoryId = sanitizeCategoryId(params.get('categoryId') || '');
        state.title = sanitizePlainText(params.get('title') || '');
        
        // Các logic reset state bên dưới giữ nguyên...
        state.nextPageToken = '';
        state.nextCommentToken = '';
        state.nextRecommendationToken = '';
        state.loading = false;
        state.commentsLoading = false;
        state.recommendationsLoading = false;
        state.channelHeaderRendered = false;
        state.historyOffset = 0;
        state.ended = false;

        resetView();

        if (scrollTop) {
            window.scrollTo({ top: 0, behavior: 'instant' });
        }

        // Điều hướng đến đúng trang tương ứng
        if (state.route === 'watch') {
            renderWatchShell();
            loadWatchData();
            return;
        }
        
        // ... Các logic route khác (history, trending, search...) giữ nguyên
        if (state.route === 'history') {
            renderHistoryPage();
            return;
        }

        loadMore();
    }

    function resetView() {
        els.results.replaceChildren();
        els.results.classList.toggle('shorts-grid', state.route === 'shorts');
        els.channelHeader.replaceChildren();
        els.channelHeader.classList.remove('active');
        els.playerWrap.replaceChildren();
        els.watchTitle.textContent = '';
        els.watchStats.textContent = '';
        els.channelInfo.replaceChildren();
        els.comments.replaceChildren();
        els.recommendations.replaceChildren();
        els.watch.classList.toggle('active', state.route === 'watch');
        els.input.value = state.query;
        els.navButtons.forEach(function (button) {
            button.setAttribute('aria-current', button.dataset.route === state.route ? 'page' : 'false');
        });
        // Toggle trending controls
        if (els.trendingControls) {
            els.trendingControls.style.display = state.route === 'trending' ? '' : 'none';
        }
        // Clear playlist header
        if (els.playlistHeader) {
            els.playlistHeader.style.display = 'none';
            els.playlistHeader.replaceChildren();
        }
        updateLoadMoreButton();
        updateWatchButtons();
        setStatus('');
    }

    async function loadMore() {
        if (state.loading || state.ended || state.route === 'watch') {
            return;
        }

        state.loading = true;
        els.loader.classList.add('active');
        updateLoadMoreButton();
        setStatus(state.nextPageToken ? '' : 'Đang tải...');

        try {
            const apiAction = {
                'feed': 'feed', 'shorts': 'shorts', 'subscriptions': 'subscriptions',
                'personal': 'personal', 'liked': 'liked', 'search': 'search',
                'channel': 'channel', 'trending': 'trending', 'playlists': 'playlists',
                'playlist': 'playlist', 'myvideos': 'myvideos', 'activities': 'activities'
            }[state.route] || 'feed';
            const data = await fetchJson(buildApiUrl(apiAction));
            if (!data.ok) {
                handleApiError(data);
                return;
            }

            if (state.route === 'channel' && data.channel && !state.channelHeaderRendered) {
                renderChannelHeader(data.channel);
                state.channelHeaderRendered = true;
            }

            if (state.route === 'playlist' && data.playlist && !state.channelHeaderRendered) {
                renderPlaylistHeader(data.playlist);
                state.channelHeaderRendered = true;
            }

            if (state.route === 'subscriptions') {
                renderChannels(Array.isArray(data.items) ? data.items : [], els.results);
            } else if (state.route === 'playlists') {
                renderPlaylists(Array.isArray(data.items) ? data.items : [], els.results);
            } else if (state.route === 'activities') {
                renderActivities(Array.isArray(data.items) ? data.items : [], els.results);
            } else {
                renderItems(
                    Array.isArray(data.items) ? data.items : [],
                    els.results,
                    state.route === 'shorts' ? 'shorts' : 'grid'
                );
            }
            state.nextPageToken = sanitizePageToken(data.nextPageToken || '');
            state.ended = !state.nextPageToken;
            updateLoadMoreButton();

            const cacheText = data.cache && data.cache.hit ? 'cache' : 'API';
            const count = els.results.children.length;
            setStatus(count ? `${count} video, nguồn ${cacheText}.` : 'Không có kết quả.');
        } catch (error) {
            setStatus('Không tải được dữ liệu. Kiểm tra mạng hoặc API key.', true);
        } finally {
            state.loading = false;
            els.loader.classList.remove('active');
            updateLoadMoreButton();
        }
    }

    function buildApiUrl(action) {
        const url = new URL(API_URL, window.location.href);
        url.searchParams.set('action', action);
        url.searchParams.set('maxResults', String(MAX_RESULTS));

        if (action === 'search') {
            url.searchParams.set('q', state.query);
        }
        if (action === 'channel') {
            url.searchParams.set('channelId', state.channelId);
        }
        if (action === 'watch' || action === 'comments') {
            url.searchParams.set('videoId', state.videoId);
        }
        if (action === 'playlist') {
            url.searchParams.set('playlistId', state.playlistId);
        }
        if (action === 'trending') {
            url.searchParams.set('regionCode', state.regionCode);
            if (state.categoryId) url.searchParams.set('videoCategoryId', state.categoryId);
        }
        if (state.nextPageToken && action !== 'watch' && action !== 'comments') {
            url.searchParams.set('pageToken', state.nextPageToken);
        }

        return url;
    }

    async function loadWatchData() {
        if (!state.videoId) {
            setStatus('Video không hợp lệ.', true);
            return;
        }

        setStatus('Đang tải trang xem...');

        try {
            const data = await fetchJson(buildApiUrl('watch'));
            if (!data.ok) {
                handleApiError(data);
                return;
            }

            renderWatchDetails(data.video || {});
            saveWatchHistory(data.video || {});
            renderChannelInfo(data.channel || null, data.video || {});
            renderComments(Array.isArray(data.comments) ? data.comments : []);
            renderItems(Array.isArray(data.recommendations) ? data.recommendations : [], els.recommendations, 'recommendation');
            state.nextCommentToken = sanitizePageToken(data.nextCommentToken || '');
            state.nextRecommendationToken = sanitizePageToken(data.nextRecommendationToken || '');
            updateWatchButtons();
            setStatus('');
        } catch (error) {
            setStatus('Không tải được trang xem.', true);
        }
    }

    function renderWatchShell() {
        const iframe = document.createElement('iframe');
        iframe.src = `https://www.youtube-nocookie.com/embed/${state.videoId}?autoplay=1&rel=0&modestbranding=1`;
        iframe.title = state.title || 'Trình phát YouTube';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
        iframe.allowFullscreen = true;
        iframe.loading = 'eager';

        els.playerWrap.appendChild(iframe);
        els.watchTitle.textContent = state.title || 'Đang phát video';
    }

    function renderWatchDetails(video) {
        const title = sanitizePlainText(video.title || state.title || 'Đang phát video');
        state.title = title;
        els.watchTitle.textContent = title;

        const stats = [];
        if (video.viewCount) {
            stats.push(`${formatCount(video.viewCount)} lượt xem`);
        }
        if (video.likeCount) {
            stats.push(`${formatCount(video.likeCount)} lượt thích`);
        }
        if (video.publishedAt) {
            stats.push(formatDate(video.publishedAt));
        }
        els.watchStats.textContent = stats.join(' • ');
    }

    function renderChannelInfo(channel, video) {
        const data = channel || {
            channelId: sanitizeChannelId(video.channelId || ''),
            title: sanitizePlainText(video.channelTitle || 'Kênh không xác định'),
            avatarUrl: sanitizeImageUrl(video.avatarUrl || ''),
            subscriberCount: '',
            videoCount: '',
            description: ''
        };

        const channelId = sanitizeChannelId(data.channelId || '');
        els.channelInfo.replaceChildren();
        if (!channelId) {
            return;
        }

        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.dataset.fallback = initials(data.title || 'YT');
        renderAvatar(avatar, sanitizeImageUrl(data.avatarUrl || ''), data.title || 'YT');

        const body = document.createElement('div');
        const name = document.createElement('h2');
        name.className = 'channel-name';
        name.textContent = sanitizePlainText(data.title || 'Kênh không xác định');

        const stats = document.createElement('div');
        stats.className = 'channel-stats';
        const statParts = [];
        if (data.subscriberCount) {
            statParts.push(`${formatCount(data.subscriberCount)} người đăng ký`);
        }
        if (data.videoCount) {
            statParts.push(`${formatCount(data.videoCount)} videos`);
        }
        stats.textContent = statParts.join(' • ');

        const description = document.createElement('p');
        description.className = 'channel-description';
        description.textContent = sanitizePlainText(data.description || '');

        body.append(name, stats);
        if (description.textContent) {
            body.appendChild(description);
        }

        const subscribe = document.createElement('button');
        subscribe.type = 'button';
        subscribe.className = 'subscribe-button';
        subscribe.dataset.subscribeChannelId = channelId;
        subscribe.textContent = 'Đăng ký';

        els.channelInfo.append(avatar, body, subscribe);
    }

    function renderChannelHeader(channel) {
        const channelId = sanitizeChannelId(channel.channelId || '');
        if (!channelId) {
            return;
        }

        const title = sanitizePlainText(channel.title || 'Kênh không xác định');
        const avatarUrl = sanitizeImageUrl(channel.avatarUrl || '');
        const bannerUrl = sanitizeImageUrl(channel.bannerUrl || '');
        const descriptionText = sanitizePlainText(channel.description || '');

        els.channelHeader.replaceChildren();

        const banner = document.createElement('div');
        banner.className = 'channel-banner';
        if (bannerUrl) {
            banner.style.backgroundImage = `url("${bannerUrl}")`;
        }

        const profile = document.createElement('div');
        profile.className = 'channel-profile';

        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.dataset.fallback = initials(title);
        renderAvatar(avatar, avatarUrl, title);

        const body = document.createElement('div');
        const heading = document.createElement('h1');
        heading.className = 'channel-profile-title';
        heading.textContent = title;

        const meta = document.createElement('div');
        meta.className = 'channel-profile-meta';
        const metaParts = [];
        if (channel.customUrl) {
            metaParts.push(sanitizePlainText(channel.customUrl));
        }
        if (channel.subscriberCount) {
            metaParts.push(`${formatCount(channel.subscriberCount)} người đăng ký`);
        }
        if (channel.videoCount) {
            metaParts.push(`${formatCount(channel.videoCount)} video`);
        }
        meta.textContent = metaParts.join(' • ');

        const description = document.createElement('p');
        description.className = 'channel-profile-description';
        description.textContent = descriptionText;

        body.append(heading, meta);
        if (descriptionText) {
            body.appendChild(description);
        }

        const subscribe = document.createElement('button');
        subscribe.type = 'button';
        subscribe.className = 'subscribe-button';
        subscribe.dataset.subscribeChannelId = channelId;
        subscribe.textContent = 'Đăng ký';

        profile.append(avatar, body, subscribe);
        els.channelHeader.append(banner, profile);
        els.channelHeader.classList.add('active');
    }

    async function loadMoreComments() {
        if (state.commentsLoading || !state.nextCommentToken) {
            return;
        }

        state.commentsLoading = true;
        updateWatchButtons();
        try {
            const url = buildApiUrl('comments');
            url.searchParams.set('pageToken', state.nextCommentToken);
            const data = await fetchJson(url);
            if (!data.ok) {
                handleApiError(data);
                return;
            }
            renderComments(Array.isArray(data.items) ? data.items : []);
            state.nextCommentToken = sanitizePageToken(data.nextPageToken || '');
        } catch (error) {
            setStatus('Không tải được bình luận.', true);
        } finally {
            state.commentsLoading = false;
            updateWatchButtons();
        }
    }

    async function loadMoreRecommendations() {
        if (state.recommendationsLoading || !state.nextRecommendationToken) {
            return;
        }

        state.recommendationsLoading = true;
        updateWatchButtons();
        try {
            const url = buildApiUrl('watch');
            url.searchParams.set('pageToken', state.nextRecommendationToken);
            const data = await fetchJson(url);
            if (!data.ok) {
                handleApiError(data);
                return;
            }
            renderItems(Array.isArray(data.recommendations) ? data.recommendations : [], els.recommendations, 'recommendation');
            state.nextRecommendationToken = sanitizePageToken(data.nextRecommendationToken || '');
        } catch (error) {
            setStatus('Không tải được đề xuất.', true);
        } finally {
            state.recommendationsLoading = false;
            updateWatchButtons();
        }
    }

    async function fetchJson(url) {
        const response = await fetch(url.toString(), {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        });

        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Invalid JSON response');
        }

        if (!response.ok && data && !data.ok) {
            return data;
        }

        return data;
    }

    async function fetchJsonPost(url, body) {
        const response = await fetch(url.toString(), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        });

        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Invalid JSON response');
        }

        return data;
    }

    function handleApiError(data) {
        const messages = {
            API_KEY_MISSING: 'Chưa cấu hình API key trong config.php.',
            QUOTA_EXCEEDED: 'Đã hết quota API hôm nay. Các trang đã cache vẫn có thể xem lại.',
            SEARCH_QUERY_REQUIRED: 'Thiếu từ khóa tìm kiếm.',
            CHANNEL_ID_REQUIRED: 'Thiếu channelId.',
            VIDEO_ID_REQUIRED: 'Thiếu videoId.',
            PLAYLIST_ID_REQUIRED: 'Thiếu playlistId.',
            VIDEO_NOT_FOUND: 'Không tìm thấy video.',
            AUTH_REQUIRED: 'Hãy đăng nhập Google để dùng tính năng này.',
            METHOD_NOT_ALLOWED: 'Yêu cầu không hợp lệ.',
            MAINTENANCE_MODE: 'Hệ thống đang bảo trì. Mở status.php để kiểm tra.',
            UPSTREAM_UNAVAILABLE: 'YouTube API đang không phản hồi.',
            YOUTUBE_API_ERROR: 'YouTube từ chối yêu cầu.',
            HISTORY_API_UNAVAILABLE: 'YouTube không còn hỗ trợ API lịch sử xem. Dùng lịch sử cục bộ trong trình duyệt.',
            NO_UPLOADS_PLAYLIST: 'Không tìm thấy video đã tải lên.',
        };

        setStatus(messages[data.error] || data.message || 'Có lỗi xảy ra.', true);
    }

    function renderItems(items, target, mode) {
        const fragment = document.createDocumentFragment();

        items.forEach(function (item) {
            const videoId = sanitizeVideoId(item.videoId || '');
            if (!videoId) {
                return;
            }

            const title = sanitizePlainText(item.title || 'Untitled video');
            const channelTitle = sanitizePlainText(item.channelTitle || 'Kênh không xác định');
            const channelId = sanitizeChannelId(item.channelId || '');
            const avatarUrl = sanitizeImageUrl(item.avatarUrl || '');

            fragment.appendChild(mode === 'recommendation'
                ? createRecommendationCard(videoId, title, channelTitle, channelId)
                : createGridCard(videoId, title, channelTitle, channelId, avatarUrl, item.publishedAt || '', mode === 'shorts'));
        });

        target.appendChild(fragment);
    }

    function createGridCard(videoId, title, channelTitle, channelId, avatarUrl, publishedAt, isShorts) {
        const card = document.createElement('article');
        card.className = isShorts ? 'video-card shorts-card' : 'video-card';

        const watchLink = createWatchLink(videoId, title);
        const img = document.createElement('img');
        img.className = 'thumb';
        img.src = `https://img.youtube.com/vi/${videoId}/mqdefault.jpg`;
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        watchLink.appendChild(img);
        card.appendChild(watchLink);

        const meta = document.createElement('div');
        meta.className = 'meta';

        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.title = channelTitle;
        avatar.dataset.fallback = initials(channelTitle);
        renderAvatar(avatar, avatarUrl, channelTitle);

        const text = document.createElement('div');
        const titleLink = createWatchLink(videoId, title);
        const titleEl = document.createElement('h2');
        titleEl.className = 'video-title';
        titleEl.textContent = title;
        titleLink.appendChild(titleEl);

        const line = document.createElement('div');
        line.className = 'channel-line';
        line.append(createChannelLink(channelId, channelTitle), document.createTextNode(formatDate(publishedAt)));

        text.append(titleLink, line);
        meta.append(avatar, text);
        card.appendChild(meta);

        return card;
    }

    function createRecommendationCard(videoId, title, channelTitle, channelId) {
        const card = document.createElement('article');
        card.className = 'recommendation-card';

        const thumbLink = createWatchLink(videoId, title);
        const img = document.createElement('img');
        img.className = 'thumb';
        img.src = `https://img.youtube.com/vi/${videoId}/mqdefault.jpg`;
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        thumbLink.appendChild(img);

        const text = document.createElement('div');
        const titleLink = createWatchLink(videoId, title);
        const titleEl = document.createElement('h3');
        titleEl.className = 'video-title';
        titleEl.textContent = title;
        titleLink.appendChild(titleEl);

        const line = document.createElement('div');
        line.className = 'channel-line';
        line.appendChild(createChannelLink(channelId, channelTitle));

        text.append(titleLink, line);
        card.append(thumbLink, text);
        return card;
    }

    function renderChannels(items, target) {
        const fragment = document.createDocumentFragment();

        items.forEach(function (item) {
            const channelId = sanitizeChannelId(item.channelId || '');
            if (!channelId) {
                return;
            }

            const title = sanitizePlainText(item.title || 'Kênh không xác định');
            const avatarUrl = sanitizeImageUrl(item.avatarUrl || '');
            const description = sanitizePlainText(item.description || '');

            const card = document.createElement('article');
            card.className = 'video-card channel-subscription-card';

            const link = document.createElement('a');
            link.className = 'watch-link';
            link.href = buildUrl({ route: 'channel', channelId: channelId });
            link.dataset.channelId = channelId;

            const avatar = document.createElement('div');
            avatar.className = 'avatar';
            avatar.style.width = '88px';
            avatar.style.height = '88px';
            avatar.style.fontSize = '22px';
            avatar.dataset.fallback = initials(title);
            renderAvatar(avatar, avatarUrl, title);

            const heading = document.createElement('h2');
            heading.className = 'video-title';
            heading.textContent = title;

            const desc = document.createElement('p');
            desc.className = 'channel-description';
            desc.textContent = description;

            link.append(avatar, heading, desc);
            card.appendChild(link);
            fragment.appendChild(card);
        });

        target.appendChild(fragment);
    }

    function renderHistoryPage() {
        if (state.loading || state.ended) {
            return;
        }

        const history = loadWatchHistory();
        if (!history.length) {
            setStatus('Chưa có lịch sử xem trên trình duyệt này.');
            state.ended = true;
            updateLoadMoreButton();
            return;
        }

        state.loading = true;
        updateLoadMoreButton();

        const nextItems = history.slice(state.historyOffset, state.historyOffset + MAX_RESULTS);
        renderItems(nextItems, els.results, 'grid');
        state.historyOffset += nextItems.length;
        state.ended = state.historyOffset >= history.length;
        state.loading = false;
        updateLoadMoreButton();
        setStatus(`${Math.min(state.historyOffset, history.length)} / ${history.length} video trong lịch sử xem cục bộ.`);
    }

    function saveWatchHistory(video) {
        const videoId = sanitizeVideoId(video.videoId || state.videoId || '');
        if (!videoId) {
            return;
        }

        const item = {
            videoId: videoId,
            title: sanitizePlainText(video.title || state.title || 'Untitled video'),
            channelId: sanitizeChannelId(video.channelId || ''),
            channelTitle: sanitizePlainText(video.channelTitle || 'Kênh không xác định'),
            avatarUrl: sanitizeImageUrl(video.avatarUrl || ''),
            publishedAt: video.publishedAt || '',
            watchedAt: new Date().toISOString()
        };

        const current = loadWatchHistory().filter(entry => entry.videoId !== videoId);
        current.unshift(item);
        localStorage.setItem(HISTORY_KEY, JSON.stringify(current.slice(0, 80)));
    }

    function loadWatchHistory() {
        try {
            const parsed = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            return Array.isArray(parsed) ? parsed.filter(item => sanitizeVideoId(item.videoId || '')) : [];
        } catch (error) {
            return [];
        }
    }

    async function subscribeChannel(button) {
        const channelId = sanitizeChannelId(button.dataset.subscribeChannelId || '');
        if (!channelId || button.disabled) {
            return;
        }

        button.disabled = true;
        const originalText = button.textContent;
        button.textContent = 'Đang đăng ký...';

        try {
            const url = new URL(API_URL, window.location.href);
            url.searchParams.set('action', 'subscribe');
            const response = await fetchJsonPost(url, { channelId: channelId });
            if (!response.ok) {
                handleApiError(response);
                if (response.error === 'AUTH_REQUIRED') {
                    button.textContent = 'Cần đăng nhập';
                } else {
                    button.textContent = originalText || 'Đăng ký';
                    button.disabled = false;
                }
                return;
            }

            button.textContent = 'Đã đăng ký';
            setStatus('Đã gửi yêu cầu đăng ký kênh.');
        } catch (error) {
            setStatus('Không đăng ký được kênh.', true);
            button.textContent = originalText || 'Đăng ký';
            button.disabled = false;
        }
    }

    function renderComments(items, target) {
        const container = target || els.comments;
        const fragment = document.createDocumentFragment();

        items.forEach(function (item) {
            const author = sanitizePlainText(item.author || 'Người dùng YouTube');
            const avatarUrl = sanitizeImageUrl(item.avatarUrl || '');
            const authorChannelId = sanitizeChannelId(item.authorChannelId || '');
            const commentId = sanitizePlainText(item.commentId || '');
            const text = sanitizePlainText(item.text || '');
            if (!text) {
                return;
            }

            const row = document.createElement('article');
            row.className = 'comment';

            const avatar = document.createElement('div');
            avatar.className = 'avatar';
            avatar.dataset.fallback = initials(author);
            if (authorChannelId) {
                avatar.dataset.channelId = authorChannelId;
                avatar.style.cursor = 'pointer';
            }
            renderAvatar(avatar, avatarUrl, author);

            const body = document.createElement('div');

            // Author: link nếu có channelId, text thường nếu không
            let name;
            if (authorChannelId) {
                name = document.createElement('a');
                name.href = buildUrl({ route: 'channel', channelId: authorChannelId });
                name.dataset.channelId = authorChannelId;
            } else {
                name = document.createElement('span');
            }
            name.className = 'comment-author';
            name.textContent = author;

            const contentEl = document.createElement('p');
            contentEl.className = 'comment-text';
            contentEl.textContent = text;

            const actions = document.createElement('div');
            actions.className = 'comment-actions';

            const meta = document.createElement('span');
            meta.className = 'comment-meta';
            const metaParts = [];
            if (item.likeCount) metaParts.push(`${formatCount(item.likeCount)} lượt thích`);
            if (item.publishedAt) metaParts.push(formatDate(item.publishedAt));
            meta.textContent = metaParts.join(' • ');
            actions.appendChild(meta);

            // Reply button (chỉ khi đăng nhập và có commentId)
            if (commentId) {
                const replyBtn = document.createElement('button');
                replyBtn.className = 'comment-reply-btn';
                replyBtn.type = 'button';
                replyBtn.textContent = 'Trả lời';
                replyBtn.dataset.replyCommentId = commentId;
                replyBtn.dataset.replyAuthor = author;
                actions.appendChild(replyBtn);
            }

            body.append(name, contentEl, actions);
            row.append(avatar, body);
            fragment.appendChild(row);
        });

        container.appendChild(fragment);
    }

    function createWatchLink(videoId, title) {
        const link = document.createElement('a');
        link.className = 'watch-link';
        link.href = buildUrl({ route: 'watch', v: videoId, title: title });
        link.dataset.videoId = videoId;
        link.dataset.title = title;
        return link;
    }

    function createChannelLink(channelId, channelTitle) {
        const channel = document.createElement('a');
        channel.className = 'channel-link';
        channel.href = channelId ? buildUrl({ route: 'channel', channelId: channelId }) : '#';
        channel.textContent = channelTitle;
        if (channelId) {
            channel.dataset.channelId = channelId;
        }
        return channel;
    }

    function setStatus(message, isError) {
        els.status.textContent = message || '';
        els.status.classList.toggle('error', Boolean(isError));
    }

    function updateLoadMoreButton() {
        const canLoadMore = state.route === 'history'
            ? !state.ended
            : state.route !== 'watch' && !state.ended && Boolean(state.nextPageToken);
        els.loadMoreButton.classList.toggle('active', canLoadMore);
        els.loadMoreButton.disabled = state.loading;
        els.loadMoreButton.textContent = state.loading ? 'Đang tải...' : 'Tải thêm';
    }

    function updateWatchButtons() {
        const hasComments = state.route === 'watch' && Boolean(state.nextCommentToken);
        const hasRecommendations = state.route === 'watch' && Boolean(state.nextRecommendationToken);
        els.loadCommentsButton.classList.toggle('active', hasComments);
        els.loadCommentsButton.disabled = state.commentsLoading;
        els.loadCommentsButton.textContent = state.commentsLoading ? 'Đang tải...' : 'Tải thêm bình luận';
        els.loadRecommendationsButton.classList.toggle('active', hasRecommendations);
        els.loadRecommendationsButton.disabled = state.recommendationsLoading;
        els.loadRecommendationsButton.textContent = state.recommendationsLoading ? 'Đang tải...' : 'Tải thêm đề xuất';
    }

    function renderAvatar(container, avatarUrl, channelTitle) {
        const fallback = container.dataset.fallback || initials(channelTitle);
        container.replaceChildren();

        if (!avatarUrl) {
            container.textContent = fallback;
            return;
        }

        const img = document.createElement('img');
        img.src = avatarUrl;
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.referrerPolicy = 'no-referrer';
        img.addEventListener('error', function () {
            container.replaceChildren();
            container.textContent = fallback;
        }, { once: true });
        container.appendChild(img);
    }

    function sanitizeQuery(value) {
        return sanitizePlainText(value).slice(0, 120);
    }

    function sanitizePlainText(value) {
        return String(value || '').replace(/[\u0000-\u001f\u007f<>]/g, '').trim();
    }

    function sanitizeVideoId(value) {
        const text = String(value || '').trim();
        return VIDEO_ID_RE.test(text) ? text : '';
    }

    function sanitizeChannelId(value) {
        const text = String(value || '').trim();
        return CHANNEL_ID_RE.test(text) ? text : '';
    }

    function sanitizePageToken(value) {
        const text = String(value || '').trim();
        return /^[A-Za-z0-9_.-]*$/.test(text) ? text : '';
    }

    function sanitizeImageUrl(value) {
        const text = String(value || '').trim();
        if (!/^https:\/\/[A-Za-z0-9.-]+\//.test(text)) {
            return '';
        }

        try {
            const url = new URL(text);
            const host = url.hostname.toLowerCase();
            const allowed = host === 'yt3.ggpht.com'
                || host === 'yt3.googleusercontent.com'
                || host.endsWith('.googleusercontent.com')
                || host.endsWith('.ggpht.com');
            return allowed ? url.toString() : '';
        } catch (error) {
            return '';
        }
    }

    function initials(name) {
        const parts = sanitizePlainText(name).split(/\s+/).filter(Boolean);
        if (!parts.length) {
            return 'YT';
        }
        return parts.slice(0, 2).map(part => part[0]).join('').toUpperCase();
    }

    function formatDate(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        return date.toLocaleDateString('vi-VN', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    function formatCount(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '';
        }
        return new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(number);
    }

    function renderPlaylists(items, target) {
        const fragment = document.createDocumentFragment();

        items.forEach(function (item) {
            const playlistId = sanitizePlainText(item.playlistId || '');
            if (!playlistId) return;

            const title = sanitizePlainText(item.title || 'Playlist');
            const thumbUrl = sanitizeImageUrl(item.thumbnailUrl || '');
            const count = parseInt(item.itemCount || 0, 10);

            const card = document.createElement('article');
            card.className = 'video-card playlist-card';

            const link = document.createElement('a');
            link.className = 'watch-link';
            link.href = buildUrl({ route: 'playlist', playlistId: playlistId });
            link.dataset.playlistId = playlistId;

            const thumbWrap = document.createElement('div');
            thumbWrap.className = 'playlist-thumb-wrap';

            const img = document.createElement('img');
            img.className = 'thumb';
            img.src = thumbUrl || `https://img.youtube.com/vi/0/mqdefault.jpg`;
            img.alt = '';
            img.loading = 'lazy';

            const badge = document.createElement('span');
            badge.className = 'playlist-count-badge';
            badge.textContent = count > 0 ? `${count} video` : '';

            thumbWrap.append(img);
            if (count > 0) thumbWrap.append(badge);

            const titleEl = document.createElement('h2');
            titleEl.className = 'video-title';
            titleEl.style.marginTop = '8px';
            titleEl.textContent = title;

            link.append(thumbWrap, titleEl);
            card.appendChild(link);
            fragment.appendChild(card);
        });

        target.appendChild(fragment);
    }

    function renderPlaylistHeader(playlist) {
        if (!els.playlistHeader) return;
        const title = sanitizePlainText(playlist.title || 'Playlist');
        const desc = sanitizePlainText(playlist.description || '');
        const channelTitle = sanitizePlainText(playlist.channelTitle || '');

        els.playlistHeader.replaceChildren();
        const h2 = document.createElement('h2');
        h2.textContent = title;

        const meta = document.createElement('p');
        meta.textContent = [channelTitle, desc].filter(Boolean).join(' — ').slice(0, 200);

        els.playlistHeader.append(h2, meta);
        els.playlistHeader.style.display = '';
    }

    function renderActivities(items, target) {
        const fragment = document.createDocumentFragment();
        const labelMap = { 'upload': '⬆ Đăng video', 'like': '👍 Thích', 'playlistItem': '📋 Thêm vào playlist' };

        items.forEach(function (item) {
            const videoId = sanitizeVideoId(item.videoId || '');
            if (!videoId) return;

            const title = sanitizePlainText(item.title || '');
            const type = sanitizePlainText(item.activityType || '');

            const card = document.createElement('article');
            card.className = 'video-card';

            const link = createWatchLink(videoId, title);
            const img = document.createElement('img');
            img.className = 'thumb';
            img.src = `https://img.youtube.com/vi/${videoId}/mqdefault.jpg`;
            img.alt = '';
            img.loading = 'lazy';
            link.appendChild(img);
            card.appendChild(link);

            const meta = document.createElement('div');
            meta.style.padding = '8px 0 0';

            const titleLink = createWatchLink(videoId, title);
            const titleEl = document.createElement('h2');
            titleEl.className = 'video-title';
            titleEl.textContent = title;
            titleLink.appendChild(titleEl);

            const badge = document.createElement('span');
            badge.className = `activity-badge ${type}`;
            badge.textContent = labelMap[type] || type;

            const dateEl = document.createElement('div');
            dateEl.className = 'channel-line';
            dateEl.textContent = formatDate(item.publishedAt || '');

            meta.append(titleLink, badge, dateEl);
            card.appendChild(meta);
            fragment.appendChild(card);
        });

        target.appendChild(fragment);
    }

    async function loadCategories(regionCode) {
        if (!els.categorySelect) return;
        try {
            const url = new URL(API_URL, window.location.href);
            url.searchParams.set('action', 'categories');
            url.searchParams.set('regionCode', regionCode);
            const data = await fetchJson(url);
            if (!data.ok || !Array.isArray(data.items)) return;

            const current = els.categorySelect.value;
            while (els.categorySelect.options.length > 1) els.categorySelect.remove(1);

            data.items.forEach(function (cat) {
                const option = document.createElement('option');
                option.value = cat.id;
                option.textContent = cat.title;
                els.categorySelect.appendChild(option);
            });

            els.categorySelect.value = current;
        } catch (e) { /* silent */ }
    }

    function sanitizePlaylistId(v) {
        const t = String(v || '').trim();
        return /^[A-Za-z0-9_-]{10,50}$/.test(t) ? t : '';
    }

    function sanitizeRegionCode(v) {
        const t = String(v || '').trim().toUpperCase();
        return /^[A-Z]{2}$/.test(t) ? t : 'VN';
    }

    function sanitizeCategoryId(v) {
        const t = String(v || '').trim();
        return /^\d{1,3}$/.test(t) ? t : '';
    }

    init();
}());
