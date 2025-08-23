<?php
declare(strict_types=1);

use App\Import\UniversalImporter;

$PROJECT_ROOT = realpath(__DIR__ . '/..');
require_once $PROJECT_ROOT . '/vendor/autoload.php';
require_once $PROJECT_ROOT . '/config/mysql_data.php';

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }

$brand = $_GET['brand'] ?? '';
if ($brand === '') {
    http_response_code(400);
    exit('Parameter "brand" fehlt');
}

$import = $db->rawQueryOne('SELECT * FROM imports WHERE brand = ?', [$brand]);
if (!$import) {
    http_response_code(404);
    exit('Import nicht gefunden');
}
$mapping = $db->rawQueryOne('SELECT * FROM import_mapping WHERE id = ?', [$import['map_id']]);
if (!$mapping) {
    http_response_code(404);
    exit('Mapping nicht gefunden');
}

try {
    $importer = new UniversalImporter($db, $import, $mapping, $DEBUG);
    $result = $importer->run();
    if (!$DEBUG) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
    }
} catch (Throwable $e) {
    if ($DEBUG) {
        http_response_code(500);
        echo '<pre>FEHLER: ' . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
