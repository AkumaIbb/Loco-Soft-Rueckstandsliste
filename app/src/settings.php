<?php
declare(strict_types=1);

$PROJECT_ROOT = __DIR__;

require_once $PROJECT_ROOT . '/../vendor/autoload.php';
require_once $PROJECT_ROOT . '/../config/mysql_data.php'; // $db (PDODb)
require_once $PROJECT_ROOT . '/../src/Csrf.php';

csrf_token();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$flash = null;

/**
 * Erwartete (empfohlene) DB-Constraints:
 * - delivery_terms: PRIMARY KEY(bestellart) oder UNIQUE(bestellart)
 * - ignores: UNIQUE(name)
 *
 * Damit funktionieren die Upserts korrekt.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? '')) {
        $flash = ['type' => 'err', 'msg' => 'Ungültiges CSRF-Token'];
    } else {
        try {
            $db->startTransaction();

            // --- Hilfsfunktion für IN-Listen ---
            $makeIn = function(array $vals): array {
                $vals = array_values($vals);
                if (count($vals) === 0) {
                    return ['', []]; // Aufrufer muss leeren Fall behandeln
                }
                $ph = implode(',', array_fill(0, count($vals), '?'));
                return [$ph, $vals];
            };

            // ====== LIEFERKONDITIONEN ======
            $nums      = $_POST['term_number'] ?? [];
            $delays    = $_POST['term_delay'] ?? [];
            $useOrders = $_POST['term_use_order'] ?? []; // kann [] oder ['<bestellart>' => 'on'] sein

            // Gewünschter Zielzustand (key = bestellart)
            $desiredTerms = [];
            foreach ($nums as $i => $numRaw) {
                $numStr = trim((string)$numRaw);
                if ($numStr === '') { continue; }
                $num = (int)$numStr;

                $delayStr = trim((string)($delays[$i] ?? ''));
                $delay = ($delayStr === '' ? null : (int)$delayStr);

                // Checkbox ist entweder mit gleichem numerischen Index ODER mit bestellart als Key gesetzt
                $checked = (isset($useOrders[$i]) || isset($useOrders[(string)$num])) ? 1 : 0;

                $desiredTerms[$num] = [
                    'bestellart' => $num,
                    'tage'       => $delay,
                    'use_order'  => $checked,
                ];
            }

            // Entferne nur Einträge, die nicht mehr im Formular vorkommen
            if (count($desiredTerms) > 0) {
                [$ph, $vals] = $makeIn(array_keys($desiredTerms));
                $db->rawQuery("DELETE FROM delivery_terms WHERE bestellart NOT IN ($ph)", $vals);
            } else {
                // Formular liefert gar keine Lieferkonditionen -> Tabelle leeren
                $db->rawQuery("DELETE FROM delivery_terms");
            }

            // Upserts
            foreach ($desiredTerms as $t) {
                $db->rawQuery(
                    "INSERT INTO delivery_terms (bestellart, tage_bis_rueckstand, use_order_date)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        tage_bis_rueckstand = VALUES(tage_bis_rueckstand),
                        use_order_date      = VALUES(use_order_date)",
                    [$t['bestellart'], $t['tage'], $t['use_order']]
                );
            }

            // ====== IGNORES ======
            $ignoreNames = [];
            foreach (($_POST['ignore_name'] ?? []) as $name) {
                $n = trim((string)$name);
                if ($n !== '') { $ignoreNames[$n] = $n; }
            }

            if (count($ignoreNames) > 0) {
                [$ph, $vals] = $makeIn($ignoreNames);
                $db->rawQuery("DELETE FROM ignores WHERE name NOT IN ($ph)", $vals);
            } else {
                $db->rawQuery("DELETE FROM ignores");
            }

            foreach ($ignoreNames as $n) {
                $db->rawQuery(
                    "INSERT INTO ignores (name) VALUES (?)
                     ON DUPLICATE KEY UPDATE name = VALUES(name)",
                    [$n]
                );
            }

            // ====== SMTP-SERVER (genau ein Datensatz) ======
            $host = trim((string)($_POST['smtp_host'] ?? ''));
            $port = (int)($_POST['smtp_port'] ?? 0);

            if ($host !== '' && $port > 0) {
                $data = [
                    'host'          => $host,
                    'port'          => $port,
                    'auth'          => isset($_POST['smtp_auth']) ? 1 : 0,
                    'username'      => trim((string)($_POST['smtp_username'] ?? '')),
                    'password'      => trim((string)($_POST['smtp_password'] ?? '')),
                    'from_address'  => trim((string)($_POST['smtp_from_address'] ?? '')),
                    'from_name'     => trim((string)($_POST['smtp_from_name'] ?? '')),
                    'reply_to'      => trim((string)($_POST['smtp_reply_to'] ?? '')),
                    'reply_to_name' => trim((string)($_POST['smtp_reply_to_name'] ?? '')),
                    'verify_ssl'    => isset($_POST['smtp_verify_ssl']) ? 1 : 0,
                ];

                // Gibt es schon einen?
                $row = $db->rawQueryOne("SELECT id FROM smtp_servers LIMIT 1");
                if ($row && isset($row['id'])) {
                    $db->rawQuery(
                        "UPDATE smtp_servers
                         SET host=?, port=?, auth=?, username=?, password=?, from_address=?, from_name=?, reply_to=?, reply_to_name=?, verify_ssl=?
                         WHERE id=?",
                        [
                            $data['host'], $data['port'], $data['auth'], $data['username'], $data['password'],
                            $data['from_address'], $data['from_name'], $data['reply_to'], $data['reply_to_name'], $data['verify_ssl'],
                            (int)$row['id']
                        ]
                    );
                } else {
                    $db->rawQuery(
                        "INSERT INTO smtp_servers (host, port, auth, username, password, from_address, from_name, reply_to, reply_to_name, verify_ssl)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $data['host'], $data['port'], $data['auth'], $data['username'], $data['password'],
                            $data['from_address'], $data['from_name'], $data['reply_to'], $data['reply_to_name'], $data['verify_ssl'],
                        ]
                    );
                }
            } else {
                // Keine gültigen SMTP-Daten übermittelt -> nichts ändern (oder hier gezielt löschen, wenn gewünscht)
                // $db->rawQuery("DELETE FROM smtp_servers");
            }

            $db->commit();
            $flash = ['type' => 'ok', 'msg' => 'Einstellungen gespeichert'];
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $ignore) {}
            $flash = ['type' => 'err', 'msg' => 'Fehler: ' . $e->getMessage()];
        }
    }
}

// Aktuelle Daten laden
$deliveryTerms = $db->rawQuery('SELECT * FROM delivery_terms ORDER BY bestellart');
$ignores       = $db->rawQuery('SELECT * FROM ignores ORDER BY name');
$smtp          = $db->rawQueryOne('SELECT * FROM smtp_servers LIMIT 1');

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
:root {
--muted: #9ca3af;
}
body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:16px; }
table { width:100%; border-collapse: collapse; margin-bottom:20px; }
th, td { border:1px solid #ddd; padding:6px 8px; vertical-align: top; }
.flash { padding:10px; border-radius:8px; margin:10px 0; }
.ok { background:#e7f6ec; color:#0a5; border:1px solid #bde5c8; }
.err { background:#fdecea; color:#b3261e; border:1px solid #f5c6cb; }
.controls { display:flex; gap:8px; margin-top:12px; }
.btn { padding:8px 12px; border:1px solid #999; border-radius:6px; background:#fff; cursor:pointer; }
.btn.primary { background:#0b5; color:#fff; border-color:#0a4; }
.muted { color: var(--muted); }
.add-row, .remove-row { text-decoration:none; font-weight:bold; padding:0 6px; }
.hint { color:#666; font-size:12px; margin:4px 0; }
</style>
</head>
<body>
<h1>Einstellungen</h1>
<p><a href="import_initiator.php">Neuen Import anlegen</a></p>
<?php if ($flash): ?>
  <div class="flash <?=h($flash['type'])?>"><?=h($flash['msg'])?></div>
<?php endif; ?>

<form method="post">
<?=csrf_field() ?>

<h2>Lieferkonditionen</h2>
<details><summary><strong>Lieferart mit -dauer verknüpfen</strong></summary>
        <pre class="muted" style="white-space:pre-wrap">
Hier wird festgelegt, ab wann eine Bestellung als Rückständig gilt.<br>
	Standardregel = 1 Tag nach Bestellung
	Anhand der Lieferkondition im Loco-Soft (572 => Nr. der Lieferkondition) kann hier ein verändertes Offset festgelegt werden.<br>
	Möglichkeit 1: Offset in Werktagen
		Typisch z.B. Eilorder - 1 Tag, Normalorder - 2 Werktage, Vorratsorder - 10 Tage
	Möglichkeit 2: Auftragsdatum
		Typisch z.B. bei Pre-Plan-Order, wenn Ware am Auftragstag geliefert wird.
        </pre>
      </details>
<table id="tblTerms">
<thead><tr><th>Nummer</th><th>Verzögerung</th><th>Auftragsdatum</th><th></th></tr></thead>
<tbody>
<?php foreach ($deliveryTerms as $t): ?>
  <tr>
    <td><input type="text" name="term_number[]" value="<?=h($t['bestellart'])?>"></td>
    <td><input type="text" name="term_delay[]" value="<?=h($t['tage_bis_rueckstand'])?>"></td>
    <td style="text-align:center;">
      <!-- Bestehende Einträge behalten das Mapping per bestellart -->
      <input type="checkbox" name="term_use_order[<?=h($t['bestellart'])?>]" <?=(!empty($t['use_order_date']) ? 'checked' : '')?>>
    </td>
    <td><a href="#" class="remove-row">−</a></td>
  </tr>
<?php endforeach; ?>
  <tr class="template">
    <td><input type="text" name="term_number[]"></td>
    <td><input type="text" name="term_delay[]"></td>
    <td style="text-align:center;">
      <!-- Neue Zeilen nutzen den numerischen Index -->
      <input type="checkbox" name="term_use_order[]">
    </td>
    <td><a href="#" class="remove-row">−</a></td>
  </tr>
</tbody>
<tfoot><tr><td colspan="4"><a href="#" class="add-row">+</a></td></tr></tfoot>
</table>

<h2>Ignores</h2>
<details><summary><strong>Ignorierte Lieferanten</strong></summary>
        <pre class="muted" style="white-space:pre-wrap">
Wenn Lieferanten nicht in der Rückstandsliste berücksichtig werden sollen können hier die Kürzel eingetragen werden.
Es ist der 4-Stellige Name aus der 572 einzutragen, z.B. HYUN..
        </pre>
      </details>
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

<h2>SMTP-Server</h2>
<details><summary><strong>ToDo: E-Mail Versand</strong></summary>
        <pre class="muted" style="white-space:pre-wrap">
Aktuell noch implementiert. Vorbereitung für einen automatischen Mailversand.
        </pre>
      </details>
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
      clone.querySelectorAll('input, select').forEach(function(inp){
        if (inp.tagName === 'SELECT') {
          inp.selectedIndex = 0;
        } else if (inp.type === 'checkbox') {
          inp.checked = false;
        } else {
          inp.value = '';
        }
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
