<?php
declare(strict_types=1);

$PROJECT_ROOT = realpath(__DIR__ . '/..');

require_once $PROJECT_ROOT . '/vendor/autoload.php';
require_once $PROJECT_ROOT . '/config/mysql_data.php'; // -> $db (PDODb)

use App\Jobs\InboundReconciler;

$days  = isset($_GET['days'])  ? max(0, (int)$_GET['days']) : 3;
$debug = !empty($_GET['debug']);

if ($debug) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font: 13px/1.4 Menlo, Consolas, monospace">';
    echo '['.date('Y-m-d H:i:s')."] Reconcile inbound… days={$days}\n";
}

try {
    $job = new InboundReconciler($db, $debug);
    $res = $job->run($days);

    if ($debug) {
        echo "\nErgebnis:\n";
        print_r($res);
        echo "</pre>";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    if ($debug) {
        echo "\nFEHLER: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
