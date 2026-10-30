<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Alertas';
$currentPage = 'alerts';
$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';
?>
<link rel="stylesheet" href="/assets/css/jrc-ipam.css?v=e0fd86c6df">
<div id="jrc-ipam" data-jrc="JRC_IPAM_V1"></div>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
<script src="/assets/js/jrc-ipam.js?v=e84f7dea0d" defer></script>
