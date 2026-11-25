USE raafat_platform;

CREATE TABLE IF NOT EXISTS exam_sessions (
 id INT AUTO_INCREMENT PRIMARY KEY, exam_id INT NOT NULL, student_id INT NOT NULL,
 started_at DATETIME NOT NULL, submitted_at DATETIME NULL,
 UNIQUE KEY one_exam_session (exam_id, student_id),
 FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
 FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS question_replies (
 id INT AUTO_INCREMENT PRIMARY KEY, question_id INT NOT NULL, student_id INT NOT NULL,
 is_teacher TINYINT(1) NOT NULL DEFAULT 0, body TEXT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
 INDEX idx_question_replies (question_id, created_at),
 FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
 FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
