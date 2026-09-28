<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install');
}
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)Request::post('question_id', Request::INT, 0);
    $action = (string)Request::post('action', Request::STRING, '');

    $stmt = db()->prepare("SELECT id,status,moderation_status FROM questions WHERE id=? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $question = $stmt->fetch();
    if ($question && in_array($action, ['approve', 'reject'], true)) {
        $approved = $action === 'approve';
        $update = db()->prepare('UPDATE questions SET moderation_status=?,published=? WHERE id=?');
        $update->execute([$approved ? 'approved' : 'rejected', $approved ? 1 : 0, $id]);
        question_history_add(
            $id,
            (int)$admin['id'],
            $approved ? 'moderation_approved' : 'moderation_rejected',
            (string)$question['status'],
            (string)$question['status']
        );
        audit_log((int)$admin['id'], $approved ? 'question.moderation_approved' : 'question.moderation_rejected', 'question', $id);
        flash('success', $approved ? 'Pergunta aprovada e publicada.' : 'Pergunta rejeitada e mantida fora do site.');
    }
    redirect('admin/moderation');
}

$rows = db()->query("
    SELECT q.id,q.title,q.body,q.focus_name,q.category,q.target_name,q.created_at,u.name author_name
    FROM questions q
    JOIN users u ON u.id=q.user_id
    WHERE q.moderation_status='pending' AND q.deleted_at IS NULL
    ORDER BY q.created_at ASC
    LIMIT 100
")->fetchAll();

$questions = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'title' => (string)$row['title'],
    'body' => (string)$row['body'],
    'focus_name' => (string)$row['focus_name'],
    'category' => (string)$row['category'],
    'target_name' => (string)$row['target_name'],
    'author_name' => (string)$row['author_name'],
    'created_at' => date('d/m/Y H:i', strtotime((string)$row['created_at'])),
    'admin_url' => base_url('admin/question?id=' . (int)$row['id']),
], $rows);

render_page('admin/moderation', [
    'questions' => $questions,
    'has_questions' => $questions !== [],
    'count' => count($questions),
], 'Moderação', true);
