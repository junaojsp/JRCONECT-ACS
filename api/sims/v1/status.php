<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../lib/SIMSAuth.php';
require_once __DIR__ . '/../../../lib/SIMSIntegration.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function simsStatusOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    jrSimsAuthorize();

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        simsStatusOut(['success' => false, 'message' => 'Método não permitido.'], 405);
    }

    $idLogin = trim((string)($_GET['id_login'] ?? ''));
    $idContrato = trim((string)($_GET['id_contrato'] ?? ''));

    if ($idLogin === '' && $idContrato === '') {
        simsStatusOut(['success' => false, 'message' => 'Informe id_login ou id_contrato.'], 400);
    }

    $ctx = jrSimsDeviceContext($idLogin, $idContrato);
    $d = $ctx['device'];
    $status = jrSimsStatus($d);

    jrSimsAudit('status_read', [
        'id_login' => $idLogin,
        'id_contrato' => $idContrato,
        'serial' => $ctx['serial'],
        'online' => $status['online'],
    ]);

    simsStatusOut([
        'success' => true,
        'device' => [
            'serial' => $ctx['serial'],
            'device_id' => $ctx['device_id'],
            'model' => jrSimsGet($d, '_deviceId._ProductClass')
                ?? jrSimsGet($d, 'InternetGatewayDevice.DeviceInfo.ProductClass'),
            'manufacturer' => jrSimsGet($d, '_deviceId._Manufacturer')
                ?? jrSimsGet($d, 'InternetGatewayDevice.DeviceInfo.Manufacturer'),
            'online' => $status['online'],
            'last_inform' => $status['last_inform'],
            'last_inform_age_seconds' => $status['last_inform_age_seconds'],
        ],
    ]);
} catch (Throwable $e) {
    jrSimsAudit('status_error', ['message' => $e->getMessage()]);
    simsStatusOut(['success' => false, 'message' => $e->getMessage()], 502);
}
