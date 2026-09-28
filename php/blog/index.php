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
    $cover = blog_cover_url((string)($post['cover_image_url'] ?? ''));

    $relatedStmt = db()->prepare(
        'SELECT id,title,slug,excerpt,content,cover_image_url,published_at,created_at
           FROM blog_posts
          WHERE id<>? AND published=1 AND (published_at IS NULL OR published_at<=NOW())
       ORDER BY COALESCE(published_at,created_at) DESC,id DESC
          LIMIT 24'
    );
    $relatedStmt->execute([(int)$post['id']]);

    $stopWords = ['para','como','mais','menos','sobre','entre','quando','onde','porque','pela','pelo','pelos','pelas','uma','com','sem','que','por','dos','das','nas','nos','aos','de'];
    $sourceTitle = function_exists('mb_strtolower')
        ? mb_strtolower((string)$post['title'], 'UTF-8')
        : strtolower((string)$post['title']);
    $words = preg_split('/[^\p{L}\p{N}]+/u', $sourceTitle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $keywords = array_values(array_unique(array_filter(
        $words,
        static fn(string $word): bool => strlen($word) >= 4 && !in_array($word, $stopWords, true)
    )));

    $relatedRows = [];
    foreach ($relatedStmt->fetchAll() as $candidate) {
        $text = (string)$candidate['title'] . ' ' . (string)($candidate['excerpt'] ?? '');
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $score = 0;
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $score++;
            }
        }
        $candidate['_score'] = $score;
        $relatedRows[] = $candidate;
    }

    usort($relatedRows, static function(array $a, array $b): int {
        $byScore = ((int)$b['_score']) <=> ((int)$a['_score']);
        if ($byScore !== 0) {
            return $byScore;
        }
        $dateA = strtotime((string)($a['published_at'] ?: $a['created_at'])) ?: 0;
        $dateB = strtotime((string)($b['published_at'] ?: $b['created_at'])) ?: 0;
        return $dateB <=> $dateA;
    });

    $relatedContext = [];
    foreach (array_slice($relatedRows, 0, 3) as $candidate) {
        $candidateDate = (string)($candidate['published_at'] ?: $candidate['created_at']);
        $relatedContext[] = [
            'title' => (string)$candidate['title'],
            'excerpt' => blog_excerpt($candidate, 150),
            'url' => base_url('blog/' . rawurlencode((string)$candidate['slug'])),
            'published_date' => blog_format_date($candidateDate),
            'cover_image_url' => blog_cover_url((string)($candidate['cover_image_url'] ?? '')),
        ];
    }

    render_page('blog/post', [
        'blog_url' => base_url('blog/'),
        'title' => (string)$post['title'],
        'excerpt' => blog_excerpt($post, 320),
        'content_html' => blog_content_html((string)$post['content']),
        'published_date' => blog_format_date($published),
        'author_name' => trim((string)($post['author_name'] ?? '')),
        'has_author' => trim((string)($post['author_name'] ?? '')) !== '',
        'cover_image_url' => $cover,
        'has_cover' => $cover !== '',
        'related_posts' => $relatedContext,
        'has_related_posts' => $relatedContext !== [],
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
