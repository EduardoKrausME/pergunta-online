<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (!app_installed()) {
    redirect('install');
}

$id = (int)Request::get('id', Request::INT, 0);
$viewer = current_user();
$viewerId = (int)($viewer['id'] ?? 0);
$isAdmin = (($viewer['role'] ?? '') === 'admin') ? 1 : 0;
$isRespondent = (($viewer['role'] ?? '') === 'respondent') ? 1 : 0;

$stmt = db()->prepare("
    SELECT e.storage_name,e.original_name,e.mime_type,q.published,q.user_id,q.target_id,q.deleted_at
    FROM question_evidence e
    JOIN questions q ON q.id=e.question_id
    WHERE e.id=? AND e.storage_name IS NOT NULL
      AND q.deleted_at IS NULL
      AND (
          q.published=1
          OR q.user_id=?
          OR ?=1
          OR (?=1 AND EXISTS (
              SELECT 1 FROM target_users tu
              WHERE tu.target_id=q.target_id AND tu.user_id=?
          ))
      )
    LIMIT 1
");
$stmt->execute([$id, $viewerId, $isAdmin, $isRespondent, $viewerId]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit('Evidência não encontrada.');
}

$file = __DIR__ . '/storage/evidence/' . basename((string)$row['storage_name']);
if (!is_file($file)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$name = preg_replace('/[^A-Za-z0-9._ -]+/u', '_', (string)$row['original_name']) ?: 'evidencia';
header('Content-Type: ' . ((string)$row['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
