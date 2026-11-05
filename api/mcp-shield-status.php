<?php
declare(strict_types=1);

// Read-only health indicator for the ACS SHIELD card.
// Never reads credentials, exposes private telemetry or invokes mitigation APIs.
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
requireLogin();

$ch = curl_init('http://127.0.0.1:3100/health');
if ($ch === false) {
    jsonResponse(['success' => true, 'mcp_reachable' => false]);
    exit;
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 3,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_MAXREDIRS => 0,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = is_string($body) ? json_decode($body, true) : null;
$healthy = $code === 200 && is_array($data) && ($data['status'] ?? null) === 'ok';
jsonResponse([
    'success' => true,
    'mcp_reachable' => $healthy,
    'shield_auth_verified' => false,
    'shield_admin_verified' => false,
    'endpoint' => 'https://mcp.jrconect.com/mcp',
]);
