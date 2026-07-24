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

function curlGet($url, $extraHeaders = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
        ], $extraHeaders),
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result ?: null;
}

function parseMetaTags($html) {
    $result = ['json_ld' => []];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    foreach ($xpath->query('//meta') as $meta) {
        $key = $meta->getAttribute('property') ?: $meta->getAttribute('name');
        $content = $meta->getAttribute('content');
        if ($key !== '' && $content !== '') {
            $result[strtolower($key)] = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        }
    }

    $titles = $xpath->query('//title');
    if ($titles->length > 0) {
        $result['page_title'] = html_entity_decode(trim($titles->item(0)->textContent), ENT_QUOTES, 'UTF-8');
    }

    foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
        $ld = @json_decode($script->textContent, true);
        if (!$ld) continue;
        $items = isset($ld['@graph']) ? $ld['@graph'] : [$ld];
        foreach ($items as $item) $result['json_ld'][] = $item;
    }

    return $result;
}

function ensureImagesDir() {
    $dir = __DIR__ . '/images';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function detectImageExt($imageData) {
    $info = @getimagesizefromstring($imageData);
    $mime = $info['mime'] ?? null;
    switch ($mime) {
        case 'image/webp': return 'webp';
        case 'image/png':  return 'png';
        case 'image/gif':  return 'gif';
        default:           return 'jpg';
    }
}

function saveImageAsJpg($imageData, $id) {
    $dir = ensureImagesDir();
    $img = @imagecreatefromstring($imageData);

    // Some GD builds can't decode WebP via imagecreatefromstring; retry with
    // the dedicated decoder before giving up on the image.
    if (!$img && function_exists('imagecreatefromwebp')) {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, $imageData);
        $img = @imagecreatefromwebp($tmp);
        unlink($tmp);
    }

    if ($img) {
        $path = "images/{$id}.jpg";
        imagejpeg($img, $dir . "/{$id}.jpg", 85);
        imagedestroy($img);
        return $path;
    }

    // Imagick often supports formats GD's build lacks (e.g. WebP without libwebp).
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->readImageBlob($imageData);
            $im->setImageFormat('jpeg');
            $path = "images/{$id}.jpg";
            $im->writeImage($dir . "/{$id}.jpg");
            $im->destroy();
            return $path;
        } catch (Exception $e) {
            // fall through to raw-bytes fallback below
        }
    }

    // Last resort: store the original bytes so the cover isn't silently
    // dropped, using the real extension so the served Content-Type matches.
    $ext = detectImageExt($imageData);
    $path = "images/{$id}.{$ext}";
    if (file_put_contents($dir . "/{$id}.{$ext}", $imageData) === false) return null;
    return $path;
}

function saveBase64AsJpg($base64, $id) {
    if (!$base64) return null;
    if (preg_match('/^data:image\/[^;]+;base64,(.+)$/', $base64, $m)) {
        $data = base64_decode($m[1]);
    } else {
        return null;
    }
    return saveImageAsJpg($data, $id);
}

function downloadImageAsBase64($url) {
    if (!$url) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Accept: image/webp,image/apng,image/*,*/*;q=0.8'],
    ]);
    $data = curl_exec($ch);
    $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if (!$data) return null;
    $mime = explode(';', $mime)[0];
    if (!str_starts_with($mime, 'image/')) return null;
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

function downloadImageAsJpg($url, $id) {
    $base64 = downloadImageAsBase64($url);
    if (!$base64) return null;
    return saveBase64AsJpg($base64, $id);
}

function deleteImageFile($cover) {
    if ($cover && str_starts_with($cover, 'images/')) {
        $file = __DIR__ . '/' . $cover;
        if (file_exists($file)) unlink($file);
    }
}

function resolveUrl($imageUrl, $pageUrl) {
    if (preg_match('/^https?:\/\//', $imageUrl)) return $imageUrl;
    $p = parse_url($pageUrl);
    $base = $p['scheme'] . '://' . $p['host'];
    if (str_starts_with($imageUrl, '//')) return $p['scheme'] . ':' . $imageUrl;
    return $base . '/' . ltrim($imageUrl, '/');
}

function extractTitle($parsed) {
    // og:title > twitter:title > page <title>
    return $parsed['og:title']
        ?? $parsed['twitter:title']
        ?? $parsed['page_title']
        ?? null;
}

function extractFromParsed($parsed, $pageUrl, $saveOrBase64) {
    $info = ['cover' => null, 'title' => extractTitle($parsed)];

    // Image: og:image > twitter:image > twitter:image:src > JSON-LD
    $imageUrl = $parsed['og:image']
        ?? $parsed['twitter:image']
        ?? $parsed['twitter:image:src']
        ?? null;

    if (!$imageUrl && !empty($parsed['json_ld'])) {
        foreach ($parsed['json_ld'] as $ld) {
            $candidate = $ld['thumbnailUrl'] ?? null;
            if (!$candidate && isset($ld['image'])) {
                $img = $ld['image'];
                $candidate = is_array($img) ? ($img['url'] ?? $img[0] ?? null) : $img;
            }
            if ($candidate) { $imageUrl = $candidate; break; }
        }
    }

    if ($imageUrl) {
        $imageUrl = resolveUrl($imageUrl, $pageUrl);
        $info['cover'] = $saveOrBase64($imageUrl);
    }

    return $info;
}

function fetchVideoInfo($url, $id = null, $titleOnly = false) {
    $info = ['cover' => null, 'title' => null];

    $saveOrBase64 = function($imageUrl) use ($id) {
        if (!$imageUrl) return null;
        if ($id) return downloadImageAsJpg($imageUrl, $id);
        return downloadImageAsBase64($imageUrl);
    };

    // YouTube
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
        $videoId = $m[1];
        $oembed = @json_decode(curlGet(
            "https://www.youtube.com/oembed?url=" . urlencode($url) . "&format=json"
        ), true);
        $info['title'] = $oembed['title'] ?? null;
        if (!$titleOnly) {
            // maxresdefault first, fall back to hqdefault
            $info['cover'] = $saveOrBase64("https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg")
                ?: $saveOrBase64("https://img.youtube.com/vi/{$videoId}/hqdefault.jpg");
        }
        return $info;
    }

    // Vimeo
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
        $oembed = @json_decode(curlGet(
            "https://vimeo.com/api/oembed.json?url=" . urlencode($url)
        ), true);
        $info['title'] = $oembed['title'] ?? null;
        if (!$titleOnly) {
            $thumbUrl = $oembed['thumbnail_url'] ?? null;
            if ($thumbUrl) {
                // upgrade to larger size when available
                $thumbUrl = preg_replace('/_\d+x\d+(\.\w+)$/', '_1280x720$1', $thumbUrl);
            }
            $info['cover'] = $saveOrBase64($thumbUrl);
        }
        return $info;
    }

    // Generic: fetch page and parse with DOMDocument
    $html = curlGet($url);
    if (!$html) return $info;

    $parsed = parseMetaTags($html);
    if ($titleOnly) {
        $info['title'] = extractTitle($parsed);
        return $info;
    }
    return extractFromParsed($parsed, $url, $saveOrBase64);
}

$action = $_GET['action'] ?? null;

// GET list
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    echo json_encode(loadData($dataFile));
    exit;
}

// GET refetch_titles: fill in a title for any video that doesn't have a real
// one yet (title missing or still just the raw URL). Existing titles are
// left untouched. Meant to be hit directly from a browser.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'refetch_titles') {
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(0);

    $data = loadData($dataFile);
    $checked = 0;
    $updated = 0;
    foreach ($data['videos'] as &$video) {
        $url = trim($video['url'] ?? '');
        $title = trim($video['title'] ?? '');
        if ($title !== '' && $title !== $url) continue;

        $checked++;
        $info = fetchVideoInfo($url, null, true);
        if (!empty($info['title'])) {
            $video['title'] = $info['title'];
            $updated++;
        }
    }
    unset($video);
    saveData($dataFile, $data);
    echo json_encode(['success' => true, 'checked' => $checked, 'updated' => $updated]);
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
        $id = uniqid('v_', true);
        $coverData = $body['cover_data'] ?? null;
        // If cover_data is base64 from browser, save to disk
        if ($coverData && str_starts_with($coverData, 'data:')) {
            $coverData = saveBase64AsJpg($coverData, $id) ?: $coverData;
        }
        $info = fetchVideoInfo($url, $coverData ? null : $id);
        $bodyTitle = trim($body['title'] ?? '');
        $video = [
            'id'         => $id,
            'url'        => $url,
            'title'      => $bodyTitle ?: ($info['title'] ?? $url),
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
        foreach ($data['videos'] as $v) {
            if ($v['id'] === $id) { deleteImageFile($v['cover'] ?? null); break; }
        }
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
        $result = fetchVideoInfo($url);
        foreach ($data['videos'] as $existing) {
            if (rtrim($existing['url'], '/') === rtrim($url, '/')) {
                $result['exists'] = true;
                $result['existing_title'] = $existing['title'] ?? $url;
                break;
            }
        }
        echo json_encode($result);

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
                $video['url']   = trim($body['url'] ?? $video['url']);
                $video['title'] = trim($body['title'] ?? $video['title']);
                if (array_key_exists('cover', $body)) {
                    $newCover = $body['cover'];
                    // Save new base64 cover to disk, delete old file if replacing
                    if ($newCover && str_starts_with($newCover, 'data:')) {
                        deleteImageFile($video['cover'] ?? null);
                        $newCover = saveBase64AsJpg($newCover, $id) ?: $newCover;
                    } elseif (!$newCover) {
                        deleteImageFile($video['cover'] ?? null);
                    }
                    $video['cover'] = $newCover;
                }
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

    } elseif ($postAction === 'migrate_images') {
        ensureImagesDir();
        $migrated = 0;
        $failed = 0;
        foreach ($data['videos'] as &$video) {
            $cover = $video['cover'] ?? null;
            if (!$cover || !str_starts_with($cover, 'data:')) continue;
            $path = saveBase64AsJpg($cover, $video['id']);
            if ($path) {
                $video['cover'] = $path;
                $migrated++;
            } else {
                $failed++;
            }
        }
        unset($video);
        saveData($dataFile, $data);
        echo json_encode(['success' => true, 'migrated' => $migrated, 'failed' => $failed]);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
