<?php

declare(strict_types=1);

function run_schema_migrations(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!(bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_meta (
            meta_key VARCHAR(100) PRIMARY KEY,
            meta_value VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->query("SELECT meta_value FROM app_meta WHERE meta_key='schema_version' LIMIT 1");
    $version = (int)($stmt->fetchColumn() ?: 0);
    if ($version >= 1) {
        return;
    }

    migration_add_column($pdo, 'users', 'last_login_at', 'DATETIME NULL AFTER active');
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('user','respondent','admin') NOT NULL DEFAULT 'user'");

    migration_add_column($pdo, 'questions', 'focus_id', 'BIGINT UNSIGNED NULL AFTER user_id');
    migration_add_column($pdo, 'questions', 'category_id', 'BIGINT UNSIGNED NULL AFTER focus_abbr');
    migration_add_column($pdo, 'questions', 'target_id', 'BIGINT UNSIGNED NULL AFTER target_name');
    migration_add_column($pdo, 'questions', 'moderation_status', "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER published");
    migration_add_column($pdo, 'questions', 'taken_by', 'BIGINT UNSIGNED NULL AFTER moderation_status');
    migration_add_column($pdo, 'questions', 'taken_at', 'DATETIME NULL AFTER taken_by');
    migration_add_column($pdo, 'questions', 'duplicate_of', 'BIGINT UNSIGNED NULL AFTER taken_at');
    migration_add_column($pdo, 'questions', 'deleted_at', 'DATETIME NULL AFTER duplicate_of');
    migration_add_column($pdo, 'questions', 'deleted_by', 'BIGINT UNSIGNED NULL AFTER deleted_at');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS focuses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            abbr VARCHAR(12) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS targets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL UNIQUE,
            description TEXT NULL,
            website VARCHAR(500) NULL,
            contact_email VARCHAR(190) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS target_users (
            target_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (target_id,user_id),
            CONSTRAINT fk_target_users_target FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE CASCADE,
            CONSTRAINT fk_target_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_id BIGINT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(60) NOT NULL,
            old_status VARCHAR(30) NULL,
            new_status VARCHAR(30) NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_question_history_question (question_id,created_at),
            CONSTRAINT fk_question_history_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
            CONSTRAINT fk_question_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_evidence (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            evidence_type ENUM('url','document','image','video','text') NOT NULL DEFAULT 'url',
            title VARCHAR(190) NOT NULL,
            reference_value TEXT NULL,
            storage_name VARCHAR(190) NULL,
            original_name VARCHAR(255) NULL,
            mime_type VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_evidence_question (question_id,created_at),
            CONSTRAINT fk_evidence_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
            CONSTRAINT fk_evidence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            reason ENUM('spam','offensive','illegal','duplicate','other') NOT NULL,
            details TEXT NULL,
            status ENUM('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_report_question_user (question_id,user_id),
            INDEX idx_reports_status (status,created_at),
            CONSTRAINT fk_reports_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
            CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(40) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created (created_at),
            INDEX idx_audit_entity (entity_type,entity_id),
            CONSTRAINT fk_audit_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("INSERT IGNORE INTO app_settings (setting_key,setting_value) VALUES
        ('registration_enabled','1'),
        ('auto_publish_questions','1'),
        ('silence_threshold_days','7'),
        ('site_name','Pergunta.Online'),
        ('max_questions_per_page','50')
    ");

    $pdo->exec("INSERT IGNORE INTO focuses (name,abbr)
        SELECT focus_name, MAX(focus_abbr) FROM questions
        WHERE focus_name <> '' GROUP BY focus_name");
    $pdo->exec("INSERT IGNORE INTO categories (name)
        SELECT DISTINCT category FROM questions WHERE category <> ''");
    $pdo->exec("INSERT IGNORE INTO targets (name)
        SELECT DISTINCT target_name FROM questions WHERE target_name <> ''");
    $pdo->exec("UPDATE questions q JOIN focuses f ON f.name=q.focus_name SET q.focus_id=f.id WHERE q.focus_id IS NULL");
    $pdo->exec("UPDATE questions q JOIN categories c ON c.name=q.category SET q.category_id=c.id WHERE q.category_id IS NULL");
    $pdo->exec("UPDATE questions q JOIN targets t ON t.name=q.target_name SET q.target_id=t.id WHERE q.target_id IS NULL");

    $upsert = $pdo->prepare("
        INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','1')
        ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)
    ");
    $upsert->execute();
}

function migration_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?
    ");
    $stmt->execute([$table, $column]);
    if (!(int)$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}
