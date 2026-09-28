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

function blog_cover_url(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
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
