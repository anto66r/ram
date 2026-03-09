<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$dataFile = __DIR__ . '/videos.json';

function loadData($file) {
    if (!file_exists($file)) {
        return ['videos' => [], 'labels' => []];
    }
    $data = json_decode(file_get_contents($file), true);
    return $data ?: ['videos' => [], 'labels' => []];
}

function saveData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function curlGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VideoLibrary/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result ?: null;
}

function downloadImageAsBase64($url) {
    if (!$url) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VideoLibrary/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $data = curl_exec($ch);
    $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if (!$data) return null;
    $mime = explode(';', $mime)[0];
    if (!str_starts_with($mime, 'image/')) return null;
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

function fetchVideoInfo($url) {
    $info = ['cover' => null, 'title' => null];

    // YouTube
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
        $videoId = $m[1];
        $oembed = @json_decode(curlGet(
            "https://www.youtube.com/oembed?url=" . urlencode($url) . "&format=json"
        ), true);
        $info['title'] = $oembed['title'] ?? null;
        $info['cover'] = downloadImageAsBase64("https://img.youtube.com/vi/{$videoId}/hqdefault.jpg");
        return $info;
    }

    // Vimeo
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
        $oembed = @json_decode(curlGet(
            "https://vimeo.com/api/oembed.json?url=" . urlencode($url)
        ), true);
        $info['title'] = $oembed['title'] ?? null;
        $info['cover'] = downloadImageAsBase64($oembed['thumbnail_url'] ?? null);
        return $info;
    }

    // Generic: parse og:image and og:title
    $html = curlGet($url);
    if ($html) {
        // og:image — handle any quote style and attribute order
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*\/?>/i', $html, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*\/?>/i', $html, $m) ||
            preg_match('/property=["\']og:image["\']\s+content=["\'](https?:\/\/[^"\']+)["\']/', $html, $m) ||
            preg_match('/content=["\'](https?:\/\/[^"\']+)["\'][^>]+property=["\']og:image["\']/', $html, $m)) {
            $imageUrl = $m[1];
            // resolve relative URLs
            if (!preg_match('/^https?:\/\//', $imageUrl)) {
                $parsed = parse_url($url);
                $base = $parsed['scheme'] . '://' . $parsed['host'];
                $imageUrl = $base . '/' . ltrim($imageUrl, '/');
            }
            $info['cover'] = downloadImageAsBase64($imageUrl);
        }
        // og:title
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/', $html, $m)) {
            $info['title'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        // fallback: <title>
        if (!$info['title'] && preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            $info['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }
    }

    return $info;
}

$action = $_GET['action'] ?? null;

// GET list
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    echo json_encode(loadData($dataFile));
    exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $data = loadData($dataFile);
    $postAction = $body['action'] ?? null;

    if ($postAction === 'add') {
        $url = trim($body['url'] ?? '');
        $tags = $body['tags'] ?? [];
        if (!$url) {
            http_response_code(400);
            echo json_encode(['error' => 'URL is required']);
            exit;
        }
        foreach ($data['videos'] as $existing) {
            if (rtrim($existing['url'], '/') === rtrim($url, '/')) {
                http_response_code(409);
                echo json_encode(['error' => 'This video is already in your library: ' . ($existing['title'] ?? $url)]);
                exit;
            }
        }
        $coverData = $body['cover_data'] ?? null;
        $info = fetchVideoInfo($url);
        $video = [
            'id'         => uniqid('v_', true),
            'url'        => $url,
            'title'      => $info['title'] ?? $url,
            'cover'      => $coverData ?: $info['cover'],
            'tags'       => array_values(array_unique($tags)),
            'rating'     => 0,
            'created_at' => date('c'),
        ];
        $data['videos'][] = $video;
        saveData($dataFile, $data);
        echo json_encode(['success' => true, 'video' => $video]);

    } elseif ($postAction === 'delete') {
        $id = $body['id'] ?? '';
        $data['videos'] = array_values(array_filter($data['videos'], fn($v) => $v['id'] !== $id));
        saveData($dataFile, $data);
        echo json_encode(['success' => true]);

    } elseif ($postAction === 'add_label') {
        $label = trim($body['label'] ?? '');
        if ($label && !in_array($label, $data['labels'])) {
            $data['labels'][] = $label;
            saveData($dataFile, $data);
        }
        echo json_encode(['success' => true, 'labels' => $data['labels']]);

    } elseif ($postAction === 'delete_label') {
        $label = $body['label'] ?? '';
        $data['labels'] = array_values(array_filter($data['labels'], fn($l) => $l !== $label));
        foreach ($data['videos'] as &$video) {
            $video['tags'] = array_values(array_filter($video['tags'], fn($t) => $t !== $label));
        }
        saveData($dataFile, $data);
        echo json_encode(['success' => true]);

    } elseif ($postAction === 'fetch_meta') {
        $url = trim($body['url'] ?? '');
        if (!$url) { echo json_encode(['error' => 'URL required']); exit; }
        echo json_encode(fetchVideoInfo($url));

    } elseif ($postAction === 'rate_video') {
        $id     = $body['id'] ?? '';
        $rating = max(0, min(5, (int)($body['rating'] ?? 0)));
        foreach ($data['videos'] as &$video) {
            if ($video['id'] === $id) {
                $video['rating'] = $rating;
                break;
            }
        }
        saveData($dataFile, $data);
        echo json_encode(['success' => true]);

    } elseif ($postAction === 'update_video') {
        $id = $body['id'] ?? '';
        foreach ($data['videos'] as &$video) {
            if ($video['id'] === $id) {
                $video['url']    = trim($body['url'] ?? $video['url']);
                $video['title']  = trim($body['title'] ?? $video['title']);
                $video['cover']  = array_key_exists('cover', $body) ? $body['cover'] : $video['cover'];
                $video['tags']   = array_values(array_unique($body['tags'] ?? $video['tags']));
                $video['rating'] = array_key_exists('rating', $body) ? max(0, min(5, (int)$body['rating'])) : ($video['rating'] ?? 0);
                break;
            }
        }
        saveData($dataFile, $data);
        echo json_encode(['success' => true]);

    } elseif ($postAction === 'update_tags') {
        $id = $body['id'] ?? '';
        $tags = $body['tags'] ?? [];
        foreach ($data['videos'] as &$video) {
            if ($video['id'] === $id) {
                $video['tags'] = array_values(array_unique($tags));
                break;
            }
        }
        saveData($dataFile, $data);
        echo json_encode(['success' => true]);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
