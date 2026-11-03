<?php
// index.php
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
