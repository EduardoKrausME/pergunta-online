<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

if (!app_installed()) redirect('install.php');

$user=current_user();
$q=trim((string)($_GET['q']??''));
$focus=trim((string)($_GET['focus']??''));
$category=trim((string)($_GET['category']??''));
$tab=(string)($_GET['tab']??'hot');

$where=['q.published = 1',"q.status <> 'archived'"];$params=[];
if($q!==''){$where[]='(q.title LIKE ? OR q.body LIKE ? OR q.focus_name LIKE ? OR q.target_name LIKE ?)';$term='%'.$q.'%';array_push($params,$term,$term,$term,$term);}
if($focus!==''){$where[]='q.focus_name = ?';$params[]=$focus;}
if($category!==''){$where[]='q.category = ?';$params[]=$category;}
if($tab==='answered')$where[]="q.status = 'answered'";
$order=$tab==='recent'?'q.created_at DESC':'vote_count DESC, q.created_at DESC';
$sql='SELECT q.*, u.name AS author_name, COUNT(DISTINCT v.user_id) AS vote_count FROM questions q JOIN users u ON u.id=q.user_id LEFT JOIN question_votes v ON v.question_id=q.id WHERE '.implode(' AND ',$where).' GROUP BY q.id ORDER BY '.$order.' LIMIT 60';
$stmt=db()->prepare($sql);$stmt->execute($params);$questions=$stmt->fetchAll();
$categories=db()->query("SELECT DISTINCT category FROM questions WHERE published=1 AND status <> 'archived' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$focuses=db()->query("SELECT focus_name, focus_abbr, COUNT(*) qty FROM questions WHERE published=1 AND status <> 'archived' GROUP BY focus_name, focus_abbr ORDER BY qty DESC, focus_name LIMIT 8")->fetchAll();

$voted=[];$saved=[];
if($user){
    $s=db()->prepare('SELECT question_id FROM question_votes WHERE user_id = ?');$s->execute([$user['id']]);$voted=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
    $s=db()->prepare('SELECT question_id FROM question_saves WHERE user_id = ?');$s->execute([$user['id']]);$saved=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
}

render_header();
?>
<section class="hero"><div class="eyebrow">A COMUNIDADE PERGUNTA. A HISTÓRIA FICA.</div><h1>O que merece<br>uma resposta?</h1><p>Encontre uma boa pergunta, ajude a levá-la mais longe e mantenha o histórico público.</p><form class="search" method="get"><input type="search" name="q" value="<?=h($q)?>" placeholder="Busque uma pergunta, foco ou pessoa..."><button type="submit">Buscar</button></form></section>
<?php if($focuses):?><div class="radar"><span class="radar-label">Focos em destaque</span><?php foreach($focuses as $item):?><a class="chip" href="?focus=<?=urlencode($item['focus_name'])?>"><?=h($item['focus_abbr'])?> <?=h($item['focus_name'])?></a><?php endforeach;?><a class="chip" href="<?=h(base_url('index.php'))?>">Ver todos</a></div><?php endif;?>
<section class="section-head"><div><h2>Perguntas em movimento</h2><div class="muted"><?=count($questions)?> resultado(s)</div></div><div class="tabs"><a class="tab <?=$tab==='hot'?'active':''?>" href="?tab=hot">Em alta</a><a class="tab <?=$tab==='recent'?'active':''?>" href="?tab=recent">Recentes</a><a class="tab <?=$tab==='answered'?'active':''?>" href="?tab=answered">Respondidas</a></div></section>
<form class="filters" method="get"><input type="hidden" name="q" value="<?=h($q)?>"><select name="category" onchange="this.form.submit()"><option value="">Todos os assuntos</option><?php foreach($categories as $item):?><option value="<?=h($item)?>" <?=$category===$item?'selected':''?>><?=h($item)?></option><?php endforeach;?></select><?php if($q!==''||$focus!==''||$category!==''):?><a class="button ghost" href="<?=h(base_url('index.php'))?>">Limpar filtros</a><?php endif;?></form>
<div class="question-grid">
<?php foreach($questions as $question):$id=(int)$question['id'];?>
<article class="card"><div class="card-top"><div class="club"><span class="badge"><?=h($question['focus_abbr'])?></span><?=h($question['focus_name'])?></div><span class="status <?=h(status_class($question['status']))?>"><?=h(status_label($question['status']))?></span></div><div class="category"><?=h($question['category'])?></div><h3 class="question-title"><a href="<?=h(base_url('question.php?id='.$id))?>"><?=h($question['title'])?></a></h3><p class="question-copy"><?=nl2br(h($question['body']))?></p><p class="target">Para <strong><?=h($question['target_name'])?></strong> · por <?=h($question['author_name'])?></p><?php if($question['status']==='waiting'&&$question['waiting_since']):?><p class="silence-inline"><?=(int)floor((time()-strtotime($question['waiting_since']))/86400)?> dias sem resposta</p><?php endif;?><div class="card-actions"><?php if($user):?><form method="post" action="<?=h(base_url('action.php'))?>"><?=csrf_field()?><input type="hidden" name="question_id" value="<?=$id?>"><input type="hidden" name="action" value="vote"><button class="action <?=in_array($id,$voted,true)?'active':''?>" type="submit">Boa! <?=(int)$question['vote_count']?></button></form><form method="post" action="<?=h(base_url('action.php'))?>"><?=csrf_field()?><input type="hidden" name="question_id" value="<?=$id?>"><input type="hidden" name="action" value="save"><button class="action save <?=in_array($id,$saved,true)?'active':''?>" type="submit"><?=in_array($id,$saved,true)?'Salva':'Salvar pergunta'?></button></form><?php else:?><a class="action" href="<?=h(base_url('login.php'))?>">Boa! <?=(int)$question['vote_count']?></a><a class="action save" href="<?=h(base_url('login.php'))?>">Salvar pergunta</a><?php endif;?></div></article>
<?php endforeach;?>
</div>
<?php if(!$questions):?><div class="empty">Nenhuma pergunta encontrada com esses filtros.</div><?php endif;?>
<section class="silence-block"><div><div class="eyebrow">CRONÔMETRO DO SILÊNCIO</div><h2>O tempo passa. A pergunta fica.</h2><p>Quando uma tentativa de contato é registrada e a resposta não vem, o silêncio também vira histórico.</p></div><div class="silence-number"><?=(int)db()->query("SELECT COUNT(*) FROM questions WHERE status='waiting' AND published=1")->fetchColumn()?><small>perguntas aguardando resposta</small></div></section>
<?php render_footer();?>
