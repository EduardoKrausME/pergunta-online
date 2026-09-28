<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

if (!app_installed()) {
    redirect('install');
}
$admin = require_admin();

$id = (int)Request::get('id', Request::INT, 0);
$post = null;
if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM blog_posts WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) {
        http_response_code(404);
        render_page('not_found', [], 'Artigo não encontrado');
        exit;
    }
}

$form = [
    'id' => $id,
    'title' => (string)($post['title'] ?? ''),
    'slug' => (string)($post['slug'] ?? ''),
    'excerpt' => (string)($post['excerpt'] ?? ''),
    'content' => (string)($post['content'] ?? ''),
    'published' => (int)($post['published'] ?? 0) === 1,
    'published_at' => blog_datetime_input((string)($post['published_at'] ?? '')),
    'cover_image_url' => blog_cover_url((string)($post['cover_image_url'] ?? '')),
    'cover_image_raw' => (string)($post['cover_image_url'] ?? ''),
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $postedId = (int)Request::post('id', Request::INT, 0);
    if ($postedId !== $id) {
        http_response_code(400);
        exit('Identificador do artigo inválido.');
    }

    $form['title'] = trim((string)Request::post('title', Request::STRING, ''));
    $form['slug'] = trim((string)Request::post('slug', Request::STRING, ''));
    $form['excerpt'] = trim((string)Request::post('excerpt', Request::STRING, ''));
    $form['content'] = trim((string)Request::post('content', Request::STRING, ''));
    $form['published'] = (bool)Request::post('published', Request::BOOL, false);
    $form['published_at'] = trim((string)Request::post('published_at', Request::STRING, ''));
    $removeImage = (bool)Request::post('remove_image', Request::BOOL, false);

    if (strlen($form['title']) < 3 || strlen($form['title']) > 255) {
        $errors[] = ['message' => 'O título precisa ter entre 3 e 255 caracteres.'];
    }
    if ($form['content'] === '') {
        $errors[] = ['message' => 'Escreva o conteúdo do artigo.'];
    }

    try {
        $publishedAt = blog_datetime_database($form['published_at']);
        if ($form['published'] && $publishedAt === null) {
            $publishedAt = date('Y-m-d H:i:s');
            $form['published_at'] = blog_datetime_input($publishedAt);
        }
    } catch (InvalidArgumentException $e) {
        $publishedAt = null;
        $errors[] = ['message' => $e->getMessage()];
    }

    $slugSource = $form['slug'] !== '' ? $form['slug'] : $form['title'];
    $slug = blog_unique_slug(db(), $slugSource, $id);
    $form['slug'] = $slug;

    $oldCover = (string)($post['cover_image_url'] ?? '');
    $newCover = $removeImage ? '' : $oldCover;
    $uploadedCover = '';

    $upload = $_FILES['cover_image'] ?? null;
    $hasUpload = is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($errors === [] && $hasUpload) {
        try {
            $uploadedCover = blog_upload_image($upload);
            $newCover = $uploadedCover;
        } catch (RuntimeException $e) {
            $errors[] = ['message' => $e->getMessage()];
        }
    }

    if ($errors === []) {
        try {
            if ($id > 0) {
                $stmt = db()->prepare(
                    'UPDATE blog_posts
                        SET title=?,slug=?,excerpt=?,content=?,cover_image_url=?,published=?,published_at=?
                      WHERE id=?'
                );
                $stmt->execute([
                    $form['title'],
                    $slug,
                    $form['excerpt'] !== '' ? $form['excerpt'] : null,
                    $form['content'],
                    $newCover !== '' ? $newCover : null,
                    $form['published'] ? 1 : 0,
                    $publishedAt,
                    $id,
                ]);
                audit_log((int)$admin['id'], 'blog.updated', 'blog_post', $id, $form['title']);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO blog_posts
                        (author_user_id,title,slug,excerpt,content,cover_image_url,published,published_at)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    (int)$admin['id'],
                    $form['title'],
                    $slug,
                    $form['excerpt'] !== '' ? $form['excerpt'] : null,
                    $form['content'],
                    $newCover !== '' ? $newCover : null,
                    $form['published'] ? 1 : 0,
                    $publishedAt,
                ]);
                $id = (int)db()->lastInsertId();
                audit_log((int)$admin['id'], 'blog.created', 'blog_post', $id, $form['title']);
            }

            if ($oldCover !== '' && $oldCover !== $newCover) {
                blog_delete_uploaded_image($oldCover);
            }

            flash('success', 'Artigo salvo.');
            redirect('admin/blog-edit?id=' . $id);
        } catch (Throwable $e) {
            if ($uploadedCover !== '') {
                blog_delete_uploaded_image($uploadedCover);
            }
            $errors[] = ['message' => 'Não foi possível salvar o artigo. Verifique os dados e tente novamente.'];
        }
    }

    $form['cover_image_raw'] = $newCover;
    $form['cover_image_url'] = blog_cover_url($newCover);
}

$hasCover = trim((string)$form['cover_image_raw']) !== '';
$isPublishedNow = $form['published'] && (
    $form['published_at'] === '' ||
    strtotime(str_replace('T', ' ', $form['published_at'])) <= time()
);

render_page('admin/blog_edit', [
    'editor_title' => $id > 0 ? 'Editar artigo' : 'Novo artigo',
    'editor_description' => $id > 0
        ? 'Atualize conteúdo, capa, URL e publicação.'
        : 'Crie uma nova publicação para o blog.',
    'form_action' => base_url('admin/blog-edit' . ($id > 0 ? '?id=' . $id : '')),
    'list_url' => base_url('admin/blog'),
    'id' => $id,
    'is_edit' => $id > 0,
    'title' => $form['title'],
    'slug' => $form['slug'],
    'excerpt' => $form['excerpt'],
    'content' => $form['content'],
    'published' => $form['published'],
    'published_at' => $form['published_at'],
    'has_cover' => $hasCover,
    'cover_image_url' => $form['cover_image_url'],
    'errors' => $errors,
    'view_url' => $isPublishedNow && $form['slug'] !== '' ? base_url('blog/' . rawurlencode($form['slug'])) : '',
    'can_view' => $isPublishedNow && $form['slug'] !== '',
], $id > 0 ? 'Editar artigo' : 'Novo artigo', true);
