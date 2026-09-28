<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$error = '';
try {
    $pdo = db();
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Não foi possível ler schema.sql.');
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$hasAdmin = false;
if ($error === '') {
    $hasAdmin = (bool)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && !$hasAdmin) {
    require_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = normalize_email((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
        $error = 'Informe nome, e-mail válido e uma senha com pelo menos 10 caracteres.';
    } else {
        $stmt = db()->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $adminId = (int)db()->lastInsertId();
        if (!empty($_POST['seed_demo'])) {
            $samples = [
                ['Vasco da Gama','VG','Contratações','Qual era o orçamento aprovado para contratações nesta temporada?','A comunidade quer entender como o planejamento financeiro se conecta às decisões do futebol. Qual foi o valor aprovado, quanto já foi utilizado e onde podemos consultar esses números?','Diretoria de futebol','taken',null],
                ['Corinthians','SCCP','Gestão','Quando o clube vai publicar o detalhamento das dívidas de curto prazo?','Há uma data prevista para a publicação de um relatório acessível ao torcedor, com valores, vencimentos e estratégia de pagamento?','Diretoria financeira','waiting',date('Y-m-d H:i:s', time() - 47 * 86400)],
                ['Flamengo','FLA','Categorias de base','Qual é o plano para dar mais espaço à base no time principal?','Quais critérios orientam a integração dos atletas da base e quais indicadores serão usados para acompanhar esse trabalho?','Coordenação de futebol','open',null],
                ['Palmeiras','SEP','Torcida','Como será definida a distribuição de ingressos para a próxima final?','Como os critérios de prioridade, os valores e as cotas serão comunicados para quem acompanha o clube?','Departamento de relacionamento','answered',null],
                ['Botafogo','BFR','Infraestrutura','Existe um cronograma público para as melhorias no centro de treinamento?','Quais etapas estão previstas e como o torcedor poderá acompanhar a execução e o investimento em cada fase?','Diretoria de infraestrutura','waiting',date('Y-m-d H:i:s', time() - 26 * 86400)],
                ['Grêmio','GRE','Futebol feminino','Quais são as metas de investimento para o futebol feminino em 2027?','Existe uma previsão de orçamento para estrutura, formação e equipe profissional, com metas que possam ser acompanhadas?','Diretoria de futebol feminino','open',null],
                ['Vasco da Gama','VG','Torcida','Como o torcedor pode acompanhar as decisões do conselho?','É possível disponibilizar um calendário de reuniões e resumos das decisões em um único local?','Conselho deliberativo','open',null],
                ['Flamengo','FLA','Infraestrutura','Que medidas de acessibilidade estão previstas para os dias de jogo?','Quais adaptações serão priorizadas e como o clube vai ouvir torcedores com deficiência durante o planejamento?','Operação do estádio','answered',null],
            ];
            $insert = db()->prepare('INSERT INTO questions (user_id,focus_name,focus_abbr,category,title,body,target_name,status,waiting_since,answer_text,answered_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($samples as $sample) {
                $answer = $sample[6] === 'answered' ? 'Resposta de demonstração registrada para mostrar o fechamento do ciclo da pergunta.' : null;
                $answeredAt = $sample[6] === 'answered' ? date('Y-m-d H:i:s') : null;
                $insert->execute([$adminId,$sample[0],$sample[1],$sample[2],$sample[3],$sample[4],$sample[5],$sample[6],$sample[7],$answer,$answeredAt]);
            }
        }
        flash('success', 'Administrador criado. Agora você já pode entrar.');
        redirect('login.php');
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação · Pergunta.Online</title><link rel="stylesheet" href="<?= h(base_url('assets/style.css')) ?>"></head><body><main class="auth-shell"><section class="auth-card"><a class="brand" href="<?= h(base_url('index.php')) ?>"><span class="brand-dot"></span>pergunta.online</a><div class="eyebrow">INSTALAÇÃO</div><h1>Prepare o banco e o primeiro administrador.</h1><?php if ($error): ?><div class="flash error"><?= h($error) ?></div><p class="muted">Confira host, porta, banco, usuário e senha em php/config.php.</p><?php elseif ($hasAdmin): ?><div class="flash success">A instalação já está concluída e existe pelo menos um administrador.</div><a class="button primary" href="<?= h(base_url('login.php')) ?>">Ir para o login</a><?php else: ?><p class="muted">As tabelas foram criadas. Falta apenas cadastrar o primeiro administrador.</p><form method="post" class="form-grid"><?= csrf_field() ?><label>Nome<input name="name" required maxlength="120" autocomplete="name"></label><label>E-mail<input type="email" name="email" required maxlength="190" autocomplete="email"></label><label>Senha<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label class="checkbox"><input type="checkbox" name="seed_demo" value="1" checked> Criar também as perguntas de demonstração da home atual</label><button class="button primary" type="submit">Criar administrador</button></form><?php endif; ?></section></main></body></html>
