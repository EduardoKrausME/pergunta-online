<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (!app_installed()) redirect('install.php');
$user=require_login();
if($_SERVER['REQUEST_METHOD']!=='POST'){redirect('index.php');}
require_csrf();
$id=(int)($_POST['question_id']??0);
$action=(string)($_POST['action']??'');
$stmt=db()->prepare('SELECT id FROM questions WHERE id=? AND published=1 LIMIT 1');$stmt->execute([$id]);if(!$stmt->fetch()){flash('error','Pergunta não encontrada.');redirect('index.php');}
$table=$action==='vote'?'question_votes':($action==='save'?'question_saves':'');
if($table===''){redirect('index.php');}
$check=db()->prepare("SELECT 1 FROM {$table} WHERE question_id=? AND user_id=?");$check->execute([$id,$user['id']]);
if($check->fetch()){$del=db()->prepare("DELETE FROM {$table} WHERE question_id=? AND user_id=?");$del->execute([$id,$user['id']]);}else{$ins=db()->prepare("INSERT INTO {$table} (question_id,user_id) VALUES (?,?)");$ins->execute([$id,$user['id']]);}
redirect('question.php?id='.$id);
