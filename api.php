<?php
/**
 * YouTube Personal Viewer - JSON API
 *
 * Modules:
 * - Security helpers: validate and normalize all user input.
 * - Cache: file-based cache keyed by a SHA-256 hash of each API request.
 * - YouTube API handler: minimal fields + static thumbnail URL generation.
 * - Router: maps frontend actions to optimized YouTube API calls.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/maintenance.php';

session_start();

maintenance_api_block_if_enabled();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

try {
    $action = get_string('action', 'feed', 24);
    $pageToken = get_page_token();
    $maxResults = get_int('maxResults', DEFAULT_RESULTS_PER_PAGE, 1, MAX_RESULTS_PER_PAGE);

    switch ($action) {
        case 'search':
            $query = get_string('q', '', 120);
            if ($query === '') {
                send_json(['ok' => false, 'error' => 'SEARCH_QUERY_REQUIRED'], 400);
            }
            send_json(handle_search($query, $pageToken, $maxResults));
            break;

        case 'feed':
            send_json(handle_feed($pageToken, $maxResults));
            break;

        case 'shorts':
            send_json(handle_shorts($pageToken, $maxResults));
            break;

        case 'subscriptions':
            send_json(handle_subscriptions($pageToken, $maxResults));
            break;

        case 'personal':
            send_json(handle_personal_recommendations($pageToken, $maxResults));
            break;

        case 'liked':
            send_json(handle_liked_videos($pageToken, $maxResults));
            break;

        case 'subscribe':
            $channelId = get_request_channel_id();
            if ($channelId === '') {
                send_json(['ok' => false, 'error' => 'CHANNEL_ID_REQUIRED'], 400);
            }
            send_json(handle_subscribe($channelId));
            break;

        case 'channel':
            $channelId = get_channel_id();
            if ($channelId === '') {
                send_json(['ok' => false, 'error' => 'CHANNEL_ID_REQUIRED'], 400);
            }
            send_json(handle_channel($channelId, $pageToken, $maxResults));
            break;

        case 'watch':
            $videoId = get_video_id();
            if ($videoId === '') {
                send_json(['ok' => false, 'error' => 'VIDEO_ID_REQUIRED'], 400);
            }
            send_json(handle_watch($videoId, $pageToken, $maxResults));
            break;

        case 'comments':
            $videoId = get_video_id();
            if ($videoId === '') {
                send_json(['ok' => false, 'error' => 'VIDEO_ID_REQUIRED'], 400);
            }
            send_json(handle_comments($videoId, $pageToken, $maxResults));
            break;
        case 'post_comment':
            $videoId = get_video_id();
            if ($videoId === '') {
                send_json(['ok' => false, 'error' => 'VIDEO_ID_REQUIRED'], 400);
            }
            send_json(handle_post_comment($videoId));
            break;
        case 'post_reply':
            send_json(handle_post_reply());
            break;

        case 'history':
            send_json(handle_watch_history($pageToken, $maxResults));
            break;

        case 'playlist':
            $playlistId = get_playlist_id();
            if ($playlistId === '') {
                send_json(['ok' => false, 'error' => 'PLAYLIST_ID_REQUIRED'], 400);
            }
            send_json(handle_playlist($playlistId, $pageToken, $maxResults));
            break;

        case 'playlists':
            send_json(handle_my_playlists($pageToken, $maxResults));
            break;

        case 'myvideos':
            send_json(handle_my_videos($pageToken, $maxResults));
            break;

        case 'activities':
            send_json(handle_activities($pageToken, $maxResults));
            break;

        case 'trending':
            $regionCode = get_string('regionCode', 'VN', 2);
            $categoryId = get_string('videoCategoryId', '', 4);
            send_json(handle_trending($regionCode, $categoryId, $pageToken, $maxResults));
            break;

        case 'categories':
            $regionCode = get_string('regionCode', 'VN', 2);
            send_json(handle_video_categories($regionCode));
            break;

        default:
            send_json(['ok' => false, 'error' => 'UNKNOWN_ACTION'], 404);
    }
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'error' => 'SERVER_ERROR',
        'message' => 'Dá»‹ch vá»¥ táº¡m thá»i khÃ´ng kháº£ dá»¥ng.',
    ], 500);
}

/**
 * Router handlers
 */
function handle_search(string $query, string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet',
        'type' => 'video',
        'q' => $query,
        'maxResults' => $maxResults,
        'safeSearch' => 'moderate',
        'fields' => 'nextPageToken,items(id/videoId,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $payload = youtube_get('/search', $params);

    return normalize_search_response($payload, 'search');
}

function handle_channel(string $channelId, string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet',
        'type' => 'video',
        'channelId' => $channelId,
        'order' => 'date',
        'maxResults' => $maxResults,
        'fields' => 'nextPageToken,items(id/videoId,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $payload = youtube_get('/search', $params);

    $normalized = normalize_search_response($payload, 'channel');
    if (($normalized['ok'] ?? false) === true) {
        $channel = get_channel_info($channelId);
        $normalized['channel'] = ($channel['ok'] ?? false) === true ? $channel['channel'] : null;
    }

    return $normalized;
}

function handle_feed(string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet',
        'chart' => 'mostPopular',
        'regionCode' => 'US',
        'maxResults' => $maxResults,
        'fields' => 'nextPageToken,items(id,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $payload = youtube_get('/videos', $params);

    return normalize_videos_response($payload, 'feed');
}

function handle_shorts(string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet',
        'type' => 'video',
        'q' => '#shorts',
        'order' => 'date',
        'videoDuration' => 'short',
        'maxResults' => $maxResults,
        'safeSearch' => 'moderate',
        'fields' => 'nextPageToken,items(id/videoId,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    return normalize_search_response(youtube_get('/search', $params), 'shorts');
}

function handle_subscriptions(string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet',
        'mine' => 'true',
        'maxResults' => $maxResults,
        'order' => 'alphabetical',
        'fields' => 'nextPageToken,items(id,snippet/title,snippet/description,snippet/resourceId/channelId,snippet/thumbnails/default/url,snippet/thumbnails/medium/url,snippet/thumbnails/high/url)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $payload = youtube_oauth_get('/subscriptions', $params);
    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $items = [];
    foreach (($payload['items'] ?? []) as $item) {
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $resource = is_array($snippet['resourceId'] ?? null) ? $snippet['resourceId'] : [];
        $channelId = (string) ($resource['channelId'] ?? '');
        if (!is_valid_channel_id($channelId)) {
            continue;
        }

        $thumbs = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
        $avatarUrl = (string) ($thumbs['default']['url'] ?? $thumbs['medium']['url'] ?? $thumbs['high']['url'] ?? '');
        $items[] = [
            'channelId' => $channelId,
            'title' => sanitize_text((string) ($snippet['title'] ?? 'KÃªnh khÃ´ng xÃ¡c Ä‘á»‹nh')),
            'description' => sanitize_long_text((string) ($snippet['description'] ?? '')),
            'avatarUrl' => is_valid_image_url($avatarUrl) ? $avatarUrl : '',
            'subscribeUrl' => 'https://www.youtube.com/channel/' . $channelId . '?sub_confirmation=1',
        ];
    }

    return [
        'ok' => true,
        'source' => 'subscriptions',
        'nextPageToken' => safe_page_token((string) ($payload['nextPageToken'] ?? '')),
        'items' => $items,
        'cache' => ['hit' => false, 'ttl' => 0],
    ];
}

function handle_personal_recommendations(string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet',
        'mine' => 'true',
        'maxResults' => 6,
        'order' => 'relevance',
        'fields' => 'items(snippet/resourceId/channelId)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
        $params['fields'] = 'nextPageToken,items(snippet/resourceId/channelId)';
    } else {
        $params['fields'] = 'nextPageToken,items(snippet/resourceId/channelId)';
    }

    $subscriptions = youtube_oauth_get('/subscriptions', $params);

    if (($subscriptions['ok'] ?? false) !== true) {
        return $subscriptions;
    }

    $items = [];
    $seen = [];
    foreach (($subscriptions['items'] ?? []) as $subscription) {
        $channelId = (string) ($subscription['snippet']['resourceId']['channelId'] ?? '');
        if (!is_valid_channel_id($channelId)) {
            continue;
        }

        $payload = youtube_get('/search', [
            'part' => 'snippet',
            'type' => 'video',
            'channelId' => $channelId,
            'order' => 'date',
            'maxResults' => 3,
            'fields' => 'items(id/videoId,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt)',
        ]);
        $normalized = normalize_search_response($payload, 'personal');
        if (($normalized['ok'] ?? false) !== true) {
            continue;
        }

        foreach ($normalized['items'] as $item) {
            $videoId = (string) ($item['videoId'] ?? '');
            if ($videoId !== '' && !isset($seen[$videoId])) {
                $seen[$videoId] = true;
                $items[] = $item;
            }
        }
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($b['publishedAt'] ?? ''), (string) ($a['publishedAt'] ?? ''));
    });

    return [
        'ok' => true,
        'source' => 'personal',
        'nextPageToken' => safe_page_token((string) ($subscriptions['nextPageToken'] ?? '')),
        'items' => array_slice($items, 0, $maxResults),
        'cache' => ['hit' => false, 'ttl' => CACHE_TTL_SECONDS],
    ];
}

function handle_liked_videos(string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet,statistics',
        'myRating' => 'like',
        'maxResults' => $maxResults,
        'fields' => 'nextPageToken,items(id,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    return normalize_videos_response(youtube_oauth_get('/videos', $params), 'liked');
}

function handle_subscribe(string $channelId): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'YÃªu cáº§u POST Ä‘á»ƒ Ä‘Äƒng kÃ½ kÃªnh.'];
    }

    $payload = youtube_oauth_post('/subscriptions', ['part' => 'snippet'], [
        'snippet' => [
            'resourceId' => [
                'kind' => 'youtube#channel',
                'channelId' => $channelId,
            ],
        ],
    ]);

    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $_SESSION['oauth_cache_version'] = (int) ($_SESSION['oauth_cache_version'] ?? 0) + 1;

    return [
        'ok' => true,
        'source' => 'subscribe',
        'subscriptionId' => sanitize_text((string) ($payload['id'] ?? '')),
        'message' => 'ÄÃ£ gá»­i yÃªu cáº§u Ä‘Äƒng kÃ½ kÃªnh.',
    ];
}

function handle_watch(string $videoId, string $pageToken, int $maxResults): array
{
    $video = get_video_detail($videoId);
    if (($video['ok'] ?? false) !== true) {
        return $video;
    }

    $channel = ['ok' => false];
    if (($video['video']['channelId'] ?? '') !== '') {
        $channel = get_channel_info((string) $video['video']['channelId']);
    }

    $recommendations = get_recommendations(
        (string) ($video['video']['title'] ?? ''),
        $videoId,
        $pageToken,
        $maxResults
    );

    $comments = handle_comments($videoId, '', min(10, $maxResults));

    return [
        'ok' => true,
        'source' => 'watch',
        'video' => $video['video'],
        'channel' => ($channel['ok'] ?? false) === true ? $channel['channel'] : null,
        'recommendations' => ($recommendations['ok'] ?? false) === true ? $recommendations['items'] : [],
        'nextRecommendationToken' => ($recommendations['ok'] ?? false) === true ? $recommendations['nextPageToken'] : '',
        'comments' => ($comments['ok'] ?? false) === true ? $comments['items'] : [],
        'nextCommentToken' => ($comments['ok'] ?? false) === true ? $comments['nextPageToken'] : '',
        'cache' => $video['cache'] ?? ['hit' => false, 'ttl' => CACHE_TTL_SECONDS],
    ];
}

function get_video_detail(string $videoId): array
{
    $payload = youtube_get('/videos', [
        'part' => 'snippet,statistics',
        'id' => $videoId,
        'fields' => 'items(id,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt,snippet/description,statistics/viewCount,statistics/likeCount,statistics/commentCount)',
    ]);

    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $item = $payload['items'][0] ?? null;
    if (!is_array($item)) {
        return ['ok' => false, 'error' => 'VIDEO_NOT_FOUND', 'message' => 'KhÃ´ng tÃ¬m tháº¥y video.'];
    }

    $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
    $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
    $channelId = (string) ($snippet['channelId'] ?? '');
    $avatarMap = get_channel_avatar_map([['videoId' => $videoId, 'snippet' => $snippet]]);

    return [
        'ok' => true,
        'video' => [
            'videoId' => $videoId,
            'title' => sanitize_text((string) ($snippet['title'] ?? 'Untitled video')),
            'description' => sanitize_long_text((string) ($snippet['description'] ?? '')),
            'channelId' => is_valid_channel_id($channelId) ? $channelId : '',
            'channelTitle' => sanitize_text((string) ($snippet['channelTitle'] ?? 'Unknown channel')),
            'publishedAt' => sanitize_text((string) ($snippet['publishedAt'] ?? '')),
            'thumbnail' => 'https://img.youtube.com/vi/' . $videoId . '/mqdefault.jpg',
            'avatarUrl' => $avatarMap[$channelId] ?? '',
            'viewCount' => safe_count((string) ($statistics['viewCount'] ?? '')),
            'likeCount' => safe_count((string) ($statistics['likeCount'] ?? '')),
            'commentCount' => safe_count((string) ($statistics['commentCount'] ?? '')),
        ],
        'cache' => $payload['cache'] ?? ['hit' => false, 'ttl' => CACHE_TTL_SECONDS],
    ];
}

function get_channel_info(string $channelId): array
{
    $payload = youtube_get('/channels', [
        'part' => 'snippet,statistics,brandingSettings',
        'id' => $channelId,
        'fields' => 'items(id,snippet/title,snippet/description,snippet/customUrl,snippet/thumbnails/default/url,snippet/thumbnails/medium/url,snippet/thumbnails/high/url,statistics/subscriberCount,statistics/videoCount,statistics/viewCount,brandingSettings/image/bannerExternalUrl)',
    ]);

    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $item = $payload['items'][0] ?? null;
    if (!is_array($item)) {
        return ['ok' => false, 'error' => 'CHANNEL_NOT_FOUND', 'message' => 'KhÃ´ng tÃ¬m tháº¥y kÃªnh.'];
    }

    $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
    $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
    $branding = is_array($item['brandingSettings'] ?? null) ? $item['brandingSettings'] : [];
    $image = is_array($branding['image'] ?? null) ? $branding['image'] : [];
    $thumbs = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
    $avatarUrl = (string) ($thumbs['default']['url'] ?? $thumbs['medium']['url'] ?? $thumbs['high']['url'] ?? '');
    $bannerUrl = (string) ($image['bannerExternalUrl'] ?? '');

    return [
        'ok' => true,
        'channel' => [
            'channelId' => $channelId,
            'title' => sanitize_text((string) ($snippet['title'] ?? 'Unknown channel')),
            'description' => sanitize_long_text((string) ($snippet['description'] ?? '')),
            'customUrl' => sanitize_text((string) ($snippet['customUrl'] ?? '')),
            'avatarUrl' => is_valid_image_url($avatarUrl) ? $avatarUrl : '',
            'bannerUrl' => is_valid_image_url($bannerUrl) ? $bannerUrl : '',
            'subscriberCount' => safe_count((string) ($statistics['subscriberCount'] ?? '')),
            'videoCount' => safe_count((string) ($statistics['videoCount'] ?? '')),
            'viewCount' => safe_count((string) ($statistics['viewCount'] ?? '')),
            'url' => 'https://www.youtube.com/channel/' . $channelId,
            'subscribeUrl' => 'https://www.youtube.com/channel/' . $channelId . '?sub_confirmation=1',
        ],
        'cache' => $payload['cache'] ?? ['hit' => false, 'ttl' => CACHE_TTL_SECONDS],
    ];
}

function get_recommendations(string $title, string $currentVideoId, string $pageToken, int $maxResults): array
{
    $query = limit_text($title, 90);
    if ($query === '') {
        return ['ok' => true, 'nextPageToken' => '', 'items' => []];
    }

    $params = [
        'part' => 'snippet',
        'type' => 'video',
        'q' => $query,
        'maxResults' => $maxResults,
        'safeSearch' => 'moderate',
        'fields' => 'nextPageToken,items(id/videoId,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $normalized = normalize_search_response(youtube_get('/search', $params), 'recommendations');
    if (($normalized['ok'] ?? false) !== true) {
        return $normalized;
    }

    $items = [];
    foreach ($normalized['items'] as $item) {
        if (($item['videoId'] ?? '') !== $currentVideoId) {
            $items[] = $item;
        }
    }

    $normalized['items'] = $items;
    return $normalized;
}

function handle_comments(string $videoId, string $pageToken, int $maxResults): array
{
    // LÆ°u Ã½: order=relevance KHÃ”NG tÆ°Æ¡ng thÃ­ch vá»›i pageToken (theo docs YouTube API)
    // DÃ¹ng order=time Ä‘á»ƒ há»— trá»£ phÃ¢n trang Ä‘Ãºng cÃ¡ch
    $params = [
        'part' => 'snippet',
        'videoId' => $videoId,
        'order' => 'time',
        'textFormat' => 'plainText',
        'maxResults' => min(20, $maxResults),
        'fields' => 'nextPageToken,items(snippet/topLevelComment/id,snippet/topLevelComment/snippet/authorDisplayName,snippet/topLevelComment/snippet/authorChannelId/value,snippet/topLevelComment/snippet/authorProfileImageUrl,snippet/topLevelComment/snippet/textDisplay,snippet/topLevelComment/snippet/publishedAt,snippet/topLevelComment/snippet/likeCount)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $payload = youtube_get('/commentThreads', $params);
    if (($payload['ok'] ?? false) !== true) {
        $error = (string) ($payload['error'] ?? '');
        // Má»™t sá»‘ video táº¯t bÃ¬nh luáº­n hoáº·c API tráº£ lá»—i khÃ´ng quan trá»ng
        // â†’ tráº£ vá» máº£ng rá»—ng thay vÃ¬ bÃ¡o lá»—i toÃ n trang
        // Má»i lá»—i tá»« commentThreads Ä‘á»u khÃ´ng nghiÃªm trá»ng â†’ áº©n Ä‘i, khÃ´ng bÃ¡o lá»—i toÃ n trang
        $silentErrors = ['commentsDisabled', 'forbidden', 'processingFailure', 'invalidValue', 'YOUTUBE_API_ERROR', 'ALL_KEYS_FAILED'];
        $msg = strtolower((string) ($payload['message'] ?? ''));
        if (in_array($error, $silentErrors, true) || str_contains($msg, 'comment') || str_contains($msg, 'process')) {
            return [
                'ok'            => true,
                'source'        => 'comments',
                'nextPageToken' => '',
                'items'         => [],
                'commentsDisabled' => true,
                'cache'         => ['hit' => false, 'ttl' => 0],
            ];
        }
        return $payload;
    }

    $items = [];
    foreach (($payload['items'] ?? []) as $item) {
        $snippet = $item['snippet']['topLevelComment']['snippet'] ?? [];
        if (!is_array($snippet)) {
            continue;
        }

        $avatarUrl = (string) ($snippet['authorProfileImageUrl'] ?? '');
        $items[] = [
            'author' => sanitize_text((string) ($snippet['authorDisplayName'] ?? 'YouTube user')),
            'avatarUrl' => is_valid_image_url($avatarUrl) ? $avatarUrl : '',
            'text' => sanitize_long_text((string) ($snippet['textDisplay'] ?? '')),
            'publishedAt' => sanitize_text((string) ($snippet['publishedAt'] ?? '')),
            'likeCount' => safe_count((string) ($snippet['likeCount'] ?? '')),
        ];
    }

    return [
        'ok' => true,
        'source' => 'comments',
        'nextPageToken' => safe_page_token((string) ($payload['nextPageToken'] ?? '')),
        'items' => $items,
        'cache' => $payload['cache'] ?? ['hit' => false, 'ttl' => CACHE_TTL_SECONDS],
    ];
}

function handle_post_comment(string $videoId): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Yêu cầu POST.'];
    }
    $raw = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    $text = trim((string) ($body['text'] ?? ''));
    if ($text === '' || mb_strlen($text, 'UTF-8') > 10000) {
        return ['ok' => false, 'error' => 'INVALID_COMMENT', 'message' => 'Nội dung bình luận không hợp lệ.'];
    }
    $payload = youtube_oauth_post('/commentThreads', ['part' => 'snippet'], [
        'snippet' => [
            'videoId'          => $videoId,
            'topLevelComment'  => [
                'snippet' => ['textOriginal' => $text],
            ],
        ],
    ]);
    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }
    $snippet = $payload['snippet']['topLevelComment']['snippet'] ?? [];
    $avatarUrl = (string) ($snippet['authorProfileImageUrl'] ?? '');
    $authorChannelId = (string) ($snippet['authorChannelId']['value'] ?? '');
    return [
        'ok'     => true,
        'source' => 'post_comment',
        'comment' => [
            'commentId'       => sanitize_text((string) ($payload['snippet']['topLevelComment']['id'] ?? $payload['id'] ?? '')),
            'author'          => sanitize_text((string) ($snippet['authorDisplayName'] ?? 'Bạn')),
            'authorChannelId' => is_valid_channel_id($authorChannelId) ? $authorChannelId : '',
            'avatarUrl'       => is_valid_image_url($avatarUrl) ? $avatarUrl : '',
            'text'            => sanitize_long_text($text),
            'publishedAt'     => sanitize_text((string) ($snippet['publishedAt'] ?? date('c'))),
            'likeCount'       => '0',
        ],
    ];
}

function handle_post_reply(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Yêu cầu POST.'];
    }
    $raw = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    $text = trim((string) ($body['text'] ?? ''));
    $parentId = trim((string) ($body['parentId'] ?? ''));
    if ($text === '' || mb_strlen($text, 'UTF-8') > 10000) {
        return ['ok' => false, 'error' => 'INVALID_COMMENT', 'message' => 'Nội dung trả lời không hợp lệ.'];
    }
    if ($parentId === '') {
        return ['ok' => false, 'error' => 'PARENT_ID_REQUIRED', 'message' => 'Thiếu parentId.'];
    }
    $payload = youtube_oauth_post('/comments', ['part' => 'snippet'], [
        'snippet' => [
            'parentId'     => $parentId,
            'textOriginal' => $text,
        ],
    ]);
    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }
    $snippet = $payload['snippet'] ?? [];
    $avatarUrl = (string) ($snippet['authorProfileImageUrl'] ?? '');
    $authorChannelId = (string) ($snippet['authorChannelId']['value'] ?? '');
    return [
        'ok'     => true,
        'source' => 'post_reply',
        'comment' => [
            'commentId'       => sanitize_text((string) ($payload['id'] ?? '')),
            'author'          => sanitize_text((string) ($snippet['authorDisplayName'] ?? 'Bạn')),
            'authorChannelId' => is_valid_channel_id($authorChannelId) ? $authorChannelId : '',
            'avatarUrl'       => is_valid_image_url($avatarUrl) ? $avatarUrl : '',
            'text'            => sanitize_long_text($text),
            'publishedAt'     => sanitize_text((string) ($snippet['publishedAt'] ?? date('c'))),
            'likeCount'       => '0',
        ],
    ];
}

function handle_watch_history(string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet,contentDetails',
        'mine' => 'true',
        'maxResults' => $maxResults,
        'fields' => 'nextPageToken,items(contentDetails/relatedPlaylists/watchHistory)',
    ];

    // YouTube no longer exposes watch history via the API (deprecated 2016).
    // We return a clear notice so the frontend can explain this.
    return [
        'ok' => false,
        'error' => 'HISTORY_API_UNAVAILABLE',
        'message' => 'YouTube Ä‘Ã£ ngá»«ng cáº¥p quyá»n truy cáº­p lá»‹ch sá»­ xem qua API. Lá»‹ch sá»­ xem cá»¥c bá»™ Ä‘ang Ä‘Æ°á»£c hiá»ƒn thá»‹ tá»« trÃ¬nh duyá»‡t.',
    ];
}

function handle_playlist(string $playlistId, string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet',
        'playlistId' => $playlistId,
        'maxResults' => $maxResults,
        'fields' => 'nextPageToken,items(snippet/resourceId/videoId,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt,snippet/videoOwnerChannelId,snippet/videoOwnerChannelTitle)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $payload = youtube_oauth_get('/playlistItems', $params);
    if (($payload['ok'] ?? false) !== true) {
        // Try public access if OAuth fails
        $payload = youtube_get('/playlistItems', $params);
    }

    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $rows = [];
    foreach (($payload['items'] ?? []) as $item) {
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $videoId = (string) ($snippet['resourceId']['videoId'] ?? '');
        if (!is_valid_video_id($videoId)) {
            continue;
        }
        $channelId = (string) ($snippet['videoOwnerChannelId'] ?? $snippet['channelId'] ?? '');
        $channelTitle = (string) ($snippet['videoOwnerChannelTitle'] ?? $snippet['channelTitle'] ?? '');
        $rows[] = ['videoId' => $videoId, 'snippet' => array_merge($snippet, [
            'channelId' => $channelId,
            'channelTitle' => $channelTitle,
        ])];
    }

    $avatarMap = get_channel_avatar_map($rows);
    $items = [];
    foreach ($rows as $row) {
        $channelId = (string) ($row['snippet']['channelId'] ?? '');
        $items[] = make_video_item($row['videoId'], $row['snippet'], $avatarMap[$channelId] ?? '');
    }

    // Also fetch playlist metadata
    $meta = null;
    $metaPayload = youtube_get('/playlists', [
        'part' => 'snippet',
        'id' => $playlistId,
        'fields' => 'items(id,snippet/title,snippet/description,snippet/channelTitle)',
    ]);
    if (($metaPayload['ok'] ?? false) === true && isset($metaPayload['items'][0])) {
        $ms = $metaPayload['items'][0]['snippet'] ?? [];
        $meta = [
            'playlistId' => $playlistId,
            'title' => sanitize_text((string) ($ms['title'] ?? '')),
            'description' => sanitize_long_text((string) ($ms['description'] ?? '')),
            'channelTitle' => sanitize_text((string) ($ms['channelTitle'] ?? '')),
        ];
    }

    return [
        'ok' => true,
        'source' => 'playlist',
        'playlist' => $meta,
        'nextPageToken' => safe_page_token((string) ($payload['nextPageToken'] ?? '')),
        'items' => $items,
        'cache' => $payload['cache'] ?? ['hit' => false, 'ttl' => CACHE_TTL_SECONDS],
    ];
}

function handle_my_playlists(string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet,contentDetails',
        'mine' => 'true',
        'maxResults' => $maxResults,
        'fields' => 'nextPageToken,items(id,snippet/title,snippet/description,snippet/thumbnails/medium/url,contentDetails/itemCount)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $payload = youtube_oauth_get('/playlists', $params);
    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $items = [];
    foreach (($payload['items'] ?? []) as $item) {
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $details = is_array($item['contentDetails'] ?? null) ? $item['contentDetails'] : [];
        $thumbs = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
        $thumbUrl = (string) ($thumbs['medium']['url'] ?? '');

        $items[] = [
            'playlistId' => sanitize_text((string) ($item['id'] ?? '')),
            'title' => sanitize_text((string) ($snippet['title'] ?? 'Playlist khÃ´ng tÃªn')),
            'description' => sanitize_long_text((string) ($snippet['description'] ?? '')),
            'thumbnailUrl' => is_valid_image_url($thumbUrl) ? $thumbUrl : '',
            'itemCount' => (int) ($details['itemCount'] ?? 0),
        ];
    }

    return [
        'ok' => true,
        'source' => 'playlists',
        'nextPageToken' => safe_page_token((string) ($payload['nextPageToken'] ?? '')),
        'items' => $items,
        'cache' => $payload['cache'] ?? ['hit' => false, 'ttl' => OAUTH_CACHE_TTL_SECONDS],
    ];
}

function handle_my_videos(string $pageToken, int $maxResults): array
{
    // Step 1: Get "uploads" playlist ID from the authenticated user's channel
    $channelsPayload = youtube_oauth_get('/channels', [
        'part' => 'contentDetails',
        'mine' => 'true',
        'fields' => 'items(contentDetails/relatedPlaylists/uploads)',
    ]);

    if (($channelsPayload['ok'] ?? false) !== true) {
        return $channelsPayload;
    }

    $uploadsPlaylistId = (string) ($channelsPayload['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '');
    if ($uploadsPlaylistId === '') {
        return ['ok' => false, 'error' => 'NO_UPLOADS_PLAYLIST', 'message' => 'KhÃ´ng tÃ¬m tháº¥y danh sÃ¡ch video Ä‘Ã£ táº£i lÃªn.'];
    }

    return handle_playlist($uploadsPlaylistId, $pageToken, $maxResults);
}

function handle_activities(string $pageToken, int $maxResults): array
{
    $params = [
        'part' => 'snippet,contentDetails',
        'mine' => 'true',
        'maxResults' => $maxResults,
        'fields' => 'nextPageToken,items(snippet/type,snippet/title,snippet/publishedAt,snippet/thumbnails/medium/url,contentDetails/upload/videoId,contentDetails/like/resourceId/videoId,contentDetails/playlistItem/resourceId/videoId)',
    ];

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $payload = youtube_oauth_get('/activities', $params);
    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $items = [];
    foreach (($payload['items'] ?? []) as $item) {
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $details = is_array($item['contentDetails'] ?? null) ? $item['contentDetails'] : [];
        $type = (string) ($snippet['type'] ?? '');

        $videoId = '';
        if ($type === 'upload') {
            $videoId = (string) ($details['upload']['videoId'] ?? '');
        } elseif ($type === 'like') {
            $videoId = (string) ($details['like']['resourceId']['videoId'] ?? '');
        } elseif ($type === 'playlistItem') {
            $videoId = (string) ($details['playlistItem']['resourceId']['videoId'] ?? '');
        }

        if (!is_valid_video_id($videoId)) {
            continue;
        }

        $thumbs = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
        $thumbUrl = (string) ($thumbs['medium']['url'] ?? '');

        $items[] = [
            'videoId' => $videoId,
            'title' => sanitize_text((string) ($snippet['title'] ?? 'KhÃ´ng cÃ³ tiÃªu Ä‘á»')),
            'activityType' => sanitize_text($type),
            'publishedAt' => sanitize_text((string) ($snippet['publishedAt'] ?? '')),
            'thumbnail' => 'https://img.youtube.com/vi/' . $videoId . '/mqdefault.jpg',
            'avatarUrl' => '',
        ];
    }

    return [
        'ok' => true,
        'source' => 'activities',
        'nextPageToken' => safe_page_token((string) ($payload['nextPageToken'] ?? '')),
        'items' => $items,
        'cache' => $payload['cache'] ?? ['hit' => false, 'ttl' => OAUTH_CACHE_TTL_SECONDS],
    ];
}

function handle_trending(string $regionCode, string $categoryId, string $pageToken, int $maxResults): array
{
    $regionCode = preg_match('/^[A-Z]{2}$/', strtoupper($regionCode)) ? strtoupper($regionCode) : 'VN';

    $params = [
        'part' => 'snippet,statistics',
        'chart' => 'mostPopular',
        'regionCode' => $regionCode,
        'maxResults' => $maxResults,
        'fields' => 'nextPageToken,items(id,snippet/title,snippet/channelId,snippet/channelTitle,snippet/publishedAt,statistics/viewCount)',
    ];

    if ($categoryId !== '' && preg_match('/^\d{1,3}$/', $categoryId)) {
        $params['videoCategoryId'] = $categoryId;
    }

    if ($pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }

    $payload = youtube_get('/videos', $params);
    $result = normalize_videos_response($payload, 'trending');

    if (($result['ok'] ?? false) === true) {
        $result['regionCode'] = $regionCode;
    }

    return $result;
}

function handle_video_categories(string $regionCode): array
{
    $regionCode = preg_match('/^[A-Z]{2}$/', strtoupper($regionCode)) ? strtoupper($regionCode) : 'VN';

    $payload = youtube_get('/videoCategories', [
        'part' => 'snippet',
        'regionCode' => $regionCode,
        'hl' => 'vi',
        'fields' => 'items(id,snippet/title,snippet/assignable)',
    ]);

    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $items = [];
    foreach (($payload['items'] ?? []) as $item) {
        if (!($item['snippet']['assignable'] ?? false)) {
            continue;
        }
        $items[] = [
            'id' => sanitize_text((string) ($item['id'] ?? '')),
            'title' => sanitize_text((string) ($item['snippet']['title'] ?? '')),
        ];
    }

    return [
        'ok' => true,
        'source' => 'categories',
        'items' => $items,
        'cache' => $payload['cache'] ?? ['hit' => false, 'ttl' => CACHE_TTL_SECONDS],
    ];
}

/**
 * YouTube API handler
 */
/**
 * Äáº£m báº£o thÆ° má»¥c cache tá»“n táº¡i
 */
function ensure_cache_dir(): void {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
}

/**
 * Kiá»ƒm tra vÃ  reset file quota náº¿u Ä‘Ã£ bÆ°á»›c sang ngÃ y má»›i theo chuáº©n UTC
 */
function check_utc_reset(): void {
    if (is_file(QUOTA_EXHAUSTED_FILE)) {
        $todayUTC = gmdate('Y-m-d');
        $fileLastUpdateUTC = gmdate('Y-m-d', filemtime(QUOTA_EXHAUSTED_FILE));

        // Náº¿u ngÃ y hiá»‡n táº¡i (UTC) khÃ¡c ngÃ y lÆ°u file -> XÃ³a file Ä‘á»ƒ reset
        if ($todayUTC !== $fileLastUpdateUTC) {
            @unlink(QUOTA_EXHAUSTED_FILE);
        }
    }
}

/**
 * Láº¥y danh sÃ¡ch cÃ¡c key Ä‘Ã£ háº¿t quota trong ngÃ y hÃ´m nay
 */
function get_exhausted_keys(): array {
    check_utc_reset();
    if (!is_file(QUOTA_EXHAUSTED_FILE)) return [];
    return json_decode(file_get_contents(QUOTA_EXHAUSTED_FILE), true) ?: [];
}

/**
 * TÃ­nh toÃ¡n sá»‘ liá»‡u Quota cho thanh Progress Bar
 */


/**
 * ÄÃ¡nh dáº¥u key Ä‘Ã£ háº¿t quota
 */
function mark_key_as_exhausted(string $key): void {
    ensure_cache_dir();
    $exhausted = get_exhausted_keys();
    if (!in_array($key, $exhausted)) {
        $exhausted[] = $key;
        file_put_contents(QUOTA_EXHAUSTED_FILE, json_encode($exhausted), LOCK_EX);
    }
}

/**
 * HÃ m gá»i API chÃ­nh - Tá»± Ä‘á»™ng xoay vÃ²ng key khi lá»—i Quota.
 * LÆ°u index key Ä‘ang dÃ¹ng vÃ o session; náº¿u index thay Ä‘á»•i sang/tá»« personal key
 * thÃ¬ Ä‘Äƒng xuáº¥t Ä‘á»ƒ trÃ¡nh dÃ¹ng sai OAuth.
 */
function youtube_get(string $endpoint, array $params): array {
    $exhaustedKeys = get_exhausted_keys();
    $allKeys       = YOUTUBE_API_KEYS;

    // Lá»c ra danh sÃ¡ch (index => key) chÆ°a exhausted, giá»¯ nguyÃªn index gá»‘c
    $availableMap = array_filter($allKeys, fn($k) => !in_array($k, $exhaustedKeys));

    if (empty($availableMap)) {
        return [
            'ok'      => false,
            'error'   => 'QUOTA_LIMIT',
            'message' => 'Táº¥t cáº£ API Keys Ä‘Ã£ háº¿t háº¡n má»©c hÃ´m nay (Reset lÃºc 0h UTC).',
        ];
    }

    $previousIndex = isset($_SESSION[SESSION_ACTIVE_KEY_INDEX])
        ? (int) $_SESSION[SESSION_ACTIVE_KEY_INDEX]
        : -1;

    foreach ($availableMap as $idx => $apiKey) {
        // Kiá»ƒm tra xem index cÃ³ thay Ä‘á»•i khÃ´ng
        if ($previousIndex !== $idx) {
            $prevIsPersonal = $previousIndex >= 0 && is_personal_key_index($previousIndex);
            $newIsPersonal  = is_personal_key_index($idx);

            // Náº¿u chuyá»ƒn sang key khÃ¡c mÃ  key cÅ© HOáº¶C key má»›i lÃ  personal â†’ Ä‘Äƒng xuáº¥t
            if ($prevIsPersonal || $newIsPersonal) {
                unset($_SESSION['google_oauth'], $_SESSION['oauth_state']);
            }

            $_SESSION[SESSION_ACTIVE_KEY_INDEX] = $idx;
            $previousIndex = $idx;
        }

        $params['key'] = $apiKey;
        $url = YOUTUBE_API_BASE . $endpoint . '?' . http_build_query($params);

        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => API_TIMEOUT_SECONDS],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            continue;
        }

        $decoded = json_decode($raw, true);

        if (isset($decoded['error'])) {
            $reason = $decoded['error']['errors'][0]['reason'] ?? '';
            if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
                mark_key_as_exhausted($apiKey);
                continue;
            }
            return ['ok' => false, 'error' => $reason, 'message' => $decoded['error']['message']];
        }

        $decoded['ok'] = true;
        return $decoded;
    }

    return ['ok' => false, 'error' => 'ALL_KEYS_FAILED'];
}

function youtube_oauth_get(string $endpoint, array $params): array
{
    $token = oauth_access_token();
    if ($token === '') {
        return [
            'ok' => false,
            'error' => 'AUTH_REQUIRED',
            'message' => 'HÃ£y Ä‘Äƒng nháº­p Google Ä‘á»ƒ dÃ¹ng tÃ­nh nÄƒng nÃ y.',
        ];
    }

    $cacheKey = oauth_cache_key($endpoint, $params);
    $cached = cache_get($cacheKey, OAUTH_CACHE_TTL_SECONDS);
    if ($cached !== null) {
        $cached['cache'] = ['hit' => true, 'ttl' => OAUTH_CACHE_TTL_SECONDS, 'private' => true];
        return $cached;
    }

    $url = YOUTUBE_API_BASE . $endpoint . '?' . http_build_query($params);
    $payload = youtube_oauth_request('GET', $url, $token, null);
    if (($payload['ok'] ?? false) === true) {
        $payload['cache'] = ['hit' => false, 'ttl' => OAUTH_CACHE_TTL_SECONDS, 'private' => true];
        cache_set($cacheKey, $payload);
    }

    return $payload;
}

function youtube_oauth_post(string $endpoint, array $params, array $body): array
{
    $token = oauth_access_token();
    if ($token === '') {
        return [
            'ok' => false,
            'error' => 'AUTH_REQUIRED',
            'message' => 'HÃ£y Ä‘Äƒng nháº­p Google Ä‘á»ƒ dÃ¹ng tÃ­nh nÄƒng nÃ y.',
        ];
    }

    $url = YOUTUBE_API_BASE . $endpoint . '?' . http_build_query($params);
    return youtube_oauth_request('POST', $url, $token, $body);
}

function youtube_oauth_request(string $method, string $url, string $token, ?array $body): array
{
    $headers = "Accept: application/json\r\nAuthorization: Bearer " . $token . "\r\n";
    $options = [
        'method' => $method,
        'timeout' => API_TIMEOUT_SECONDS,
        'ignore_errors' => true,
        'header' => $headers,
    ];

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $options['header'] .= "Content-Type: application/json\r\n";
        $options['content'] = $json === false ? '{}' : $json;
    }

    try {
        $raw = file_get_contents($url, false, stream_context_create(['http' => $options]));
        if ($raw === false) {
            throw new RuntimeException('OAuth request failed.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OAuth response was invalid JSON.');
        }

        if (isset($decoded['error'])) {
            return handle_youtube_error($decoded);
        }

        $decoded['ok'] = true;
        return $decoded;
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'UPSTREAM_UNAVAILABLE',
            'message' => 'YouTube API táº¡m thá»i khÃ´ng pháº£n há»“i. HÃ£y thá»­ láº¡i sau.',
        ];
    }
}

function oauth_access_token(): string
{
    $auth = $_SESSION['google_oauth'] ?? null;
    if (!is_array($auth)) {
        return '';
    }

    if ((int) ($auth['expires_at'] ?? 0) <= time()) {
        unset($_SESSION['google_oauth']);
        return '';
    }

    $token = (string) ($auth['access_token'] ?? '');
    return strlen($token) > 20 ? $token : '';
}

function handle_youtube_error(array $decoded): array
{
    $reason = '';
    if (isset($decoded['error']['errors'][0]['reason'])) {
        $reason = (string) $decoded['error']['errors'][0]['reason'];
    }

    $quotaReasons = ['quotaExceeded', 'dailyLimitExceeded', 'userRateLimitExceeded'];
    if (in_array($reason, $quotaReasons, true)) {
        return [
            'ok' => false,
            'error' => 'QUOTA_EXCEEDED',
            'message' => 'ÄÃ£ háº¿t quota YouTube API hÃ´m nay. CÃ¡c trang Ä‘Ã£ cache váº«n cÃ³ thá»ƒ xem láº¡i.',
        ];
    }

    return [
        'ok' => false,
        'error' => 'YOUTUBE_API_ERROR',
        'message' => 'YouTube tá»« chá»‘i yÃªu cáº§u.',
    ];
}

/**
 * Cache module
 */
function cache_key(string $endpoint, array $params): string
{
    ksort($params);
    return hash('sha256', $endpoint . ':' . json_encode($params, JSON_UNESCAPED_SLASHES));
}

function oauth_cache_key(string $endpoint, array $params): string
{
    ksort($params);
    $user = oauth_cache_user_id();
    $version = (string) ((int) ($_SESSION['oauth_cache_version'] ?? 0));
    return 'oauth_' . hash('sha256', $user . ':' . $version . ':' . $endpoint . ':' . json_encode($params, JSON_UNESCAPED_SLASHES));
}

function oauth_cache_user_id(): string
{
    $auth = $_SESSION['google_oauth'] ?? null;
    $profile = is_array($auth) && is_array($auth['profile'] ?? null) ? $auth['profile'] : [];
    $email = (string) ($profile['email'] ?? '');
    if ($email !== '') {
        return hash('sha256', strtolower($email));
    }

    return hash('sha256', session_id());
}

function cache_path(string $key): string
{
    return rtrim(CACHE_DIR, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
}

function cache_get(string $key, ?int $ttl = null): ?array
{
    ensure_cache_dir();
    $path = cache_path($key);

    if (!is_file($path)) {
        return null;
    }

    $age = time() - (int) filemtime($path);
    $maxAge = $ttl ?? CACHE_TTL_SECONDS;
    if ($age < 0 || $age > $maxAge) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function cache_set(string $key, array $data): void
{
    ensure_cache_dir();
    $path = cache_path($key);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        file_put_contents($path, $json, LOCK_EX);
    }
}


/**
 * Response normalization
 */
function normalize_search_response(array $payload, string $source): array
{
    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $items = [];
    $rows = [];
    foreach (($payload['items'] ?? []) as $item) {
        $videoId = (string) ($item['id']['videoId'] ?? '');
        if ($videoId === '' || !is_valid_video_id($videoId)) {
            continue;
        }
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $rows[] = ['videoId' => $videoId, 'snippet' => $snippet];
    }

    $avatarMap = get_channel_avatar_map($rows);
    foreach ($rows as $row) {
        $snippet = $row['snippet'];
        $channelId = (string) ($snippet['channelId'] ?? '');
        $items[] = make_video_item($row['videoId'], $snippet, $avatarMap[$channelId] ?? '');
    }

    return [
        'ok' => true,
        'source' => $source,
        'nextPageToken' => safe_page_token((string) ($payload['nextPageToken'] ?? '')),
        'items' => $items,
        'cache' => $payload['cache'] ?? ['hit' => false, 'ttl' => CACHE_TTL_SECONDS],
    ];
}

function normalize_videos_response(array $payload, string $source): array
{
    if (($payload['ok'] ?? false) !== true) {
        return $payload;
    }

    $items = [];
    $rows = [];
    foreach (($payload['items'] ?? []) as $item) {
        $videoId = (string) ($item['id'] ?? '');
        if ($videoId === '' || !is_valid_video_id($videoId)) {
            continue;
        }
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $rows[] = ['videoId' => $videoId, 'snippet' => $snippet];
    }

    $avatarMap = get_channel_avatar_map($rows);
    foreach ($rows as $row) {
        $snippet = $row['snippet'];
        $channelId = (string) ($snippet['channelId'] ?? '');
        $items[] = make_video_item($row['videoId'], $snippet, $avatarMap[$channelId] ?? '');
    }

    return [
        'ok' => true,
        'source' => $source,
        'nextPageToken' => safe_page_token((string) ($payload['nextPageToken'] ?? '')),
        'items' => $items,
        'cache' => $payload['cache'] ?? ['hit' => false, 'ttl' => CACHE_TTL_SECONDS],
    ];
}

function get_channel_avatar_map(array $rows): array
{
    $channelIds = [];
    foreach ($rows as $row) {
        $snippet = is_array($row['snippet'] ?? null) ? $row['snippet'] : [];
        $channelId = (string) ($snippet['channelId'] ?? '');
        if (is_valid_channel_id($channelId)) {
            $channelIds[$channelId] = true;
        }
    }

    $ids = array_keys($channelIds);
    if (!$ids) {
        return [];
    }

    $payload = youtube_get('/channels', [
        'part' => 'snippet',
        'id' => implode(',', $ids),
        'maxResults' => min(50, count($ids)),
        'fields' => 'items(id,snippet/thumbnails/default/url,snippet/thumbnails/medium/url,snippet/thumbnails/high/url)',
    ]);

    if (($payload['ok'] ?? false) !== true) {
        return [];
    }

    $avatarMap = [];
    foreach (($payload['items'] ?? []) as $item) {
        $channelId = (string) ($item['id'] ?? '');
        if (!is_valid_channel_id($channelId)) {
            continue;
        }

        $thumbs = is_array($item['snippet']['thumbnails'] ?? null) ? $item['snippet']['thumbnails'] : [];
        $url = (string) ($thumbs['default']['url'] ?? $thumbs['medium']['url'] ?? $thumbs['high']['url'] ?? '');
        if (is_valid_image_url($url)) {
            $avatarMap[$channelId] = $url;
        }
    }

    return $avatarMap;
}

function make_video_item(string $videoId, array $snippet, string $avatarUrl): array
{
    $channelId = (string) ($snippet['channelId'] ?? '');

    return [
        'videoId' => $videoId,
        'title' => sanitize_text((string) ($snippet['title'] ?? 'Untitled video')),
        'channelId' => is_valid_channel_id($channelId) ? $channelId : '',
        'channelTitle' => sanitize_text((string) ($snippet['channelTitle'] ?? 'Unknown channel')),
        'publishedAt' => sanitize_text((string) ($snippet['publishedAt'] ?? '')),
        'thumbnail' => 'https://img.youtube.com/vi/' . $videoId . '/mqdefault.jpg',
        'avatarUrl' => is_valid_image_url($avatarUrl) ? $avatarUrl : '',
        'avatarMode' => CHANNEL_AVATAR_FALLBACK,
    ];
}

/**
 * Input and output helpers
 */
function get_string(string $key, string $default, int $maxLength): string
{
    $value = isset($_GET[$key]) ? (string) $_GET[$key] : $default;
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return limit_text($value, $maxLength);
}

function get_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }
    return max($min, min($max, (int) $value));
}

function get_page_token(): string
{
    return safe_page_token(get_string('pageToken', '', 120));
}

function get_channel_id(): string
{
    $channelId = get_string('channelId', '', 80);
    return is_valid_channel_id($channelId) ? $channelId : '';
}

function get_request_channel_id(): string
{
    $channelId = get_channel_id();
    if ($channelId !== '') {
        return $channelId;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return '';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return '';
    }

    $candidate = (string) ($decoded['channelId'] ?? '');
    return is_valid_channel_id($candidate) ? $candidate : '';
}

function get_video_id(): string
{
    $videoId = get_string('videoId', '', 32);
    return is_valid_video_id($videoId) ? $videoId : '';
}

function get_playlist_id(): string
{
    $id = get_string('playlistId', '', 50);
    return preg_match('/^[A-Za-z0-9_-]{10,50}$/', $id) === 1 ? $id : '';
}

function safe_page_token(string $token): string
{
    return preg_match('/^[A-Za-z0-9_\-\.]*$/', $token) === 1 ? $token : '';
}

function sanitize_text(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    return limit_text($text, 300);
}

function sanitize_long_text(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
    $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? '';
    return limit_text(trim($text), 1800);
}

function safe_count(string $value): string
{
    return preg_match('/^\d+$/', $value) === 1 ? $value : '';
}

function limit_text(string $text, int $maxLength): string
{
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            return mb_substr($text, 0, $maxLength, 'UTF-8');
        }
        return $text;
    }

    return strlen($text) > $maxLength ? substr($text, 0, $maxLength) : $text;
}

function is_valid_video_id(string $videoId): bool
{
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1;
}

function is_valid_channel_id(string $channelId): bool
{
    return preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $channelId) === 1;
}

function is_valid_image_url(string $url): bool
{
    if ($url === '' || strlen($url) > 500) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));

    return $scheme === 'https' && (
        $host === 'yt3.ggpht.com'
        || $host === 'yt3.googleusercontent.com'
        || ends_with($host, '.googleusercontent.com')
        || ends_with($host, '.ggpht.com')
    );
}

function ends_with(string $text, string $suffix): bool
{
    $length = strlen($suffix);
    if ($length === 0) {
        return true;
    }

    return substr($text, -$length) === $suffix;
}

function send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}



