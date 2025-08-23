<?php
declare(strict_types=1);

$PROJECT_ROOT = __DIR__;
require_once $PROJECT_ROOT . '/../vendor/autoload.php';
require_once $PROJECT_ROOT . '/../config/mysql_data.php'; // $db (PDODb)
require_once $PROJECT_ROOT . '/Csrf.php';

csrf_token(); // Session + Token sicherstellen

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$step   = $_POST['step'] ?? $_GET['step'] ?? 'form';
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$flash  = null;

// nächste freie Map-ID
$nextMapRow = $db->rawQueryOne('SELECT COALESCE(MAX(map_id),0)+1 AS next_id FROM imports');
$nextMapId  = (int)($nextMapRow['next_id'] ?? 1);

// verfügbare Zielfelder
$fields = [
    'partnernummer'     => 'Partnernummer',
    'auftragsnummer'    => 'Auftragsnummer',
    'teilenummer'       => 'Teilenummer *',
    'kundenreferenz'    => 'Kundenreferenz *',
    'anlagedatum'       => 'Anlagedatum *',
    'auftragsart'       => 'Auftragsart',
    'bestellte_menge'   => 'Bestellte Menge',
    'bestaetigte_menge' => 'Bestätigte Menge',
    'offene_menge'      => 'Offene Menge',
    'vsl_lt_sap'        => 'Vsl. LT SAP',
    'vsl_lt_vz'         => 'Vsl. LT VZ',
    'info_vz'           => 'Info VZ',
    'aenderungsdatum'   => 'Änderungsdatum',
    'teilelocator'      => 'Teilelocator',
];

$headers  = [];
$brand    = $_POST['brand']   ?? '';
$pattern  = $_POST['pattern'] ?? '';
$filetype = $_POST['filetype'] ?? 'csv';

/** ----- CSRF-Check für POST-Aktionen ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        http_response_code(400);
        die('Ungültiger CSRF-Token');
    }
}

/** ----- Actions: delete / edit prefill ----- */
if ($action === 'delete' && isset($_POST['map_id'])) {
    $mapId = (int)$_POST['map_id'];
    $db->startTransaction();
    try {
        $db->rawQuery('DELETE FROM imports WHERE map_id = ?', [$mapId]);
        $db->rawQuery('DELETE FROM import_mapping WHERE id = ?', [$mapId]);
        $db->commit();
        $flash = "Map {$mapId} wurde gelöscht.";
        $step  = 'form';
    } catch (\Throwable $e) {
        $db->rollback();
        $flash = 'Löschen fehlgeschlagen: ' . $e->getMessage();
        $step  = 'form';
    }
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : null;

if ($editId) {
    // Prefill für Bearbeiten: headers + vorausgewählte map + meta laden
    $imp = $db->rawQueryOne('SELECT brand, filename, fields FROM imports WHERE map_id = ?', [$editId]);
    $mapRow = $db->rawQueryOne('SELECT * FROM import_mapping WHERE id = ?', [$editId]);

    if ($imp && $mapRow) {
        $brand    = (string)$imp['brand'];
        $pattern  = (string)$imp['filename'];
        $filetype = (string)$imp['fields']; // hier ist bei dir der Dateityp abgelegt

        // Headerliste aus den gespeicherten Spaltennamen generieren
        $storedHeaders = [];
        foreach (array_keys($fields) as $f) {
            if (!empty($mapRow[$f])) {
                $storedHeaders[] = (string)$mapRow[$f];
            }
        }
        $headers = array_values(array_unique(array_filter($storedHeaders)));

        // Vorauswahl für Selects in $_POST['map'] emulieren
        $_POST['map'] = [];
        foreach (array_keys($fields) as $f) {
            if (!empty($mapRow[$f])) {
                $_POST['map'][(string)$mapRow[$f]] = $f;
            }
        }
        $step = 'map';
    } else {
        $flash = "Map {$editId} nicht gefunden.";
        $step  = 'form';
    }
}

/** ----- Upload -> Header lesen ----- */
if ($step === 'upload' && isset($_FILES['importfile'])) {
    $tmp = $_FILES['importfile']['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $flash = 'Keine Datei hochgeladen.';
        $step = 'form';
    } else {
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
        if ($step !== 'form') {
            $_SESSION['headers']  = $headers;
            $_SESSION['brand']    = $brand;
            $_SESSION['pattern']  = $pattern;
            $_SESSION['filetype'] = $filetype;
            $step = 'map';
        }
    }
}
/** ----- Save Mapping ----- */
elseif ($step === 'save') {
    $headers  = $_SESSION['headers']  ?? [];
    $brand    = $_SESSION['brand']    ?? '';
    $pattern  = $_SESSION['pattern']  ?? '';
    $filetype = $_SESSION['filetype'] ?? 'csv';
    $mapSel   = $_POST['map'] ?? [];

    // invert header->field mapping to field->header
    $map = [];
    foreach ($mapSel as $header => $field) {
        $field = trim((string)$field);
        if ($field !== '') {
            $map[$field] = $header;
        }
    }

    // Pflichtfelder prüfen
    foreach (['auftragsnummer','anlagedatum','kundenreferenz'] as $req) {
        if (empty($map[$req])) {
            $flash = 'Bitte alle Pflichtfelder zuordnen.';
            $step  = 'map';
            break;
        }
    }

    if ($step !== 'map') {
        // neue ID beibehalten, außer wir bearbeiten (editId)
        $useMapId = $editId ?: $nextMapId;

        $db->startTransaction();
        try {
            // upsert import_mapping
            $cols = ['id'];
            $vals = [$useMapId];
            $setParts = [];
            foreach (array_keys($fields) as $f) {
                $cols[] = $f;
                $vals[] = $map[$f] ?? null;
                $setParts[] = "{$f}=VALUES({$f})";
            }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $sql = 'INSERT INTO import_mapping (' . implode(',', $cols) . ') VALUES (' . $ph . ')
                    ON DUPLICATE KEY UPDATE ' . implode(',', $setParts);
            $db->rawQuery($sql, $vals);

            // upsert imports
            $db->rawQuery(
                'INSERT INTO imports (map_id, brand, filename, fields) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE brand=VALUES(brand), filename=VALUES(filename), fields=VALUES(fields)',
                [$useMapId, $brand, $pattern, $filetype]
            );

            $db->commit();
            unset($_SESSION['headers'], $_SESSION['brand'], $_SESSION['pattern'], $_SESSION['filetype']);
            $flash = $editId ? ("Map {$useMapId} wurde aktualisiert.") : ("Import wurde gespeichert (Map-ID {$useMapId}).");
            $step  = 'form';
        } catch (\Throwable $e) {
            $db->rollback();
            $flash = 'Fehler beim Speichern: ' . $e->getMessage();
            $step  = 'form';
        }
    }
}

/** ----- Liste vorhandener Maps ziehen ----- */
$maps = $db->rawQuery('
    SELECT i.map_id, i.brand, i.filename, i.fields,
           (SELECT COUNT(*) FROM import_mapping m WHERE m.id = i.map_id) AS has_mapping
    FROM imports i
    ORDER BY i.map_id DESC
');

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Import-Initiator</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: system-ui, Arial, sans-serif; margin:16px; }
  h1,h2 { margin: 0 0 12px 0; }
  .flash { padding:10px; margin:10px 0; border:1px solid #999; background:#fffbe6; }
  .table { border-collapse: collapse; width: 100%; }
  .table th, .table td { border:1px solid #ccc; padding:6px 8px; vertical-align: top; }
  .table th { text-align:left; background:#f5f5f5; }
  .form-grid { border:1px solid #ddd; border-radius:6px; overflow:hidden; }
  .form-grid table { width:100%; border-collapse: collapse; }
  .form-grid td { border-bottom:1px solid #eee; padding:8px; }
  .form-grid td:first-child { width:220px; font-weight:600; background:#fafafa; }
  .actions form { display:inline; margin:0; }
  .muted { color:#666; font-size: 0.9em; }
  .pill { display:inline-block; padding:2px 8px; border:1px solid #bbb; border-radius:999px; font-size:0.85em; }
  .mb12 { margin-bottom:12px; }
  .mb20 { margin-bottom:20px; }
  .mt20 { margin-top:20px; }
  .btn { padding:8px 12px; border:1px solid #888; background:#fafafa; cursor:pointer; }
</style>
</head>
<body>
<h1>Import-Initiator</h1>
<?php if ($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>

<?php if ($step === 'form'): ?>
<form method="post" enctype="multipart/form-data" class="mb20">
<input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
<input type="hidden" name="step" value="upload">
<div class="form-grid mb12">
  <table>
    <tr>
      <td>Map-ID</td>
      <td>
        <span class="pill"><?=h($nextMapId)?></span>
        <span class="muted">wird automatisch vergeben (bei Bearbeitung bestehender Maps behalten)</span>
      </td>
    </tr>
    <tr>
      <td>Marke</td>
      <td><input type="text" name="brand" value="<?=h($brand)?>" required></td>
    </tr>
    <tr>
      <td>Dateiname-Muster</td>
      <td>
        <input type="text" name="pattern" value="<?=h($pattern)?>" required>
        <div class="muted">Platzhalter: * (beliebig viele), ? (ein Zeichen). z. B.: <code>Rückstandsliste *.csv</code></div>
      </td>
    </tr>
    <tr>
      <td>Dateityp</td>
      <td>
        <select name="filetype">
          <option value="csv"   <?=($filetype==='csv'  ?'selected':'')?>>CSV</option>
          <option value="excel" <?=($filetype==='excel'?'selected':'')?>>Excel</option>
        </select>
      </td>
    </tr>
    <tr>
      <td>Datei hochladen</td>
      <td><input type="file" name="importfile" required></td>
    </tr>
  </table>
</div>
<button type="submit" class="btn">Weiter</button>
</form>

<?php if ($viewId):
    $mapRow = $db->rawQueryOne('SELECT * FROM import_mapping WHERE id = ?', [$viewId]); ?>
  <h2>Mapping anzeigen (Map-ID <?=h($viewId)?>)</h2>
  <?php if ($mapRow): ?>
    <table class="table mb20">
      <thead><tr><th>Zielfeld</th><th>Quellspalte (Header)</th></tr></thead>
      <tbody>
      <?php foreach ($fields as $k => $label): ?>
        <tr>
          <td><?=h($label)?></td>
          <td><?=h((string)($mapRow[$k] ?? ''))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted">Kein Mapping gefunden.</p>
  <?php endif; ?>
<?php endif; ?>

<h2 class="mt20">Vorhandene Maps</h2>
<table class="table">
  <thead>
    <tr>
      <th>Map-ID</th>
      <th>Marke</th>
      <th>Dateiname-Muster</th>
      <th>Dateityp</th>
      <th>Mapping?</th>
      <th>Aktionen</th>
    </tr>
  </thead>
  <tbody>
  <?php if ($maps): foreach ($maps as $row): ?>
    <tr>
      <td><?=h($row['map_id'])?></td>
      <td><?=h($row['brand'])?></td>
      <td><?=h($row['filename'])?></td>
      <td><?=h($row['fields'])?></td>
      <td><?=((int)$row['has_mapping'] ? 'Ja' : 'Nein')?></td>
      <td class="actions">
        <a class="btn" href="?view=<?=h($row['map_id'])?>">Ansehen</a>
        <a class="btn" href="?edit=<?=h($row['map_id'])?>">Bearbeiten</a>
        <form method="post" onsubmit="return confirm('Map wirklich löschen?');" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="map_id" value="<?=h($row['map_id'])?>">
          <button type="submit" class="btn">Löschen</button>
        </form>
      </td>
    </tr>
  <?php endforeach; else: ?>
    <tr><td colspan="6" class="muted">Keine Maps vorhanden.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php elseif ($step === 'map'): ?>
<form method="post" class="mb20">
<input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
<input type="hidden" name="step" value="save">
<table class="table">
  <thead><tr><th>Spalte in Datei</th><th>Zuordnen zu</th></tr></thead>
  <tbody>
  <?php foreach ($headers as $hh): ?>
    <tr>
      <td><?=h($hh)?></td>
      <td>
        <select name="map[<?=h($hh)?>]">
          <option value="">-- nicht zuordnen --</option>
          <?php foreach ($fields as $k=>$label): ?>
            <option value="<?=h($k)?>" <?=(isset($_POST['map'][$hh]) && $_POST['map'][$hh]===$k?'selected':'')?>><?=h($label)?></option>
          <?php endforeach; ?>
        </select>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p>Pflichtfelder: Teilenummer, Anlagedatum, Kundenreferenz.</p>
<button type="submit" class="btn">Speichern</button>
</form>
<?php endif; ?>

<p><a href="settings.php">Zurück zu Einstellungen</a></p>
</body>
</html>
