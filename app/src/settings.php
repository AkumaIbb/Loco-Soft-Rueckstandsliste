<?php
declare(strict_types=1);

$PROJECT_ROOT = __DIR__;

require_once $PROJECT_ROOT . '/vendor/autoload.php';
require_once $PROJECT_ROOT . '/config/mysql_data.php'; // $db (PDODb)
require_once $PROJECT_ROOT . '/src/Csrf.php';

csrf_token();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? '')) {
        $flash = ['type' => 'err', 'msg' => 'Ungültiges CSRF-Token'];
    } else {
        try {
            $db->startTransaction();

            // Lieferkonditionen
            $db->rawQuery('TRUNCATE TABLE delivery_terms');
            $nums = $_POST['term_number'] ?? [];
            $delays = $_POST['term_delay'] ?? [];
            $useOrders = $_POST['term_use_order'] ?? [];
            foreach ($nums as $i => $num) {
                $num = trim((string)$num);
                if ($num === '') continue;
                $db->insert('delivery_terms', [
                    'bestellart' => (int)$num,
                    'tage_bis_rueckstand' => ($delays[$i] !== '' ? (int)$delays[$i] : null),
                    'use_order_date' => isset($useOrders[$i]) ? 1 : 0,
                ]);
            }

            // Ignores
            $db->rawQuery('TRUNCATE TABLE ignores');
            foreach (($_POST['ignore_name'] ?? []) as $name) {
                $name = trim((string)$name);
                if ($name === '') continue;
                $db->insert('ignores', ['name' => $name]);
            }

            // Imports
            $db->rawQuery('TRUNCATE TABLE imports');
            $brands = $_POST['import_brand'] ?? [];
            $files  = $_POST['import_filename'] ?? [];
            $types  = $_POST['import_type'] ?? [];
            $fields = $_POST['import_fields'] ?? [];
            $max = max(count($brands), count($files), count($types), count($fields));
            for ($i = 0; $i < $max; $i++) {
                $b = trim($brands[$i] ?? '');
                $f = trim($files[$i] ?? '');
                if ($b === '' && $f === '') continue;
                $db->insert('imports', [
                    'brand' => $b,
                    'filename' => $f,
                    'type' => trim($types[$i] ?? 'csv'),
                    'fields' => trim($fields[$i] ?? ''),
                ]);
            }

            // SMTP Server
            $db->rawQuery('TRUNCATE TABLE smtp_servers');
            $host = trim((string)($_POST['smtp_host'] ?? ''));
            $port = (int)($_POST['smtp_port'] ?? 0);
            if ($host !== '' && $port > 0) {
                $db->insert('smtp_servers', [
                    'host' => $host,
                    'port' => $port,
                    'auth' => isset($_POST['smtp_auth']) ? 1 : 0,
                    'username' => trim((string)($_POST['smtp_username'] ?? '')),
                    'password' => trim((string)($_POST['smtp_password'] ?? '')),
                    'from_address' => trim((string)($_POST['smtp_from_address'] ?? '')),
                    'from_name' => trim((string)($_POST['smtp_from_name'] ?? '')),
                    'reply_to' => trim((string)($_POST['smtp_reply_to'] ?? '')),
                    'reply_to_name' => trim((string)($_POST['smtp_reply_to_name'] ?? '')),
                    'verify_ssl' => isset($_POST['smtp_verify_ssl']) ? 1 : 0,
                ]);
            }

            $db->commit();
            $flash = ['type' => 'ok', 'msg' => 'Einstellungen gespeichert'];
        } catch (Exception $e) {
            $db->rollback();
            $flash = ['type' => 'err', 'msg' => 'Fehler: ' . $e->getMessage()];
        }
    }
}

$deliveryTerms = $db->rawQuery('SELECT * FROM delivery_terms ORDER BY bestellart');
$ignores = $db->rawQuery('SELECT * FROM ignores ORDER BY name');
$imports = $db->rawQuery('SELECT * FROM imports ORDER BY brand, filename');
$smtp = $db->rawQueryOne('SELECT * FROM smtp_servers LIMIT 1');

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Einstellungen</title>
<style>
body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:16px; }
table { width:100%; border-collapse: collapse; margin-bottom:20px; }
th, td { border:1px solid #ddd; padding:6px 8px; vertical-align: top; }
.flash { padding:10px; border-radius:8px; margin:10px 0; }
.ok { background:#e7f6ec; color:#0a5; border:1px solid #bde5c8; }
.err { background:#fdecea; color:#b3261e; border:1px solid #f5c6cb; }
.controls { display:flex; gap:8px; margin-top:12px; }
.btn { padding:8px 12px; border:1px solid #999; border-radius:6px; background:#fff; cursor:pointer; }
.btn.primary { background:#0b5; color:#fff; border-color:#0a4; }
.add-row, .remove-row { text-decoration:none; font-weight:bold; padding:0 6px; }
.hint { color:#666; font-size:12px; margin:4px 0; }
</style>
</head>
<body>
<h1>Einstellungen</h1>
<?php if ($flash): ?>
  <div class="flash <?=h($flash['type'])?>"><?=h($flash['msg'])?></div>
<?php endif; ?>

<form method="post">
<?=csrf_field() ?>

<h2>Lieferkonditionen</h2>
<table id="tblTerms">
<thead><tr><th>Nummer</th><th>Verzögerung</th><th>Auftragsdatum</th><th></th></tr></thead>
<tbody>
<?php foreach ($deliveryTerms as $t): ?>
  <tr>
    <td><input type="text" name="term_number[]" value="<?=h($t['bestellart'])?>"></td>
    <td><input type="text" name="term_delay[]" value="<?=h($t['tage_bis_rueckstand'])?>"></td>
    <td style="text-align:center;"><input type="checkbox" name="term_use_order[<?=h($t['bestellart'])?>]" <?=($t['use_order_date'] ? 'checked' : '')?>></td>
    <td><a href="#" class="remove-row">−</a></td>
  </tr>
<?php endforeach; ?>
  <tr class="template">
    <td><input type="text" name="term_number[]"></td>
    <td><input type="text" name="term_delay[]"></td>
    <td style="text-align:center;"><input type="checkbox" name="term_use_order[]"></td>
    <td><a href="#" class="remove-row">−</a></td>
  </tr>
</tbody>
<tfoot><tr><td colspan="4"><a href="#" class="add-row">+</a></td></tr></tfoot>
</table>

<h2>Ignores</h2>
<table id="tblIgnores">
<thead><tr><th>Hersteller</th><th></th></tr></thead>
<tbody>
<?php foreach ($ignores as $i): ?>
  <tr>
    <td><input type="text" name="ignore_name[]" value="<?=h($i['name'])?>"></td>
    <td><a href="#" class="remove-row">−</a></td>
  </tr>
<?php endforeach; ?>
  <tr class="template">
    <td><input type="text" name="ignore_name[]"></td>
    <td><a href="#" class="remove-row">−</a></td>
  </tr>
</tbody>
<tfoot><tr><td colspan="2"><a href="#" class="add-row">+</a></td></tr></tfoot>
</table>

<h2>Imports</h2>
<p class="hint">Platzhalter z.B. DD.MM.YYYY und * für beliebige Zeichen im Dateinamen verwenden.</p>
<table id="tblImports">
<thead><tr><th>Hersteller</th><th>Dateiname</th><th>Typ</th><th>Felder</th><th></th></tr></thead>
<tbody>
<?php foreach ($imports as $imp): ?>
  <tr>
    <td><input type="text" name="import_brand[]" value="<?=h($imp['brand'])?>"></td>
    <td><input type="text" name="import_filename[]" value="<?=h($imp['filename'])?>"></td>
    <td>
      <select name="import_type[]">
        <option value="csv" <?=($imp['type'] === 'csv' ? 'selected' : '')?>>CSV</option>
        <option value="excel" <?=($imp['type'] === 'excel' ? 'selected' : '')?>>Excel</option>
      </select>
    </td>
    <td><input type="text" name="import_fields[]" value="<?=h($imp['fields'])?>"></td>
    <td><a href="#" class="remove-row">−</a></td>
  </tr>
<?php endforeach; ?>
  <tr class="template">
    <td><input type="text" name="import_brand[]"></td>
    <td><input type="text" name="import_filename[]"></td>
    <td>
      <select name="import_type[]">
        <option value="csv">CSV</option>
        <option value="excel">Excel</option>
      </select>
    </td>
    <td><input type="text" name="import_fields[]"></td>
    <td><a href="#" class="remove-row">−</a></td>
  </tr>
</tbody>
<tfoot><tr><td colspan="5"><a href="#" class="add-row">+</a></td></tr></tfoot>
</table>

<h2>SMTP-Server</h2>
<table>
<tbody>
  <tr><th>Host</th><td><input type="text" name="smtp_host" value="<?=h($smtp['host'] ?? '')?>"></td></tr>
  <tr><th>Port</th><td><input type="text" name="smtp_port" value="<?=h($smtp['port'] ?? '')?>"></td></tr>
  <tr><th>Auth</th><td><input type="checkbox" name="smtp_auth" value="1" <?=(!empty($smtp['auth']) ? 'checked' : '')?>></td></tr>
  <tr><th>Username</th><td><input type="text" name="smtp_username" value="<?=h($smtp['username'] ?? '')?>"></td></tr>
  <tr><th>Password</th><td><input type="password" name="smtp_password" value="<?=h($smtp['password'] ?? '')?>"></td></tr>
  <tr><th>From-Adresse</th><td><input type="text" name="smtp_from_address" value="<?=h($smtp['from_address'] ?? '')?>"></td></tr>
  <tr><th>From-Name</th><td><input type="text" name="smtp_from_name" value="<?=h($smtp['from_name'] ?? '')?>"></td></tr>
  <tr><th>Reply-To</th><td><input type="text" name="smtp_reply_to" value="<?=h($smtp['reply_to'] ?? '')?>"></td></tr>
  <tr><th>Reply-To-Name</th><td><input type="text" name="smtp_reply_to_name" value="<?=h($smtp['reply_to_name'] ?? '')?>"></td></tr>
  <tr><th>SSL prüfen</th><td><input type="checkbox" name="smtp_verify_ssl" value="1" <?=(!empty($smtp['verify_ssl']) ? 'checked' : '')?>></td></tr>
</tbody>
</table>

<div class="controls">
  <button class="btn primary" type="submit">Speichern</button>
  <a class="btn" href="index.php">Zurück</a>
</div>

</form>

<script>
document.querySelectorAll('table').forEach(function(tbl){
  tbl.addEventListener('click', function(e){
    if (e.target.matches('.add-row')) {
      e.preventDefault();
      const tbody = tbl.querySelector('tbody');
      const template = tbody.querySelector('tr.template');
      const clone = template.cloneNode(true);
      clone.classList.remove('template');
      clone.querySelectorAll('input').forEach(function(inp){
        if (inp.type === 'checkbox') { inp.checked = false; } else { inp.value = ''; }
      });
      tbody.insertBefore(clone, template);
    }
    if (e.target.matches('.remove-row')) {
      e.preventDefault();
      const tr = e.target.closest('tr');
      if (tr.classList.contains('template')) return;
      tr.remove();
    }
  });
});
</script>

</body>
</html>
