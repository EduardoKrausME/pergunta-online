<?php

declare(strict_types=1);

function question_store_evidence(
    int $questionId,
    int $userId,
    string $type,
    string $title,
    string $reference,
    ?array $file = null
): int {
    $allowedTypes = ['url', 'document', 'image', 'video', 'text'];
    $type = trim($type);
    $title = trim($title);
    $reference = trim($reference);

    if (!in_array($type, $allowedTypes, true) || strlen($title) < 2) {
        throw new InvalidArgumentException('Informe um título e tipo de evidência válidos.');
    }
    if ($type === 'url' && !filter_var($reference, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Informe uma URL válida.');
    }
    if ($type === 'text' && $reference === '') {
        throw new InvalidArgumentException('Informe o conteúdo textual da evidência.');
    }

    $storage = null;
    $original = null;
    $mime = null;

    if (in_array($type, ['document', 'image', 'video'], true)) {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Envie um arquivo para essa evidência.');
        }
        if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('O arquivo precisa ter até 10 MB.');
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $allowedMime = [
            'document' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
            ],
            'image' => ['image/jpeg', 'image/png', 'image/webp'],
            'video' => ['video/mp4', 'video/webm'],
        ];
        if (!in_array($mime, $allowedMime[$type], true)) {
            throw new InvalidArgumentException('Tipo de arquivo não permitido para essa evidência.');
        }

        $dir = dirname(__DIR__) . '/storage/evidence';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar o diretório de evidências.');
        }

        $storage = bin2hex(random_bytes(24));
        if (!move_uploaded_file($tmp, $dir . '/' . $storage)) {
            throw new RuntimeException('Não foi possível armazenar a evidência.');
        }
        $original = basename((string)($file['name'] ?? 'evidencia'));
    }

    $stmt = db()->prepare("
        INSERT INTO question_evidence
            (question_id,user_id,evidence_type,title,reference_value,storage_name,original_name,mime_type)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $questionId,
        $userId,
        $type,
        $title,
        $reference !== '' ? $reference : null,
        $storage,
        $original,
        $mime,
    ]);

    return (int)db()->lastInsertId();
}
