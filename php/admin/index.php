<?php

declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) redirect('install.php');
require_admin();
$stats=[
 'Usuários'=>(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
 'Perguntas'=>(int)db()->query('SELECT COUNT(*) FROM questions')->fetchColumn(),
 'Aguardando resposta'=>(int)db()->query("SELECT COUNT(*) FROM questions WHERE status='waiting'")->fetchColumn(),
 'Respondidas'=>(int)db()->query("SELECT COUNT(*) FROM questions WHERE status='answered'")->fetchColumn(),
];
$latest=db()->query('SELECT q.id,q.title,q.status,q.created_at,u.name author_name FROM questions q JOIN users u ON u.id=q.user_id ORDER BY q.created_at DESC LIMIT 8')->fetchAll();
render_header('Administração',true);
?><section class="admin-head"><div><div class="eyebrow">PAINEL</div><h1>Administração</h1><p class="muted">O que está acontecendo no produto agora.</p></div></section><div class="stats-grid"><?php foreach($stats as $label=>$value):?><div class="stat-card"><strong><?=$value?></strong><span><?=h($label)?></span></div><?php endforeach;?></div><section class="panel"><div class="panel-head"><h2>Perguntas recentes</h2><a class="button ghost" href="<?=h(base_url('admin/questions.php'))?>">Gerenciar</a></div><div class="table-wrap"><table><thead><tr><th>Pergunta</th><th>Autor</th><th>Status</th><th>Data</th></tr></thead><tbody><?php foreach($latest as $q):?><tr><td><a href="<?=h(base_url('question.php?id='.$q['id']))?>"><?=h($q['title'])?></a></td><td><?=h($q['author_name'])?></td><td><?=h(status_label($q['status']))?></td><td><?=date('d/m/Y H:i',strtotime($q['created_at']))?></td></tr><?php endforeach;?></tbody></table></div></section><?php render_footer();?>
