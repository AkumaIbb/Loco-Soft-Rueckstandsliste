<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/postgres_data.php';

$pg = @pg_connect($postgresdata);
if (!$pg) {
    http_response_code(500);
    exit('Datenbankverbindung fehlgeschlagen');
}

const MAX_WORK_POSITIONS = 3;
const MAX_WORK_LINES     = 3;
const MAX_PART_POSITIONS = 3;
const MAX_PART_LINES     = 3;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function fetchLabours($pg, int $orderNo): array {
    $sql = 'SELECT order_position, order_position_line, text_line
            FROM (
              SELECT order_position, order_position_line, text_line,
                     ROW_NUMBER() OVER (PARTITION BY order_position ORDER BY order_position_line) AS line_no,
                     DENSE_RANK() OVER (ORDER BY order_position) AS pos_no
              FROM labours
              WHERE order_number = $1
            ) t
            WHERE pos_no <= $2 AND line_no <= $3
            ORDER BY order_position, order_position_line';
    $res = pg_query_params($pg, $sql, [$orderNo, MAX_WORK_POSITIONS, MAX_WORK_LINES]);
    $rows = $res ? pg_fetch_all($res) : [];
    $out = [];
    foreach ($rows ?: [] as $r) {
        $pos = (int)$r['order_position'];
        $out[$pos][] = $r['text_line'];
    }
    return $out;
}

function fetchParts($pg, int $orderNo): array {
    $sql = 'SELECT p.order_position, p.order_position_line,
                   COALESCE(pm.description, p.text_line) AS description
            FROM (
              SELECT *,
                     ROW_NUMBER() OVER (PARTITION BY order_position ORDER BY order_position_line) AS line_no,
                     DENSE_RANK() OVER (ORDER BY order_position) AS pos_no
              FROM parts
              WHERE order_number = $1
            ) p
            LEFT JOIN parts_master pm ON pm.part_number = p.part_number
            WHERE p.pos_no <= $2 AND p.line_no <= $3
            ORDER BY p.order_position, p.order_position_line';
    $res = pg_query_params($pg, $sql, [$orderNo, MAX_PART_POSITIONS, MAX_PART_LINES]);
    $rows = $res ? pg_fetch_all($res) : [];
    $out = [];
    foreach ($rows ?: [] as $r) {
        $pos = (int)$r['order_position'];
        $out[$pos][] = $r['description'];
    }
    return $out;
}

$sql = 'SELECT o.number, o.order_date, o.order_customer, o.vehicle_number,
               c.name_prefix, c.first_name, c.family_name, c.name_postfix,
               m.description AS make
        FROM orders o
        LEFT JOIN customers_suppliers c ON c.customer_number = o.order_customer
        LEFT JOIN vehicles v ON v.internal_number = o.vehicle_number
        LEFT JOIN makes m ON m.make_number = v.make_number
        WHERE o.has_open_positions = \x74 -- "t" as boolean
        ORDER BY o.order_date
        LIMIT 50';

$res = pg_query($pg, $sql);
$orders = $res ? pg_fetch_all($res) : [];
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Offene Aufträge</title>
<style>
body { font-family: system-ui, sans-serif; margin: 16px; }
table { border-collapse: collapse; width: 100%; }
th, td { border:1px solid #ddd; padding:4px 6px; vertical-align: top; }
.work-item, .part-item { white-space: nowrap; margin-bottom:2px; }
.part-item::before { content:"\1F4E6\0020"; } /* Paket/Teil-Icon */
</style>
</head>
<body>
<h1>Offene Aufträge</h1>
<table>
<thead>
<tr>
  <th>Auftrag</th>
  <th>Datum</th>
  <th>Kunde</th>
  <th>Marke</th>
  <th>Arbeiten</th>
  <th>Teile</th>
  <th>Kommentar</th>
  <th>Unterlagen</th>
  <th>Übermittelt</th>
  <th>Bezahlt</th>
  <th>Entsorgt</th>
</tr>
</thead>
<tbody>
<?php foreach ($orders as $o):
    $custParts = [];
    foreach (['name_prefix','first_name','family_name','name_postfix'] as $k) {
        if (!empty($o[$k])) $custParts[] = trim($o[$k]);
    }
    $customer = implode(' ', $custParts);
    $labours = fetchLabours($pg, (int)$o['number']);
    $parts = fetchParts($pg, (int)$o['number']);
?>
<tr>
  <td><?=h($o['number'])?></td>
  <td><?=h(substr($o['order_date'],0,10))?></td>
  <td><?=h($customer)?></td>
  <td><?=h($o['make'])?></td>
  <td>
    <?php foreach ($labours as $lines):
        $short = h($lines[0] ?? '');
        $full = h(implode("\n", $lines));
    ?>
      <div class="work-item" title="<?=$full?>"><?=$short?></div>
    <?php endforeach; ?>
  </td>
  <td>
    <?php foreach ($parts as $lines):
        $short = h($lines[0] ?? '');
        $full = h(implode("\n", $lines));
    ?>
      <div class="part-item" title="<?=$full?>"><?=$short?></div>
    <?php endforeach; ?>
  </td>
  <td><input type="text" name="comment[<?=$o['number']?>]"></td>
  <td><input type="checkbox" name="unterlagen[<?=$o['number']?>]"></td>
  <td><input type="checkbox" name="uebermittelt[<?=$o['number']?>]"></td>
  <td><input type="checkbox" name="bezahlt[<?=$o['number']?>]"></td>
  <td><input type="checkbox" name="entsorgt[<?=$o['number']?>]"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
