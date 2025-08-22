<?php
declare(strict_types=1);

$PROJECT_ROOT = __DIR__;

require_once $PROJECT_ROOT . '/vendor/autoload.php';
require_once $PROJECT_ROOT . '/config/mysql_data.php'; // $db (PDODb)
require_once $PROJECT_ROOT . '/src/Csrf.php';

csrf_token(); // start session and ensure token

/* Helpers */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function toDateOrNull(?string $s): ?string {
    $s = trim((string)($s ?? ''));
    if ($s === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d ? $d->format('Y-m-d') : null;
}
function fmtDate(?string $ymd): string {
    if (!$ymd) return '';
    $d = DateTime::createFromFormat('Y-m-d', substr($ymd,0,10)) ?: (strtotime($ymd) ? new DateTime($ymd) : null);
    return $d ? $d->format('d.m.Y') : '';
}
function mailto(string $addr, string $subject): string {
    $addr = trim($addr);
    if ($addr === '') return '';
    $q = http_build_query(['subject' => $subject], '', '&', PHP_QUERY_RFC3986);
    return "mailto:{$addr}?{$q}";
}

/* Kundennamen live aus PG (nur Anzeige) */
function fetchCustomerNameFromPG(string $orderNo): ?string {
    static $pg = null;
    static $cache = [];
    $orderNo = trim($orderNo);
    if ($orderNo === '') return null;
    if (array_key_exists($orderNo, $cache)) return $cache[$orderNo];

    $cfg = __DIR__ . '/config/postgres_data.php';
    if (!is_readable($cfg)) { $cache[$orderNo] = null; return null; }
    if ($pg === null) {
        require $cfg; // $postgresdata
        $pg = @pg_connect($postgresdata);
        if (!$pg) { $cache[$orderNo] = null; return null; }
    }

    $res = @pg_query_params($pg, "SELECT order_customer FROM orders WHERE number = $1 LIMIT 1", [$orderNo]);
    if (!$res) { $cache[$orderNo] = null; return null; }
    $row = pg_fetch_assoc($res);
    if (!$row || empty($row['order_customer'])) { $cache[$orderNo] = null; return null; }
    $custNo = trim((string)$row['order_customer']);

    $res2 = @pg_query_params($pg,
        "SELECT name_prefix, first_name, family_name, name_postfix
           FROM customers_suppliers
          WHERE customer_number = $1
          LIMIT 1",
        [$custNo]
    );
    if (!$res2) { $cache[$orderNo] = null; return null; }
    $c = pg_fetch_assoc($res2);
    if (!$c) { $cache[$orderNo] = null; return null; }

    $parts = [];
    foreach (['name_prefix','first_name','family_name','name_postfix'] as $k) {
        if (!empty($c[$k])) $parts[] = trim((string)$c[$k]);
    }
    $name = $parts ? implode(' ', $parts) : null;
    $cache[$orderNo] = $name ?: null;
    return $cache[$orderNo];
}

/* Kundenmail live aus PG (nur Anzeige/Link) */
function fetchCustomerEmailFromPG(string $orderNo): ?string {
    static $pg = null;
    static $cache = [];
    $orderNo = trim($orderNo);
    if ($orderNo === '') return null;
    if (array_key_exists($orderNo, $cache)) return $cache[$orderNo];

    $cfg = __DIR__ . '/config/postgres_data.php';
    if (!is_readable($cfg)) { $cache[$orderNo] = null; return null; }
    if ($pg === null) {
        require $cfg; // $postgresdata
        $pg = @pg_connect($postgresdata);
        if (!$pg) { $cache[$orderNo] = null; return null; }
    }

    // 1) Kundennummer holen
    $res = @pg_query_params($pg, "SELECT order_customer FROM orders WHERE number = $1 LIMIT 1", [$orderNo]);
    if (!$res) { $cache[$orderNo] = null; return null; }
    $row = pg_fetch_assoc($res);
    if (!$row || empty($row['order_customer'])) { $cache[$orderNo] = null; return null; }
    $custNo = trim((string)$row['order_customer']);

    // 2) Erste E-Mail-Adresse des Kunden
    $res2 = @pg_query_params($pg,
        "SELECT address
           FROM customer_com_numbers
          WHERE customer_number = $1
            AND com_type = 'E'
            AND address IS NOT NULL
            AND address <> ''
          ORDER BY address
          LIMIT 1",
        [$custNo]
    );
    if (!$res2) { $cache[$orderNo] = null; return null; }
    $c = pg_fetch_assoc($res2);
    $addr = $c && !empty($c['address']) ? trim((string)$c['address']) : null;
    $cache[$orderNo] = $addr ?: null;
    return $cache[$orderNo];
}

/* Dropdown-Liste möglicher SB (PG employees, Fallback MySQL) */
$sbNames = [];
try {
    $pgCfg = $PROJECT_ROOT . '/config/postgres_data.php';
    if (is_readable($pgCfg)) {
        require $pgCfg;
        if (!empty($postgresdata)) {
            $pgTmp = @pg_connect($postgresdata);
            if ($pgTmp) {
                $res = @pg_query($pgTmp, "SELECT name FROM employees WHERE name IS NOT NULL AND name <> '' AND leave_date IS NULL AND mechanic_number IS NULL
ORDER BY name");
                if ($res) { while ($r = pg_fetch_assoc($res)) { $sbNames[] = $r['name']; } }
                @pg_close($pgTmp);
            }
        }
    }
} catch (\Throwable $e) { /* ignore */ }
if (!$sbNames) {
    $rowsTmp = $db->rawQuery("SELECT DISTINCT serviceberater FROM backlog_annotations WHERE serviceberater IS NOT NULL AND serviceberater <> '' ORDER BY serviceberater");
    foreach ($rowsTmp as $r) { $sbNames[] = $r['serviceberater']; }
}
$sbNames = array_values(array_unique(array_filter($sbNames, fn($x)=>trim((string)$x) !== '')));

/* POST: Änderungen speichern (inkl. kundeninfo) */
$flash = null; $flashErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Bad Request');
    }
    csrf_token(true); // renew token after successful validation
    $rowIds     = $_POST['row']            ?? []; // alle Zeilen-IDs aus dem Formular
    $kommentare = $_POST['kommentar']      ?? [];
    $vltIntern  = $_POST['vlt_intern']     ?? [];
    $sbSel      = $_POST['serviceberater'] ?? [];
    $angMap     = $_POST['angemahnt']      ?? []; // nur gesendete (checked)
    $kiMap      = $_POST['kundeninfo']     ?? []; // nur gesendete (checked)

    $ids = array_unique(array_merge($rowIds, array_keys($kommentare), array_keys($vltIntern), array_keys($sbSel), array_keys($angMap), array_keys($kiMap)));

    $saved = 0; $failed = 0;
    $db->rawQuery("START TRANSACTION");
    foreach ($ids as $id) {
        if (!ctype_digit((string)$id)) { $failed++; continue; }
        $id = (int)$id;

        $kom = isset($kommentare[$id]) ? trim((string)$kommentare[$id]) : null;
        $vlt = isset($vltIntern[$id])  ? toDateOrNull((string)$vltIntern[$id]) : null;
        $sb  = isset($sbSel[$id])      ? trim((string)$sbSel[$id]) : null;
        $ang = array_key_exists($id, $angMap) ? 1 : 0;
        $ki  = array_key_exists($id, $kiMap)  ? 1 : 0;

        $sql = "INSERT INTO backlog_annotations
                    (order_id, voraussichtlicher_liefertermin, serviceberater, angemahnt, kundeninfo, kommentar, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, 'web-ui')
                ON DUPLICATE KEY UPDATE
                    voraussichtlicher_liefertermin = VALUES(voraussichtlicher_liefertermin),
                    serviceberater = VALUES(serviceberater),
                    angemahnt = VALUES(angemahnt),
                    kundeninfo = VALUES(kundeninfo),
                    kommentar = VALUES(kommentar),
                    updated_by = 'web-ui',
                    updated_at = NOW()";
        $ok = $db->rawQuery($sql, [
            $id,
            $vlt,
            ($sb !== '') ? $sb : null,
            $ang,
            $ki,
            ($kom !== '') ? $kom : null,
        ]);
        if ($ok === false) { $failed++; } else { $saved++; }
    }
    if ($failed === 0) { $db->rawQuery("COMMIT"); $flash = "Änderungen gespeichert: {$saved} Zeile(n)."; }
    else { $db->rawQuery("ROLLBACK"); $flashErr = "Fehler beim Speichern. Erfolgreich: {$saved}, Fehler: {$failed}."; }
}

/* Daten laden (echte Rückstände) — inkl. kundeninfo + Supplier-Join s1/s2/s3 */
$sql = "
SELECT
  o.id,
  o.bestellkonzern                         AS konzern,
  o.bestelldatum,
  COALESCE(o.bezugs_auftrags_nr, o.bestellnummer) AS auftragsnummer,
  o.teile_nr,
  o.description                            AS bezeichnung,
  a.kommentar                              AS kommentar_intern,
  a.voraussichtlicher_liefertermin         AS vlt_intern,
  a.angemahnt,
  a.kundeninfo,
  a.serviceberater,
  COALESCE(s1.info_vz, s2.info_vz, s3.info_vz) AS kommentar_lieferant,
  COALESCE(s1.vsl_lt_vz, s1.vsl_lt_sap,
           s2.vsl_lt_vz, s2.vsl_lt_sap,
           s3.vsl_lt_vz, s3.vsl_lt_sap)       AS vlt_lieferant
FROM backlog_orders o
LEFT JOIN backlog_annotations a
  ON a.order_id = o.id
LEFT JOIN supplier_import_items s1
  ON s1.auftragsnummer = o.bezugs_auftrags_nr
 AND s1.teilenummer    = o.teile_nr
LEFT JOIN supplier_import_items s2
  ON s2.auftragsnummer = o.bestellnummer
 AND s2.teilenummer    = o.teile_nr
LEFT JOIN supplier_import_items s3
  ON CAST(REGEXP_SUBSTR(s3.kundenreferenz, '[0-9]+') AS UNSIGNED) = CAST(o.bezugs_auftrags_nr AS UNSIGNED)
 AND s3.teilenummer    = o.teile_nr
WHERE o.rueckstand_relevant = 1
  AND o.rueck_ab_date IS NOT NULL
  AND CURRENT_DATE() > o.rueck_ab_date
  AND (o.rueckstands_menge IS NULL OR o.rueckstands_menge > 0)
ORDER BY o.bestellkonzern, o.bestelldatum, auftragsnummer, o.teile_nr
";
$rows = $db->rawQuery($sql);
echo "<!-- rows=".count($rows)." -->";

/* Aktive SBs für Filter-Buttons */
$activeSbs = [];
foreach ($rows as $r) {
    $name = trim((string)($r['serviceberater'] ?? ''));
    if ($name !== '') $activeSbs[$name] = true;
}
$activeSbs = array_keys($activeSbs);
sort($activeSbs, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Rückstandsliste</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 16px; }
    .header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
    .header h1 { margin:0; font-size:1.5em; }
    .searchbox { display:flex; align-items:center; gap:6px; flex:1; max-width:420px; }
    .searchbox input { flex:1; padding:6px 10px; border:1px solid #ccc; border-radius:6px; }

    .flash { padding:10px; border-radius:8px; margin:10px 0; }
    .ok { background:#e7f6ec; color:#0a5; border:1px solid #bde5c8; }
    .err { background:#fdecea; color:#b3261e; border:1px solid #f5c6cb; }

    .controls { display:flex; gap:8px; margin:12px 0; flex-wrap:wrap; }
    .btn { padding:8px 12px; border:1px solid #999; border-radius:6px; background:#fff; cursor:pointer; }
    .btn.primary { background:#0b5; color:#fff; border-color:#0a4; }
    .sb-filter { display:flex; gap:6px; flex-wrap:wrap; }
    .sb-btn { border-color:#bbb; }
    .sb-btn.active { background:#0b5; color:#fff; border-color:#0a4; }

    table { width:100%; border-collapse: collapse; }
    th, td { border:1px solid #ddd; padding:6px 8px; vertical-align: top; }
    th { background:#f7f7f7; position: sticky; top: 0; z-index: 1; }
    input[type="text"], input[type="date"], select { width: 100%; box-sizing: border-box; }
    .hint { color:#666; font-size:12px; margin-top:4px; }
    .nowrap { white-space: nowrap; }
    .mailicon { text-decoration:none; font-size:16px; }
    .mailwrap { display:flex; align-items:center; gap:8px; }
	/* kompakte Lieferanten-Kommentarspalte */
	.col-kom-lieferant, td.supplier-comment { width: 48px; text-align: center; }
	td.supplier-comment { position: relative; }

	/* Tooltip-Icon */
	.info-tip {
	  cursor: help;
	  user-select: none;
	  display: inline-block;
	  line-height: 1;
	  font-size: 16px;
	  outline-offset: 2px;
	}

	/* Tooltip-Bubble (CSS-only, nimmt Text aus data-tip) */
	.info-tip::after {
	  content: attr(data-tip);
	  position: absolute;
	  left: 50%;
	  top: 0;
	  transform: translate(-50%, -6px) translateY(-100%);
	  background: rgba(0,0,0,.85);
	  color: #fff;
	  padding: 8px 10px;
	  border-radius: 6px;
	  white-space: pre-wrap;
	  max-width: min(60ch, 60vw);
	  box-shadow: 0 6px 20px rgba(0,0,0,.2);
	  pointer-events: none;
	  opacity: 0;
	  visibility: hidden;
	  z-index: 10; /* über dem sticky thead */
	}

	/* anzeigen bei Hover oder Tastatur-Fokus */
	.info-tip:hover::after,
	.info-tip:focus::after {
	  opacity: 1;
	  visibility: visible;
	}

	
  </style>
</head>
<body>
  <div class="header">
    <h1>Rückstandsliste</h1>
    <div class="searchbox">
      <input id="liveSearch" type="text" placeholder="Suchen … (Konzern, Auftrag, Kundenname, Teil, Bezeichnung, Kommentar, SB)">
      <button class="btn" type="button" id="clearSearch" title="Suche löschen">✕</button>
    </div>
  </div>

  <?php if ($flash): ?><div class="flash ok"><?=h($flash)?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash err"><?=h($flashErr)?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <!-- SB-Filterleiste -->
    <div class="controls" style="justify-content:flex-start;">
      <div class="sb-filter">
        <button type="button" class="btn sb-btn active" data-sb="__all__">Alle</button>
        <?php foreach ($activeSbs as $name): ?>
          <button type="button" class="btn sb-btn" data-sb="<?=h($name)?>"><?=h($name)?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th class="nowrap">Konzern</th>
          <th class="nowrap">Bestelldatum</th>
          <th class="nowrap">Auftragsnummer</th>
          <th class="nowrap">Teilenummer</th>
          <th>Bezeichnung</th>
          <th>Kommentar (Intern)</th>
          <th>Kommentar (Lieferant)</th>
          <th class="nowrap">Vorauss. LT (intern)</th>
          <th class="nowrap">Vorauss. LT (Lieferant)</th>
          <th class="nowrap">Angemahnt</th>
		  <th class="nowrap">Kundeninfo</th>
          <th class="nowrap">Serviceberater</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr data-empty-row="1"><td colspan="12" style="text-align:center;color:#666;">Keine rückständigen Positionen.</td></tr>
      <?php else: foreach ($rows as $r):
          $id = (int)$r['id'];
          $konzern = $r['konzern'];
          $bestelldatum = $r['bestelldatum'] ? substr($r['bestelldatum'],0,10) : null;
          $auftrag = $r['auftragsnummer'] ?? '';
          $teil = $r['teile_nr'] ?? '';
          $bez = $r['bezeichnung'] ?? '';
          $komI = $r['kommentar_intern'] ?? '';
          $komL = $r['kommentar_lieferant'] ?? '';
          $vltI = $r['vlt_intern'] ? substr($r['vlt_intern'],0,10) : '';
          $vltLraw = $r['vlt_lieferant'] ?? null;
          $vltL = '';
          if ($vltLraw) {
              $d = DateTime::createFromFormat('Y-m-d', substr($vltLraw,0,10)) ?: (strtotime($vltLraw) ? new DateTime($vltLraw) : null);
              if ($d) $vltL = $d->format('Y-m-d');
          }
          $ang = (int)($r['angemahnt'] ?? 0);
          $ki  = (int)($r['kundeninfo'] ?? 0);
          $sb  = $r['serviceberater'] ?? '';

          $kundeName = $auftrag ? fetchCustomerNameFromPG($auftrag) : null;
          $kundeMail = $auftrag ? fetchCustomerEmailFromPG($auftrag) : null;
          $mailtoHref = $kundeMail ? mailto($kundeMail, 'Rückstandsinformation') : '';
      ?>
        <tr data-sb="<?=h(trim((string)$sb))?>">
		  <td class="nowrap">
			<input type="hidden" name="row[]" value="<?=$id?>">
			<?=h($konzern)?>
		  </td>
		  <td class="nowrap"><?=h(fmtDate($bestelldatum))?></td>
		  <td class="nowrap" title="<?= h($kundeName ?? '') ?>"><?=h($auftrag)?></td>
		  <td class="nowrap"><?=h($teil)?></td>
		  <td><?=h($bez)?></td>

          <td><input type="text" name="kommentar[<?=$id?>]" value="<?=h($komI)?>"></td>

          <td class="supplier-comment">
		  <?php if (trim((string)$komL) !== ''):
			  // Für Tooltip/Title: Zeilenumbrüche in &#10; umwandeln, Rest sicher escapen
			  $tipAttr = str_replace(["\r\n","\r","\n"], '&#10;', h($komL));
		  ?>
			<span class="info-tip"
				  title="<?=$tipAttr?>"
				  data-tip="<?=$tipAttr?>"
				  tabindex="0"
				  aria-label="Kommentar des Lieferanten anzeigen">ℹ️</span>
		  <?php endif; ?>
		</td>


          <td>
            <input type="date" name="vlt_intern[<?=$id?>]" value="<?=h($vltI)?>">
            <div class="hint"><?= $vltI ? '('.h(fmtDate($vltI)).')' : '' ?></div>
          </td>

          <td class="nowrap"><?= $vltL ? h(fmtDate($vltL)) : '' ?></td>

          <td style="text-align:center;">
            <input type="checkbox" name="angemahnt[<?=$id?>]" value="1" <?=$ang ? 'checked' : ''?>>
          </td>
		  <td class="nowrap">
            <div class="mailwrap">
              <?php if ($mailtoHref): ?>
                <a class="mailicon" href="<?=h($mailtoHref)?>" title="E-Mail an Kunden öffnen">✉️</a>
              <?php else: ?>
                <span title="Keine Kunden-E-Mail gefunden" style="opacity:.4;">✉️</span>
              <?php endif; ?>
              <label title="Kundeninfo gesendet">
                <input type="checkbox" name="kundeninfo[<?=$id?>]" value="1" <?=$ki ? 'checked' : ''?>> gesendet
              </label>
            </div>
          </td>

          <td>
            <select name="serviceberater[<?=$id?>]">
              <option value="">— auswählen —</option>
              <?php foreach ($sbNames as $name): ?>
                <option value="<?=h($name)?>" <?=($sb === $name ? 'selected' : '')?>><?=h($name)?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <!-- Unten: Speichern/Zurücksetzen -->
    <div class="controls">
      <button class="btn primary" type="submit">Änderungen speichern</button>
      <button class="btn" type="reset">Zurücksetzen</button>
    </div>
  </form>

  <!-- Live-Suche + SB-Filter -->
  <script>
  (function() {
    const q = document.getElementById('liveSearch');
    const clearBtn = document.getElementById('clearSearch');
    const tbody = document.querySelector('table tbody');
    const sbButtons = Array.from(document.querySelectorAll('.sb-btn'));
    if (!tbody) return;

    // Debounce
    let timer;
    const normalize = s => (s || '').toString().toLowerCase().normalize('NFKD').replace(/[^\w\s.-]/g,'').trim();

    function rowText(tr) {
      const tds = tr.querySelectorAll('td');
      if (tds.length < 12) return '';
      const konzern = tds[0].textContent;
      const auftrag = tds[2].textContent;
      const kundenTooltip = tds[2].getAttribute('title') || '';
      const teil = tds[3].textContent;
      const bez = tds[4].textContent;
      const kommIntern = (tds[5].querySelector('input')?.value) ?? tds[5].textContent;
      const kommLief   = tds[6].textContent;
      const kundeninfo = (tds[7].querySelector('input[type=checkbox]')?.checked) ? 'kundeninfo' : '';
      const vltIntern  = (tds[8].querySelector('input')?.value) ?? tds[8].textContent;
      const vltLief    = tds[9].textContent;
      const angemahnt  = tds[10].querySelector('input[type=checkbox]')?.checked ? 'angemahnt' : '';
      const sb = tds[11].querySelector('select')?.value || tds[11].textContent;
      return normalize([konzern, auftrag, kundenTooltip, teil, bez, kommIntern, kommLief, kundeninfo, vltIntern, vltLief, angemahnt, sb].join(' '));
    }

    const rows = Array.from(tbody.querySelectorAll('tr'));
    const cache = new Map(rows.map(tr => [tr, rowText(tr)]));
    const emptyRow = tbody.querySelector('tr[data-empty-row]');

    let sbFilter = '__all__';

    function applyFilter() {
      const term = normalize(q ? q.value : '');
      let visible = 0;

      rows.forEach(tr => {
        const rowSbRaw = (tr.getAttribute('data-sb') || '').trim();
        const rowHasNoSb = rowSbRaw === '';
        const sbMatch =
          (sbFilter === '__all__') ||
          (sbFilter === '__none__' && rowHasNoSb) ||
          (sbFilter !== '__none__' && sbFilter !== '__all__' && rowSbRaw === sbFilter);

        const textMatch = (term === '') || cache.get(tr).includes(term);
        const show = sbMatch && textMatch;
        tr.style.display = show ? '' : 'none';
        if (show) visible++;
      });

      if (emptyRow) emptyRow.style.display = visible === 0 ? '' : 'none';
    }

    if (q) q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(applyFilter, 80); });
    if (clearBtn) clearBtn.addEventListener('click', () => { if (q) q.value = ''; applyFilter(); q?.focus(); });

    sbButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        const val = btn.getAttribute('data-sb') || '__all__';
        if (btn.classList.contains('active')) {
          sbFilter = '__all__';
          sbButtons.forEach(b => b.classList.remove('active'));
          const allBtn = sbButtons.find(b => (b.getAttribute('data-sb') || '') === '__all__');
          allBtn?.classList.add('active');
        } else {
          sbFilter = val;
          sbButtons.forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
        }
        applyFilter();
      });
    });

    // Änderungen in Inputs/Drops: Cache und data-sb aktualisieren
    tbody.addEventListener('input', e => {
      const tr = e.target.closest('tr');
      if (!tr || !cache.has(tr)) return;
      if (e.target.matches('select[name^="serviceberater["]')) {
        tr.setAttribute('data-sb', e.target.value.trim());
      }
      cache.set(tr, rowText(tr));
      applyFilter();
    });
  })();
  </script>
</body>
</html>
