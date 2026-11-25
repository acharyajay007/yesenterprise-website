<?php

ini_set('display_errors', '0');
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo '{"error":"Authenticated POST required."}';
    exit;
}
if (is_file(__DIR__.'/yesenterprise-app/storage/app/private/git-layout-migration-complete.json')) {
    http_response_code(410);
    echo '{"error":"The one-time migration endpoint is disabled."}';
    exit;
}
require __DIR__.'/yesenterprise-app/vendor/autoload.php';
try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
        throw new RuntimeException('Authenticated migration requires HTTPS at the hosting PHP server.');
    }
    $migration = new App\Support\GitLayoutMigration(__DIR__);
    $body = file_get_contents('php://input', false, null, 0, 2049);
    if ($body === false) {
        throw new RuntimeException('Could not read the migration request.');
    }
    $result = $migration->dispatch(
        $body,
        $_SERVER['HTTP_X_DEPLOYMENT_TIME'] ?? '',
        $_SERVER['HTTP_X_DEPLOYMENT_NONCE'] ?? '',
        $_SERVER['HTTP_X_DEPLOYMENT_SIGNATURE'] ?? '',
    );
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (RuntimeException|JsonException $error) {
    error_log('Git layout migration blocked: '.$error->getMessage());
    http_response_code(409);
    echo '{"error":"Migration blocked; inspect the private hosting error log. Maintenance is not automatically removed."}';
}
