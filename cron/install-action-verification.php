<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/config.php';
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = getDBConnection();
    foreach (['20261005_device_action_history.sql', '20261005_action_verification.sql'] as $file) {
        $sql = file_get_contents(__DIR__ . '/../migrations/' . $file);
        if ($sql === false) throw new RuntimeException('Migration missing');
        $db->query($sql);
    }
    echo "Confirmação de configurações ativada.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Não foi possível instalar a confirmação. Confira o banco e suas permissões.\n");
    exit(1);
}
