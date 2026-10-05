<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

// Network locations and topology are managed in IXC.
header('Location: /devices.php', true, 302);
exit;
