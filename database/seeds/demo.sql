-- Datos de demostración (solo para entornos locales). Contraseña de todas las cuentas: admin123
-- Uso: php database/migrate.php --seed

-- Dos agencias independientes: cada una solo ve sus propios usuarios y proyectos
-- Códigos de conexión fijos para poder probar la conexión entre agencias
INSERT INTO `agencies` (`id`, `name`, `connect_code`) VALUES
(1, 'Agencia Demo', 'AGDM-2345'),
(2, 'Estudio Norte', 'NRTE-6789')
ON DUPLICATE KEY UPDATE `id`=`id`;

INSERT INTO `users` (`id`, `agency_id`, `name`, `email`, `role`, `password`) VALUES
(1, 1, 'Administrador Demo', 'admin@demo.com', 'admin', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6'),
(2, 1, 'Carlos PM', 'pm@demo.com', 'project_manager', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6'),
(3, 1, 'Ana Colaboradora', 'colaborador@demo.com', 'colaborador', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6'),
(4, 1, 'Roberto Cliente', 'cliente@demo.com', 'cliente', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6'),
(5, 2, 'Lucía Norte', 'admin@norte.com', 'admin', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6'),
(6, 2, 'Diego Norte', 'colaborador@norte.com', 'colaborador', '$2y$10$3ZZzNvgVe8cVluIKNJM8DeWT04pOpeKl8zd0NqDtv/h/pe9yjRVq6')
ON DUPLICATE KEY UPDATE `role`=VALUES(`role`);

INSERT INTO `projects` (`id`, `agency_id`, `user_id`, `name`, `description`, `category`, `status`, `color`, `deadline`) VALUES
(1, 1, 1, 'Rediseño E-commerce 2026', 'Modernización de la interfaz de usuario, pasarela de pagos y panel de control para clientes.', 'Diseño & UI/UX', 'activo', '#6366F1', DATE_ADD(CURRENT_DATE, INTERVAL 21 DAY)),
(2, 1, 1, 'App Móvil de Entregas', 'Aplicación interna para seguimiento y asignación de rutas a repartidores.', 'Móvil', 'activo', '#0EA5E9', DATE_ADD(CURRENT_DATE, INTERVAL 45 DAY)),
(3, 2, 5, 'Web Corporativa Norte', 'Sitio institucional de Estudio Norte.', 'Desarrollo Web', 'activo', '#10B981', DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY))
ON DUPLICATE KEY UPDATE `id`=`id`;

INSERT INTO `project_folders` (`id`, `project_id`, `name`, `color`) VALUES
(1, 1, 'Diseño & Wireframes', '#6366F1'),
(2, 1, 'Documentación & APIs', '#0EA5E9')
ON DUPLICATE KEY UPDATE `id`=`id`;

INSERT INTO `tasks` (`project_id`, `title`, `description`, `priority`, `status`, `assigned_user_id`, `due_date`) VALUES
(1, 'Diseño de Wireframes en Figma', 'Crear flujo completo de checkout y carrito interactivo.', 'alta', 'completada', 3, DATE_ADD(CURRENT_DATE, INTERVAL -2 DAY)),
(1, 'Integración de Pasarela Stripe', 'Configurar webhooks y cobros con tarjetas internacionales.', 'urgente', 'en_progreso', 3, DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY)),
(1, 'Optimización Core Web Vitals', 'Comprimir imágenes webp y revisar script de Tailwind.', 'media', 'pendiente', NULL, DATE_ADD(CURRENT_DATE, INTERVAL 10 DAY));

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

-- Miembros de los proyectos demo
INSERT IGNORE INTO `project_members` (`project_id`, `user_id`, `role`) VALUES
(1, 1, 'project_manager'), (1, 2, 'project_manager'), (1, 3, 'colaborador'), (1, 4, 'cliente'),
(2, 1, 'project_manager'), (2, 2, 'project_manager'), (2, 3, 'colaborador'),
(3, 5, 'project_manager'), (3, 6, 'colaborador');

INSERT INTO `tasks` (`project_id`, `title`, `description`, `priority`, `status`, `assigned_user_id`) VALUES
(3, 'Maquetar página de inicio', 'Tarea interna de Estudio Norte.', 'media', 'pendiente', 6);

-- Presupuestos y facturas de ejemplo
UPDATE `projects` SET `budget` = 4500.00, `currency` = 'USD' WHERE `id` = 1;
UPDATE `projects` SET `budget` = 8000.00, `currency` = 'USD' WHERE `id` = 2;
INSERT IGNORE INTO `invoices` (`project_id`, `invoice_number`, `concept`, `amount`, `currency`, `status`, `due_date`, `paid_at`, `notes`) VALUES
(1, 'INV-2026-001', 'Anticipo inicial 50% - Rediseño E-commerce', 2250.00, 'USD', 'pagada', DATE_ADD(CURRENT_DATE, INTERVAL -15 DAY), DATE_ADD(CURRENT_DATE, INTERVAL -16 DAY), 'Pagado mediante transferencia bancaria'),
(1, 'INV-2026-002', 'Hito 2: Prototipado y Maquetación Frontend', 1250.00, 'USD', 'pendiente', DATE_ADD(CURRENT_DATE, INTERVAL 15 DAY), NULL, 'Pendiente de cobro al validar maquetas'),
(2, 'INV-2026-001', 'Hito inicial - Arquitectura y Base de Datos', 3000.00, 'USD', 'pendiente', DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY), NULL, 'Factura por cobrar');
