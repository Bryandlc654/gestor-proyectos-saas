<?php
// Solo ejecutable desde línea de comandos: php migrations/create_settings_table.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/app.php';
$db = getDB();

$db->exec("
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Insertar valores por defecto si no existen
$stmt = $db->query("SELECT COUNT(*) FROM app_settings WHERE setting_key = 'app_name'");
if ($stmt->fetchColumn() == 0) {
    $db->exec("INSERT INTO app_settings (setting_key, setting_value) VALUES 
        ('app_name', 'FlowTasks'),
        ('app_logo', ''),
        ('app_logo_type', 'icon')");
}

echo "app_settings migration executed successfully.\n";
