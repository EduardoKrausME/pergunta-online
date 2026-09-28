<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
api_method(['GET']);
api_require_auth();

$q = trim((string)($_GET['q'] ?? ''));
if (strlen($q) < 3) {
    api_error('Informe q com ao menos 3 caracteres.', 422);
}

$like = '%' . $q . '%';
$stmt = db()->prepare("
    SELECT id,title,body,focus_name,category,target_name,status,published,moderation_status,created_at
    FROM questions
    WHERE deleted_at IS NULL
      AND (title LIKE ? OR body LIKE ? OR focus_name LIKE ? OR category LIKE ? OR target_name LIKE ?)
    ORDER BY updated_at DESC,id DESC
    LIMIT 20
");
$stmt->execute([$like, $like, $like, $like, $like]);
$rows = $stmt->fetchAll();
$root = api_root_url();
foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['published'] = (int)$row['published'] === 1;
    $row['url'] = ($root !== '' ? $root : '') . '/question?id=' . $row['id'];
}
unset($row);

api_json(['ok' => true, 'data' => ['query' => $q, 'results' => $rows]]);
