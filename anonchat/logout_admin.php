<?php
// Logout admin seguro
require_once __DIR__ . '/api/session.php';

start_secure_session();
destroy_session();

header('Location: admin.php');
exit;