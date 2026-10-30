<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Endereçamento IP';
$currentPage = 'ipam';
$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';
?>
<link rel="stylesheet" href="/assets/css/jrc-ipam.css?v=20261030-table3">
<div id="jrc-ipam" data-jrc="JRC_IPAM_V1"></div>
<script src="/assets/js/jrc-ipam.js?v=20261030-freeips5" defer></script>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
