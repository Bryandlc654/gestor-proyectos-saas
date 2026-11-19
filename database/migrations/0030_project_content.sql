-- 0030_project_content: documentación, revisiones (proofing) y pizarra por proyecto.

CREATE TABLE IF NOT EXISTS `project_docs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `content` MEDIUMTEXT NULL,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_project_docs_project` (`project_id`, `updated_at`),
    CONSTRAINT `fk_project_docs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_project_docs_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_project_docs_editor` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_proofs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_type` ENUM('image', 'pdf') NOT NULL DEFAULT 'image',
    `status` ENUM('pending', 'approved', 'changes') NOT NULL DEFAULT 'pending',
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_project_proofs_project` (`project_id`, `created_at`),
    CONSTRAINT `fk_project_proofs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_project_proofs_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `proof_annotations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `proof_id` INT NOT NULL,
    `page` INT NOT NULL DEFAULT 1,
    `x` DECIMAL(6,3) NOT NULL DEFAULT 50.000,
    `y` DECIMAL(6,3) NOT NULL DEFAULT 50.000,
    `comment` TEXT NOT NULL,
    `status` ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_proof_annotations_proof` (`proof_id`, `page`, `id`),
    CONSTRAINT `fk_proof_annotations_proof` FOREIGN KEY (`proof_id`) REFERENCES `project_proofs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_proof_annotations_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_whiteboards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `data` MEDIUMTEXT NULL,
    `updated_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_project_whiteboards_project` (`project_id`),
    CONSTRAINT `fk_project_whiteboards_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_project_whiteboards_editor` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
