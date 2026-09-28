<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function api_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function api_public_url(string $path = ''): string {
    global $appconfig;

    $configured = rtrim((string)($appconfig['app_url'] ?? ''), '/');
    if ($configured !== '') {
        return $configured . '/' . ltrim($path, '/');
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $root = preg_replace('~/admin/api-chatgpt/[^/]+(?:\\.php)?$~', '', $script) ?? '';
    $root = $root === '/' ? '' : rtrim($root, '/');

    return $root . '/' . ltrim($path, '/');
}

function api_require_method(array $allowed): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        api_response(['ok' => false, 'error' => 'Método HTTP não permitido.'], 405);
    }
}

function api_require_auth(): array {
    global $appconfig;

    if (!app_installed()) {
        api_response(['ok' => false, 'error' => 'A aplicação ainda não foi instalada.'], 503);
    }

    $settings = $appconfig['chatgpt_api'] ?? [];
    $configuredToken = trim((string)($settings['token'] ?? ''));
    if ($configuredToken === '' || str_starts_with($configuredToken, 'troque-')) {
        api_response([
            'ok' => false,
            'error' => 'A API ChatGPT não está configurada. Defina chatgpt_api.token em config.php.',
        ], 503);
    }

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    $receivedToken = '';
    if (preg_match('/^Bearer\\s+(.+)$/i', $authorization, $matches)) {
        $receivedToken = trim($matches[1]);
    }
    if ($receivedToken === '') {
        $receivedToken = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    }

    if ($receivedToken === '' || !hash_equals($configuredToken, $receivedToken)) {
        api_response(['ok' => false, 'error' => 'Token da API inválido.'], 401);
    }

    $configuredUserId = (int)($settings['user_id'] ?? 0);
    if ($configuredUserId > 0) {
        $stmt = db()->prepare(
            "SELECT id,name,email,role,active FROM users
             WHERE id=? AND role='admin' AND active=1 LIMIT 1"
        );
        $stmt->execute([$configuredUserId]);
    } else {
        $stmt = db()->query(
            "SELECT id,name,email,role,active FROM users
             WHERE role='admin' AND active=1 ORDER BY id LIMIT 1"
        );
    }

    $user = $stmt->fetch();
    if (!$user) {
        api_response([
            'ok' => false,
            'error' => 'Nenhum administrador ativo está disponível para assinar os cadastros da API.',
        ], 503);
    }

    return $user;
}

function api_json_body(): array {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('O JSON enviado ultrapassa o limite de 2 MB.');
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        throw new InvalidArgumentException('Envie um corpo JSON.');
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('JSON inválido: ' . $e->getMessage(), 0, $e);
    }

    if (!is_array($decoded)) {
        throw new InvalidArgumentException('O corpo da requisição precisa ser um objeto ou uma lista JSON.');
    }

    return $decoded;
}

function api_payload_items(array $payload): array {
    if (array_is_list($payload)) {
        $items = $payload;
    } elseif (array_key_exists('items', $payload)) {
        if (!is_array($payload['items']) || !array_is_list($payload['items'])) {
            throw new InvalidArgumentException('items precisa ser uma lista JSON.');
        }
        $items = $payload['items'];
    } else {
        $items = [$payload];
    }

    if ($items === [] || count($items) > 50) {
        throw new InvalidArgumentException('Envie entre 1 e 50 itens por requisição.');
    }

    foreach ($items as $item) {
        if (!is_array($item) || array_is_list($item)) {
            throw new InvalidArgumentException('Cada item precisa ser um objeto JSON.');
        }
    }

    return $items;
}

function api_text(
    array $data,
    string $key,
    int $minLength = 0,
    int $maxLength = 10000,
    bool $required = false
): string {
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        if ($required) {
            throw new InvalidArgumentException("Campo obrigatório ausente: {$key}.");
        }
        return '';
    }
    if (!is_string($data[$key])) {
        throw new InvalidArgumentException("O campo {$key} precisa ser texto.");
    }

    $value = trim($data[$key]);
    $length = strlen($value);
    if (($required && $length < $minLength) || (!$required && $value !== '' && $length < $minLength)) {
        throw new InvalidArgumentException("O campo {$key} precisa ter ao menos {$minLength} caracteres.");
    }
    if ($length > $maxLength) {
        throw new InvalidArgumentException("O campo {$key} ultrapassa {$maxLength} caracteres.");
    }

    return $value;
}

function api_bool_value(array $data, string $key, bool $default): bool {
    if (!array_key_exists($key, $data)) {
        return $default;
    }
    $value = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($value === null) {
        throw new InvalidArgumentException("O campo {$key} precisa ser booleano.");
    }
    return $value;
}

function api_upsert_focus(array $data, int $actorId): array {
    $name = api_text($data, 'name', 2, 120, true);
    $abbr = strtoupper(api_text($data, 'abbr', 1, 12, false));

    $stmt = db()->prepare('SELECT id,name,abbr,active FROM focuses WHERE name=? LIMIT 1');
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($abbr === '') {
            $abbr = (string)$existing['abbr'];
        }
        $changed = (string)$existing['abbr'] !== $abbr || (int)$existing['active'] !== 1;
        if ($changed) {
            $update = db()->prepare('UPDATE focuses SET abbr=?,active=1 WHERE id=?');
            $update->execute([$abbr, (int)$existing['id']]);
            $sync = db()->prepare('UPDATE questions SET focus_name=?,focus_abbr=? WHERE focus_id=?');
            $sync->execute([(string)$existing['name'], $abbr, (int)$existing['id']]);
            audit_log($actorId, 'api_chatgpt.focus_updated', 'focus', (int)$existing['id'], $name);
        }
        return [
            'id' => (int)$existing['id'],
            'name' => (string)$existing['name'],
            'abbr' => $abbr,
            'created' => false,
            'changed' => $changed,
        ];
    }

    if ($abbr === '') {
        throw new InvalidArgumentException('O campo focus.abbr é obrigatório para um foco novo.');
    }

    $insert = db()->prepare('INSERT INTO focuses (name,abbr,active) VALUES (?,?,1)');
    $insert->execute([$name, $abbr]);
    $id = (int)db()->lastInsertId();
    audit_log($actorId, 'api_chatgpt.focus_created', 'focus', $id, $name);

    return ['id' => $id, 'name' => $name, 'abbr' => $abbr, 'created' => true, 'changed' => true];
}

function api_upsert_category(array $data, int $actorId): array {
    $name = api_text($data, 'name', 2, 120, true);

    $stmt = db()->prepare('SELECT id,name,active FROM categories WHERE name=? LIMIT 1');
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    if ($existing) {
        $changed = (int)$existing['active'] !== 1;
        if ($changed) {
            $update = db()->prepare('UPDATE categories SET active=1 WHERE id=?');
            $update->execute([(int)$existing['id']]);
            audit_log($actorId, 'api_chatgpt.category_updated', 'category', (int)$existing['id'], $name);
        }
        return [
            'id' => (int)$existing['id'],
            'name' => (string)$existing['name'],
            'created' => false,
            'changed' => $changed,
        ];
    }

    $insert = db()->prepare('INSERT INTO categories (name,active) VALUES (?,1)');
    $insert->execute([$name]);
    $id = (int)db()->lastInsertId();
    audit_log($actorId, 'api_chatgpt.category_created', 'category', $id, $name);

    return ['id' => $id, 'name' => $name, 'created' => true, 'changed' => true];
}

function api_upsert_target(array $data, int $actorId): array {
    $name = api_text($data, 'name', 2, 190, true);
    $description = api_text($data, 'description', 0, 10000, false);
    $website = api_text($data, 'website', 0, 500, false);
    $email = normalize_email(api_text($data, 'contact_email', 0, 190, false));

    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('target.website precisa ser uma URL válida.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('target.contact_email precisa ser um e-mail válido.');
    }

    $stmt = db()->prepare(
        'SELECT id,name,description,website,contact_email,active FROM targets WHERE name=? LIMIT 1'
    );
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    if ($existing) {
        $newDescription = $description !== '' ? $description : (string)($existing['description'] ?? '');
        $newWebsite = $website !== '' ? $website : (string)($existing['website'] ?? '');
        $newEmail = $email !== '' ? $email : (string)($existing['contact_email'] ?? '');
        $changed = (int)$existing['active'] !== 1
            || $newDescription !== (string)($existing['description'] ?? '')
            || $newWebsite !== (string)($existing['website'] ?? '')
            || $newEmail !== (string)($existing['contact_email'] ?? '');

        if ($changed) {
            $update = db()->prepare(
                'UPDATE targets SET description=?,website=?,contact_email=?,active=1 WHERE id=?'
            );
            $update->execute([
                $newDescription !== '' ? $newDescription : null,
                $newWebsite !== '' ? $newWebsite : null,
                $newEmail !== '' ? $newEmail : null,
                (int)$existing['id'],
            ]);
            audit_log($actorId, 'api_chatgpt.target_updated', 'target', (int)$existing['id'], $name);
        }

        return [
            'id' => (int)$existing['id'],
            'name' => (string)$existing['name'],
            'description' => $newDescription,
            'website' => $newWebsite,
            'contact_email' => $newEmail,
            'created' => false,
            'changed' => $changed,
        ];
    }

    $insert = db()->prepare(
        'INSERT INTO targets (name,description,website,contact_email,active) VALUES (?,?,?,?,1)'
    );
    $insert->execute([
        $name,
        $description !== '' ? $description : null,
        $website !== '' ? $website : null,
        $email !== '' ? $email : null,
    ]);
    $id = (int)db()->lastInsertId();
    audit_log($actorId, 'api_chatgpt.target_created', 'target', $id, $name);

    return [
        'id' => $id,
        'name' => $name,
        'description' => $description,
        'website' => $website,
        'contact_email' => $email,
        'created' => true,
        'changed' => true,
    ];
}

function api_sources_from_item(array $item): array {
    $sources = $item['sources'] ?? [];
    if (!is_array($sources) || !array_is_list($sources) || $sources === [] || count($sources) > 20) {
        throw new InvalidArgumentException('sources precisa conter entre 1 e 20 fontes.');
    }

    $normalized = [];
    foreach ($sources as $source) {
        if (is_string($source)) {
            $url = trim($source);
            $title = '';
        } elseif (is_array($source) && !array_is_list($source)) {
            $url = api_text($source, 'url', 8, 2000, true);
            $title = api_text($source, 'title', 0, 190, false);
        } else {
            throw new InvalidArgumentException('Cada fonte precisa ser uma URL ou um objeto com url e title.');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Fonte inválida: ' . $url);
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('As fontes precisam usar HTTP ou HTTPS.');
        }
        if ($title === '') {
            $title = (string)(parse_url($url, PHP_URL_HOST) ?: 'Fonte');
        }

        $normalized[] = ['title' => $title, 'url' => $url];
    }

    return $normalized;
}

function api_add_sources(int $questionId, int $actorId, array $sources): array {
    $added = 0;
    $skipped = 0;

    $check = db()->prepare(
        "SELECT id FROM question_evidence
         WHERE question_id=? AND evidence_type='url' AND reference_value=? LIMIT 1"
    );

    foreach ($sources as $source) {
        $check->execute([$questionId, $source['url']]);
        if ($check->fetchColumn()) {
            $skipped++;
            continue;
        }

        question_store_evidence(
            $questionId,
            $actorId,
            'url',
            (string)$source['title'],
            (string)$source['url']
        );
        $added++;
    }

    if ($added > 0) {
        question_history_add(
            $questionId,
            $actorId,
            'evidence_added',
            null,
            null,
            $added . ' fonte(s) adicionada(s) pela API ChatGPT.'
        );
        audit_log($actorId, 'api_chatgpt.sources_added', 'question', $questionId, (string)$added);
    }

    return ['added' => $added, 'skipped' => $skipped];
}

function api_import_question(array $item, array $actor): array {
    foreach (['focus', 'category', 'target', 'question'] as $section) {
        if (!isset($item[$section]) || !is_array($item[$section]) || array_is_list($item[$section])) {
            throw new InvalidArgumentException("A seção {$section} é obrigatória e precisa ser um objeto.");
        }
    }

    $sources = api_sources_from_item($item);
    $question = $item['question'];
    $title = api_text($question, 'title', 15, 255, true);
    $body = api_text($question, 'body', 20, 20000, true);
    $status = api_text($question, 'status', 0, 30, false);
    $status = $status === '' ? 'open' : $status;
    $allowedStatuses = ['open', 'waiting', 'taken', 'answered', 'archived'];
    if (!in_array($status, $allowedStatuses, true)) {
        throw new InvalidArgumentException('question.status inválido.');
    }

    $answer = api_text($question, 'answer_text', 0, 20000, false);
    if ($status === 'answered' && $answer === '') {
        throw new InvalidArgumentException('question.answer_text é obrigatório quando status=answered.');
    }
    if ($status !== 'answered' && $answer !== '') {
        throw new InvalidArgumentException('question.answer_text só pode ser usado quando status=answered.');
    }

    $published = api_bool_value($question, 'published', true);
    $actorId = (int)$actor['id'];
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $focus = api_upsert_focus($item['focus'], $actorId);
        $category = api_upsert_category($item['category'], $actorId);
        $target = api_upsert_target($item['target'], $actorId);

        $duplicate = $pdo->prepare(
            'SELECT id,title,status,target_name,created_at
             FROM questions
             WHERE deleted_at IS NULL AND target_id=? AND title=? LIMIT 1'
        );
        $duplicate->execute([(int)$target['id'], $title]);
        $existing = $duplicate->fetch();

        if ($existing) {
            $sourceResult = api_add_sources((int)$existing['id'], $actorId, $sources);
            $pdo->commit();

            return [
                'id' => (int)$existing['id'],
                'created' => false,
                'duplicate' => true,
                'title' => (string)$existing['title'],
                'status' => (string)$existing['status'],
                'target' => (string)$existing['target_name'],
                'sources' => $sourceResult,
                'url' => api_public_url('question?id=' . (int)$existing['id']),
            ];
        }

        $now = date('Y-m-d H:i:s');
        $waitingSince = in_array($status, ['waiting', 'taken'], true) ? $now : null;
        $answeredAt = $status === 'answered' ? $now : null;
        $takenBy = $status === 'taken' ? $actorId : null;
        $takenAt = $status === 'taken' ? $now : null;
        $moderation = $published ? 'approved' : 'pending';

        $insert = $pdo->prepare(
            'INSERT INTO questions
                (user_id,focus_id,focus_name,focus_abbr,category_id,category,title,body,
                 target_name,target_id,status,answer_text,waiting_since,answered_at,published,
                 moderation_status,taken_by,taken_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $actorId,
            (int)$focus['id'],
            (string)$focus['name'],
            (string)$focus['abbr'],
            (int)$category['id'],
            (string)$category['name'],
            $title,
            $body,
            (string)$target['name'],
            (int)$target['id'],
            $status,
            $answer !== '' ? $answer : null,
            $waitingSince,
            $answeredAt,
            $published ? 1 : 0,
            $moderation,
            $takenBy,
            $takenAt,
        ]);
        $questionId = (int)$pdo->lastInsertId();

        question_history_add(
            $questionId,
            $actorId,
            'created',
            null,
            $status,
            'Criada automaticamente pela API ChatGPT.'
        );
        audit_log($actorId, 'api_chatgpt.question_created', 'question', $questionId, $title);

        $sourceResult = api_add_sources($questionId, $actorId, $sources);
        $pdo->commit();

        return [
            'id' => $questionId,
            'created' => true,
            'duplicate' => false,
            'title' => $title,
            'status' => $status,
            'focus' => $focus,
            'category' => $category,
            'target' => $target,
            'sources' => $sourceResult,
            'url' => api_public_url('question?id=' . $questionId),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function api_handle_exception(Throwable $e): never {
    if ($e instanceof InvalidArgumentException) {
        api_response(['ok' => false, 'error' => $e->getMessage()], 422);
    }

    error_log('api-chatgpt: ' . $e->getMessage());
    api_response(['ok' => false, 'error' => 'Erro interno ao processar a API.'], 500);
}
