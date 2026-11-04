<?php
/** Compatibility entry: retain inventory filters, bulk actions and device controls. */
require_once __DIR__ . '/config/config.php';
requireLogin();
header('Location: /devices.php', true, 302);
exit;
