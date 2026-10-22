<?php

declare(strict_types=1);

/*
 * =========================================================
 * JR CONECT ACS
 * DIAGNOSTICO DE CAPACIDADES TR-143
 * =========================================================
 *
 * Arquivo:
 * /var/www/gacs/api/speedtest-capabilities.php
 *
 * Objetivo:
 * Descobrir quais parametros de DownloadDiagnostics e
 * UploadDiagnostics a ONU realmente suporta.
 *
 * Esta API NAO inicia nenhum teste.
 * Apenas consulta o GenieACS.
 *
 * =========================================================
 */

require_once __DIR__ . '/../config/config.php';

if (function_exists('requireLogin')) {
    requireLogin();
}


/*
 * =========================================================
 * HEADERS
 * =========================================================
 */

header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);


/*
 * =========================================================
 * RESPOSTA JSON
 * =========================================================
 */

function speedtestCapabilityJson(
    array $payload,
    int $status = 200
): never {

    http_response_code(
        $status
    );


    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );


    exit;
}


/*
 * =========================================================
 * GET GENIEACS
 * =========================================================
 */

function speedtestGenieGet(
    string $url,
    int $timeout = 10
): array {

    $ch =
        curl_init();


    curl_setopt_array(
        $ch,
        [

            CURLOPT_URL =>
                $url,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                3,

            CURLOPT_TIMEOUT =>
                $timeout,

            CURLOPT_HTTPHEADER =>
                [

                    'Accept: application/json'

                ]

        ]
    );


    $raw =
        curl_exec(
            $ch
        );


    if (
        $raw === false
    ) {

        $error =
            curl_error(
                $ch
            );


        curl_close(
            $ch
        );


        throw new RuntimeException(
            'Erro ao consultar GenieACS: ' .
            $error
        );
    }


    $httpCode =
        (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close(
        $ch
    );


    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        throw new RuntimeException(

            'GenieACS retornou HTTP ' .

            $httpCode .

            ': ' .

            substr(
                $raw,
                0,
                500
            )
        );
    }


    $decoded =
        json_decode(
            $raw,
            true
        );


    if (
        !is_array(
            $decoded
        )
    ) {

        throw new RuntimeException(
            'Resposta inválida do GenieACS.'
        );
    }


    return $decoded;
}


/*
 * =========================================================
 * PEGAR CAMINHO DE ARRAY
 * =========================================================
 */

function speedtestGetPath(
    array $data,
    string $path
): mixed {

    $parts =
        explode(
            '.',
            $path
        );


    $current =
        $data;


    foreach (
        $parts as $part
    ) {

        if (
            !is_array(
                $current
            ) ||
            !array_key_exists(
                $part,
                $current
            )
        ) {

            return null;
        }


        $current =
            $current[
                $part
            ];
    }


    return $current;
}


/*
 * =========================================================
 * FLATTEN DOS PARAMETROS GENIEACS
 * =========================================================
 */

function speedtestFlattenParameters(
    mixed $node,
    string $prefix = ''
): array {

    $result =
        [];


    if (
        !is_array(
            $node
        )
    ) {

        return $result;
    }


    foreach (
        $node as $key => $value
    ) {

        /*
         * Ignorar metadados internos do GenieACS.
         */

        if (
            str_starts_with(
                (string) $key,
                '_'
            )
        ) {

            continue;
        }


        $path =

            $prefix === ''

                ? (string) $key

                : $prefix .
                    '.' .
                    $key;


        /*
         * Parametro TR-069.
         */

        if (
            is_array(
                $value
            ) &&
            array_key_exists(
                '_value',
                $value
            )
        ) {

            $result[
                $path
            ] =
                $value[
                    '_value'
                ];


            continue;
        }


        /*
         * Subobjeto.
         */

        if (
            is_array(
                $value
            )
        ) {

            $result +=
                speedtestFlattenParameters(
                    $value,
                    $path
                );
        }
    }


    ksort(
        $result
    );


    return $result;
}


/*
 * =========================================================
 * PROCURAR PARAMETRO PELO NOME
 * =========================================================
 */

function speedtestFindParameter(
    array $parameters,
    array $names
): mixed {

    foreach (
        $names as $name
    ) {

        foreach (
            $parameters as $path => $value
        ) {

            $pieces =
                explode(
                    '.',
                    $path
                );


            $last =
                end(
                    $pieces
                );


            if (
                strcasecmp(
                    (string) $last,
                    $name
                ) === 0
            ) {

                return $value;
            }
        }
    }


    return null;
}


/*
 * =========================================================
 * MAIN
 * =========================================================
 */

try {

    /*
     * Exemplo:
     *
     * /api/speedtest-capabilities.php
     * ?device_id=000AC2-HG6143D3-FHTT9BA43D00
     */

    $deviceId =
        trim(
            (string) (
                $_GET[
                    'device_id'
                ] ??
                ''
            )
        );


    if (
        $deviceId === ''
    ) {

        speedtestCapabilityJson(
            [

                'success' =>
                    false,

                'message' =>
                    'Informe device_id na URL.'

            ],
            400
        );
    }


    /*
     * =====================================================
     * QUERY GENIEACS
     * =====================================================
     */

    $query =
        json_encode(
            [

                '_id' =>
                    $deviceId

            ],
            JSON_UNESCAPED_SLASHES
        );


    /*
     * Consultamos TR-098 e TR-181.
     *
     * FiberHome HG6143D3 normalmente aparece
     * utilizando InternetGatewayDevice.
     *
     * Mas deixamos Device.IP também para futuras ONUs.
     */

    $projection =
        implode(
            ',',
            [

                '_id',

                '_lastInform',

                'InternetGatewayDevice.DownloadDiagnostics',

                'InternetGatewayDevice.UploadDiagnostics',

                'Device.IP.Diagnostics.DownloadDiagnostics',

                'Device.IP.Diagnostics.UploadDiagnostics'

            ]
        );


    $url =

        'http://127.0.0.1:7557/devices/?query=' .

        rawurlencode(
            $query
        ) .

        '&projection=' .

        rawurlencode(
            $projection
        );


    $devices =
        speedtestGenieGet(
            $url,
            10
        );


    if (
        !$devices
    ) {

        speedtestCapabilityJson(
            [

                'success' =>
                    false,

                'device_id' =>
                    $deviceId,

                'message' =>
                    'Dispositivo não encontrado no GenieACS.'

            ],
            404
        );
    }


    $device =
        $devices[
            0
        ];


    /*
     * =====================================================
     * OBJETOS POSSIVEIS
     * =====================================================
     */

    $roots =
        [

            'tr098_download' =>
                'InternetGatewayDevice.DownloadDiagnostics',

            'tr098_upload' =>
                'InternetGatewayDevice.UploadDiagnostics',

            'tr181_download' =>
                'Device.IP.Diagnostics.DownloadDiagnostics',

            'tr181_upload' =>
                'Device.IP.Diagnostics.UploadDiagnostics'

        ];


    $diagnostics =
        [];


    $allParameters =
        [];


    foreach (
        $roots as $label => $path
    ) {

        $node =
            speedtestGetPath(
                $device,
                $path
            );


        /*
         * Objeto inexistente.
         */

        if (
            !is_array(
                $node
            )
        ) {

            $diagnostics[
                $label
            ] =
                [

                    'supported' =>
                        false,

                    'path' =>
                        $path,

                    'parameters' =>
                        []

                ];


            continue;
        }


        /*
         * Listar todos os parametros
         * dentro do objeto.
         */

        $flat =
            speedtestFlattenParameters(
                $node,
                $path
            );


        $diagnostics[
            $label
        ] =
            [

                'supported' =>
                    count(
                        $flat
                    ) > 0,

                'path' =>
                    $path,

                'parameters' =>
                    $flat

            ];


        /*
         * Guardar para resumo.
         */

        $allParameters +=
            $flat;
    }


    /*
     * =====================================================
     * RESUMO DOS PARAMETROS IMPORTANTES
     * =====================================================
     */

    $summary =
        [

            /*
             * Download
             */

            'download_supported' =>

                (
                    $diagnostics[
                        'tr098_download'
                    ][
                        'supported'
                    ] ??
                    false
                )

                ||

                (
                    $diagnostics[
                        'tr181_download'
                    ][
                        'supported'
                    ] ??
                    false
                ),


            /*
             * Upload
             */

            'upload_supported' =>

                (
                    $diagnostics[
                        'tr098_upload'
                    ][
                        'supported'
                    ] ??
                    false
                )

                ||

                (
                    $diagnostics[
                        'tr181_upload'
                    ][
                        'supported'
                    ] ??
                    false
                ),


            /*
             * Numero maximo de conexoes.
             */

            'download_max_connections' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'DownloadDiagnosticMaxConnections'

                    ]
                ),


            'upload_max_connections' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'UploadDiagnosticMaxConnections'

                    ]
                ),


            /*
             * Numero de conexoes configurado.
             */

            'number_of_connections' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'NumberOfConnections'

                    ]
                ),


            /*
             * Estado atual.
             */

            'diagnostics_state' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'DiagnosticsState'

                    ]
                ),


            /*
             * Tempos TR-143.
             */

            'bom_time' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'BOMTime'

                    ]
                ),


            'rom_time' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'ROMTime'

                    ]
                ),


            'eom_time' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'EOMTime'

                    ]
                ),


            /*
             * Contadores importantes.
             */

            'test_bytes_received' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'TestBytesReceived'

                    ]
                ),


            'total_bytes_received' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'TotalBytesReceived'

                    ]
                ),


            'total_bytes_sent' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'TotalBytesSent'

                    ]
                ),


            /*
             * Upload.
             */

            'test_file_length' =>

                speedtestFindParameter(
                    $allParameters,
                    [

                        'TestFileLength'

                    ]
                )

        ];


    /*
     * =====================================================
     * RESPONDER
     * =====================================================
     */

    speedtestCapabilityJson(
        [

            'success' =>
                true,

            'device_id' =>
                $deviceId,

            'last_inform' =>
                $device[
                    '_lastInform'
                ] ??
                null,

            'summary' =>
                $summary,

            'diagnostics' =>
                $diagnostics

        ]
    );


} catch (
    Throwable $e
) {

    speedtestCapabilityJson(
        [

            'success' =>
                false,

            'error_type' =>
                get_class(
                    $e
                ),

            'message' =>
                $e->getMessage()

        ],
        500
    );
}
