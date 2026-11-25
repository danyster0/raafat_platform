<?php
require __DIR__ . '/includes/bootstrap.php';
$page = $_GET['page'] ?? (is_teacher() ? 'admin' : (student_id() ? 'home' : 'login'));
$allowed = ['login','logout','home','admin','students','materials','videos','questions','exams','take_exam','results'];
if (!in_array($page, $allowed, true)) $page = 'login';
verify_csrf();

if ($page === 'logout') { session_destroy(); go('index.php?page=login'); }

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['full_name'] ?? '');
    $code = trim($_POST['access_code'] ?? '');
    $teacherPass = (string)($_POST['teacher_password'] ?? '');
    if ($teacherPass !== '' && hash_equals((string)$config['teacher_password'], $teacherPass)) {
        session_regenerate_id(true); $_SESSION['teacher'] = true; go('index.php?page=admin');
    }
    if ($name !== '' && $code !== '') {
        $stmt = $pdo->query("SELECT id, full_name, access_code_hash FROM students WHERE is_active=1");
        foreach ($stmt as $row) {
            if (password_verify($code, $row['access_code_hash'])) {
                session_regenerate_id(true); $_SESSION['student_id'] = (int)$row['id']; $_SESSION['student_name'] = $row['full_name'];
                $pdo->prepare("UPDATE students SET last_login=NOW() WHERE id=?")->execute([$row['id']]);
                $pdo->prepare("INSERT INTO login_logs(student_id,ip_address) VALUES(?,?)")->execute([$row['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
                go('index.php?page=home');
            }
        }
    }
    $error = 'بيانات الدخول غير صحيحة أو الكود غير مفعل.';
}

if ($page === 'admin') { require_teacher(); $stats = [
'students'=>(int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn(),
'visits'=>(int)$pdo->query("SELECT COUNT(*) FROM login_logs")->fetchColumn(),
'exams'=>(int)$pdo->query("SELECT COUNT(*) FROM exams")->fetchColumn(),
'questions'=>(int)$pdo->query("SELECT COUNT(*) FROM questions WHERE answer IS NULL OR answer=''")->fetchColumn()
]; }
if ($page === 'home' || $page === 'take_exam' || $page === 'results') require_login();

if ($page === 'students' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_teacher();
    $name=trim($_POST['full_name']??''); $code=trim($_POST['access_code']??'');
    if ($name && strlen($code)>=5) {
        $pdo->prepare("INSERT INTO students(full_name,access_code_hash) VALUES(?,?)")->execute([$name,password_hash($code,PASSWORD_DEFAULT)]);
        $success='تم إنشاء حساب الطالب. احتفظ بالكود وسلمه للطالب.';
    } else $error='أدخل اسم الطالب وكودًا لا يقل عن 5 أحرف.';
}
if ($page === 'materials' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_teacher();
    if (!empty($_FILES['material']['name']) && $_FILES['material']['error']===UPLOAD_ERR_OK) {
        $ext=strtolower(pathinfo($_FILES['material']['name'],PATHINFO_EXTENSION));
        if (!in_array($ext,['pdf','doc','docx','ppt','pptx'],true) || $_FILES['material']['size']>15*1024*1024) $error='الملف غير مسموح أو أكبر من 15MB.';
        else {
            if (!is_dir($config['upload_dir'])) mkdir($config['upload_dir'],0755,true);
            $stored=bin2hex(random_bytes(16)).'.'.$ext;
            if (move_uploaded_file($_FILES['material']['tmp_name'],$config['upload_dir'].'/'.$stored)) {
                $pdo->prepare("INSERT INTO materials(title,subject,description,file_name) VALUES(?,?,?,?)")->execute([trim($_POST['title']),$_POST['subject'],trim($_POST['description']??''),$stored]);
                $success='تم رفع الملف بنجاح.';
            } else $error='فشل رفع الملف.';
        }
    }
}
if ($page === 'videos' && $_SERVER['REQUEST_METHOD']==='POST' && !isset($_POST['manage_action'])) {
    require_teacher();
    $url=trim($_POST['video_url']??'');
    if (filter_var($url,FILTER_VALIDATE_URL) && in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)) {
        $pdo->prepare("INSERT INTO videos(title,subject,description,video_url) VALUES(?,?,?,?)")->execute([trim($_POST['title']),$_POST['subject'],trim($_POST['description']??''),$url]);
        $success='تم إضافة الفيديو.';
    } else $error='أدخل رابط فيديو صحيحًا.';
}
if ($page === 'questions' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_login();
    $action = $_POST['qa_action'] ?? 'create_question';
    $id = (int)($_POST['item_id'] ?? 0);
    if ($action === 'create_question' && student_id()) {
        $subject = $_POST['subject'] ?? 'general';
        if (!in_array($subject, ['history','geography','general'], true)) $subject = 'general';
        $title = trim($_POST['title'] ?? ''); $body = trim($_POST['body'] ?? '');
        if ($title !== '' && $body !== '') {
            $pdo->prepare("INSERT INTO questions(student_id,subject,title,body) VALUES(?,?,?,?)")->execute([student_id(),$subject,$title,$body]);
            $success='تم إرسال سؤالك للأستاذ.';
        } else $error='اكتب عنوان السؤال وتفاصيله.';
    } elseif ($action === 'edit_question') {
        // السؤال ملك الطالب؛ الأستاذ لا يحرر نص سؤال الطالب.
        if (!student_id()) { $error='لا يمكن للأستاذ تعديل سؤال الطالب.'; }
        else {
            $title=trim($_POST['title']??''); $body=trim($_POST['body']??'');
            if ($title && $body) { $st=$pdo->prepare("UPDATE questions SET title=?,body=? WHERE id=? AND student_id=?"); $st->execute([$title,$body,$id,student_id()]); $success=$st->rowCount()?'تم تعديل السؤال.':'لا يمكنك تعديل هذا السؤال.'; }
        }
    } elseif ($action === 'delete_question') {
        $sql=is_teacher()?"DELETE FROM questions WHERE id=?":"DELETE FROM questions WHERE id=? AND student_id=?";
        $pdo->prepare($sql)->execute(is_teacher()?[$id]:[$id,student_id()]); $success='تم حذف السؤال.';
    } elseif ($action === 'add_reply') {
        $body=trim($_POST['body']??'');
        $check=$pdo->prepare("SELECT id FROM questions WHERE id=?".(!is_teacher()?" AND student_id=?":""));
        $check->execute(is_teacher()?[$id]:[$id,student_id()]);
        if ($check->fetch() && $body!=='') {
            $pdo->prepare("INSERT INTO question_replies(question_id,student_id,is_teacher,body) VALUES(?,?,?,?)")->execute([$id,is_teacher()?null:student_id(),is_teacher()?1:0,$body]);
            $success='تم إرسال ردك.';
        } else $error='لا يمكن إرسال الرد على هذا السؤال.';
    } elseif ($action === 'edit_reply' || $action === 'delete_reply') {
        $replyId=(int)($_POST['reply_id']??0);
        $check=$pdo->prepare("SELECT student_id,is_teacher FROM question_replies WHERE id=?"); $check->execute([$replyId]); $reply=$check->fetch();
        if ($reply && ((is_teacher() && (int)$reply['is_teacher']===1) || (student_id() && !(int)$reply['is_teacher'] && (int)$reply['student_id']===student_id()))) {
            if ($action==='delete_reply') $pdo->prepare("DELETE FROM question_replies WHERE id=?")->execute([$replyId]);
            else { $body=trim($_POST['body']??''); if($body!=='') $pdo->prepare("UPDATE question_replies SET body=?,updated_at=NOW() WHERE id=?")->execute([$body,$replyId]); }
            $success=$action==='delete_reply'?'تم حذف الرد.':'تم تعديل الرد.';
        } else $error='غير مسموح بتعديل هذا الرد.';
    }
}
if ($page === 'exams' && $_SERVER['REQUEST_METHOD']==='POST' && !isset($_POST['manage_action'])) {
    require_teacher();
    $qs=$_POST['question']??[];
    $validQs=array_filter($qs,fn($q)=>trim($q['text']??'')!=='');
    if (!$validQs) { $error='أضف سؤالًا واحدًا على الأقل قبل إنشاء الامتحان.'; } else {
    $pdo->prepare("INSERT INTO exams(title,subject,duration_minutes,is_published) VALUES(?,?,?,?)")->execute([trim($_POST['title']),$_POST['subject'],max(1,(int)$_POST['duration']),isset($_POST['published'])?1:0]);
    $examId=(int)$pdo->lastInsertId();
    foreach ($qs as $q) {
        if (trim($q['text']??'')==='') continue;
        $pdo->prepare("INSERT INTO exam_questions(exam_id,question_text,question_type,option_a,option_b,option_c,option_d,correct_answer) VALUES(?,?,?,?,?,?,?,?)")
            ->execute([$examId,trim($q['text']),$q['type']??'mcq',$q['a']??null,$q['b']??null,$q['c']??null,$q['d']??null,$q['correct']??'a']);
    }
    $success='تم إنشاء الامتحان وإضافة الأسئلة.'; }
}
// Teacher content controls: hide/show or permanently delete.
if (in_array($page,['exams','materials','videos','students','questions'],true) && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['manage_action'])) {
    require_teacher(); $id=(int)($_POST['item_id']??0); $action=$_POST['manage_action'];
    if ($page==='exams' && $action==='toggle') { $pdo->prepare('UPDATE exams SET is_published=IF(is_published=1,0,1) WHERE id=?')->execute([$id]); $success='تم تحديث حالة ظهور الامتحان.'; }
    elseif ($page==='exams' && $action==='delete') { $pdo->prepare('DELETE FROM exams WHERE id=?')->execute([$id]); $success='تم حذف الامتحان ومحاولاته وأسئلته.'; }
    elseif ($page==='materials' && $action==='delete') { $q=$pdo->prepare('SELECT file_name FROM materials WHERE id=?');$q->execute([$id]);$f=$q->fetchColumn(); if($f){$path=$config['upload_dir'].'/'.$f;if(is_file($path))unlink($path);$pdo->prepare('DELETE FROM materials WHERE id=?')->execute([$id]);$success='تم حذف الملف.';} }
    elseif ($page==='videos' && $action==='delete') { $pdo->prepare('DELETE FROM videos WHERE id=?')->execute([$id]); $success='تم حذف الفيديو.'; }
    elseif ($page==='students' && $action==='toggle') { $pdo->prepare('UPDATE students SET is_active=IF(is_active=1,0,1) WHERE id=?')->execute([$id]); $success='تم تحديث حالة حساب الطالب.'; }
    elseif ($page==='students' && $action==='delete') { if($id!==student_id()) { $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$id]); $success='تم حذف الطالب وكل بياناته المرتبطة.'; } }
}
if ($page === 'take_exam' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_login();
    if (!student_id()) { http_response_code(403); exit; }
    $examId=(int)($_POST['exam_id'] ?? 0);
    // Verify that the exam is published and that this student actually started it.
    $examCheck=$pdo->prepare('SELECT duration_minutes FROM exams WHERE id=? AND is_published=1');
    $examCheck->execute([$examId]);
    $duration=$examCheck->fetchColumn();
    $session=$pdo->prepare('SELECT started_at, submitted_at, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed_seconds FROM exam_sessions WHERE exam_id=? AND student_id=?');
    $session->execute([$examId,student_id()]);
    $examSession=$session->fetch();
    $check=$pdo->prepare('SELECT id FROM exam_attempts WHERE exam_id=? AND student_id=?');
    $check->execute([$examId,student_id()]);
    if (!$duration || !$examSession) {
        $error='لم يتم العثور على جلسة امتحان صالحة. افتح الامتحان من صفحة الامتحانات.';
    } elseif ($check->fetch() || !empty($examSession['submitted_at'])) {
        $error='سبق لك تسليم هذا الامتحان بالفعل.';
    } else {
        // Use MySQL's clock for both timestamps to avoid PHP/MySQL timezone differences.
        // A submission that reaches the server after the countdown is still graded and shown.
        $late = (int)$examSession['elapsed_seconds'] > ((int)$duration * 60);
        $q=$pdo->prepare('SELECT id,correct_answer FROM exam_questions WHERE exam_id=?');
        $q->execute([$examId]);
        $questions=$q->fetchAll();
        $answers=$_POST['answers'] ?? [];
        $score=0;
        foreach($questions as $question) {
            $given=strtolower(trim((string)($answers[$question['id']] ?? '')));
            if ($given !== '' && $given === strtolower((string)$question['correct_answer'])) $score++;
        }
        $pdo->prepare('INSERT INTO exam_attempts(exam_id,student_id,score,total) VALUES(?,?,?,?)')->execute([$examId,student_id(),$score,count($questions)]);
        $pdo->prepare('UPDATE exam_sessions SET submitted_at=NOW() WHERE exam_id=? AND student_id=?')->execute([$examId,student_id()]);
        $result=$score.'/'.count($questions);
        if ($late) $resultNote='تم استلام إجاباتك بعد انتهاء الوقت، وتم تصحيحها وتسجيل نتيجتك.';
    }
}
$pageTitle = 'الأستاذ رأفت عبدالله المؤرخاتي';
require __DIR__.'/includes/header.php';
?>
<?php if ($page==='login'): ?>
<section class="login-card bgr-1"><div class="eyebrow">منصة تعليمية للمرحلة الثانوية</div><h1 class="black-t">الأستاذ رأفت عبدالله المؤرخاتي</h1><p><span class="gold">.</span> مدرس أول تاريخ وجغرافيا · مدرسة هشام الحميلي الثانوية <span class="gold">.</span></p>
<?php if(!empty($error)): ?><div class="alert"><?=e($error)?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label class="main-label-color">اسم الطالب بالكامل</label><input name="full_name" placeholder="اكتب اسمك بالكامل" required>
<label class="main-label-color">كود الطالب</label><input name="access_code" placeholder="أدخل الكود المخصص لك" required>
<button class="btn full">دخول المنصة</button></form>
<details><summary class="main-label-color">دخول الأستاذ</summary><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label class="main-label-color">كلمة مرور الأستاذ</label><input type="password" name="teacher_password" required><button class="btn full">دخول لوحة التحكم</button></form></details>
<a class="whatsapp" href="https://wa.me/201093767010" target="_blank">تواصل مع الأستاذ عبر واتساب</a>
</section>
<?php elseif($page==='admin'): ?>
<div class="page-head eyeb-1"><div><span class="eyebrow">لوحة الإدارة</span><h1>مرحبًا أستاذ رأفت</h1><p>إدارة المنصة ومتابعة الطلاب والمحتوى.</p></div></div>
<div class="stats"><div class="stat"><b><?=$stats['students']?></b><span>إجمالي الطلاب</span></div><div class="stat"><b><?=$stats['visits']?></b><span>مرات تسجيل الدخول</span></div><div class="stat"><b><?=$stats['exams']?></b><span>الامتحانات</span></div><div class="stat"><b><?=$stats['questions']?></b><span>أسئلة بلا رد</span></div></div>
<div class="grid">
<a class="tile" href="index.php?page=students"><strong>إدارة الطلاب</strong><span>إضافة الطلاب وإنشاء أكواد الدخول</span></a>
<a class="tile" href="index.php?page=exams"><strong>الامتحانات</strong><span>إنشاء الامتحانات ومتابعة النتائج</span></a>
<a class="tile" href="index.php?page=materials"><strong>الملفات والمراجعات</strong><span>رفع ملفات المذاكرة</span></a>
<a class="tile" href="index.php?page=videos"><strong>فيديوهات الشرح</strong><span>إضافة روابط فيديوهات الدروس</span></a>
<a class="tile" href="index.php?page=questions"><strong>أسئلة الطلاب</strong><span>متابعة الأسئلة والرد عليها</span></a>
</div>
<?php elseif($page==='home'): ?>
<div class="hero"><div class="bgr-0"><img src="img/r-0.png" alt=""></div><span class="eyebrow">أهلاً بك في منصتك التعليمية</span><h1>أ/ رأفت عبدالله <em>المؤرخاتي</em></h1><p>مدرس أول تاريخ وجغرافيا — مدرسة هشام الحميلي الثانوية</p><div class="hero-actions"><a class="btn" href="index.php?page=exams">الامتحانات الإلكترونية</a><a class="btn secondary" href="index.php?page=materials">المراجعات والملفات</a></div></div>
<div class="grid">
<a class="tile" href="index.php?page=exams"><strong>الامتحانات الإلكترونية</strong><span>اختبر مستواك واعرف نتيجتك</span></a>
<a class="tile" href="index.php?page=materials"><strong>الملفات والمراجعات</strong><span>حمل المذكرات والملفات</span></a>
<a class="tile" href="index.php?page=videos"><strong>فيديوهات الشرح</strong><span>شاهد الدروس التعليمية</span></a>
<a class="tile" href="index.php?page=questions"><strong>اسأل الأستاذ</strong><span>اكتب سؤالك وتابع الإجابة</span></a>
</div>
<?php elseif($page==='students'): require_teacher(); ?>
<h1 class="eyeb-1">إدارة الطلاب</h1><?php if(!empty($success)):?><div class="success"><?=e($success)?></div><?php endif;?><?php if(!empty($error)):?><div class="alert"><?=e($error)?></div><?php endif;?>
<form class="panel form-grid" method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>اسم الطالب<input name="full_name" required></label><label>كود الدخول (5 أحرف على الأقل)<input name="access_code" minlength="5" required></label><button class="btn">إنشاء حساب طالب</button></form>
<div class="panel"><h3>الطلاب المسجلون</h3><?php foreach($pdo->query("SELECT id,full_name,is_active,last_login FROM students ORDER BY id DESC") as $s):?><div class="row"><div><b><?=e($s['full_name'])?></b><small> · <?=((int)$s['is_active']?'نشط':'محظور')?> · <?= $s['last_login'] ? e($s['last_login']) : 'لم يسجل الدخول بعد' ?></small></div><form method="post" class="inline-form" onsubmit="return confirm('تأكيد تنفيذ الإجراء؟')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="item_id" value="<?=$s['id']?>"><button class="btn secondary" name="manage_action" value="toggle"><?=$s['is_active']?'حظر الطالب':'إلغاء الحظر'?></button><button class="btn danger" name="manage_action" value="delete" onclick="return confirm('سيتم حذف الطالب ونتائجه وأسئلته وسجلاته نهائيًا. هل أنت متأكد؟')">حذف الطالب</button></form></div><?php endforeach;?></div>
<?php elseif($page==='materials'): ?>
<h1 class="eyeb-1">الملفات والمراجعات</h1><?php if(is_teacher()):?><form class="panel form-grid" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>اسم الملف<input name="title" required></label><label>المادة<select name="subject"><option value="history">التاريخ</option><option value="geography">الجغرافيا</option><option value="general">عام</option></select></label><label>وصف الملف<input name="description"></label><label>الملف (PDF/Word/PowerPoint)<input type="file" name="material" required></label><button class="btn">رفع الملف</button></form><?php endif;?>
<?php if(!empty($success)):?><div class="success"><?=e($success)?></div><?php endif;?><?php if(!empty($error)):?><div class="alert"><?=e($error)?></div><?php endif;?>
<div class="grid"><?php foreach($pdo->query("SELECT * FROM materials ORDER BY uploaded_at DESC") as $m):?><article class="tile"><small><?=e(subject_name($m['subject']))?></small><strong><?=e($m['title'])?></strong><span><?=e($m['description'])?></span><a class="btn" href="download.php?id=<?=$m['id']?>">تحميل الملف</a><?php if(is_teacher()):?><form method="post" onsubmit="return confirm('حذف الملف نهائيًا؟')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="item_id" value="<?=$m['id']?>"><button class="btn danger" name="manage_action" value="delete">حذف الملف</button></form><?php endif;?></article><?php endforeach;?></div>
<?php elseif($page==='videos'): ?>
<h1 class="eyeb-1">فيديوهات الشرح</h1><?php if(is_teacher()):?><form class="panel form-grid" method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>عنوان الفيديو<input name="title" required></label><label>المادة<select name="subject"><option value="history">التاريخ</option><option value="geography">الجغرافيا</option><option value="general">عام</option></select></label><label>رابط الفيديو<input name="video_url" type="url" required></label><label>الوصف<input name="description"></label><button class="btn">إضافة الفيديو</button></form><?php endif;?>
<?php if(!empty($success)):?><div class="success"><?=e($success)?></div><?php endif;?><div class="grid"><?php foreach($pdo->query("SELECT * FROM videos ORDER BY created_at DESC") as $v):?><article class="tile"><small><?=e(subject_name($v['subject']))?></small><strong><?=e($v['title'])?></strong><span><?=e($v['description'])?></span><a class="btn" target="_blank" rel="noopener" href="<?=e($v['video_url'])?>">مشاهدة الفيديو</a><?php if(is_teacher()):?><form method="post" onsubmit="return confirm('حذف الفيديو؟')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="item_id" value="<?=$v['id']?>"><button class="btn danger" name="manage_action" value="delete">حذف</button></form><?php endif;?></article><?php endforeach;?></div>
<?php elseif($page==='questions'): ?>
<h1 class="eyeb-1">اسأل الأستاذ</h1>
<?php if(student_id()):?><form class="panel form-grid" method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="qa_action" value="create_question"><label>المادة<select name="subject"><option value="history">التاريخ</option><option value="geography">الجغرافيا</option><option value="general">عام</option></select></label><label>عنوان السؤال<input name="title" required></label><label>تفاصيل السؤال<textarea name="body" required></textarea></label><button class="btn">إرسال السؤال</button></form><?php endif;?>
<?php if(!empty($success)):?><div class="success"><?=e($success)?></div><?php endif;?><?php if(!empty($error)):?><div class="alert"><?=e($error)?></div><?php endif;?>
<?php $sql=is_teacher()?"SELECT q.*,s.full_name FROM questions q JOIN students s ON s.id=q.student_id ORDER BY q.created_at DESC":"SELECT q.*,s.full_name FROM questions q JOIN students s ON s.id=q.student_id WHERE q.student_id=".student_id()." ORDER BY q.created_at DESC"; foreach($pdo->query($sql) as $q):?>
<article class="panel"><small><?=e($q['full_name'])?> · <?=e(subject_name($q['subject']))?></small><h3><?=e($q['title'])?></h3><p><?=nl2br(e($q['body']))?></p>
<?php if(student_id() && (int)$q['student_id']===student_id()):?><button type="button" class="btn secondary toggle-editor">تعديل السؤال</button><form method="post" class="form-grid inline-editor" hidden><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="qa_action" value="edit_question"><input type="hidden" name="item_id" value="<?=$q['id']?>"><label>عنوان السؤال<input name="title" value="<?=e($q['title'])?>" required></label><label>التفاصيل<textarea name="body" required><?=e($q['body'])?></textarea></label><button class="btn secondary">حفظ التعديل</button><button type="button" class="btn cancel-editor">إلغاء</button></form><?php endif;?>
<form method="post" onsubmit="return confirm('حذف السؤال وكل الردود المرتبطة به؟')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="qa_action" value="delete_question"><input type="hidden" name="item_id" value="<?=$q['id']?>"><button class="btn danger">حذف السؤال</button></form>
<?php $rs=$pdo->prepare("SELECT * FROM question_replies WHERE question_id=? ORDER BY created_at ASC");$rs->execute([$q['id']]);foreach($rs as $r):?><div class="answer"><b><?=((int)$r['is_teacher']?'رد الأستاذ':'رد الطالب')?>:</b><p><?=nl2br(e($r['body']))?></p><?php if((is_teacher() && (int)$r['is_teacher']===1) || (student_id() && !(int)$r['is_teacher'] && (int)$r['student_id']===student_id())):?><button type="button" class="btn secondary toggle-editor">تعديل الرد</button><form method="post" class="form-grid inline-editor" hidden><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="qa_action" value="edit_reply"><input type="hidden" name="reply_id" value="<?=$r['id']?>"><input type="hidden" name="item_id" value="<?=$q['id']?>"><textarea name="body" required><?=e($r['body'])?></textarea><button class="btn secondary">حفظ التعديل</button><button type="button" class="btn cancel-editor">إلغاء</button></form><?php endif;?><?php if(is_teacher() || (!(int)$r['is_teacher'] && (int)$r['student_id']===student_id())):?><form method="post" onsubmit="return confirm('حذف الرد؟')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="qa_action" value="delete_reply"><input type="hidden" name="reply_id" value="<?=$r['id']?>"><input type="hidden" name="item_id" value="<?=$q['id']?>"><button class="btn danger">حذف الرد</button></form><?php endif;?></div><?php endforeach;?>
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="qa_action" value="add_reply"><input type="hidden" name="item_id" value="<?=$q['id']?>"><textarea name="body" required placeholder="اكتب ردك أو استفسارك هنا"></textarea><button class="btn"><?=is_teacher()?'إرسال رد الأستاذ':'الرد على السؤال'?></button></form></article><?php endforeach;?>
<script>
document.addEventListener('click', function (event) {
  const toggle = event.target.closest('.toggle-editor');
  if (toggle) {
    const form = toggle.nextElementSibling;
    if (form && form.classList.contains('inline-editor')) {
      form.hidden = !form.hidden;
      toggle.textContent = form.hidden ? (toggle.textContent.includes('السؤال') ? 'تعديل السؤال' : 'تعديل الرد') : 'إخفاء التعديل';
    }
  }
  const cancel = event.target.closest('.cancel-editor');
  if (cancel) {
    const form = cancel.closest('.inline-editor');
    if (form) { form.hidden = true; const toggle = form.previousElementSibling; if (toggle) toggle.textContent = toggle.textContent.includes('السؤال') || form.querySelector('[name="qa_action"]')?.value === 'edit_question' ? 'تعديل السؤال' : 'تعديل الرد'; }
  }
});
</script>
<?php elseif($page==='exams'): ?>
<h1 class="eyeb-1">الامتحانات الإلكترونية</h1>
<?php if(is_teacher()):?>
<form class="panel form-grid" method="post" id="examBuilder"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label>اسم الامتحان<input name="title" required></label><label>المادة<select name="subject"><option value="history">التاريخ</option><option value="geography">الجغرافيا</option></select></label><label>المدة بالدقائق<input type="number" name="duration" min="1" value="30"></label><label><input type="checkbox" name="published" checked> إظهار الامتحان للطلاب فور إنشائه</label>
<div id="questionList"><div class="question-editor panel"><h3>السؤال 1</h3><label>نص السؤال<input name="question[0][text]" required></label><label>الاختيار أ<input name="question[0][a]" required></label><label>الاختيار ب<input name="question[0][b]" required></label><label>الاختيار ج<input name="question[0][c]"></label><label>الاختيار د<input name="question[0][d]"></label><label>الإجابة الصحيحة<select name="question[0][correct]"><option value="a">أ</option><option value="b">ب</option><option value="c">ج</option><option value="d">د</option></select></label></div></div>
<button type="button" class="btn secondary" id="addQuestion">+ إضافة سؤال آخر</button><button class="btn">إنشاء الامتحان</button></form>
<script>let qi=1;document.getElementById('addQuestion').addEventListener('click',()=>{const box=document.createElement('div');box.className='question-editor panel';box.innerHTML=`<h3>السؤال ${qi+1}</h3><label>نص السؤال<input name="question[${qi}][text]" required></label><label>الاختيار أ<input name="question[${qi}][a]" required></label><label>الاختيار ب<input name="question[${qi}][b]" required></label><label>الاختيار ج<input name="question[${qi}][c]"></label><label>الاختيار د<input name="question[${qi}][d]"></label><label>الإجابة الصحيحة<select name="question[${qi}][correct]"><option value="a">أ</option><option value="b">ب</option><option value="c">ج</option><option value="d">د</option></select></label><button type="button" class="btn danger remove-question">إزالة السؤال</button>`;box.querySelector('.remove-question').onclick=()=>box.remove();document.getElementById('questionList').appendChild(box);qi++;});</script>
<?php endif;?>
<?php if(!empty($success)):?><div class="success"><?=e($success)?></div><?php endif;?><?php if(!empty($error)):?><div class="alert"><?=e($error)?></div><?php endif;?>
<?php foreach($pdo->query("SELECT * FROM exams ".(is_teacher()?"":"WHERE is_published=1")." ORDER BY created_at DESC") as $exam):?><article class="panel"><small><?=e(subject_name($exam['subject']))?> · <?=$exam['duration_minutes']?> دقيقة · <?=$exam['is_published']?'ظاهر للطلاب':'مخفي عن الطلاب'?></small><h3><?=e($exam['title'])?></h3><?php if(is_teacher()):?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="item_id" value="<?=$exam['id']?>"><button class="btn secondary" name="manage_action" value="toggle"><?=$exam['is_published']?'إخفاء عن الطلاب':'نشر للطلاب'?></button><button class="btn danger" name="manage_action" value="delete" onclick="return confirm('سيتم حذف الامتحان وكل نتائجه نهائيًا. هل أنت متأكد؟')">حذف نهائي</button></form><a class="btn" href="index.php?page=exams#attempts">عرض الممتحنين والنتائج</a><?php elseif(student_id()):?><a class="btn" href="index.php?page=take_exam&id=<?=$exam['id']?>">بدء الامتحان</a><?php endif;?></article><?php endforeach;?>
<?php if(is_teacher()):?><section class="panel" id="attempts"><h2>سجل الممتحنين والنتائج</h2><?php $attempts=$pdo->query("SELECT e.title,s.full_name,a.score,a.total,ss.started_at,a.submitted_at FROM exam_attempts a JOIN exams e ON e.id=a.exam_id JOIN students s ON s.id=a.student_id LEFT JOIN exam_sessions ss ON ss.exam_id=a.exam_id AND ss.student_id=a.student_id ORDER BY a.submitted_at DESC");?><div class="table-wrap"><table><thead><tr><th>الامتحان</th><th>اسم الطالب</th><th>الدرجة</th><th>وقت الدخول</th><th>وقت التسليم</th></tr></thead><tbody><?php foreach($attempts as $a):?><tr><td><?=e($a['title'])?></td><td><?=e($a['full_name'])?></td><td><?=$a['score']?> / <?=$a['total']?></td><td><?=e($a['started_at']??'—')?></td><td><?=e($a['submitted_at']??'—')?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
<?php elseif($page==='take_exam'): $examId=(int)($_GET['id']??$_POST['exam_id']??0); $st=$pdo->prepare("SELECT * FROM exams WHERE id=? AND is_published=1");$st->execute([$examId]);$exam=$st->fetch(); if(!$exam):?><div class="alert">الامتحان غير متاح.</div><?php elseif(isset($result)):?><div class="result eyeb-1"><h1>نتيجة الامتحان</h1><strong><?=e($result)?></strong><p><?=e($resultNote ?? 'تم تصحيح إجاباتك وتسجيل نتيجتك بنجاح.')?></p><a class="btn" href="index.php?page=exams">العودة إلى الامتحانات</a></div><?php elseif(!empty($error)):?><div class="alert"><?=e($error)?></div><?php else: $done=$pdo->prepare("SELECT id FROM exam_attempts WHERE exam_id=? AND student_id=?");$done->execute([$examId,student_id()]);if($done->fetch()):?><div class="alert">لقد أديت هذا الامتحان بالفعل.</div><?php else: $pdo->prepare('INSERT INTO exam_sessions(exam_id,student_id,started_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([$examId,student_id()]); $st=$pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=?");$st->execute([$examId]);$qs=$st->fetchAll();$tm = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) FROM exam_sessions WHERE exam_id = ? AND student_id = ?"); $tm->execute([$examId, student_id()]); $elapsedSeconds = (int) $tm->fetchColumn(); $totalSeconds = (int) $exam['duration_minutes'] * 60; $remaining = max(0, $totalSeconds - $elapsedSeconds);?>
    // حساب الوقت المتبقي باستخدام توقيت MySQL فقط
<h1><?=e($exam['title'])?></h1><div class="notice">مدة الامتحان: <?=$exam['duration_minutes']?> دقيقة. لا تغلق الصفحة قبل التسليم.</div><?php if($remaining<=0):?><div class="alert">انتهى وقت هذا الامتحان.</div><?php else:?><form class="panel" method="post" id="examForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="exam_id" value="<?=$examId?>"><?php foreach($qs as $i=>$q):?><div class="question"><h3><?=($i+1)?>. <?=e($q['question_text'])?></h3><?php foreach(['a'=>'option_a','b'=>'option_b','c'=>'option_c','d'=>'option_d'] as $key=>$col):if($q[$col]):?><label class="choice"><input type="radio" name="answers[<?=$q['id']?>]" value="<?=$key?>" required> <?=e($q[$col])?></label><?php endif;endforeach;?></div><?php endforeach;?><button class="btn">إنهاء وتسليم الامتحان</button><span id="timer" class="timer"></span></form><script>let sec=<?=$remaining?>;const t=document.getElementById('timer');let timerInterval;function tick() {let m = Math.floor(sec / 60);let s = sec % 60;t.textContent = `الوقت المتبقي: ${m}:${String(s).padStart(2, '0')}`;if (sec <= 0) {clearInterval(timerInterval);const form = document.getElementById('examForm');if (form) {form.requestSubmit();}return;}sec--;}tick();timerInterval = setInterval(tick, 1000);tick();setInterval(tick,1000);</script><?php endif;?>
<?php endif;endif;?>
<?php endif; ?>
<?php if(!empty($success) && !in_array($page,['students','materials','videos','questions','exams'],true)):?><div class="success"><?=e($success)?></div><?php endif;?>
<?php require __DIR__.'/includes/footer.php'; ?>


