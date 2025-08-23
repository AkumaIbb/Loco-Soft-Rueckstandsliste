<?php
declare(strict_types=1);

use App\Import\UniversalImporter;

$PROJECT_ROOT = realpath(__DIR__ . '/..');
require_once $PROJECT_ROOT . '/vendor/autoload.php';
require_once $PROJECT_ROOT . '/config/mysql_data.php';

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }

$importFolder = realpath($PROJECT_ROOT . '/Import-Folder') ?: $PROJECT_ROOT . '/Import-Folder';

$imports = $db->rawQuery('SELECT * FROM imports');
if (!$imports) {
    http_response_code(404);
    exit('Keine Imports gefunden');
}

$allFiles = array_diff(scandir($importFolder) ?: [], ['.', '..']);

$results = [];
foreach ($imports as $import) {
    $regex = '#^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($import['filename'], '#')) . '$#i';
    $matches = [];
    foreach ($allFiles as $file) {
        if (preg_match($regex, $file)) {
            $matches[] = $file;
        }
    }
    if (!$matches) {
        if ($DEBUG) {
            echo htmlspecialchars('Keine Datei für Brand ' . $import['brand'] . ' gefunden') . "<br>";
        }
        continue;
    }
    rsort($matches);
    $file = $matches[0];

    $mapping = $db->rawQueryOne('SELECT * FROM import_mapping WHERE id = ?', [$import['map_id']]);
    if (!$mapping) {
        if ($DEBUG) {
            echo htmlspecialchars('Mapping nicht gefunden für Brand ' . $import['brand']) . "<br>";
        }
        continue;

$results = [];
foreach ($imports as $import) {
    $pattern = $importFolder . '/' . $import['filename'];
    $files = glob($pattern);
    if (!$files) {
        if ($DEBUG) {
            echo htmlspecialchars('Keine Datei für Brand ' . $import['brand'] . ' gefunden') . "<br>";
        }
        continue;
    }

    $mapping = $db->rawQueryOne('SELECT * FROM import_mapping WHERE id = ?', [$import['map_id']]);
    if (!$mapping) {
        if ($DEBUG) {
            echo htmlspecialchars('Mapping nicht gefunden für Brand ' . $import['brand']) . "<br>";
        }
        continue;
    }

    try {
        $importer = new UniversalImporter($db, $import, $mapping, $DEBUG);
        $results[$import['brand']] = $importer->run();
    } catch (Throwable $e) {
        if ($DEBUG) {
            echo '<pre>FEHLER bei ' . htmlspecialchars($import['brand']) . ': ' .
                htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        } else {
            $results[$import['brand']] = ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    $importWithFile = $import;
    $importWithFile['filename'] = $file;

    try {
        $importer = new UniversalImporter($db, $importWithFile, $mapping, $DEBUG);
        $results[$import['brand']] = $importer->run();
    } catch (Throwable $e) {
        if ($DEBUG) {
            echo '<pre>FEHLER bei ' . htmlspecialchars($import['brand']) . ': ' .
                htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        } else {
            $results[$import['brand']] = ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}

if (!$DEBUG) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($results);
}

if (!$DEBUG) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($results);
}
