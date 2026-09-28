<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
$admin = require_admin();
$id = (int)Request::get('id', Request::INT, 0);

$load = static function(int $questionId): array|false {
    $stmt = db()->prepare("
        SELECT q.*,u.name author_name,u.email author_email
        FROM questions q
        JOIN users u ON u.id=q.user_id
        WHERE q.id=? LIMIT 1
    ");
    $stmt->execute([$questionId]);
    return $stmt->fetch();
};

$question = $load($id);
if (!$question) {
    http_response_code(404);
    render_page('not_found', [], 'Pergunta não encontrada');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)Request::post('action', Request::STRING, '');

    if ($action === 'save_content') {
        $focusId = (int)Request::post('focus_id', Request::INT, 0);
        $categoryId = (int)Request::post('category_id', Request::INT, 0);
        $targetId = (int)Request::post('target_id', Request::INT, 0);
        $title = trim((string)Request::post('title', Request::STRING, ''));
        $body = trim((string)Request::post('body', Request::STRING, ''));

        $f = db()->prepare('SELECT id,name,abbr FROM focuses WHERE id=?');
        $f->execute([$focusId]);
        $focus = $f->fetch();
        $c = db()->prepare('SELECT id,name FROM categories WHERE id=?');
        $c->execute([$categoryId]);
        $category = $c->fetch();
        $t = db()->prepare('SELECT id,name FROM targets WHERE id=?');
        $t->execute([$targetId]);
        $target = $t->fetch();

        if (!$focus || !$category || !$target || strlen($title) < 15 || strlen($body) < 20) {
            flash('error', 'Preencha foco, categoria, destinatário, pergunta e contexto corretamente.');
        } else {
            $stmt = db()->prepare("
                UPDATE questions
                SET focus_id=?,focus_name=?,focus_abbr=?,category_id=?,category=?,target_id=?,target_name=?,title=?,body=?
                WHERE id=?
            ");
            $stmt->execute([
                (int)$focus['id'], (string)$focus['name'], (string)$focus['abbr'],
                (int)$category['id'], (string)$category['name'],
                (int)$target['id'], (string)$target['name'], $title, $body, $id
            ]);

            if ((int)($question['target_id'] ?? 0) !== (int)$target['id'] && !empty($question['taken_by'])) {
                $validOwner = db()->prepare('SELECT 1 FROM target_users WHERE target_id=? AND user_id=?');
                $validOwner->execute([(int)$target['id'], (int)$question['taken_by']]);
                if (!$validOwner->fetchColumn()) {
                    $clearOwner = db()->prepare('UPDATE questions SET taken_by=NULL,taken_at=NULL WHERE id=?');
                    $clearOwner->execute([$id]);
                    question_history_add($id, (int)$admin['id'], 'respondent_unassigned', (string)$question['status'], (string)$question['status'], 'Destinatário alterado.');
                    audit_log((int)$admin['id'], 'question.respondent_unassigned', 'question', $id, 'Destinatário alterado.');
                }
            }

            question_history_add($id, (int)$admin['id'], 'edited', (string)$question['status'], (string)$question['status']);
            audit_log((int)$admin['id'], 'question.edited', 'question', $id);
            flash('success', 'Conteúdo da pergunta atualizado.');
        }
    } elseif ($action === 'save_state') {
        $status = (string)Request::post('status', Request::STRING, '');
        $answer = (string)Request::post('answer_text', Request::STRING, '');
        $published = (bool)Request::post('published', Request::BOOL, false);
        try {
            question_update_state($id, $admin, $status, $answer, $published);
            if ($status === 'taken') {
                $respondentId = (int)Request::post('taken_by', Request::INT, 0);
                if ($respondentId > 0) {
                    $check = db()->prepare("
                        SELECT u.id
                        FROM users u
                        JOIN target_users tu ON tu.user_id=u.id
                        WHERE u.id=? AND u.active=1 AND tu.target_id=(SELECT target_id FROM questions WHERE id=?)
                    ");
                    $check->execute([$respondentId, $id]);
                    if ($check->fetch()) {
                        $assign = db()->prepare('UPDATE questions SET taken_by=?,taken_at=NOW() WHERE id=?');
                        $assign->execute([$respondentId, $id]);
                        question_history_add($id, (int)$admin['id'], 'respondent_assigned', 'taken', 'taken', 'user:' . $respondentId);
                        audit_log((int)$admin['id'], 'question.respondent_assigned', 'question', $id, 'user:' . $respondentId);
                    }
                }
            }
            flash('success', 'Estado da pergunta atualizado.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
    } elseif ($action === 'mark_duplicate') {
        $duplicateOf = (int)Request::post('duplicate_of', Request::INT, 0);
        $check = db()->prepare('SELECT id FROM questions WHERE id=? AND id<>? AND deleted_at IS NULL');
        $check->execute([$duplicateOf, $id]);
        if ($check->fetch()) {
            $stmt = db()->prepare("UPDATE questions SET duplicate_of=?,status='archived' WHERE id=?");
            $stmt->execute([$duplicateOf, $id]);
            question_history_add($id, (int)$admin['id'], 'marked_duplicate', (string)$question['status'], 'archived', 'question:' . $duplicateOf);
            audit_log((int)$admin['id'], 'question.marked_duplicate', 'question', $id, 'question:' . $duplicateOf);
            flash('success', 'Pergunta marcada como duplicada e arquivada.');
        } else {
            flash('error', 'Pergunta principal inválida.');
        }
    } elseif ($action === 'unmark_duplicate') {
        $newStatus = (string)$question['status'] === 'archived' ? 'open' : (string)$question['status'];
        $stmt = db()->prepare('UPDATE questions SET duplicate_of=NULL,status=? WHERE id=?');
        $stmt->execute([$newStatus, $id]);
        question_history_add($id, (int)$admin['id'], 'duplicate_removed', (string)$question['status'], $newStatus);
        audit_log((int)$admin['id'], 'question.duplicate_removed', 'question', $id);
        flash('success', 'Marca de duplicidade removida.');
    } elseif ($action === 'delete') {
        $stmt = db()->prepare('UPDATE questions SET deleted_at=NOW(),deleted_by=?,published=0 WHERE id=?');
        $stmt->execute([(int)$admin['id'], $id]);
        question_history_add($id, (int)$admin['id'], 'deleted', (string)$question['status'], (string)$question['status']);
        audit_log((int)$admin['id'], 'question.deleted', 'question', $id);
        flash('success', 'Pergunta movida para exclusão lógica.');
    } elseif ($action === 'restore') {
        $stmt = db()->prepare('UPDATE questions SET deleted_at=NULL,deleted_by=NULL WHERE id=?');
        $stmt->execute([$id]);
        question_history_add($id, (int)$admin['id'], 'restored', (string)$question['status'], (string)$question['status']);
        audit_log((int)$admin['id'], 'question.restored', 'question', $id);
        flash('success', 'Pergunta restaurada.');
    } elseif ($action === 'add_evidence') {
        $type = (string)Request::post('evidence_type', Request::STRING, 'url');
        $title = trim((string)Request::post('evidence_title', Request::STRING, ''));
        $reference = trim((string)Request::post('reference_value', Request::STRING, ''));
        $allowedTypes = ['url', 'document', 'image', 'video', 'text'];

        if (!in_array($type, $allowedTypes, true) || strlen($title) < 2) {
            flash('error', 'Informe um título e tipo de evidência válidos.');
        } elseif ($type === 'url' && !filter_var($reference, FILTER_VALIDATE_URL)) {
            flash('error', 'Informe uma URL válida.');
        } elseif ($type === 'text' && $reference === '') {
            flash('error', 'Informe o conteúdo textual da evidência.');
        } elseif (in_array($type, ['document', 'image', 'video'], true)) {
            $file = $_FILES['evidence_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$file['size'] > 10 * 1024 * 1024) {
                flash('error', 'Envie um arquivo válido de até 10 MB.');
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: '';
                $allowedMime = [
                    'document' => ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/plain'],
                    'image' => ['image/jpeg','image/png','image/webp'],
                    'video' => ['video/mp4','video/webm'],
                ];
                if (!in_array($mime, $allowedMime[$type], true)) {
                    flash('error', 'Tipo de arquivo não permitido para essa evidência.');
                } else {
                    $dir = dirname(__DIR__) . '/storage/evidence';
                    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                        throw new RuntimeException('Não foi possível criar o diretório de evidências.');
                    }
                    $storage = bin2hex(random_bytes(24));
                    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $storage)) {
                        throw new RuntimeException('Não foi possível armazenar a evidência.');
                    }
                    $stmt = db()->prepare("
                        INSERT INTO question_evidence
                            (question_id,user_id,evidence_type,title,reference_value,storage_name,original_name,mime_type)
                        VALUES (?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([$id, (int)$admin['id'], $type, $title, $reference ?: null, $storage, basename((string)$file['name']), $mime]);
                    question_history_add($id, (int)$admin['id'], 'evidence_added', (string)$question['status'], (string)$question['status'], $title);
                    audit_log((int)$admin['id'], 'question.evidence_added', 'question', $id, $title);
                    flash('success', 'Evidência anexada.');
                }
            }
        } else {
            $stmt = db()->prepare("
                INSERT INTO question_evidence (question_id,user_id,evidence_type,title,reference_value)
                VALUES (?,?,?,?,?)
            ");
            $stmt->execute([$id, (int)$admin['id'], $type, $title, $reference]);
            question_history_add($id, (int)$admin['id'], 'evidence_added', (string)$question['status'], (string)$question['status'], $title);
            audit_log((int)$admin['id'], 'question.evidence_added', 'question', $id, $title);
            flash('success', 'Evidência adicionada.');
        }
    } elseif ($action === 'remove_evidence') {
        $evidenceId = (int)Request::post('evidence_id', Request::INT, 0);
        $stmt = db()->prepare('SELECT id,title,storage_name FROM question_evidence WHERE id=? AND question_id=?');
        $stmt->execute([$evidenceId, $id]);
        $evidence = $stmt->fetch();
        if ($evidence) {
            if (!empty($evidence['storage_name'])) {
                $file = dirname(__DIR__) . '/storage/evidence/' . basename((string)$evidence['storage_name']);
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            $delete = db()->prepare('DELETE FROM question_evidence WHERE id=? AND question_id=?');
            $delete->execute([$evidenceId, $id]);
            question_history_add($id, (int)$admin['id'], 'evidence_removed', (string)$question['status'], (string)$question['status'], (string)$evidence['title']);
            audit_log((int)$admin['id'], 'question.evidence_removed', 'question', $id, (string)$evidence['title']);
            flash('success', 'Evidência removida.');
        }
    }

    redirect('admin/question.php?id=' . $id);
}

$question = $load($id);
$focusRows = db()->query('SELECT id,name,abbr,active FROM focuses ORDER BY active DESC,name')->fetchAll();
$categoryRows = db()->query('SELECT id,name,active FROM categories ORDER BY active DESC,name')->fetchAll();
$targetRows = db()->query('SELECT id,name,active FROM targets ORDER BY active DESC,name')->fetchAll();

$respondentStmt = db()->prepare("
    SELECT u.id,u.name,u.email
    FROM target_users tu
    JOIN users u ON u.id=tu.user_id
    WHERE tu.target_id=? AND u.active=1
    ORDER BY u.name
");
$respondentStmt->execute([(int)($question['target_id'] ?? 0)]);
$respondentRows = $respondentStmt->fetchAll();

$historyStmt = db()->prepare("
    SELECT h.*,u.name actor_name
    FROM question_history h
    LEFT JOIN users u ON u.id=h.actor_user_id
    WHERE h.question_id=?
    ORDER BY h.created_at DESC,h.id DESC
    LIMIT 100
");
$historyStmt->execute([$id]);
$history = array_map(static fn(array $row): array => [
    'label' => question_event_label((string)$row['event_type']),
    'details' => (string)($row['details'] ?? ''),
    'actor' => (string)($row['actor_name'] ?? 'Sistema'),
    'status_change' => !empty($row['old_status']) && !empty($row['new_status']) && $row['old_status'] !== $row['new_status']
        ? status_label((string)$row['old_status']) . ' → ' . status_label((string)$row['new_status'])
        : '',
    'created_at' => date('d/m/Y H:i', strtotime((string)$row['created_at'])),
], $historyStmt->fetchAll());

$evidenceStmt = db()->prepare('SELECT * FROM question_evidence WHERE question_id=? ORDER BY created_at DESC');
$evidenceStmt->execute([$id]);
$evidence = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'type' => (string)$row['evidence_type'],
    'title' => (string)$row['title'],
    'reference' => (string)($row['reference_value'] ?? ''),
    'has_reference' => !empty($row['reference_value']),
    'has_file' => !empty($row['storage_name']),
    'download_url' => !empty($row['storage_name']) ? base_url('evidence.php?id=' . (int)$row['id']) : '',
    'original_name' => (string)($row['original_name'] ?? ''),
    'created_at' => date('d/m/Y H:i', strtotime((string)$row['created_at'])),
], $evidenceStmt->fetchAll());

$voteStmt = db()->prepare("
    SELECT u.name,u.email,v.created_at
    FROM question_votes v JOIN users u ON u.id=v.user_id
    WHERE v.question_id=? ORDER BY v.created_at DESC LIMIT 100
");
$voteStmt->execute([$id]);
$voters = array_map(static fn(array $row): array => [
    'name' => (string)$row['name'],
    'email' => (string)$row['email'],
    'created_at' => date('d/m/Y H:i', strtotime((string)$row['created_at'])),
], $voteStmt->fetchAll());

$saveStmt = db()->prepare('SELECT COUNT(*) FROM question_saves WHERE question_id=?');
$saveStmt->execute([$id]);
$reportStmt = db()->prepare("SELECT COUNT(*) FROM question_reports WHERE question_id=? AND status='pending'");
$reportStmt->execute([$id]);

$duplicatesStmt = db()->prepare("
    SELECT id,title FROM questions WHERE id<>? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 100
");
$duplicatesStmt->execute([$id]);
$duplicates = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'title' => '#' . (int)$row['id'] . ' · ' . (string)$row['title'],
    'selected' => (int)$row['id'] === (int)($question['duplicate_of'] ?? 0),
], $duplicatesStmt->fetchAll());

$statusOptions = [];
foreach (['open' => 'Aberta', 'waiting' => 'Aguardando resposta', 'taken' => 'Pauta assumida', 'answered' => 'Resposta registrada', 'archived' => 'Arquivada'] as $value => $label) {
    $statusOptions[] = ['value' => $value, 'label' => $label, 'selected' => $question['status'] === $value];
}

render_page('admin/question', [
    'question' => [
        'id' => $id,
        'title' => (string)$question['title'],
        'body' => (string)$question['body'],
        'answer_text' => (string)($question['answer_text'] ?? ''),
        'author_name' => (string)$question['author_name'],
        'author_email' => (string)$question['author_email'],
        'published' => (int)$question['published'] === 1,
        'moderation_status' => (string)$question['moderation_status'],
        'status_label' => status_label((string)$question['status']),
        'deleted' => !empty($question['deleted_at']),
        'duplicate' => !empty($question['duplicate_of']),
        'public_url' => base_url('question.php?id=' . $id),
    ],
    'focuses' => array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'label'=>(string)$row['abbr'].' · '.(string)$row['name'],'selected'=>(int)$row['id']===(int)$question['focus_id']], $focusRows),
    'categories' => array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'selected'=>(int)$row['id']===(int)$question['category_id']], $categoryRows),
    'targets' => array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'selected'=>(int)$row['id']===(int)$question['target_id']], $targetRows),
    'respondents' => array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'label'=>(string)$row['name'].' · '.(string)$row['email'],'selected'=>(int)$row['id']===(int)($question['taken_by'] ?? 0)], $respondentRows),
    'has_respondents' => $respondentRows !== [],
    'statuses' => $statusOptions,
    'history' => $history,
    'has_history' => $history !== [],
    'evidence' => $evidence,
    'has_evidence' => $evidence !== [],
    'voters' => $voters,
    'has_voters' => $voters !== [],
    'save_count' => (int)$saveStmt->fetchColumn(),
    'pending_report_count' => (int)$reportStmt->fetchColumn(),
    'duplicates' => $duplicates,
    'has_duplicates' => $duplicates !== [],
    'back_url' => base_url('admin/questions.php'),
], 'Administrar pergunta', true);
