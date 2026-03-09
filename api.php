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

function fetchVideoInfo($url) {
    $info = ['cover' => null, 'title' => null];

    // YouTube
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
        $videoId = $m[1];
        $info['cover'] = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
        $oembed = @json_decode(@file_get_contents(
            "https://www.youtube.com/oembed?url=" . urlencode($url) . "&format=json"
        ), true);
        $info['title'] = $oembed['title'] ?? null;
        return $info;
    }

    // Vimeo
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
        $oembed = @json_decode(@file_get_contents(
            "https://vimeo.com/api/oembed.json?url=" . urlencode($url)
        ), true);
        $info['cover'] = $oembed['thumbnail_url'] ?? null;
        $info['title'] = $oembed['title'] ?? null;
        return $info;
    }

    // Generic: parse og:image and og:title
    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'user_agent' => 'Mozilla/5.0 (compatible; VideoLibrary/1.0)',
        'follow_location' => true,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html) {
        // og:image
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/', $html, $m)) {
            $info['cover'] = $m[1];
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
        $info = fetchVideoInfo($url);
        $video = [
            'id'         => uniqid('v_', true),
            'url'        => $url,
            'title'      => $info['title'] ?? $url,
            'cover'      => $info['cover'],
            'tags'       => array_values(array_unique($tags)),
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
