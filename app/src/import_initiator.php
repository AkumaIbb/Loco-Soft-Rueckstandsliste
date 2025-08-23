<?php
declare(strict_types=1);

$PROJECT_ROOT = __DIR__;
require_once $PROJECT_ROOT . '/../vendor/autoload.php';
require_once $PROJECT_ROOT . '/../config/mysql_data.php'; // $db (PDODb)
require_once $PROJECT_ROOT . '/Csrf.php';

csrf_token();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$step = $_POST['step'] ?? 'form';
$flash = null;

// determine next free map id
$nextMapRow = $db->rawQueryOne('SELECT COALESCE(MAX(map_id),0)+1 AS next_id FROM imports');
$nextMapId = (int)($nextMapRow['next_id'] ?? 1);

// available fields for mapping
$fields = [
    'partnernummer'    => 'Partnernummer',
    'auftragsnummer'   => 'Auftragsnummer *',
    'teilenummer'      => 'Teilenummer',
    'kundenreferenz'   => 'Kundenreferenz *',
    'anlagedatum'      => 'Anlagedatum *',
    'auftragsart'      => 'Auftragsart',
    'bestellte_menge'  => 'Bestellte Menge',
    'bestaetigte_menge'=> 'Bestätigte Menge',
    'offene_menge'     => 'Offene Menge',
    'vsl_lt_sap'       => 'Vsl. LT SAP',
    'vsl_lt_vz'        => 'Vsl. LT VZ',
    'info_vz'          => 'Info VZ',
    'aenderungsdatum'  => 'Änderungsdatum',
    'teilelocator'     => 'Teilelocator',
];

$headers = [];
$brand = $_POST['brand'] ?? '';
$pattern = $_POST['pattern'] ?? '';
$filetype = $_POST['filetype'] ?? 'csv';

if ($step === 'upload' && isset($_FILES['importfile'])) {
    $tmp = $_FILES['importfile']['tmp_name'];
    if ($filetype === 'excel') {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
            $sheet = $spreadsheet->getSheet(0);
            $highestColumn = $sheet->getHighestDataColumn();
            $headerRow = $sheet->rangeToArray('A1:' . $highestColumn . '1', null, true, true, false);
            $headers = $headerRow[0] ?? [];
        } catch (\Throwable $e) {
            $flash = 'Datei konnte nicht gelesen werden: ' . $e->getMessage();
            $step = 'form';
        }
    } else { // csv
        $fh = fopen($tmp, 'r');
        if ($fh) {
            $headers = fgetcsv($fh, 0, ';');
            if ($headers === false) { $headers = []; }
            fclose($fh);
        }
    }
    $_SESSION['headers'] = $headers;
    $_SESSION['brand'] = $brand;
    $_SESSION['pattern'] = $pattern;
    $_SESSION['filetype'] = $filetype;
    $step = 'map';
} elseif ($step === 'save') {
    $headers = $_SESSION['headers'] ?? [];
    $brand = $_SESSION['brand'] ?? '';
    $pattern = $_SESSION['pattern'] ?? '';
    $filetype = $_SESSION['filetype'] ?? 'csv';
    $mapSelection = $_POST['map'] ?? [];

    // invert header->field mapping to field->header
    $map = [];
    foreach ($mapSelection as $header => $field) {
        $field = trim((string)$field);
        if ($field !== '') {
            $map[$field] = $header;
        }
    }

    // check required fields
    foreach (['auftragsnummer','anlagedatum','kundenreferenz'] as $req) {
        if (empty($map[$req])) {
            $flash = 'Bitte alle Pflichtfelder zuordnen.';
            $step = 'map';
            break;
        }
    }

    if ($step !== 'map') {
        // save mapping and import definition
        $db->startTransaction();
        try {
            $cols = ['id'];
            $vals = [$nextMapId];
            foreach (array_keys($fields) as $f) {
                $cols[] = $f;
                $vals[] = $map[$f] ?? null;
            }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $db->rawQuery(
                'INSERT INTO import_mapping (' . implode(',', $cols) . ') VALUES (' . $ph . ')',
                $vals
            );
            $db->rawQuery(
                'INSERT INTO imports (brand, filename, map_id, fields) VALUES (?, ?, ?, ?)',
                [$brand, $pattern, $nextMapId, $filetype]
            );
            $db->commit();
            unset($_SESSION['headers'], $_SESSION['brand'], $_SESSION['pattern'], $_SESSION['filetype']);
            $flash = 'Import wurde gespeichert (Map-ID ' . $nextMapId . ').';
            $step = 'form';
        } catch (\Throwable $e) {
            $db->rollback();
            $flash = 'Fehler beim Speichern: ' . $e->getMessage();
            $step = 'form';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Import-Initiator</title>
<style>
body { font-family: system-ui, Arial, sans-serif; margin:16px; }
.flash { padding:10px; margin:10px 0; border:1px solid #999; }
.table { border-collapse: collapse; }
.table th, .table td { border:1px solid #ccc; padding:4px 6px; }
</style>
</head>
<body>
<h1>Import-Initiator</h1>
<?php if ($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>
<?php if ($step === 'form'): ?>
<form method="post" enctype="multipart/form-data">
<?=csrf_field()?>
<input type="hidden" name="step" value="upload">
<p>Map-ID: <?=h($nextMapId)?></p>
<p>
  <label>Marke: <input type="text" name="brand" value="<?=h($brand)?>" required></label>
</p>
<p>
  <label>Dateiname-Muster: <input type="text" name="pattern" value="<?=h($pattern)?>" required></label><br>
  <small>Platzhalter: * für beliebige Zeichen, ? für ein Zeichen. Beispiel: <code>Rückstandsliste *.csv</code></small>
</p>
<p>
  <label>Dateityp: 
    <select name="filetype">
      <option value="csv" <?=($filetype==='csv'?'selected':'')?>>CSV</option>
      <option value="excel" <?=($filetype==='excel'?'selected':'')?>>Excel</option>
    </select>
  </label>
</p>
<p>
  <label>Datei hochladen: <input type="file" name="importfile" required></label>
</p>
<button type="submit">Weiter</button>
</form>
<?php elseif ($step === 'map'): ?>
<form method="post">
<?=csrf_field()?>
<input type="hidden" name="step" value="save">
<table class="table">
<thead><tr><th>Spalte in Datei</th><th>Zuordnen zu</th></tr></thead>
<tbody>
<?php foreach ($headers as $h): ?>
  <tr>
    <td><?=h($h)?></td>
    <td>
      <select name="map[<?=h($h)?>]">
        <option value="">-- nicht zuordnen --</option>
        <?php foreach ($fields as $k=>$label): ?>
          <option value="<?=h($k)?>" <?=(isset($_POST['map'][$h]) && $_POST['map'][$h]===$k?'selected':'')?>><?=h($label)?></option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>
<p>Pflichtfelder: Auftragsnummer, Anlagedatum, Kundenreferenz. Bei der Kundenreferenz werden nur Auftragsnummern gelesen, der Rest wird verworfen.</p>
<button type="submit">Speichern</button>
</form>
<?php endif; ?>
<p><a href="settings.php">Zurück zu Einstellungen</a></p>
</body>
</html>
