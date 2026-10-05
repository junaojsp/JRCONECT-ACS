<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/config.php';
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = getDBConnection();
    $sql = file_get_contents(__DIR__ . '/../migrations/20261005_device_action_history.sql');
    if ($sql === false) throw new RuntimeException('Migration missing');
    $db->query($sql);
    echo "Histórico de ações ativado.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Não foi possível instalar o histórico de ações. Confira o banco e suas permissões.\n");
    exit(1);
}
