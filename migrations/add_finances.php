<?php
// Solo ejecutable desde línea de comandos: php migrations/add_finances.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/app.php';

$db = getDB();

// 1. Agregar columnas a projects
$cols = $db->query("SHOW COLUMNS FROM projects LIKE 'budget'")->fetchAll();
if (empty($cols)) {
    $db->exec("ALTER TABLE projects 
        ADD COLUMN budget DECIMAL(12,2) DEFAULT 0.00 AFTER deadline,
        ADD COLUMN currency VARCHAR(10) DEFAULT 'USD' AFTER budget");
    echo "Columns budget and currency added to projects.\n";
} else {
    echo "Projects table already has budget column.\n";
}

// 2. Crear tabla invoices
$db->exec("CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    concept VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'USD',
    status ENUM('pendiente', 'pagada', 'anulada') DEFAULT 'pendiente',
    due_date DATE NULL,
    paid_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "Invoices table ready.\n";

// 3. Sembrar datos de ejemplo para proyectos existentes si tienen budget = 0
$p1 = $db->query("SELECT id, budget FROM projects WHERE id = 1")->fetch();
if ($p1 && floatval($p1['budget']) == 0) {
    $db->exec("UPDATE projects SET budget = 4500.00, currency = 'USD' WHERE id = 1");
    // Insertar un par de facturas de ejemplo para el proyecto 1
    $db->exec("INSERT INTO invoices (project_id, invoice_number, concept, amount, currency, status, due_date, paid_at, notes) VALUES
        (1, 'INV-2026-001', 'Anticipo inicial 50% - Rediseño E-commerce', 2250.00, 'USD', 'pagada', '2026-10-15', '2026-10-14', 'Pagado mediante transferencia bancaria'),
        (1, 'INV-2026-002', 'Hito 2: Prototipado y Maquetación Frontend', 1250.00, 'USD', 'pendiente', '2026-11-15', NULL, 'Pendiente de cobro al validar maquetas')");
    echo "Sample budget and invoices added to Project 1.\n";
}

$p2 = $db->query("SELECT id, budget FROM projects WHERE id = 2")->fetch();
if ($p2 && floatval($p2['budget']) == 0) {
    $db->exec("UPDATE projects SET budget = 8000.00, currency = 'USD' WHERE id = 2");
    $db->exec("INSERT INTO invoices (project_id, invoice_number, concept, amount, currency, status, due_date, notes) VALUES
        (2, 'INV-2026-003', 'Hito inicial - Arquitectura y Base de Datos', 3000.00, 'USD', 'pendiente', '2026-11-30', 'Factura por cobrar')");
    echo "Sample budget and invoice added to Project 2.\n";
}
