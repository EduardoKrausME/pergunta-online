<?php

declare(strict_types=1);

function blog_slugify(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return 'artigo';
    }

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? substr($value, 0, 190) : 'artigo';
}

function blog_unique_slug(PDO $pdo, string $value, int $excludeId = 0): string {
    $base = blog_slugify($value);
    $slug = $base;
    $suffix = 2;

    while (true) {
        if ($excludeId > 0) {
            $stmt = $pdo->prepare('SELECT id FROM blog_posts WHERE slug=? AND id<>? LIMIT 1');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM blog_posts WHERE slug=? LIMIT 1');
            $stmt->execute([$slug]);
        }

        if (!$stmt->fetchColumn()) {
            return $slug;
        }

        $tail = '-' . $suffix++;
        $slug = substr($base, 0, max(1, 190 - strlen($tail))) . $tail;
    }
}

function blog_fallback_image_url(): string {
    return base_url('assets/post-sem-image.jpg');
}

function blog_cover_url(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return blog_fallback_image_url();
    }

    if (str_starts_with($value, '/') || preg_match('~^https?://~i', $value)) {
        return $value;
    }

    return base_url($value);
}

function blog_upload_image(array $file): string {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Nenhuma imagem foi enviada.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload da imagem.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('O arquivo recebido não é um upload válido.');
    }
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        throw new RuntimeException('A imagem deve ter no máximo 8 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Formato inválido. Use JPG, PNG, WEBP ou GIF.');
    }

    $dimensions = @getimagesize($tmp);
    if (!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
        throw new RuntimeException('O arquivo enviado não é uma imagem válida.');
    }
    $width = (int)$dimensions[0];
    $height = (int)$dimensions[1];
    if ($width > 12000 || $height > 12000 || ($width * $height) > 60000000) {
        throw new RuntimeException('A resolução da imagem é muito grande.');
    }

    $uploadDir = dirname(__DIR__) . '/upload';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Não foi possível criar a pasta de upload.');
    }
    if (!is_writable($uploadDir)) {
        throw new RuntimeException('A pasta php/upload não possui permissão de escrita.');
    }

    $filename = 'blog-' . date('Ymd-His') . '-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Não foi possível salvar a imagem enviada.');
    }

    @chmod($destination, 0644);
    return 'upload/' . $filename;
}

function blog_delete_uploaded_image(?string $value): void {
    $value = trim((string)$value);
    if ($value === '' || !str_starts_with($value, 'upload/')) {
        return;
    }

    $filename = basename($value);
    if (!preg_match('/^blog-[A-Za-z0-9._-]+$/', $filename)) {
        return;
    }

    $file = dirname(__DIR__) . '/upload/' . $filename;
    if (is_file($file)) {
        @unlink($file);
    }
}

function blog_datetime_input(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

function blog_datetime_database(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        throw new InvalidArgumentException('Data de publicação inválida.');
    }

    return $date->format('Y-m-d H:i:s');
}

function blog_sanitize_html(string $html): string {
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        $html = strip_tags(
            $html,
            '<p><br><strong><b><em><i><u><s><h2><h3><h4><ul><ol><li><blockquote><a><img><figure><figcaption><table><thead><tbody><tfoot><tr><th><td><hr><pre><code><span><div>'
        );
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/(href|src)\s*=\s*("|\')\s*(?:javascript|data):.*?\2/iu', '$1="#"', $html) ?? $html;
        return $html;
    }

    $allowedTags = [
        'p','br','strong','b','em','i','u','s','h2','h3','h4','ul','ol','li',
        'blockquote','a','img','figure','figcaption','table','thead','tbody','tfoot',
        'tr','th','td','hr','pre','code','span','div'
    ];
    $attributesByTag = [
        'a' => ['href','target','rel','title'],
        'img' => ['src','alt','title','width','height','loading'],
        'th' => ['colspan','rowspan'],
        'td' => ['colspan','rowspan'],
        'ol' => ['start'],
    ];

    $dom = new DOMDocument('1.0', 'UTF-8');
    $old = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML(
        '<?xml encoding="UTF-8"><div id="blog-html-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($old);

    if (!$loaded) {
        return '';
    }

    $root = $dom->getElementById('blog-html-root');
    if (!$root) {
        return '';
    }

    $sanitize = static function(DOMNode $node) use (&$sanitize, $allowedTags, $attributesByTag): void {
        for ($child = $node->firstChild; $child !== null;) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowedTags, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    $child = $next;
                    continue;
                }

                $allowedAttributes = $attributesByTag[$tag] ?? [];
                for ($i = $child->attributes->length - 1; $i >= 0; $i--) {
                    $attribute = $child->attributes->item($i);
                    if ($attribute && !in_array(strtolower($attribute->name), $allowedAttributes, true)) {
                        $child->removeAttribute($attribute->name);
                    }
                }

                if ($tag === 'a' && $child->hasAttribute('href')) {
                    $href = trim($child->getAttribute('href'));
                    if ($href !== '' && !str_starts_with($href, '#') && !preg_match('~^(https?://|mailto:)~i', $href)) {
                        $child->removeAttribute('href');
                    }
                    if ($child->getAttribute('target') === '_blank') {
                        $child->setAttribute('rel', 'noopener noreferrer');
                    }
                }

                if ($tag === 'img') {
                    $src = trim($child->getAttribute('src'));
                    if ($src === '' || (!str_starts_with($src, '/') && !preg_match('~^https?://~i', $src))) {
                        $child->removeAttribute('src');
                    }
                    $child->setAttribute('loading', 'lazy');
                }

                $sanitize($child);
            }

            $child = $next;
        }
    };

    $sanitize($root);

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $dom->saveHTML($child);
    }
    return trim($output);
}

function blog_content_html(string $content): string {
    $content = trim($content);
    if ($content === '') {
        return '';
    }
    if (!preg_match('/<\/?[a-z][\s\S]*>/i', $content)) {
        return nl2br(h($content));
    }
    return blog_sanitize_html($content);
}

