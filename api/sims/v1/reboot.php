<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../lib/SIMSAuth.php';
require_once __DIR__ . '/../../../lib/SIMSIntegration.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function simsRebootOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    jrSimsAuthorize();

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST')) !== 'POST') {
        simsRebootOut(['success' => false, 'message' => 'Método não permitido.'], 405);
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        simsRebootOut(['success' => false, 'message' => 'JSON inválido.'], 400);
    }

    $idLogin = trim((string)($input['id_login'] ?? ''));
    $idContrato = trim((string)($input['id_contrato'] ?? ''));

    if ($idLogin === '' && $idContrato === '') {
        simsRebootOut(['success' => false, 'message' => 'Informe id_login ou id_contrato.'], 400);
    }

    $ctx = jrSimsDeviceContext($idLogin, $idContrato);
    if ($ctx['device_id'] === '') {
        throw new RuntimeException('Device ID não disponível no GenieACS.');
    }

    $result = $ctx['genie']->rebootDevice($ctx['device_id']);

    jrSimsAudit(!empty($result['success']) ? 'reboot' : 'reboot_failed', [
        'id_login' => $idLogin,
        'id_contrato' => $idContrato,
        'serial' => $ctx['serial'],
        'http_code' => $result['http_code'] ?? null,
        'error' => $result['error'] ?? null,
    ]);

    if (empty($result['success'])) {
        simsRebootOut([
            'success' => false,
            'message' => $result['error'] ?? 'Não foi possível enviar o reboot.',
            'http_code' => $result['http_code'] ?? null,
        ], 502);
    }

    simsRebootOut([
        'success' => true,
        'message' => 'Comando de reboot enviado ao equipamento.',
        'device' => [
            'serial' => $ctx['serial'],
            'device_id' => $ctx['device_id'],
        ],
        'task' => [
            'http_code' => $result['http_code'] ?? null,
            'status' => (($result['http_code'] ?? 0) === 200) ? 'applied' : 'queued',
        ],
    ]);
} catch (Throwable $e) {
    jrSimsAudit('reboot_error', ['message' => $e->getMessage()]);
    simsRebootOut(['success' => false, 'message' => $e->getMessage()], 502);
}
