<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (!app_installed()) {
    redirect('install');
}
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}
require_csrf();

$id = (int)Request::post('question_id', Request::INT, 0);
$reason = (string)Request::post('reason', Request::STRING, '');
$details = trim((string)Request::post('details', Request::STRING, ''));
$allowed = ['spam', 'offensive', 'illegal', 'duplicate', 'other'];

$stmt = db()->prepare('SELECT id,status FROM questions WHERE id=? AND published=1 AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$id]);
$question = $stmt->fetch();
if (!$question || !in_array($reason, $allowed, true)) {
    flash('error', 'Não foi possível registrar a denúncia.');
    redirect('/');
}

$upsert = db()->prepare("
    INSERT INTO question_reports (question_id,user_id,reason,details,status,reviewed_by,reviewed_at)
    VALUES (?,?,?,?,'pending',NULL,NULL)
    ON DUPLICATE KEY UPDATE reason=VALUES(reason),details=VALUES(details),status='pending',reviewed_by=NULL,reviewed_at=NULL
");
$upsert->execute([$id, (int)$user['id'], $reason, $details ?: null]);
question_history_add($id, (int)$user['id'], 'reported', (string)$question['status'], (string)$question['status'], $reason);
flash('success', 'Denúncia registrada para análise.');
redirect('question?id=' . $id);
