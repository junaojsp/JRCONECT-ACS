<?php

declare(strict_types=1);

use App\CPEProfiles;

/*
 * =========================================================
 * JR CONECT ACS
 * GET ONU OPTICAL - VIA IXC API
 * =========================================================
 *
 * Arquivo:
 * /var/www/gacs/api/get-onu-optical.php
 *
 * Fluxo:
 *
 * device_id
 *    ↓
 * GenieACS
 *    ↓
 * Serial da ONU
 *    ↓
 * IXC API
 *    ↓
 * radpop_radio_cliente_fibra
 *    ↓
 * busca pelo campo MAC
 *    ↓
 * RX / TX / Temperatura / Voltagem / PON
 *
 * =========================================================
 */


/* =========================================================
   CONFIGURAÇÃO DO PAINEL
   ========================================================= */

require_once __DIR__ . '/../config/config.php';


if (function_exists('requireLogin')) {
    requireLogin();
}


/* =========================================================
   HEADER
   ========================================================= */

header('Content-Type: application/json; charset=utf-8');


/* =========================================================
   CONFIGURAÇÃO IXC
   ========================================================= */

$ixcBaseUrl =
    'https://cda.jrconect.com';


/*
 * COLE AQUI O TOKEN BRUTO DO IXC.
 *
 * Exemplo:
 *
 * 37:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 *
 * NÃO coloque "Basic ".
 *
 * O próprio código faz:
 *
 * base64_encode($ixcToken)
 */

$ixcToken =
    '37:052335621c659145292a29b3d08c39318380211e2a9b5cd1ad621f1cefc6bd6e';


/* =========================================================
   RESPOSTA JSON
   ========================================================= */

function jrOpticalJson(
    array $data,
    int $status = 200
): void {

    http_response_code($status);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
   NORMALIZAR SERIAL
   ========================================================= */

function jrNormalizeOpticalSerial(
    string $value
): string {

    return strtoupper(
        preg_replace(
            '/[^A-Z0-9]/i',
            '',
            trim($value)
        )
    );
}


/* =========================================================
   CONVERTER NUMERO IXC
   ========================================================= */

function jrOpticalNumber(
    mixed $value
): ?float {

    if (
        $value === null ||
        $value === ''
    ) {
        return null;
    }


    $value =
        str_replace(
            ',',
            '.',
            trim(
                (string)$value
            )
        );


    if (!is_numeric($value)) {
        return null;
    }


    return (float)$value;
}


/* =========================================================
   CONSULTAR GENIEACS
   ========================================================= */

function jrGetGenieDevice(
    string $deviceId
): array {

    if (!function_exists('curl_init')) {

        throw new RuntimeException(
            'Extensão PHP cURL não instalada.'
        );
    }


    $query =
        json_encode(
            [
                '_id' =>
                    $deviceId
            ]
        );


    if ($query === false) {

        throw new RuntimeException(
            'Erro ao gerar query para GenieACS.'
        );
    }


    $url =
        'http://127.0.0.1:7557/devices/?query=' .
        rawurlencode($query);


    $ch =
        curl_init();


    if ($ch === false) {

        throw new RuntimeException(
            'Não foi possível iniciar cURL para GenieACS.'
        );
    }


    curl_setopt_array(
        $ch,
        [
            CURLOPT_URL =>
                $url,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                5,

            CURLOPT_TIMEOUT =>
                15,

            CURLOPT_HTTPHEADER =>
                [
                    'Accept: application/json'
                ]
        ]
    );


    $response =
        curl_exec($ch);


    if ($response === false) {

        $error =
            curl_error($ch);


        curl_close($ch);


        throw new RuntimeException(
            'Erro ao consultar GenieACS: ' .
            $error
        );
    }


    $httpCode =
        (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close($ch);


    if ($httpCode !== 200) {

        throw new RuntimeException(
            'GenieACS retornou HTTP ' .
            $httpCode
        );
    }


    $json =
        json_decode(
            $response,
            true
        );


    if (
        !is_array($json) ||
        empty($json[0])
    ) {

        throw new RuntimeException(
            'Equipamento não encontrado no GenieACS.'
        );
    }


    return $json[0];
}


/* =========================================================
   EXTRAIR SERIAL DO DEVICE
   ========================================================= */

function jrGetSerialFromDevice(
    array $device
): string {

    $candidates =
        [
            $device
                ['InternetGatewayDevice']
                ['DeviceInfo']
                ['SerialNumber']
                ['_value']
                ?? null,

            $device
                ['Device']
                ['DeviceInfo']
                ['SerialNumber']
                ['_value']
                ?? null,

            $device
                ['_deviceId']
                ['_SerialNumber']
                ?? null
        ];


    foreach ($candidates as $serial) {

        if (
            is_string($serial) &&
            trim($serial) !== ''
        ) {

            return jrNormalizeOpticalSerial(
                $serial
            );
        }
    }


    throw new RuntimeException(
        'Serial Number não encontrado no TR-069.'
    );
}


/* =========================================================
   EXTRAIR FABRICANTE
   ========================================================= */

function jrGetManufacturerFromDevice(
    array $device
): string {

    $candidates =
        [
            $device
                ['InternetGatewayDevice']
                ['DeviceInfo']
                ['Manufacturer']
                ['_value']
                ?? null,

            $device
                ['Device']
                ['DeviceInfo']
                ['Manufacturer']
                ['_value']
                ?? null,

            $device
                ['_deviceId']
                ['_Manufacturer']
                ?? null
        ];


    foreach ($candidates as $manufacturer) {

        if (
            is_string($manufacturer) &&
            trim($manufacturer) !== ''
        ) {

            return trim($manufacturer);
        }
    }


    return '';
}


/* =========================================================
   REQUEST IXC
   ========================================================= */

function jrIxcOpticalRequest(
    string $baseUrl,
    string $token,
    string $endpoint,
    array $params
): array {

    if (!function_exists('curl_init')) {

        throw new RuntimeException(
            'Extensão PHP cURL não instalada.'
        );
    }


    $url =
        rtrim($baseUrl, '/') .
        '/webservice/v1/' .
        ltrim($endpoint, '/');


    /*
     * IXC usa:
     *
     * Authorization: Basic BASE64(TOKEN_BRUTO)
     */

    $authorization =
        'Basic ' .
        base64_encode($token);


    $body =
        json_encode(
            $params,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );


    if ($body === false) {

        throw new RuntimeException(
            'Falha ao gerar JSON da consulta IXC.'
        );
    }


    $ch =
        curl_init();


    if ($ch === false) {

        throw new RuntimeException(
            'Não foi possível iniciar cURL para IXC.'
        );
    }


    curl_setopt_array(
        $ch,
        [
            CURLOPT_URL =>
                $url,

            CURLOPT_POST =>
                true,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                5,

            CURLOPT_TIMEOUT =>
                15,

            CURLOPT_FOLLOWLOCATION =>
                false,

            CURLOPT_HTTPHEADER =>
                [
                    'Content-Type: application/json',

                    'Accept: application/json',

                    'ixcsoft: listar',

                    'Authorization: ' .
                    $authorization
                ],

            CURLOPT_POSTFIELDS =>
                $body
        ]
    );


    $response =
        curl_exec($ch);


    if ($response === false) {

        $errno =
            curl_errno($ch);

        $error =
            curl_error($ch);

        curl_close($ch);


        throw new RuntimeException(
            'Erro cURL IXC (' .
            $errno .
            '): ' .
            $error
        );
    }


    $httpCode =
        (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close($ch);


    $json =
        json_decode(
            $response,
            true
        );


    return [
        'http_code' =>
            $httpCode,

        'raw' =>
            $response,

        'json' =>
            is_array($json)
                ? $json
                : null
    ];
}


/* =========================================================
   DEFINIR STATUS OPTICO
   =========================================================
 *
 * Por enquanto:
 *
 * Se o IXC devolver valor numérico,
 * usamos "Normal".
 *
 * Depois podemos evoluir para
 * níveis:
 *
 * Normal
 * Atenção
 * Crítico
 *
 * conforme faixa RX.
 * =========================================================
 */

function jrOpticalStatus(
    ?float $value
): ?string {

    if ($value === null) {
        return null;
    }


    return 'Normal';
}


/* =========================================================
   EXECUÇÃO PRINCIPAL
   ========================================================= */

try {

    /* =====================================================
       DEVICE ID
       ===================================================== */

    $deviceId =
        trim(
            (string)(
                $_GET['device_id']
                ?? ''
            )
        );


    if ($deviceId === '') {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'message' =>
                    'device_id não informado.'
            ],
            400
        );
    }


    /* =====================================================
       VALIDAR CONFIG IXC
       ===================================================== */

    if (
        $ixcToken === '' ||
        str_contains(
            $ixcToken,
            'COLE_AQUI'
        )
    ) {

        throw new RuntimeException(
            'Configure $ixcToken com o token bruto do IXC.'
        );
    }


    /* =====================================================
       CONSULTAR GENIEACS
       ===================================================== */

    $device =
        jrGetGenieDevice(
            $deviceId
        );


    $serial =
        jrGetSerialFromDevice(
            $device
        );


    $manufacturer =
        jrGetManufacturerFromDevice(
            $device
        );

    // Perfil CPE: permite usar dados ópticos diretamente do TR-069
    // quando o IXC não possui leitura para este modelo.
    $profileOptical = CPEProfiles::optical($device);
    $hasProfileOptical =
        (($profileOptical['rx_power'] ?? null) !== null) ||
        (($profileOptical['tx_power'] ?? null) !== null) ||
        (($profileOptical['temperature'] ?? null) !== null) ||
        (($profileOptical['voltage'] ?? null) !== null);


    /* =====================================================
       CONSULTAR IXC
       ===================================================== */

    $endpoint =
        'radpop_radio_cliente_fibra';


    $params =
        [
            'qtype' =>
                'radpop_radio_cliente_fibra.mac',

            'query' =>
                $serial,

            'oper' =>
                '=',

            'page' =>
                '1',

            'rp' =>
                '20',

            'sortname' =>
                'radpop_radio_cliente_fibra.id',

            'sortorder' =>
                'desc'
        ];


    $result =
        jrIxcOpticalRequest(
            $ixcBaseUrl,
            $ixcToken,
            $endpoint,
            $params
        );


    /* =====================================================
       VALIDAR HTTP
       ===================================================== */

    if (
        $result['http_code'] === 401
    ) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'authentication',

                'message' =>
                    'IXC recusou autenticação.'
            ],
            401
        );
    }


    if (
        $result['http_code'] < 200 ||
        $result['http_code'] >= 300
    ) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'http',

                'http_code' =>
                    $result['http_code'],

                'message' =>
                    'IXC retornou HTTP inesperado.'
            ],
            502
        );
    }


    /* =====================================================
       VALIDAR JSON
       ===================================================== */

    if ($result['json'] === null) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'json',

                'message' =>
                    'IXC respondeu, mas a resposta não é JSON válido.'
            ],
            502
        );
    }


    $json =
        $result['json'];


    /* =====================================================
       ERRO IXC
       ===================================================== */

    if (
        isset($json['type']) &&
        $json['type'] === 'error'
    ) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'ixc',

                'message' =>
                    $json['message']
                    ?? 'IXC retornou erro.'
            ],
            422
        );
    }


    /* =====================================================
       REGISTROS
       ===================================================== */

    $records =
        [];


    if (
        isset($json['registros']) &&
        is_array($json['registros'])
    ) {

        $records =
            $json['registros'];

    } elseif (
        isset($json['records']) &&
        is_array($json['records'])
    ) {

        $records =
            $json['records'];

    } elseif (
        array_is_list($json)
    ) {

        $records =
            $json;
    }


    /* =====================================================
       ONU NÃO ENCONTRADA
       ===================================================== */

    if (count($records) === 0) {

        if ($hasProfileOptical) {
            jrOpticalJson(
                [
                    'success' => true,
                    'source' => 'TR-069 / Perfil CPE',
                    'device_id' => $deviceId,
                    'serial' => $serial,
                    'manufacturer' => $manufacturer,
                    'profile' => $profileOptical['profile'] ?? null,
                    'ixc_id' => null,
                    'nome' => null,
                    'id_login' => null,
                    'id_contrato' => null,
                    'pon_id' => null,
                    'onu_number' => null,
                    'slot' => null,
                    'pon' => null,
                    'optical' => [
                        'rx_power' => $profileOptical['rx_power'] ?? null,
                        'rx_status' => jrOpticalStatus($profileOptical['rx_power'] ?? null),
                        'tx_power' => $profileOptical['tx_power'] ?? null,
                        'tx_status' => jrOpticalStatus($profileOptical['tx_power'] ?? null),
                        'temperature' => $profileOptical['temperature'] ?? null,
                        'temperature_status' => jrOpticalStatus($profileOptical['temperature'] ?? null),
                        'voltage' => $profileOptical['voltage'] ?? null,
                        'voltage_status' => jrOpticalStatus($profileOptical['voltage'] ?? null),
                        'last_update' => $profileOptical['last_update'] ?? null,
                    ],
                ]
            );
        }

        jrOpticalJson(
            [
                'success' => false,
                'source' => 'IXC',
                'stage' => 'search',
                'device_id' => $deviceId,
                'serial' => $serial,
                'manufacturer' => $manufacturer,
                'message' => 'ONU não encontrada no Cliente Fibra do IXC e sem leitura óptica no perfil CPE.'
            ],
            404
        );
    }


    /* =====================================================
       LOCALIZAR REGISTRO EXATO
       ===================================================== */

    $record =
        null;


    foreach ($records as $item) {

        if (!is_array($item)) {
            continue;
        }


        $itemMac =
            jrNormalizeOpticalSerial(
                (string)(
                    $item['mac']
                    ?? ''
                )
            );


        if (
            $itemMac ===
            $serial
        ) {

            $record =
                $item;

            break;
        }
    }


    /*
     * Se IXC já filtrou e só devolveu um registro,
     * usamos ele.
     */

    if (
        $record === null &&
        count($records) === 1
    ) {

        $record =
            $records[0];
    }


    if ($record === null) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'match',

                'device_id' =>
                    $deviceId,

                'serial' =>
                    $serial,

                'message' =>
                    'IXC retornou registros, mas nenhum corresponde ao serial da ONU.'
            ],
            404
        );
    }


    /* =====================================================
       EXTRAIR DADOS OPTICOS
       ===================================================== */

    $rx =
        jrOpticalNumber(
            $record['sinal_rx']
            ?? null
        );


    $tx =
        jrOpticalNumber(
            $record['sinal_tx']
            ?? null
        );


    $temperature =
        jrOpticalNumber(
            $record['temperatura']
            ?? null
        );


    $voltage =
        jrOpticalNumber(
            $record['voltagem']
            ?? null
        );

    // Completa campos ausentes do IXC com o perfil TR-069 do equipamento.
    if ($rx === null) $rx = $profileOptical['rx_power'] ?? null;
    if ($tx === null) $tx = $profileOptical['tx_power'] ?? null;
    if ($temperature === null) $temperature = $profileOptical['temperature'] ?? null;
    if ($voltage !== null && $voltage <= 0) $voltage = null;
    if ($voltage === null) $voltage = $profileOptical['voltage'] ?? null;
    if ($voltage !== null && $voltage <= 0) $voltage = null;


    /* =====================================================
       PON
       ===================================================== */

    $ponId =
        $record['ponid']
        ?? null;


    $onuNumber =
        $record['onu_numero']
        ?? null;


    $slot =
        $record['slotno']
        ?? null;


    $pon =
        $record['ponno']
        ?? null;


    $lastUpdate =
        $record['data_sinal']
        ?? $profileOptical['last_update']
        ?? null;


    /* =====================================================
       RESPOSTA FINAL
       ===================================================== */

    jrOpticalJson(
        [
            'success' =>
                true,

            'source' =>
                ($hasProfileOptical ? 'IXC + TR-069 / Perfil CPE' : 'IXC'),

            'device_id' =>
                $deviceId,

            'manufacturer' =>
                $manufacturer,

            'serial' =>
                $serial,

            'ixc_id' =>
                $record['id']
                ?? null,

            'nome' =>
                $record['nome']
                ?? null,

            'id_login' =>
                $record['id_login']
                ?? null,

            'id_contrato' =>
                $record['id_contrato']
                ?? null,

            'id_transmissor' =>
                $record['id_transmissor']
                ?? null,

            'pon_id' =>
                $ponId,

            'onu_number' =>
                $onuNumber,

            'slot' =>
                $slot,

            'pon' =>
                $pon,

            'optical' =>
                [
                    'rx_power' =>
                        $rx,

                    'rx_status' =>
                        jrOpticalStatus($rx),

                    'tx_power' =>
                        $tx,

                    'tx_status' =>
                        jrOpticalStatus($tx),

                    'temperature' =>
                        $temperature,

                    'temperature_status' =>
                        jrOpticalStatus($temperature),

                    'voltage' =>
                        $voltage,

                    'voltage_status' =>
                        jrOpticalStatus($voltage),

                    'last_update' =>
                        $lastUpdate
                ]
        ]
    );


} catch (Throwable $e) {

    jrOpticalJson(
        [
            'success' =>
                false,

            'source' =>
                'IXC',

            'stage' =>
                'php',

            'error_type' =>
                get_class($e),

            'message' =>
                $e->getMessage(),

            'line' =>
                $e->getLine()
        ],
        500
    );
}