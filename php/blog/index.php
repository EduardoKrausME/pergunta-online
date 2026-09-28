<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/layout.php';

if (!app_installed()) {
    redirect('install');
}

function blog_public_where(): string {
    return "published = 1 AND (published_at IS NULL OR published_at <= NOW())";
}

function blog_format_date(?string $value): string {
    if ($value === null || trim($value) === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable $e) {
        return '';
    }

    $months = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    return $date->format('j') . ' de ' . $months[(int)$date->format('n')] . ' de ' . $date->format('Y');
}

function blog_excerpt(array $post, int $limit = 220): string {
    $excerpt = trim((string)($post['excerpt'] ?? ''));
    if ($excerpt !== '') {
        return $excerpt;
    }

    $text = preg_replace('/\s+/u', ' ', trim(strip_tags((string)($post['content'] ?? '')))) ?? '';
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit
            ? rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…'
            : $text;
    }

    return strlen($text) > $limit ? rtrim(substr($text, 0, $limit - 3)) . '...' : $text;
}

$slug = trim((string)Request::get('slug', Request::STRING, ''));

if ($slug !== '') {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        http_response_code(404);
        render_page('not_found', [], 'Página não encontrada');
        exit;
    }

    $stmt = db()->prepare(
        'SELECT bp.*, u.name AS author_name
           FROM blog_posts bp
      LEFT JOIN users u ON u.id = bp.author_user_id
          WHERE bp.slug = ? AND ' . blog_public_where() . '
          LIMIT 1'
    );
    $stmt->execute([$slug]);
    $post = $stmt->fetch();

    if (!$post) {
        http_response_code(404);
        render_page('not_found', [], 'Artigo não encontrado');
        exit;
    }

    $published = (string)($post['published_at'] ?: $post['created_at']);
    render_page('blog/post', [
        'blog_url' => base_url('blog/'),
        'title' => (string)$post['title'],
        'excerpt' => blog_excerpt($post, 320),
        'content_html' => nl2br(h((string)$post['content'])),
        'published_date' => blog_format_date($published),
        'author_name' => trim((string)($post['author_name'] ?? '')),
        'has_author' => trim((string)($post['author_name'] ?? '')) !== '',
        'cover_image_url' => blog_cover_url((string)($post['cover_image_url'] ?? '')),
        'has_cover' => trim((string)($post['cover_image_url'] ?? '')) !== '',
    ], (string)$post['title']);
    exit;
}

$perPage = 9;
$page = max(1, (int)Request::get('page', Request::INT, 1));
$total = (int)db()->query('SELECT COUNT(*) FROM blog_posts WHERE ' . blog_public_where())->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    'SELECT bp.*, u.name AS author_name
       FROM blog_posts bp
  LEFT JOIN users u ON u.id = bp.author_user_id
      WHERE ' . blog_public_where() . '
   ORDER BY COALESCE(bp.published_at, bp.created_at) DESC, bp.id DESC
      LIMIT :limit OFFSET :offset'
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$posts = [];
foreach ($stmt->fetchAll() as $post) {
    $published = (string)($post['published_at'] ?: $post['created_at']);
    $cover = blog_cover_url((string)($post['cover_image_url'] ?? ''));
    $posts[] = [
        'title' => (string)$post['title'],
        'excerpt' => blog_excerpt($post),
        'url' => base_url('blog/' . rawurlencode((string)$post['slug'])),
        'published_date' => blog_format_date($published),
        'cover_image_url' => $cover,
        'has_cover' => $cover !== '',
    ];
}

$hasPrevious = $page > 1;
$hasNext = $page < $totalPages;

render_page('blog/index', [
    'posts' => $posts,
    'post_count' => $total,
    'page' => $page,
    'total_pages' => $totalPages,
    'show_pagination' => $totalPages > 1,
    'has_previous' => $hasPrevious,
    'previous_url' => $hasPrevious ? base_url('blog/?page=' . ($page - 1)) : '',
    'has_next' => $hasNext,
    'next_url' => $hasNext ? base_url('blog/?page=' . ($page + 1)) : '',
], 'Blog');
