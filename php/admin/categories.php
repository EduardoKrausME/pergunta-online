<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)Request::post('action', Request::STRING, '');
    $id = (int)Request::post('id', Request::INT, 0);

    if ($action === 'save') {
        $name = trim((string)Request::post('name', Request::STRING, ''));
        if (strlen($name) < 2) {
            flash('error', 'Informe o nome da categoria.');
        } else {
            try {
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE categories SET name=? WHERE id=?');
                    $stmt->execute([$name, $id]);
                    $sync = db()->prepare('UPDATE questions SET category=? WHERE category_id=?');
                    $sync->execute([$name, $id]);
                    audit_log((int)$admin['id'], 'category.updated', 'category', $id, $name);
                } else {
                    $stmt = db()->prepare('INSERT INTO categories (name) VALUES (?)');
                    $stmt->execute([$name]);
                    $id = (int)db()->lastInsertId();
                    audit_log((int)$admin['id'], 'category.created', 'category', $id, $name);
                }
                flash('success', 'Categoria salva.');
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'Já existe uma categoria com esse nome.' : 'Não foi possível salvar a categoria.');
            }
        }
    } elseif ($action === 'set_active' && $id > 0) {
        $active = (bool)Request::post('active', Request::BOOL, false);
        $stmt = db()->prepare('UPDATE categories SET active=? WHERE id=?');
        $stmt->execute([$active ? 1 : 0, $id]);
        audit_log((int)$admin['id'], 'category.active_changed', 'category', $id, $active ? 'active' : 'inactive');
        flash('success', 'Disponibilidade da categoria atualizada.');
    }
    redirect('admin/categories.php');
}

$rows = db()->query("
    SELECT c.*, COUNT(q.id) question_count
    FROM categories c
    LEFT JOIN questions q ON q.category_id=c.id AND q.deleted_at IS NULL
    GROUP BY c.id
    ORDER BY c.active DESC,c.name
")->fetchAll();

$items = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'name' => (string)$row['name'],
    'question_count' => (int)$row['question_count'],
    'active' => (int)$row['active'] === 1,
    'status' => (int)$row['active'] === 1 ? 'Ativa' : 'Inativa',
    'status_class' => (int)$row['active'] === 1 ? 'active' : 'inactive',
    'activate' => (int)$row['active'] !== 1,
], $rows);

render_page('admin/catalog', [
    'eyebrow' => 'CATEGORIAS',
    'title' => 'Assuntos disponíveis',
    'description' => 'Mantenha os assuntos consistentes para busca, filtros e relatórios.',
    'item_label' => 'Categoria',
    'items' => $items,
    'has_items' => $items !== [],
    'is_focus' => false,
    'form_action' => base_url('admin/categories.php'),
], 'Categorias', true);
