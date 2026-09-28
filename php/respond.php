<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
if (!app_installed()) {
    redirect('install');
}
$user = require_login();
$id = (int)Request::get('id', Request::INT, 0);

$stmt = db()->prepare('SELECT * FROM questions WHERE id=? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$id]);
$question = $stmt->fetch();
if (!$question) {
    http_response_code(404);
    render_page('not_found', [], 'Pergunta não encontrada');
    exit;
}

$authorized = ($user['role'] ?? '') === 'admin';
if (!$authorized && ($user['role'] ?? '') === 'respondent' && !empty($question['target_id'])) {
    $check = db()->prepare('SELECT 1 FROM target_users WHERE target_id=? AND user_id=?');
    $check->execute([(int)$question['target_id'], (int)$user['id']]);
    $authorized = (bool)$check->fetchColumn();
}
if (!$authorized) {
    http_response_code(403);
    exit('Esta conta não está autorizada a responder por este destinatário.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)Request::post('action', Request::STRING, '');

    try {
        if ($action === 'take') {
            question_update_state(
                $id,
                $user,
                'taken',
                (string)($question['answer_text'] ?? ''),
                (int)$question['published'] === 1
            );
            flash('success', 'Pauta assumida e registrada no histórico.');
        } elseif ($action === 'answer') {
            $answer = trim((string)Request::post('answer_text', Request::STRING, ''));
            question_update_state($id, $user, 'answered', $answer, (int)$question['published'] === 1);
            flash('success', 'Resposta registrada e ciclo fechado.');
        } elseif ($action === 'add_evidence') {
            $title = trim((string)Request::post('evidence_title', Request::STRING, ''));
            $type = (string)Request::post('evidence_type', Request::STRING, 'url');
            $reference = trim((string)Request::post('reference_value', Request::STRING, ''));
            $file = isset($_FILES['evidence_file']) && is_array($_FILES['evidence_file'])
                ? $_FILES['evidence_file']
                : null;

            question_store_evidence($id, (int)$user['id'], $type, $title, $reference, $file);
            question_history_add(
                $id,
                (int)$user['id'],
                'evidence_added',
                (string)$question['status'],
                (string)$question['status'],
                $title
            );
            audit_log((int)$user['id'], 'question.evidence_added', 'question', $id, $title);
            flash('success', 'Evidência adicionada à resposta.');
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect('respond?id=' . $id);
}

$stmt->execute([$id]);
$question = $stmt->fetch();

$evidenceStmt = db()->prepare("
    SELECT id,evidence_type,title,reference_value,storage_name,original_name,created_at
    FROM question_evidence
    WHERE question_id=?
    ORDER BY created_at DESC
");
$evidenceStmt->execute([$id]);
$evidence = array_map(static fn(array $row): array => [
    'title' => (string)$row['title'],
    'type' => (string)$row['evidence_type'],
    'reference' => (string)($row['reference_value'] ?? ''),
    'has_reference' => !empty($row['reference_value']),
    'has_file' => !empty($row['storage_name']),
    'download_url' => !empty($row['storage_name']) ? base_url('evidence?id=' . (int)$row['id']) : '',
    'original_name' => (string)($row['original_name'] ?? ''),
    'created_at' => date('d/m/Y H:i', strtotime((string)$row['created_at'])),
], $evidenceStmt->fetchAll());

render_page('respond', [
    'question_id' => $id,
    'title' => (string)$question['title'],
    'body' => (string)$question['body'],
    'target_name' => (string)$question['target_name'],
    'status_label' => status_label((string)$question['status']),
    'answer_text' => (string)($question['answer_text'] ?? ''),
    'is_taken' => (string)$question['status'] === 'taken',
    'is_answered' => (string)$question['status'] === 'answered',
    'evidence' => $evidence,
    'has_evidence' => $evidence !== [],
    'question_url' => base_url('question?id=' . $id),
    'responses_url' => base_url('responses'),
], 'Responder pergunta');
