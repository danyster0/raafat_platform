<?php $pageTitle = $pageTitle ?? 'منصة الأستاذ رأفت عبدالله المؤرخاتي'; ?>
<!doctype html><html lang="ar" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title><link rel="stylesheet" href="assets/style.css">
</head><body>
<header class="topbar"><a class="brand" href="index.php">أ/ رأفت عبدالله <span>المؤرخاتي</span></a>
<nav><?php if (is_teacher()): ?>
<a href="index.php?page=admin">لوحة الأستاذ</a><a href="index.php?page=logout">خروج</a>
<?php elseif (student_id()): ?>
<a href="index.php?page=home">الرئيسية</a><a href="index.php?page=logout">خروج</a>
<?php else: ?><a href="index.php?page=login">دخول الطلاب</a><?php endif; ?></nav></header>
<main class="container eyeb-1">
