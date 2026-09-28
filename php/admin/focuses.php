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
        $abbr = strtoupper(trim((string)Request::post('abbr', Request::STRING, '')));
        if (strlen($name) < 2 || $abbr === '') {
            flash('error', 'Informe nome e sigla do foco.');
        } else {
            try {
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE focuses SET name=?,abbr=? WHERE id=?');
                    $stmt->execute([$name, substr($abbr, 0, 12), $id]);
                    $sync = db()->prepare('UPDATE questions SET focus_name=?,focus_abbr=? WHERE focus_id=?');
                    $sync->execute([$name, substr($abbr, 0, 12), $id]);
                    audit_log((int)$admin['id'], 'focus.updated', 'focus', $id, $name);
                } else {
                    $stmt = db()->prepare('INSERT INTO focuses (name,abbr) VALUES (?,?)');
                    $stmt->execute([$name, substr($abbr, 0, 12)]);
                    $id = (int)db()->lastInsertId();
                    audit_log((int)$admin['id'], 'focus.created', 'focus', $id, $name);
                }
                flash('success', 'Foco salvo.');
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'Já existe um foco com esse nome.' : 'Não foi possível salvar o foco.');
            }
        }
    } elseif ($action === 'set_active' && $id > 0) {
        $active = (bool)Request::post('active', Request::BOOL, false);
        $stmt = db()->prepare('UPDATE focuses SET active=? WHERE id=?');
        $stmt->execute([$active ? 1 : 0, $id]);
        audit_log((int)$admin['id'], 'focus.active_changed', 'focus', $id, $active ? 'active' : 'inactive');
        flash('success', 'Disponibilidade do foco atualizada.');
    }
    redirect('admin/focuses');
}

$rows = db()->query("
    SELECT f.*, COUNT(q.id) question_count
    FROM focuses f
    LEFT JOIN questions q ON q.focus_id=f.id AND q.deleted_at IS NULL
    GROUP BY f.id
    ORDER BY f.active DESC,f.name
")->fetchAll();

$items = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'name' => (string)$row['name'],
    'abbr' => (string)$row['abbr'],
    'question_count' => (int)$row['question_count'],
    'active' => (int)$row['active'] === 1,
    'status' => (int)$row['active'] === 1 ? 'Ativo' : 'Inativo',
    'status_class' => (int)$row['active'] === 1 ? 'active' : 'inactive',
    'activate' => (int)$row['active'] !== 1,
], $rows);

render_page('admin/catalog', [
    'eyebrow' => 'FOCOS',
    'title' => 'Focos da comunidade',
    'description' => 'Padronize a entidade ou assunto principal para impedir variações de nome no conteúdo público.',
    'item_label' => 'Foco',
    'items' => $items,
    'has_items' => $items !== [],
    'is_focus' => true,
    'form_action' => base_url('admin/focuses'),
], 'Focos', true);
