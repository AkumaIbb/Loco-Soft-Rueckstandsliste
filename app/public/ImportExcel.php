<?php
declare(strict_types=1);

use App\Import\ExcelImporter;

$PROJECT_ROOT = realpath(__DIR__ . '/..');
require_once $PROJECT_ROOT . '/vendor/autoload.php';
require_once $PROJECT_ROOT . '/config/mysql_data.php'; // stellt $db (PDODb) bereit

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }

$excelPath = '/mnt/Import-Folder/LocoBestellungen.xlsx';

try {
    $importer = new ExcelImporter($db, $excelPath, $DEBUG);
    $result = $importer->run();

    if (!$DEBUG) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
    }
} catch (Throwable $e) {
    if ($DEBUG) {
        http_response_code(500);
        echo "<pre>FEHLER: " . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
