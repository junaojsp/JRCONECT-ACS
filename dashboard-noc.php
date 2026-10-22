<?php
/** Compatibility entry: the visual theme belongs to the existing operational page. */
require_once __DIR__ . '/config/config.php';
requireLogin();
header('Location: /dashboard.php', true, 302);
exit;
