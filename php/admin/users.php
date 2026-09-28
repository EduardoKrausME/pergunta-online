<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
$admin = require_admin();

$q = trim((string)($_GET['q'] ?? ''));
$roleFilter = (string)($_GET['role'] ?? '');
$stateFilter = (string)($_GET['state'] ?? '');

if (!in_array($roleFilter, ['', 'user', 'admin'], true)) {
    $roleFilter = '';
}
if (!in_array($stateFilter, ['', 'active', 'inactive'], true)) {
    $stateFilter = '';
}

$returnQuery = http_build_query(array_filter([
    'q' => $q,
    'role' => $roleFilter,
    'state' => $stateFilter,
], static fn(string $value): bool => $value !== ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['user_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');

    if ($id === (int)$admin['id']) {
        flash('warning', 'Você não pode desativar ou remover seu próprio acesso administrativo aqui.');
        redirect('admin/users.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
    }

    $stmt = db()->prepare('SELECT id,role,active FROM users WHERE id=?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();

    if ($target) {
        if ($action === 'toggle_active') {
            $s = db()->prepare('UPDATE users SET active=? WHERE id=?');
            $s->execute([(int)!((int)$target['active']), $id]);
            flash('success', 'Status do usuário atualizado.');
        } elseif ($action === 'toggle_role') {
            $role = $target['role'] === 'admin' ? 'user' : 'admin';
            $s = db()->prepare('UPDATE users SET role=? WHERE id=?');
            $s->execute([$role, $id]);
            flash('success', 'Perfil do usuário atualizado.');
        }
    }
    redirect('admin/users.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(name LIKE ? OR email LIKE ?)';
    $term = '%' . $q . '%';
    array_push($params, $term, $term);
}
if ($roleFilter !== '') {
    $where[] = 'role = ?';
    $params[] = $roleFilter;
}
if ($stateFilter === 'active') {
    $where[] = 'active = 1';
} elseif ($stateFilter === 'inactive') {
    $where[] = 'active = 0';
}

$sql = 'SELECT id,name,email,role,active,created_at,updated_at FROM users';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY created_at DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statsRow = db()->query("
    SELECT
        COUNT(*) AS total,
        SUM(active = 1) AS active_count,
        SUM(active = 0) AS inactive_count,
        SUM(role = 'admin') AS admin_count
    FROM users
")->fetch();

$summary = [
    ['label' => 'Usuários', 'value' => (int)$statsRow['total']],
    ['label' => 'Ativos', 'value' => (int)$statsRow['active_count']],
    ['label' => 'Desativados', 'value' => (int)$statsRow['inactive_count']],
    ['label' => 'Administradores', 'value' => (int)$statsRow['admin_count']],
];

$users = array_map(static function(array $user) use ($admin): array {
    $isActive = (int)$user['active'] === 1;
    $isAdmin = $user['role'] === 'admin';

    return [
        'id' => (int)$user['id'],
        'name' => (string)$user['name'],
        'email' => (string)$user['email'],
        'role_label' => $isAdmin ? 'Administrador' : 'Usuário',
        'role_class' => $isAdmin ? 'admin' : 'user',
        'status' => $isActive ? 'Ativo' : 'Desativado',
        'status_class' => $isActive ? 'active' : 'inactive',
        'created_at' => date('d/m/Y', strtotime((string)$user['created_at'])),
        'updated_at' => date('d/m/Y H:i', strtotime((string)$user['updated_at'])),
        'can_manage' => (int)$user['id'] !== (int)$admin['id'],
        'role_action_label' => $isAdmin ? 'Tornar usuário' : 'Tornar admin',
        'active_action_label' => $isActive ? 'Desativar' : 'Ativar',
    ];
}, $rows);

$roleFilters = [
    ['value' => '', 'label' => 'Todos os perfis', 'selected' => $roleFilter === ''],
    ['value' => 'user', 'label' => 'Usuários', 'selected' => $roleFilter === 'user'],
    ['value' => 'admin', 'label' => 'Administradores', 'selected' => $roleFilter === 'admin'],
];

$stateFilters = [
    ['value' => '', 'label' => 'Todos os status', 'selected' => $stateFilter === ''],
    ['value' => 'active', 'label' => 'Ativos', 'selected' => $stateFilter === 'active'],
    ['value' => 'inactive', 'label' => 'Desativados', 'selected' => $stateFilter === 'inactive'],
];

render_page('admin/users', [
    'users' => $users,
    'summary' => $summary,
    'result_count' => count($users),
    'search_query' => $q,
    'role_filters' => $roleFilters,
    'state_filters' => $stateFilters,
    'has_filters' => $q !== '' || $roleFilter !== '' || $stateFilter !== '',
], 'Usuários', true);
