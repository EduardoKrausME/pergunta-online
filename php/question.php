<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
if (!app_installed()) redirect('install.php');
$id=(int)($_GET['id']??0);
$viewer=current_user();
$viewerId=(int)($viewer['id']??0);
$isAdmin=(int)(($viewer['role']??'')==='admin');
$stmt=db()->prepare('SELECT q.*,u.name author_name,(SELECT COUNT(*) FROM question_votes v WHERE v.question_id=q.id) vote_count FROM questions q JOIN users u ON u.id=q.user_id WHERE q.id=? AND (q.published=1 OR q.user_id=? OR ?=1) LIMIT 1');
$stmt->execute([$id,$viewerId,$isAdmin]);
$q=$stmt->fetch();
if(!$q){http_response_code(404);render_header('Pergunta não encontrada');echo '<div class="empty">Pergunta não encontrada.</div>';render_footer();exit;}
render_header($q['title']);
?><article class="detail-card"><div class="card-top"><div class="club"><span class="badge"><?=h($q['focus_abbr'])?></span><?=h($q['focus_name'])?></div><span class="status <?=h(status_class($q['status']))?>"><?=h(status_label($q['status']))?></span></div><div class="category"><?=h($q['category'])?></div><h1><?=h($q['title'])?></h1><p class="lead"><?=nl2br(h($q['body']))?></p><div class="meta">Pergunta de <strong><?=h($q['author_name'])?></strong> para <strong><?=h($q['target_name'])?></strong> · <?=date('d/m/Y H:i',strtotime($q['created_at']))?></div><div class="detail-stats"><strong><?= (int)$q['vote_count'] ?></strong><span>endossos “Boa!”</span><?php if($q['waiting_since']): $silenceEnd=($q['status']==='answered'&&$q['answered_at'])?strtotime($q['answered_at']):time(); ?><strong><?=max(0,(int)floor(($silenceEnd-strtotime($q['waiting_since']))/86400))?></strong><span>dias de silêncio registrados</span><?php endif;?></div><?php if($q['answer_text']):?><section class="answer"><div class="eyebrow">RESPOSTA REGISTRADA</div><p><?=nl2br(h($q['answer_text']))?></p><?php if($q['answered_at']):?><small>Registrada em <?=date('d/m/Y H:i',strtotime($q['answered_at']))?></small><?php endif;?></section><?php endif;?></article><?php render_footer();?>
