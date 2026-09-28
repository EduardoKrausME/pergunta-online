<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
if (!app_installed()) redirect('install.php');
$user = require_login();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $focus = trim((string)($_POST['focus_name'] ?? ''));
    $abbr = strtoupper(trim((string)($_POST['focus_abbr'] ?? '')));
    $category = trim((string)($_POST['category'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    $target = trim((string)($_POST['target_name'] ?? ''));
    if (strlen($focus)<2 || strlen($abbr)<1 || strlen($category)<2 || strlen($title)<15 || strlen($body)<20 || strlen($target)<2) {
        $error = 'Preencha todos os campos. A pergunta precisa ter ao menos 15 caracteres e o contexto ao menos 20.';
    } else {
        $stmt=db()->prepare("INSERT INTO questions (user_id,focus_name,focus_abbr,category,title,body,target_name,status) VALUES (?,?,?,?,?,?,?,'open')");
        $stmt->execute([$user['id'],$focus,substr($abbr,0,12),$category,$title,$body,$target]);
        $id=(int)db()->lastInsertId();
        flash('success','Pergunta publicada. Agora ela pode ganhar relevância e histórico.');
        redirect('question.php?id='.$id);
    }
}
render_header('Nova pergunta');
?><section class="form-page"><div class="eyebrow">NOVA PERGUNTA</div><h1>Escreva uma pergunta que mereça resposta.</h1><p class="muted">Título claro, contexto suficiente e um destinatário definido. O resto a comunidade ajuda a construir.</p><?php if($error):?><div class="flash error"><?=h($error)?></div><?php endif;?><form method="post" class="form-grid wide"><?=csrf_field()?><div class="form-row"><label>Foco<input name="focus_name" required maxlength="120" placeholder="Ex.: Vasco da Gama" value="<?=h($_POST['focus_name']??'')?>"></label><label>Sigla<input name="focus_abbr" required maxlength="12" placeholder="VG" value="<?=h($_POST['focus_abbr']??'')?>"></label></div><label>Assunto<input name="category" required maxlength="120" placeholder="Ex.: Gestão" value="<?=h($_POST['category']??'')?>"></label><label>Pergunta<input name="title" required maxlength="255" minlength="15" value="<?=h($_POST['title']??'')?>"></label><label>Contexto<textarea name="body" required minlength="20" rows="7"><?=h($_POST['body']??'')?></textarea></label><label>Para quem deve responder?<input name="target_name" required maxlength="190" placeholder="Ex.: Diretoria financeira" value="<?=h($_POST['target_name']??'')?>"></label><button class="button primary" type="submit">Publicar pergunta</button></form></section><?php render_footer();?>
