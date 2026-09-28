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

    if ($id > 0 && $action === 'toggle') {
        $stmt = db()->prepare('SELECT id,title,published,published_at FROM blog_posts WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $post = $stmt->fetch();
        if ($post) {
            $published = (int)$post['published'] === 1 ? 0 : 1;
            $publishedAt = $post['published_at'];
            if ($published === 1 && empty($publishedAt)) {
                $publishedAt = date('Y-m-d H:i:s');
            }
            $status = $published === 0
                ? 'draft'
                : ($publishedAt !== null && strtotime((string)$publishedAt) > time() ? 'scheduled' : 'published');
            $update = db()->prepare('UPDATE blog_posts SET published=?,status=?,published_at=? WHERE id=?');
            $update->execute([$published, $status, $publishedAt, $id]);
            audit_log((int)$admin['id'], 'blog.published_changed', 'blog_post', $id, $published ? 'published' : 'draft');
            flash('success', $published ? 'Artigo publicado.' : 'Artigo retirado de publicação.');
        }
    } elseif ($id > 0 && $action === 'delete') {
        $stmt = db()->prepare('SELECT id,title,cover_image_url FROM blog_posts WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $post = $stmt->fetch();
        if ($post) {
            $delete = db()->prepare('DELETE FROM blog_posts WHERE id=?');
            $delete->execute([$id]);
            blog_delete_uploaded_image((string)($post['cover_image_url'] ?? ''));
            audit_log((int)$admin['id'], 'blog.deleted', 'blog_post', $id, (string)$post['title']);
            flash('success', 'Artigo excluído.');
        }
    }

    redirect('admin/blog');
}

$q = trim((string)Request::get('q', Request::STRING, ''));
$status = trim((string)Request::get('status', Request::STRING, ''));
$page = max(1, (int)Request::get('page', Request::INT, 1));
$perPage = 30;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(bp.title LIKE ? OR bp.slug LIKE ? OR bp.excerpt LIKE ?)';
    $term = '%' . $q . '%';
    array_push($params, $term, $term, $term);
}
if ($status === 'published') {
    $where[] = 'bp.published=1 AND (bp.published_at IS NULL OR bp.published_at<=NOW())';
} elseif ($status === 'scheduled') {
    $where[] = 'bp.published=1 AND bp.published_at>NOW()';
} elseif ($status === 'draft') {
    $where[] = 'bp.published=0';
}

$count = db()->prepare('SELECT COUNT(*) FROM blog_posts bp WHERE ' . implode(' AND ', $where));
$count->execute($params);
$total = (int)$count->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT bp.*,u.name AS author_name FROM blog_posts bp
        LEFT JOIN users u ON u.id=bp.author_user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY bp.updated_at DESC,bp.id DESC
        LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = db()->prepare($sql);
$stmt->execute($params);

$posts = [];
foreach ($stmt->fetchAll() as $post) {
    $isPublished = (int)$post['published'] === 1;
    $publishedAt = trim((string)($post['published_at'] ?? ''));
    $scheduled = $isPublished && $publishedAt !== '' && strtotime($publishedAt) > time();
    $visible = $isPublished && !$scheduled;

    $statusLabel = 'Rascunho';
    $statusClass = 'hidden';
    if ($scheduled) {
        $statusLabel = 'Agendado';
        $statusClass = 'waiting';
    } elseif ($visible) {
        $statusLabel = 'Publicado';
        $statusClass = 'published';
    }

    $cover = blog_cover_url((string)($post['cover_image_url'] ?? ''));
    $posts[] = [
        'id' => (int)$post['id'],
        'title' => (string)$post['title'],
        'slug' => (string)$post['slug'],
        'author_name' => trim((string)($post['author_name'] ?? '')) ?: 'Sem autor',
        'updated_at' => date('d/m/Y H:i', strtotime((string)$post['updated_at'])),
        'publication_date' => $publishedAt !== '' ? date('d/m/Y H:i', strtotime($publishedAt)) : '—',
        'status_label' => $statusLabel,
        'status_class' => $statusClass,
        'toggle_label' => $isPublished ? 'Despublicar' : 'Publicar',
        'edit_url' => base_url('admin/blog-edit?id=' . (int)$post['id']),
        'view_url' => $visible ? base_url('blog/' . rawurlencode((string)$post['slug'])) : '',
        'can_view' => $visible,
        'cover_image_url' => $cover,
        'has_cover' => $cover !== '',
    ];
}

$query = ['q' => $q, 'status' => $status];
$previousQuery = array_filter($query, static fn($v): bool => $v !== '');
$nextQuery = $previousQuery;
$previousQuery['page'] = max(1, $page - 1);
$nextQuery['page'] = min($totalPages, $page + 1);

render_page('admin/blog', [
    'q' => $q,
    'status_all' => $status === '',
    'status_published' => $status === 'published',
    'status_scheduled' => $status === 'scheduled',
    'status_draft' => $status === 'draft',
    'posts' => $posts,
    'has_posts' => $posts !== [],
    'total' => $total,
    'new_url' => base_url('admin/blog-edit'),
    'form_action' => base_url('admin/blog'),
    'page' => $page,
    'total_pages' => $totalPages,
    'show_pagination' => $totalPages > 1,
    'has_previous' => $page > 1,
    'previous_url' => base_url('admin/blog?' . http_build_query($previousQuery)),
    'has_next' => $page < $totalPages,
    'next_url' => base_url('admin/blog?' . http_build_query($nextQuery)),
], 'Blog', true);
