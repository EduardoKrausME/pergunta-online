<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install');
}
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)Request::post('action', Request::STRING, '');
    $id = (int)Request::post('id', Request::INT, 0);

    if ($action === 'save') {
        $name = trim((string)Request::post('name', Request::STRING, ''));
        $description = trim((string)Request::post('description', Request::STRING, ''));
        $website = trim((string)Request::post('website', Request::STRING, ''));
        $email = normalize_email((string)Request::post('contact_email', Request::STRING, ''));
        if (strlen($name) < 2 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) ||
            ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL))) {
            flash('error', 'Informe nome e, quando usados, site e e-mail válidos.');
        } else {
            try {
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE targets SET name=?,description=?,website=?,contact_email=? WHERE id=?');
                    $stmt->execute([$name, $description ?: null, $website ?: null, $email ?: null, $id]);
                    $sync = db()->prepare('UPDATE questions SET target_name=? WHERE target_id=?');
                    $sync->execute([$name, $id]);
                    audit_log((int)$admin['id'], 'target.updated', 'target', $id, $name);
                } else {
                    $stmt = db()->prepare('INSERT INTO targets (name,description,website,contact_email) VALUES (?,?,?,?)');
                    $stmt->execute([$name, $description ?: null, $website ?: null, $email ?: null]);
                    $id = (int)db()->lastInsertId();
                    audit_log((int)$admin['id'], 'target.created', 'target', $id, $name);
                }
                flash('success', 'Destinatário salvo.');
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'Já existe um destinatário com esse nome.' : 'Não foi possível salvar.');
            }
        }
    } elseif ($action === 'set_active' && $id > 0) {
        $active = (bool)Request::post('active', Request::BOOL, false);
        $stmt = db()->prepare('UPDATE targets SET active=? WHERE id=?');
        $stmt->execute([$active ? 1 : 0, $id]);
        audit_log((int)$admin['id'], 'target.active_changed', 'target', $id, $active ? 'active' : 'inactive');
        flash('success', 'Disponibilidade atualizada.');
    } elseif ($action === 'assign_user' && $id > 0) {
        $userId = (int)Request::post('user_id', Request::INT, 0);
        $check = db()->prepare("SELECT id FROM users WHERE id=? AND role='respondent' AND active=1");
        $check->execute([$userId]);
        if ($check->fetch()) {
            $stmt = db()->prepare('INSERT IGNORE INTO target_users (target_id,user_id) VALUES (?,?)');
            $stmt->execute([$id, $userId]);
            audit_log((int)$admin['id'], 'target.respondent_added', 'target', $id, 'user:' . $userId);
            flash('success', 'Respondente vinculado.');
        }
    } elseif ($action === 'remove_user' && $id > 0) {
        $userId = (int)Request::post('user_id', Request::INT, 0);
        $stmt = db()->prepare('DELETE FROM target_users WHERE target_id=? AND user_id=?');
        $stmt->execute([$id, $userId]);
        audit_log((int)$admin['id'], 'target.respondent_removed', 'target', $id, 'user:' . $userId);
        flash('success', 'Vínculo removido.');
    }
    redirect('admin/targets');
}

$respondents = db()->query("SELECT id,name,email FROM users WHERE role='respondent' AND active=1 ORDER BY name")->fetchAll();
$rows = db()->query("
    SELECT t.*, COUNT(DISTINCT q.id) question_count
    FROM targets t
    LEFT JOIN questions q ON q.target_id=t.id AND q.deleted_at IS NULL
    GROUP BY t.id
    ORDER BY t.active DESC,t.name
")->fetchAll();

$assignments = [];
if ($rows !== []) {
    $assignedRows = db()->query("
        SELECT tu.target_id,u.id user_id,u.name,u.email
        FROM target_users tu
        JOIN users u ON u.id=tu.user_id
        ORDER BY u.name
    ")->fetchAll();
    foreach ($assignedRows as $assigned) {
        $assignments[(int)$assigned['target_id']][] = [
            'user_id' => (int)$assigned['user_id'],
            'name' => (string)$assigned['name'],
            'email' => (string)$assigned['email'],
        ];
    }
}

$targets = [];
foreach ($rows as $row) {
    $options = [];
    foreach ($respondents as $respondent) {
        $options[] = [
            'id' => (int)$respondent['id'],
            'label' => (string)$respondent['name'] . ' · ' . (string)$respondent['email'],
        ];
    }
    $linked = $assignments[(int)$row['id']] ?? [];
    $targets[] = [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'description' => (string)($row['description'] ?? ''),
        'website' => (string)($row['website'] ?? ''),
        'contact_email' => (string)($row['contact_email'] ?? ''),
        'question_count' => (int)$row['question_count'],
        'status' => (int)$row['active'] === 1 ? 'Ativo' : 'Inativo',
        'status_class' => (int)$row['active'] === 1 ? 'active' : 'inactive',
        'activate' => (int)$row['active'] !== 1,
        'respondents' => $linked,
        'has_respondents' => $linked !== [],
        'respondent_options' => $options,
        'has_available_respondents' => $options !== [],
    ];
}

render_page('admin/targets', [
    'targets' => $targets,
    'has_targets' => $targets !== [],
], 'Destinatários', true);
