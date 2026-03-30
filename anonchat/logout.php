<?php
require_once __DIR__ . '/api/session.php';

start_secure_session();
destroy_session();

// Redirigir al inicio (index.php)
header('Location: index.php');
exit;
?>