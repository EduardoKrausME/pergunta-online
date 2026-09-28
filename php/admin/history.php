<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
require_admin();

$q = trim((string)Request::get('q', Request::STRING, ''));
$entity = (string)Request::get('entity', Request::STRING, '');
$page = max(1, (int)Request::get('page', Request::INT, 1));
$perPage = 60;

if (!in_array($entity, ['', 'question', 'user', 'focus', 'category', 'target', 'report', 'settings'], true)) {
    $entity = '';
}

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(a.action LIKE ? OR a.details LIKE ? OR u.name LIKE ?)';
    $term = '%' . $q . '%';
    array_push($params, $term, $term, $term);
}
if ($entity !== '') {
    $where[] = 'a.entity_type=?';
    $params[] = $entity;
}
$whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

$count = db()->prepare('SELECT COUNT(*) FROM admin_audit_log a LEFT JOIN users u ON u.id=a.admin_user_id' . $whereSql);
$count->execute($params);
$total = (int)$count->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("
    SELECT a.*,u.name admin_name
    FROM admin_audit_log a
    LEFT JOIN users u ON u.id=a.admin_user_id
    {$whereSql}
    ORDER BY a.created_at DESC,a.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);

$rows = array_map(static function(array $row): array {
    $entityType = (string)$row['entity_type'];
    $entityId = (int)($row['entity_id'] ?? 0);
    $url = '';
    if ($entityType === 'question' && $entityId > 0) {
        $url = base_url('admin/question.php?id=' . $entityId);
    } elseif ($entityType === 'user' && $entityId > 0) {
        $url = base_url('admin/user.php?id=' . $entityId);
    }
    return [
        'action' => (string)$row['action'],
        'entity' => $entityType,
        'entity_id' => $entityId ?: '',
        'details' => (string)($row['details'] ?? ''),
        'admin_name' => (string)($row['admin_name'] ?? 'Sistema'),
        'created_at' => date('d/m/Y H:i:s', strtotime((string)$row['created_at'])),
        'has_url' => $url !== '',
        'url' => $url,
    ];
}, $stmt->fetchAll());

$filters = [['value'=>'','label'=>'Tudo','selected'=>$entity==='']];
foreach (['question'=>'Perguntas','user'=>'Usuários','focus'=>'Focos','category'=>'Categorias','target'=>'Destinatários','report'=>'Denúncias','settings'=>'Configurações'] as $value=>$label) {
    $filters[] = ['value'=>$value,'label'=>$label,'selected'=>$entity===$value];
}

$base = ['q'=>$q,'entity'=>$entity];
$prev = $base; $prev['page'] = max(1,$page-1);
$next = $base; $next['page'] = min($totalPages,$page+1);

render_page('admin/history', [
    'rows' => $rows,
    'has_rows' => $rows !== [],
    'search_query' => $q,
    'filters' => $filters,
    'total' => $total,
    'page' => $page,
    'total_pages' => $totalPages,
    'has_prev' => $page > 1,
    'has_next' => $page < $totalPages,
    'prev_url' => base_url('admin/history.php?' . http_build_query($prev)),
    'next_url' => base_url('admin/history.php?' . http_build_query($next)),
], 'Histórico', true);
