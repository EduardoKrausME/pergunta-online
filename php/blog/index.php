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

    $currentTagsStmt = db()->prepare('SELECT tag,tag_slug FROM blog_post_tags WHERE post_id=? ORDER BY tag');
    $currentTagsStmt->execute([(int)$post['id']]);
    $currentTags = $currentTagsStmt->fetchAll();
    $currentTagSlugs = array_column($currentTags, 'tag_slug');

    $sourcesStmt = db()->prepare('SELECT title,url FROM blog_post_sources WHERE post_id=? ORDER BY id');
    $sourcesStmt->execute([(int)$post['id']]);
    $sources = $sourcesStmt->fetchAll();

    $relatedStmt = db()->prepare(
        'SELECT id,title,slug,excerpt,content,cover_image_url,cover_image_alt,category,published_at,created_at
           FROM blog_posts
          WHERE id<>? AND published=1 AND (published_at IS NULL OR published_at<=NOW())
       ORDER BY COALESCE(published_at,created_at) DESC,id DESC
          LIMIT 30'
    );
    $relatedStmt->execute([(int)$post['id']]);
    $relatedRows = $relatedStmt->fetchAll();

    $candidateIds = array_map(static fn(array $row): int => (int)$row['id'], $relatedRows);
    $candidateTags = [];
    if ($candidateIds !== []) {
        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $tagStmt = db()->prepare(
            'SELECT post_id,tag_slug FROM blog_post_tags WHERE post_id IN (' . $placeholders . ')'
        );
        $tagStmt->execute($candidateIds);
        foreach ($tagStmt->fetchAll() as $row) {
            $candidateTags[(int)$row['post_id']][] = (string)$row['tag_slug'];
        }
    }

    $stopWords = ['para','como','mais','menos','sobre','entre','quando','onde','porque','pela','pelo','pelos','pelas','uma','com','sem','que','por','dos','das','nas','nos','aos','de'];
    $sourceTitle = function_exists('mb_strtolower')
        ? mb_strtolower((string)$post['title'], 'UTF-8')
        : strtolower((string)$post['title']);
    $words = preg_split('/[^\p{L}\p{N}]+/u', $sourceTitle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $keywords = array_values(array_unique(array_filter(
        $words,
        static fn(string $word): bool => strlen($word) >= 4 && !in_array($word, $stopWords, true)
    )));

    $currentCategory = trim((string)($post['category'] ?? ''));
    foreach ($relatedRows as &$candidate) {
        $score = 0;

        if (
            $currentCategory !== '' &&
            strcasecmp($currentCategory, trim((string)($candidate['category'] ?? ''))) === 0
        ) {
            $score += 4;
        }

        $sharedTags = array_intersect(
            $currentTagSlugs,
            $candidateTags[(int)$candidate['id']] ?? []
        );
        $score += count($sharedTags) * 6;

        $text = (string)$candidate['title'] . ' ' . (string)($candidate['excerpt'] ?? '');
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $score++;
            }
        }

        $candidate['_score'] = $score;
    }
    unset($candidate);

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
            'cover_image_alt' => trim((string)($candidate['cover_image_alt'] ?? '')) ?: (string)$candidate['title'],
        ];
    }

    $metaTitle = trim((string)($post['meta_title'] ?? ''));
    $metaDescription = trim((string)($post['meta_description'] ?? ''));
    if ($metaDescription === '') {
        $metaDescription = blog_excerpt($post, 160);
    }
    $canonicalUrl = trim((string)($post['canonical_url'] ?? ''));
    if ($canonicalUrl === '') {
        $canonicalUrl = base_url('blog/' . rawurlencode((string)$post['slug']));
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
        'cover_image_alt' => trim((string)($post['cover_image_alt'] ?? '')) ?: (string)$post['title'],
        'cover_image_credit' => trim((string)($post['cover_image_credit'] ?? '')),
        'has_image_credit' => trim((string)($post['cover_image_credit'] ?? '')) !== '',
        'category' => trim((string)($post['category'] ?? '')),
        'has_category' => trim((string)($post['category'] ?? '')) !== '',
        'tags' => $currentTags,
        'has_tags' => $currentTags !== [],
        'sources' => $sources,
        'has_sources' => $sources !== [],
        'meta_description' => $metaDescription,
        'canonical_url' => $canonicalUrl,
        'related_posts' => $relatedContext,
        'has_related_posts' => $relatedContext !== [],
    ] + ($metaTitle !== '' ? ['page_title' => $metaTitle] : []), (string)$post['title']);
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
