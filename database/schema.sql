-- Base de datos para el Gestor de Proyectos y Tareas
CREATE DATABASE IF NOT EXISTS `gestor_proyectos` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gestor_proyectos`;

-- 1. Tabla de Usuarios con Niveles de Acceso (Roles)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `role` ENUM('admin', 'project_manager', 'colaborador', 'cliente') NOT NULL DEFAULT 'colaborador',
    `password` VARCHAR(255) NOT NULL,
    `avatar` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Proyectos
CREATE TABLE IF NOT EXISTS `projects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(50) DEFAULT 'Desarrollo Web',
    `status` ENUM('activo', 'en_espera', 'completado') DEFAULT 'activo',
    `color` VARCHAR(20) DEFAULT '#4F46E5',
    `deadline` DATE NULL,
    `budget` DECIMAL(12,2) DEFAULT 0.00,
    `currency` VARCHAR(10) DEFAULT 'USD',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.05 Tabla de Facturas y Cobros por Proyecto
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `invoice_number` VARCHAR(50) NOT NULL,
    `concept` VARCHAR(255) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(10) DEFAULT 'USD',
    `status` ENUM('pendiente', 'pagada', 'anulada') DEFAULT 'pendiente',
    `due_date` DATE NULL,
    `paid_at` DATE NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_inv_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.1 Tabla de Miembros del Proyecto (Asignaciones y Roles por Proyecto)
CREATE TABLE IF NOT EXISTS `project_members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `role` ENUM('project_manager', 'colaborador', 'cliente') DEFAULT 'colaborador',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_proj_user` (`project_id`, `user_id`),
    CONSTRAINT `fk_pm_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Carpetas de Adjuntos por Proyecto
CREATE TABLE IF NOT EXISTS `project_folders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(20) DEFAULT '#6366F1',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_folders_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabla de Tareas / Actividades
CREATE TABLE IF NOT EXISTS `tasks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `priority` ENUM('baja', 'media', 'alta', 'urgente') DEFAULT 'media',
    `status` ENUM('pendiente', 'en_progreso', 'completada') DEFAULT 'pendiente',
    `assigned_to` VARCHAR(100) NULL,
    `due_date` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_tasks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabla de Checklists (Listas agrupadas por proyecto o por tarea)
CREATE TABLE IF NOT EXISTS `checklists` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `task_id` INT NULL,
    `title` VARCHAR(150) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_checklists_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_checklists_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabla de Items de Checklist
CREATE TABLE IF NOT EXISTS `checklist_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `checklist_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `is_completed` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_items_checklist` FOREIGN KEY (`checklist_id`) REFERENCES `checklists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabla de Tickets de Soporte / Incidentes
CREATE TABLE IF NOT EXISTS `tickets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `priority` ENUM('baja', 'media', 'alta', 'critica') DEFAULT 'media',
    `status` ENUM('abierto', 'en_revision', 'resuelto', 'cerrado') DEFAULT 'abierto',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_tickets_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabla de Enlaces y Recursos Útiles (de proyecto o asociados a tareas)
CREATE TABLE IF NOT EXISTS `project_links` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `task_id` INT NULL,
    `folder_id` INT NULL,
    `title` VARCHAR(150) NOT NULL,
    `url` VARCHAR(500) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_links_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_links_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_links_folder` FOREIGN KEY (`folder_id`) REFERENCES `project_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tabla de Archivos Adjuntos (de proyecto o asociados a tareas)
CREATE TABLE IF NOT EXISTS `project_files` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `task_id` INT NULL,
    `user_id` INT NOT NULL,
    `folder_id` INT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `file_size` INT NOT NULL,
    `file_type` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_files_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_files_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_files_folder` FOREIGN KEY (`folder_id`) REFERENCES `project_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Tabla de Comentarios y Notas (con soporte para imagen adjunta / pegada y comentarios específicos por tarea)
CREATE TABLE IF NOT EXISTS `comments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `task_id` INT NULL,
    `user_id` INT NOT NULL,
    `content` TEXT NOT NULL,
    `image_path` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_comments_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Registro de intentos fallidos de inicio de sesión (protección contra fuerza bruta)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(150) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_login_email_time` (`email`, `attempted_at`),
    INDEX `idx_login_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DATOS DE DEMOSTRACIÓN (Password: admin123 para todas las cuentas)
INSERT INTO `users` (`id`, `name`, `email`, `role`, `password`) VALUES
(1, 'Administrador Demo', 'admin@demo.com', 'admin', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6'),
(2, 'Carlos PM', 'pm@demo.com', 'project_manager', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6'),
(3, 'Ana Colaboradora', 'colaborador@demo.com', 'colaborador', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6'),
(4, 'Roberto Cliente', 'cliente@demo.com', 'cliente', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6')
ON DUPLICATE KEY UPDATE `role`=VALUES(`role`);

INSERT INTO `projects` (`id`, `user_id`, `name`, `description`, `category`, `status`, `color`, `deadline`) VALUES
(1, 1, 'Rediseño E-commerce 2026', 'Modernización de la interfaz de usuario, pasarela de pagos y panel de control para clientes.', 'Diseño & UI/UX', 'activo', '#6366F1', DATE_ADD(CURRENT_DATE, INTERVAL 21 DAY)),
(2, 1, 'App Móvil de Entregas', 'Aplicación interna para seguimiento y asignación de rutas a repartidores.', 'Móvil', 'activo', '#0EA5E9', DATE_ADD(CURRENT_DATE, INTERVAL 45 DAY))
ON DUPLICATE KEY UPDATE `id`=`id`;

INSERT INTO `project_folders` (`id`, `project_id`, `name`, `color`) VALUES
(1, 1, 'Diseño & Wireframes', '#6366F1'),
(2, 1, 'Documentación & APIs', '#0EA5E9')
ON DUPLICATE KEY UPDATE `id`=`id`;

INSERT INTO `tasks` (`project_id`, `title`, `description`, `priority`, `status`, `assigned_to`, `due_date`) VALUES
(1, 'Diseño de Wireframes en Figma', 'Crear flujo completo de checkout y carrito interactivo.', 'alta', 'completada', 'Equipo UI', DATE_ADD(CURRENT_DATE, INTERVAL -2 DAY)),
(1, 'Integración de Pasarela Stripe', 'Configurar webhooks y cobros con tarjetas internacionales.', 'urgente', 'en_progreso', 'Backend Dev', DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY)),
(1, 'Optimización Core Web Vitals', 'Comprimir imágenes webp y revisar script de Tailwind.', 'media', 'pendiente', 'Frontend Dev', DATE_ADD(CURRENT_DATE, INTERVAL 10 DAY));

INSERT INTO `checklists` (`id`, `project_id`, `title`) VALUES
(1, 1, 'Control de Calidad (QA Pre-lanzamiento)'),
(2, 1, 'Checklist de Configuración SEO')
ON DUPLICATE KEY UPDATE `id`=`id`;

INSERT INTO `checklist_items` (`checklist_id`, `title`, `is_completed`) VALUES
(1, 'Verificación de responsive en móviles y tablets', 1),
(1, 'Pruebas de cobro en sandbox con tarjeta de test', 1),
(1, 'Test de recuperación de contraseña por email', 0),
(1, 'Revisión de políticas de privacidad y términos', 0),
(2, 'Metadatos Open Graph y Twitter Cards', 1),
(2, 'Generación de sitemap.xml y robots.txt', 0);

INSERT INTO `tickets` (`project_id`, `user_id`, `title`, `description`, `priority`, `status`) VALUES
(1, 1, 'Error al autocompletar dirección de entrega', 'En iOS Safari el dropdown de ciudades se oculta detrás del modal.', 'alta', 'abierto'),
(1, 1, 'Ajuste de textos en el email de confirmación', 'El cliente solicita cambiar el remitente y logo corporativo.', 'baja', 'resuelto');

INSERT INTO `project_links` (`project_id`, `folder_id`, `title`, `url`) VALUES
(1, 1, 'Figma - Sistema de Diseño', 'https://www.figma.com'),
(1, 2, 'Repositorio GitHub', 'https://github.com'),
(1, 2, 'Documentación de API Stripe', 'https://stripe.com/docs');

INSERT INTO `comments` (`project_id`, `user_id`, `content`) VALUES
(1, 1, '¡Hemos avanzado un 50% del proyecto esta semana! Recuerden revisar el checklist de QA antes del viernes.'),
(1, 1, 'Se han subido las credenciales de prueba al entorno de staging.');
