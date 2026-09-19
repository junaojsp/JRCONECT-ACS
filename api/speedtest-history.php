<?php

require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');


function speedHistoryResponse(
    array $data,
    int $code = 200
): void {

    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


function speedHistoryInput(): array
{
    $raw = file_get_contents('php://input');

    if (!$raw) {
        return $_POST ?: $_GET;
    }

    $data = json_decode($raw, true);

    return is_array($data)
        ? $data
        : [];
}


try {

    $input = speedHistoryInput();

    $action =
        trim(
            $input['action'] ?? ''
        );


    $conn =
        getDBConnection();


    /* =====================================================
       SALVAR TESTE
       ===================================================== */

    if ($action === 'save') {

        $deviceId =
            trim(
                $input['device_id'] ?? ''
            );

        if ($deviceId === '') {

            speedHistoryResponse(
                [
                    'success' => false,
                    'message' => 'Device ID não informado.'
                ],
                400
            );
        }


        $serial =
            trim(
                $input['serial_number'] ?? ''
            );


        $download =
            isset(
                $input['download_mbps']
            )
                ? (float)$input['download_mbps']
                : null;


        $upload =
            isset(
                $input['upload_mbps']
            )
                ? (float)$input['upload_mbps']
                : null;


        $downloadDuration =
            isset(
                $input['download_duration']
            )
                ? (float)$input['download_duration']
                : null;


        $uploadDuration =
            isset(
                $input['upload_duration']
            )
                ? (float)$input['upload_duration']
                : null;


        $totalDuration =
            isset(
                $input['total_duration']
            )
                ? (float)$input['total_duration']
                : null;


        $downloadBytes =
            isset(
                $input['download_bytes']
            )
                ? (int)$input['download_bytes']
                : null;


        $uploadBytes =
            isset(
                $input['upload_bytes']
            )
                ? (int)$input['upload_bytes']
                : null;


        $multipleStreams =
            !empty(
                $input['multiple_streams']
            )
                ? 1
                : 0;


        $createdBy =
            $_SESSION['username']
            ?? 'desconhecido';


        $stmt =
            $conn->prepare(
                "
                INSERT INTO onu_speedtest_history
                (
                    device_id,
                    serial_number,
                    download_mbps,
                    upload_mbps,
                    download_duration,
                    upload_duration,
                    total_duration,
                    download_bytes,
                    upload_bytes,
                    multiple_streams,
                    created_by
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                "
            );


        if (!$stmt) {

            throw new Exception(
                'Erro ao preparar gravação do histórico.'
            );
        }


        $stmt->bind_param(
            'ssdddddiiis',
            $deviceId,
            $serial,
            $download,
            $upload,
            $downloadDuration,
            $uploadDuration,
            $totalDuration,
            $downloadBytes,
            $uploadBytes,
            $multipleStreams,
            $createdBy
        );


        $stmt->execute();


        speedHistoryResponse(
            [
                'success' => true,
                'id' => $stmt->insert_id,
                'message' => 'Teste salvo no histórico.'
            ]
        );
    }


    /* =====================================================
       LISTAR HISTORICO
       ===================================================== */

    if ($action === 'list') {

        $deviceId =
            trim(
                $input['device_id'] ?? ''
            );


        if ($deviceId === '') {

            speedHistoryResponse(
                [
                    'success' => false,
                    'message' => 'Device ID não informado.'
                ],
                400
            );
        }


        $stmt =
            $conn->prepare(
                "
                SELECT
                    id,
                    device_id,
                    serial_number,
                    download_mbps,
                    upload_mbps,
                    download_duration,
                    upload_duration,
                    total_duration,
                    multiple_streams,
                    server_name,
                    created_by,
                    created_at
                FROM onu_speedtest_history
                WHERE device_id = ?
                ORDER BY created_at DESC
                LIMIT 30
                "
            );


        $stmt->bind_param(
            's',
            $deviceId
        );


        $stmt->execute();


        $result =
            $stmt->get_result();


        $rows = [];


        while (
            $row =
            $result->fetch_assoc()
        ) {

            $rows[] =
                $row;
        }


        speedHistoryResponse(
            [
                'success' => true,
                'history' => $rows
            ]
        );
    }


    speedHistoryResponse(
        [
            'success' => false,
            'message' => 'Ação inválida.'
        ],
        400
    );


} catch (Throwable $e) {

    speedHistoryResponse(
        [
            'success' => false,
            'message' => $e->getMessage()
        ],
        500
    );
}
