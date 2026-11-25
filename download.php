<?php
require __DIR__.'/includes/bootstrap.php';
require_login();
$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT title,file_name FROM materials WHERE id=?");$stmt->execute([$id]);$file=$stmt->fetch();
if(!$file){http_response_code(404);exit('الملف غير موجود');}
$path=$config['upload_dir'].'/'.$file['file_name'];
if(!is_file($path)){http_response_code(404);exit('الملف غير متاح');}
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.basename($file['file_name']).'"');
header('Content-Length: '.filesize($path));readfile($path);
