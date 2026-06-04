<?php
require_once __DIR__ . '/_auth0.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only GET is allowed.',
    ]);
}

ssaApiRequireAuth0Claims();

// ---------------------------------------------------------------------------
// Config — values come from DigitalOcean App Platform environment variables.
// Never log or return these values.
// ---------------------------------------------------------------------------

$apiKey     = getenv('YOUTUBE_API_KEY');
$playlistId = getenv('YOUTUBE_RECORDINGS_PLAYLIST_ID');

if (empty($apiKey) || empty($playlistId)) {
    error_log('SSA YouTube endpoint: YOUTUBE_API_KEY or YOUTUBE_RECORDINGS_PLAYLIST_ID not set.');
    ssaApiJsonResponse(503, [
        'error'   => 'youtube_config_missing',
        'message' => 'YouTube integration is not configured on this server.',
    ]);
}

// ---------------------------------------------------------------------------
// Cache — file-based, 10-minute TTL (matches JWKS cache pattern in _auth0.php)
// ---------------------------------------------------------------------------

define('YOUTUBE_CACHE_TTL', 600);
$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssa_youtube_recordings_v1.json';

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < YOUTUBE_CACHE_TTL) {
    $cached = file_get_contents($cacheFile);
    $decoded = json_decode($cached, true);
    if (is_array($decoded) && isset($decoded['videos'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        http_response_code(200);
        echo $cached;
        exit;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function ytHttpGet(string $url): ?array
{
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function ytBestThumbnail(array $thumbnails): string
{
    foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $size) {
        if (!empty($thumbnails[$size]['url'])) {
            return $thumbnails[$size]['url'];
        }
    }
    return '';
}

/**
 * Parse ISO 8601 duration (PT1H22M54S) → seconds.
 * Returns null if the string is missing or unparseable.
 */
function ytParseDurationSeconds(?string $iso): ?int
{
    if (empty($iso) || $iso === 'P0D') {
        return null;
    }
    if (!preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) {
        return null;
    }
    $h = isset($m[1]) && $m[1] !== '' ? (int)$m[1] : 0;
    $i = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
    $s = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
    $total = $h * 3600 + $i * 60 + $s;
    return $total > 0 ? $total : null;
}

/**
 * Format seconds → "22:54" (< 1 h) or "1:22:54" (≥ 1 h).
 */
function ytFormatDuration(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $i = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $i, $s);
    }
    return sprintf('%d:%02d', $i, $s);
}

/**
 * Clean recording title — mirrors Swift LiveYouTubeChannelService.cleanRecordingTitle().
 *
 * "2026-05-22 - Frame 2 - Michael M vs Wayne E"
 *   → "Frame 2 - Michael M vs Wayne E"
 *
 * "SSA | 2026-06-03 | Table 1 | Frame 1 | Test vs Player"
 *   → "Table 1 - Frame 1 - Test vs Player"
 */
function ytCleanTitle(string $raw): string
{
    $title = trim($raw);

    if (preg_match('/^SSA \| \d{4}-\d{2}-\d{2} \| /u', $title)) {
        // Strip "SSA | YYYY-MM-DD | "
        $title = preg_replace('/^SSA \| \d{4}-\d{2}-\d{2} \| /u', '', $title);
        $title = str_replace(' | ', ' - ', $title);
        $title = str_replace('|', ' - ', $title);
    } else {
        // Strip "YYYY-MM-DD" + following separator chars
        $title = preg_replace('/^\d{4}-\d{2}-\d{2}[\s\-|]+/u', '', $title);
    }

    // Normalise hyphen spacing
    $title = preg_replace('/\s*-\s*/u', ' - ', $title);
    return trim($title);
}

/**
 * Split cleaned title into (primary, secondary) lines.
 *
 * "Frame 2 - Michael M vs Wayne E"         → ["Frame 2", "Michael M vs Wayne E"]
 * "Table 1 - Frame 1 - Test vs Player"     → ["Table 1 - Frame 1", "Test vs Player"]
 * Anything else                             → [$title, null]
 */
function ytSplitTitle(string $title): array
{
    $patterns = [
        '/^(Table \w+ - Frame \w+) - (.+)$/u',
        '/^(Frame \w+) - (.+)$/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $title, $m)) {
            return [$m[1], $m[2]];
        }
    }
    return [$title, null];
}

/**
 * Return true for titles that look like live session recordings.
 * Mirrors Swift LiveYouTubeChannelService.isLiveRecording().
 */
function ytIsLiveRecording(string $raw): bool
{
    // Pattern B: "SSA | YYYY-MM-DD | ..."
    if (preg_match('/^SSA \| \d{4}-\d{2}-\d{2} \| /u', $raw)) {
        return true;
    }
    // Pattern A: starts with YYYY-MM-DD followed by separator
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}[\s\-|]/u', $raw);
}

// ---------------------------------------------------------------------------
// Step 1 — collect all video IDs from the playlist (paginate, max 200 items)
// ---------------------------------------------------------------------------

$videoIds   = [];
$pageToken  = null;
$pageLimit  = 4; // 4 × 50 = 200 items maximum

for ($page = 0; $page < $pageLimit; $page++) {
    $params = http_build_query(array_filter([
        'part'       => 'snippet',
        'playlistId' => $playlistId,
        'maxResults' => 50,
        'key'        => $apiKey,
        'pageToken'  => $pageToken,
    ]));

    $data = ytHttpGet('https://www.googleapis.com/youtube/v3/playlistItems?' . $params);

    if ($data === null || !isset($data['items'])) {
        error_log('SSA YouTube endpoint: playlistItems.list failed or returned no items on page ' . $page);
        break;
    }

    foreach ($data['items'] as $item) {
        $videoId = $item['snippet']['resourceId']['videoId'] ?? null;
        if ($videoId) {
            $videoIds[] = $videoId;
        }
    }

    $pageToken = $data['nextPageToken'] ?? null;
    if ($pageToken === null) {
        break;
    }
}

if (empty($videoIds)) {
    $result = ['videos' => [], 'cachedAt' => date('c'), 'source' => 'youtube-api'];
    file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    ssaApiJsonResponse(200, $result);
}

// ---------------------------------------------------------------------------
// Step 2 — fetch video details in batches of 50
// ---------------------------------------------------------------------------

$allVideos = [];

foreach (array_chunk($videoIds, 50) as $batch) {
    $params = http_build_query([
        'part' => 'snippet,contentDetails,liveStreamingDetails,status',
        'id'   => implode(',', $batch),
        'key'  => $apiKey,
    ]);

    $data = ytHttpGet('https://www.googleapis.com/youtube/v3/videos?' . $params);

    if ($data === null || !isset($data['items'])) {
        error_log('SSA YouTube endpoint: videos.list failed for batch of ' . count($batch));
        continue;
    }

    foreach ($data['items'] as $item) {
        $snippet              = $item['snippet']              ?? [];
        $contentDetails       = $item['contentDetails']       ?? [];
        $liveStreamingDetails = $item['liveStreamingDetails'] ?? [];
        $status               = $item['status']               ?? [];

        $videoId  = $item['id'] ?? '';
        $rawTitle = $snippet['title'] ?? '';

        if (!ytIsLiveRecording($rawTitle)) {
            continue;
        }

        $cleanedTitle = ytCleanTitle($rawTitle);
        [$titlePrimary, $titleSecondary] = ytSplitTitle($cleanedTitle);

        $thumbnailUrl = ytBestThumbnail($snippet['thumbnails'] ?? []);
        if (empty($thumbnailUrl)) {
            $thumbnailUrl = "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg";
        }

        $publishedAt = $snippet['publishedAt'] ?? null;

        $durationIso     = $contentDetails['duration'] ?? null;
        $durationSeconds = ytParseDurationSeconds($durationIso);
        $durationDisplay = $durationSeconds !== null ? ytFormatDuration($durationSeconds) : null;

        // Live status: activeLiveBroadcast = currently live, completed = was live, none = VOD
        $liveStatus = $snippet['liveBroadcastContent'] ?? 'none';

        $entry = [
            'videoId'      => $videoId,
            'rawTitle'     => $rawTitle,
            'title'        => $cleanedTitle,
            'titlePrimary' => $titlePrimary,
            'url'          => "https://www.youtube.com/watch?v={$videoId}",
            'thumbnailUrl' => $thumbnailUrl,
            'publishedAt'  => $publishedAt,
            'liveStatus'   => $liveStatus,
        ];

        if ($titleSecondary !== null) {
            $entry['titleSecondary'] = $titleSecondary;
        }
        if ($durationSeconds !== null) {
            $entry['durationSeconds'] = $durationSeconds;
            $entry['durationDisplay'] = $durationDisplay;
        }
        if (!empty($status['privacyStatus'])) {
            $entry['visibility'] = $status['privacyStatus'];
        }

        $allVideos[] = $entry;
    }
}

// Sort newest first (publishedAt descending)
usort($allVideos, function (array $a, array $b): int {
    return strcmp((string)($b['publishedAt'] ?? ''), (string)($a['publishedAt'] ?? ''));
});

// ---------------------------------------------------------------------------
// Cache and respond
// ---------------------------------------------------------------------------

$result = [
    'videos'   => $allVideos,
    'cachedAt' => date('c'),
    'source'   => 'youtube-api',
];

file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

ssaApiJsonResponse(200, $result);
