<?php
declare(strict_types=1);
session_start();
$configPath = dirname(__DIR__) . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    exit('يرجى نسخ config.example.php إلى config.php وإعداد قاعدة البيانات.');
}
$config = require $configPath;
try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('تعذر الاتصال بقاعدة البيانات. راجع إعدادات config.php.');
}
// Required tables for exam timing and threaded Q&A. Safe to run on every request.
$pdo->exec("CREATE TABLE IF NOT EXISTS exam_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    started_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    UNIQUE KEY one_exam_session (exam_id, student_id),
    CONSTRAINT fk_exam_sessions_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_exam_sessions_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS question_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    student_id INT NULL,
    is_teacher TINYINT(1) NOT NULL DEFAULT 0,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    INDEX idx_question_replies (question_id, created_at),
    CONSTRAINT fk_replies_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_replies_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("INSERT INTO question_replies(question_id,student_id,is_teacher,body) SELECT q.id,q.student_id,1,q.answer FROM questions q WHERE q.answer IS NOT NULL AND q.answer<>'' AND NOT EXISTS (SELECT 1 FROM question_replies r WHERE r.question_id=q.id AND r.is_teacher=1)");

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function is_teacher(): bool { return !empty($_SESSION['teacher']); }
function student_id(): int { return (int)($_SESSION['student_id'] ?? 0); }
function require_login(): void {
    if (!is_teacher() && !student_id()) { header('Location: index.php?page=login'); exit; }
}
function require_teacher(): void {
    if (!is_teacher()) { http_response_code(403); exit('غير مصرح لك بالوصول.'); }
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('انتهت صلاحية الطلب. أعد تحميل الصفحة وحاول مرة أخرى.');
    }
}
function subject_name(string $s): string {
    return ['history'=>'التاريخ','geography'=>'الجغرافيا','general'=>'عام'][$s] ?? 'عام';
}
function go(string $url): never { header("Location: $url"); exit; }
