<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

api_method(['POST']);
$actor = api_require_auth();

function api_blog_public_ip(string $host): string {
    $host = trim($host, '[]');
    $lower = strtolower($host);
    if ($host === '' || $lower === 'localhost' || str_ends_with($lower, '.local')) {
        throw new InvalidArgumentException('image_url aponta para um host não permitido.');
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            $records = [];
        }
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = (string)$record['ip'];
            } elseif (!empty($record['ipv6'])) {
                $ips[] = (string)$record['ipv6'];
            }
        }
    }

    if ($ips === []) {
        throw new InvalidArgumentException('Não foi possível resolver o host de image_url.');
    }

    foreach (array_unique($ips) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }

    throw new InvalidArgumentException('image_url não pode apontar para rede privada, local ou reservada.');
}

function api_blog_validate_image_url(string $url): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('image_url precisa ser uma URL válida.');
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new InvalidArgumentException('image_url precisa ser uma URL válida.');
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('image_url aceita apenas HTTP ou HTTPS.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('image_url não pode conter usuário ou senha.');
    }

    $host = strtolower(trim((string)($parts['host'] ?? '')));
    if ($host === '') {
        throw new InvalidArgumentException('image_url precisa informar um host.');
    }

    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443], true)) {
        throw new InvalidArgumentException('image_url aceita somente as portas 80 e 443.');
    }

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
        'ip' => api_blog_public_ip($host),
    ];
}

function api_blog_redirect_url(string $base, string $location): string {
    $location = trim($location);
    if ($location === '') {
        throw new InvalidArgumentException('A imagem respondeu com redirecionamento inválido.');
    }
    if (preg_match('~^https?://~i', $location)) {
        return $location;
    }

    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new InvalidArgumentException('Não foi possível resolver o redirecionamento da imagem.');
    }

    $origin = (string)$parts['scheme'] . '://' . (string)$parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . (int)$parts['port'];
    }

    if (str_starts_with($location, '//')) {
        return (string)$parts['scheme'] . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }

    $path = (string)($parts['path'] ?? '/');
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    $combined = ($dir === '' ? '' : $dir) . '/' . $location;
    $segments = [];
    foreach (explode('/', $combined) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return $origin . '/' . implode('/', $segments);
}

function api_blog_download_image(string $url): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL do PHP é necessária para baixar image_url.');
    }

    $current = $url;

    for ($redirect = 0; $redirect <= 3; $redirect++) {
        $target = api_blog_validate_image_url($current);
        $tmp = tempnam(sys_get_temp_dir(), 'pergunta-blog-');
        if ($tmp === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário para a imagem.');
        }

        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            @unlink($tmp);
            throw new RuntimeException('Não foi possível abrir arquivo temporário para a imagem.');
        }

        $received = 0;
        $tooLarge = false;
        $location = '';

        $ch = curl_init($current);
        if ($ch === false) {
            fclose($fp);
            @unlink($tmp);
            throw new RuntimeException('Não foi possível iniciar o download da imagem.');
        }

        $resolvedIp = str_contains((string)$target['ip'], ':')
            ? '[' . $target['ip'] . ']'
            : (string)$target['ip'];

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Pergunta.Online Blog Image Fetcher/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [
                $target['host'] . ':' . $target['port'] . ':' . $resolvedIp,
            ],
            CURLOPT_HEADERFUNCTION => static function($curl, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function($curl, string $chunk) use ($fp, &$received, &$tooLarge): int {
                $length = strlen($chunk);
                $received += $length;
                if ($received > 8 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }
                $written = fwrite($fp, $chunk);
                return $written === false ? 0 : $written;
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if ($tooLarge) {
            @unlink($tmp);
            throw new InvalidArgumentException('A imagem remota ultrapassa o limite de 8 MB.');
        }

        if ($status >= 300 && $status < 400 && $location !== '') {
            @unlink($tmp);
            if ($redirect >= 3) {
                throw new InvalidArgumentException('image_url excedeu o limite de redirecionamentos.');
            }
            $current = api_blog_redirect_url($current, $location);
            continue;
        }

        if ($ok === false || $status < 200 || $status >= 300 || $received <= 0) {
            @unlink($tmp);
            throw new InvalidArgumentException('Não foi possível baixar image_url.');
        }

        try {
            return blog_store_downloaded_image($tmp, $received);
        } catch (Throwable $e) {
            @unlink($tmp);
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    throw new InvalidArgumentException('Não foi possível baixar image_url.');
}

function api_blog_datetime(mixed $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        throw new InvalidArgumentException('published_at precisa ser uma data válida.');
    }
}

function api_blog_create(array $input, array $actor): array {
    $title = api_string($input['title'] ?? '', 'title', 3, 255);
    $content = api_string($input['content'] ?? ($input['body'] ?? ''), 'content', 1);
    $excerpt = api_string($input['excerpt'] ?? '', 'excerpt');
    $requestedSlug = api_string($input['slug'] ?? '', 'slug', 0, 190);
    $published = api_bool($input['published'] ?? null, true);
    $publishedAt = api_blog_datetime($input['published_at'] ?? null);

    if ($published && $publishedAt === null) {
        $publishedAt = date('Y-m-d H:i:s');
    }

    $duplicate = db()->prepare('SELECT id,title,slug,cover_image_url,published,published_at FROM blog_posts WHERE title=? LIMIT 1');
    $duplicate->execute([$title]);
    $existing = $duplicate->fetch();
    if ($existing) {
        $root = rtrim(api_root_url(), '/');
        $coverPath = trim((string)($existing['cover_image_url'] ?? ''));
        $coverUrl = $root . '/assets/post-sem-image.jpg';
        if ($coverPath !== '') {
            $coverUrl = preg_match('~^https?://~i', $coverPath)
                ? $coverPath
                : $root . '/' . ltrim($coverPath, '/');
        }

        return [
            'id' => (int)$existing['id'],
            'created' => false,
            'title' => (string)$existing['title'],
            'slug' => (string)$existing['slug'],
            'published' => (int)$existing['published'] === 1,
            'published_at' => $existing['published_at'],
            'cover_image_url' => $coverUrl,
            'url' => $root . '/blog/' . rawurlencode((string)$existing['slug']),
        ];
    }

    $imageUrl = api_string(
        $input['image_url'] ?? ($input['image'] ?? ($input['cover_image_url'] ?? '')),
        'image_url',
        0,
        2000
    );

    $storedImage = '';
    if ($imageUrl !== '') {
        $storedImage = api_blog_download_image($imageUrl);
    }

    try {
        $slug = blog_unique_slug(db(), $requestedSlug !== '' ? $requestedSlug : $title);
        $safeContent = blog_sanitize_html($content);
        if ($safeContent === '') {
            throw new InvalidArgumentException('content ficou vazio depois da validação HTML.');
        }

        $stmt = db()->prepare(
            'INSERT INTO blog_posts
                (author_user_id,title,slug,excerpt,content,cover_image_url,published,published_at)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$actor['id'],
            $title,
            $slug,
            $excerpt !== '' ? $excerpt : null,
            $safeContent,
            $storedImage !== '' ? $storedImage : null,
            $published ? 1 : 0,
            $publishedAt,
        ]);

        $id = (int)db()->lastInsertId();
        audit_log((int)$actor['id'], 'api.blog.created', 'blog_post', $id, $title);

        $root = rtrim(api_root_url(), '/');
        return [
            'id' => $id,
            'created' => true,
            'title' => $title,
            'slug' => $slug,
            'published' => $published,
            'published_at' => $publishedAt,
            'image_downloaded' => $storedImage !== '',
            'cover_image_path' => $storedImage,
            'cover_image_url' => $storedImage !== ''
                ? $root . '/' . ltrim($storedImage, '/')
                : $root . '/assets/post-sem-image.jpg',
            'url' => $root . '/blog/' . rawurlencode($slug),
        ];
    } catch (Throwable $e) {
        if ($storedImage !== '') {
            blog_delete_uploaded_image($storedImage);
        }
        throw $e;
    }
}

api_handle(static function() use ($actor): array {
    $items = api_payload_items();
    if (count($items) > 10) {
        throw new InvalidArgumentException('A API de blog aceita no máximo 10 posts por chamada.');
    }

    $posts = [];
    $createdImages = [];

    db()->beginTransaction();
    try {
        foreach ($items as $item) {
            $post = api_blog_create($item, $actor);
            $posts[] = $post;
            if (!empty($post['created']) && !empty($post['cover_image_path'])) {
                $createdImages[] = (string)$post['cover_image_path'];
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        foreach ($createdImages as $image) {
            blog_delete_uploaded_image($image);
        }
        throw $e;
    }

    return [
        'received' => count($items),
        'posts' => $posts,
    ];
});
