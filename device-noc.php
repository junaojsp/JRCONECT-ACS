<?php
/** Compatibility entry: keep the operational device page and all of its controls. */
require_once __DIR__ . '/config/config.php';
requireLogin();
$deviceId = $_GET['id'] ?? '';
if (!is_string($deviceId) || $deviceId === '' || strlen($deviceId) > 1024) {
    header('Location: /devices.php');
    exit;
}
header('Location: /device-detail.php?id=' . rawurlencode($deviceId), true, 302);
exit;
