<?php
declare(strict_types=1);

namespace App\Import;

use DateTime;
use DateTimeInterface;
use PDODb;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use Throwable;

class ExcelImporter
{
    private PDODb $db;
    private string $excelPath;
    private bool $debug;

    private array $seenKeys = [];     // ["KONZERN|BESTELLNR|TEILENR" => true]
    private int $importRunId = 0;
    private int $rowsTotal = 0;
    private int $rowsOk = 0;
    private array $deliveryTerms = [];    // [bestellart => ['tage_bis_rueckstand'=>int,'use_order_date'=>bool]]

    /** @var resource|null */
    private $pg = null; // Postgres-Connection

    public function __construct(PDODb $db, string $excelPath, bool $debug = false)
    {
        $this->db = $db;
        $this->excelPath = $excelPath;
        $this->debug = $debug;
    }

    private function out(string $msg): void
    {
        if ($this->debug) {
            echo htmlspecialchars('[' . date('Y-m-d H:i:s') . "] $msg") . "<br>";
            @ob_flush(); @flush();
        }
    }

    // ===== Helpers =====

    private function parseExcelDate($val): ?string
    {
        if ($val === null) return null;
        if ($val instanceof DateTimeInterface) return $val->format('Y-m-d');
        if (is_numeric($val)) {
            try {
                $dt = XlsDate::excelToDateTimeObject((float)$val);
                return $dt->format('Y-m-d');
            } catch (Throwable $e) {}
        }
        $s = trim((string)$val);
        if ($s === '') return null;

        if (preg_match('~^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$~', $s, $m)) {
            [$all,$d,$mth,$y] = $m;
            if (strlen($y) === 2) $y = (int)$y + 2000;
            return sprintf('%04d-%02d-%02d', (int)$y, (int)$mth, (int)$d);
        }

        $try = ['d.m.Y','d.m.y','Y-m-d','d-m-Y','m/d/Y','d M Y','j M Y','M j, Y','d F Y','j F Y','F j, Y'];
        foreach ($try as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $s);
            if ($dt instanceof DateTime) return $dt->format('Y-m-d');
        }
        $ts = strtotime($s);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private function addDays(?string $ymd, int $days): ?string {
        if ($ymd === null) return null;
        $dt = new DateTime($ymd);
        $dt->modify('+' . $days . ' day');
        return $dt->format('Y-m-d');
    }

    /** +N Werktage (Mo–Fr), Wochenenden werden übersprungen */
    private function addBusinessDays(?string $ymd, int $days): ?string {
        if ($ymd === null) return null;
        if ($days <= 0) return $ymd;
        $d = new DateTime($ymd);
        $added = 0;
        while ($added < $days) {
            $d->modify('+1 day');
            $w = (int)$d->format('N'); // 1=Mo ... 7=So
            if ($w <= 5) { $added++; }
        }
        return $d->format('Y-m-d');
    }

    private function dec($val): ?string
    {
        if ($val === null || $val === '') return null;
        if (is_numeric($val)) return (string)$val;
        $s = trim((string)$val);
        $s = str_replace(['.', ' '], ['', ''], $s);
        $s = str_replace(',', '.', $s);
        return ($s === '' || $s === '-') ? null : $s;
    }

    private function norm($val): ?string
    {
        if ($val === null) return null;
        $s = preg_replace('~\s+~u', ' ', trim((string)$val));
        return $s === '' ? null : $s;
    }

    private function key(string $konzern, string $bestellnr, string $teilenr): string
    {
        return $konzern . '|' . $bestellnr . '|' . $teilenr;
    }

    private function rowHash(array $parts): string {
        return hash('sha256', implode('|', array_map(fn($v)=> $v === null ? '' : (string)$v, $parts)));
    }

    private function normalizeDesc(?string $s): ?string {
        if (!$s) return null;
        $s = mb_strtolower(trim($s), 'UTF-8');
        return mb_convert_case($s, MB_CASE_TITLE, "UTF-8");
    }

    private function pgConnect(): void
    {
        if ($this->pg) return;
        $PROJECT_ROOT = realpath(__DIR__ . '/../../');
        require $PROJECT_ROOT . '/config/postgres_data.php'; // $postgresdata
        $this->pg = @pg_connect($postgresdata);             // keine Port-Manipulation
        if (!$this->pg) {
            $this->out('WARN: Postgres nicht erreichbar – benötigte Daten fehlen.');
        }
    }

        private function fetchDescriptionFromPG(?string $partNo): ?string
        {
                if (!$partNo) return null;
                $this->pgConnect();
                if (!$this->pg) return null;

		// 1) Exakt
		$res = @pg_query_params(
			$this->pg,
			"SELECT description
			   FROM parts_master
			  WHERE part_number = $1
			  LIMIT 1",
			[ $partNo ]
		);
		if ($res && ($row = pg_fetch_assoc($res)) && isset($row['description'])) {
			return $this->normalizeDesc($row['description']);
		}

		// 2) Führende Spaces (inkl. NBSP) in der DB-Spalte ignorieren
		//    NBSP = CHR(160). Erst NBSP -> normales Space mappen, dann LTRIM.
		$res = @pg_query_params(
			$this->pg,
			"SELECT description
			   FROM parts_master
			  WHERE ltrim(replace(part_number, CHR(160), ' ')) = $1
			  LIMIT 1",
			[ $partNo ]
		);
		if ($res && ($row = pg_fetch_assoc($res)) && isset($row['description'])) {
			return $this->normalizeDesc($row['description']);
		}

                return null;
        }

        private function fetchOrderDateFromPG(?string $orderNumber): ?string
        {
                if (!$orderNumber) return null;
                $this->pgConnect();
                if (!$this->pg) return null;
                $res = @pg_query_params(
                        $this->pg,
                        "SELECT order_date FROM orders WHERE number = $1 LIMIT 1",
                        [ $orderNumber ]
                );
                if ($res && ($row = pg_fetch_assoc($res)) && isset($row['order_date'])) {
                        $dt = new \DateTime($row['order_date']);
                        return $dt->format('Y-m-d');
                }
                return null;
        }



    // ===== Main =====

    public function run(): array
    {
        if (!file_exists($this->excelPath)) {
            throw new \RuntimeException("Excel-Datei nicht gefunden: {$this->excelPath}");
        }

        $this->out("Lade Datei: {$this->excelPath}");
        $spreadsheet = IOFactory::load($this->excelPath);
        $sheet = $spreadsheet->getActiveSheet();

        $headerRow = 1;
        $highestCol = $sheet->getHighestColumn();
        $highestRow = (int)$sheet->getHighestRow();
        $lastColIdx = Coordinate::columnIndexFromString($highestCol);

        $this->rowsTotal = max(0, $highestRow - $headerRow);

        // Ignorierte Konzerne laden
        $ignoreRows = $this->db->rawQuery('SELECT name FROM ignores');
        $ignoredCompanies = [];
        foreach ($ignoreRows as $row) {
            $n = trim((string)($row['name'] ?? ''));
            if ($n !== '') {
                $ignoredCompanies[] = mb_strtoupper($n, 'UTF-8');
            }
        }

        // delivery terms
        $terms = $this->db->rawQuery('SELECT bestellart, tage_bis_rueckstand, use_order_date FROM delivery_terms');
        foreach ($terms as $row) {
            $this->deliveryTerms[(int)$row['bestellart']] = [
                'tage_bis_rueckstand' => isset($row['tage_bis_rueckstand']) ? (int)$row['tage_bis_rueckstand'] : 1,
                'use_order_date' => (int)$row['use_order_date'] === 1,
            ];
        }

        // Headermap
        $map = [];
        for ($c = 1; $c <= $lastColIdx; $c++) {
            $addr = Coordinate::stringFromColumnIndex($c) . $headerRow;
            $val = (string)$sheet->getCell($addr)->getCalculatedValue();
            // Explicitly treat header values as UTF-8 so special characters
            // like "Ü" are handled correctly regardless of the mb_internal_encoding
            $key = mb_strtolower(trim($val), 'UTF-8');
            if ($key !== '') {
                $map[$key] = $c;
            }
        }

        $need = [
            'typ','bestellkonzern','bestelldatum','bestellnummer','bestellart','lieferant',
            'bezugs-kunden-nr.','bezugs-auftrags-nr.','teile-nr.','teileart',
            'bestell menge','bestell wert','rückstands menge','rückstands wert',
            'bestellherkunft code','bestellherkunft text',
        ];
        foreach ($need as $h) {
            if (!isset($map[$h])) $this->out("WARN: Spalte fehlt: $h");
        }

        // Importlauf protokollieren
        $fileHash = hash_file('sha256', $this->excelPath);
        $sqlRun = "INSERT INTO import_runs
            (source_filename, source_path, imported_at, rows_total, rows_ok, file_hash, notes, source_system, supplier)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, 'main', NULL)";
        $this->db->rawQuery($sqlRun, [
            basename($this->excelPath),
            $this->excelPath,
            $this->rowsTotal,
            0,
            hex2bin($fileHash),
            'Excel täglicher Import'
        ]);
        $rid = $this->db->rawQuery("SELECT LAST_INSERT_ID() AS id");
        if (!is_array($rid) || empty($rid[0]['id'])) {
            throw new \RuntimeException('Konnte LAST_INSERT_ID() nicht ermitteln');
        }
        $this->importRunId = (int)$rid[0]['id'];
        $this->out("Importlauf ID: {$this->importRunId}");

        // UPSERT (inkl. description & Rückstandslogik)
        $sqlUpsert = "
            INSERT INTO backlog_orders
            (import_run_id, typ, bestellkonzern, bestelldatum, bestellnummer, bestellart, lieferant,
             bezugs_kunden_nr, bezugs_auftrags_nr, teile_nr, description, teileart,
             bestell_menge, bestell_wert, rueckstands_menge, rueckstands_wert,
             bestellherkunft_code, bestellherkunft_text,
             rueck_ab_date, rueckstand_relevant, rueck_rule_note,
             source_row, row_hash, created_at, updated_at)
            VALUES
            (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              typ=VALUES(typ),
              bestelldatum=VALUES(bestelldatum),
              bestellart=VALUES(bestellart),
              lieferant=VALUES(lieferant),
              bezugs_kunden_nr=VALUES(bezugs_kunden_nr),
              bezugs_auftrags_nr=VALUES(bezugs_auftrags_nr),
              description=VALUES(description),
              teileart=VALUES(teileart),
              bestell_menge=VALUES(bestell_menge),
              bestell_wert=VALUES(bestell_wert),
              rueckstands_menge=VALUES(rueckstands_menge),
              rueckstands_wert=VALUES(rueckstands_wert),
              bestellherkunft_code=VALUES(bestellherkunft_code),
              bestellherkunft_text=VALUES(bestellherkunft_text),
              rueck_ab_date=VALUES(rueck_ab_date),
              rueckstand_relevant=VALUES(rueckstand_relevant),
              rueck_rule_note=VALUES(rueck_rule_note),
              import_run_id=VALUES(import_run_id),
              source_row=VALUES(source_row),
              row_hash=VALUES(row_hash),
              updated_at=NOW()
        ";

        $this->db->rawQuery("START TRANSACTION");

        $emptyStreak = 0; $emptyCut = 20;

        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $get = function(string $lower) use ($sheet, $map, $r) {
                if (!isset($map[$lower])) return null;
                $col = $map[$lower];
                $addr = Coordinate::stringFromColumnIndex($col) . $r;
                return $sheet->getCell($addr)->getCalculatedValue();
            };

            $typ            = $this->norm($get('typ'));
            // Fallback für leere Typen -> verhindert NOT NULL Fehler
            if ($typ === null || $typ === '') {
                $typ = 'Offene Bestellung';
            }

            $konzern        = $this->norm($get('bestellkonzern'));
            $bestelldatum   = $this->parseExcelDate($get('bestelldatum'));
            $bestellnummer  = $this->norm($get('bestellnummer'));
            $bestellart     = $this->norm($get('bestellart'));
            $lieferant      = $this->norm($get('lieferant'));
            $bez_kdnr       = $this->norm($get('bezugs-kunden-nr.'));
            $bez_auftragsnr = $this->norm($get('bezugs-auftrags-nr.'));
            $teilenr        = $this->norm($get('teile-nr.'));
            $teileart       = $this->norm($get('teileart'));

            $bestell_menge  = $this->dec($get('bestell menge'));
            $bestell_wert   = $this->dec($get('bestell wert'));
            $rueck_menge    = $this->dec($get('rückstands menge'));
            $rueck_wert     = $this->dec($get('rückstands wert'));

            $bh_code        = $this->norm($get('bestellherkunft code'));
            $bh_text        = $this->norm($get('bestellherkunft text'));

            // Zeile leer?
            $isEmpty = !$typ && !$konzern && !$bestellnummer && !$teilenr;
            if ($isEmpty) {
                if (++$emptyStreak >= $emptyCut) { $this->out("Früher Abbruch bei Zeile $r"); break; }
                continue;
            } else {
                $emptyStreak = 0;
            }

            // Pflichtfelder
            if (!$konzern || !$bestellnummer || !$teilenr) {
                $this->out("SKIP Zeile $r: fehlende Schlüssel");
                continue;
            }

            // Ignorierte Konzerne komplett überspringen
            $konz = mb_strtoupper($konzern, 'UTF-8');
            if (in_array($konz, $ignoredCompanies, true)) {
                if ($this->debug) { $this->out("SKIP $konzern bei Zeile $r"); }
                continue;
            }

            // Bestellart als int
            $bestellartInt = null;
            if ($bestellart !== null && $bestellart !== '') {
                $bestellartInt = (int)preg_replace('~\D+~', '', $bestellart);
            }

            // Rückstandsdaten berechnen
            $rueckstand_relevant = 1;
            $rueck_rule_note = null;
            $rueck_ab_date = $this->addDays(date('Y-m-d'), 1); // Default 1 Tag ab heute

            if ($bestellartInt !== null && isset($this->deliveryTerms[$bestellartInt])) {
                $term = $this->deliveryTerms[$bestellartInt];
                $tage = $term['tage_bis_rueckstand'] ?? 1;

                if ($term['use_order_date']) {
                    $orderDate = $this->fetchOrderDateFromPG($bez_auftragsnr);
                    if ($orderDate !== null) {
                        $rueck_ab_date = $this->addDays($orderDate, $tage);
                    } else {
                        $rueck_ab_date = null;
                        $rueck_rule_note = 'PG orders.number nicht gefunden';
                    }
                } else {
                    $rueck_ab_date = $this->addDays(date('Y-m-d'), $tage);
                }
            }

            // Description aus PG (parts_master)
            $description = $this->fetchDescriptionFromPG($teilenr);

            // Hash
            $hash = $this->rowHash([
                $typ,$konzern,$bestelldatum,$bestellnummer,$bestellart,$lieferant,
                $bez_kdnr,$bez_auftragsnr,$teilenr,$description,$teileart,
                $bestell_menge,$bestell_wert,$rueck_menge,$rueck_wert,
                $bh_code,$bh_text,$rueck_ab_date,$rueckstand_relevant,$rueck_rule_note
            ]);

            // UPSERT
            $params = [
                $this->importRunId, $typ, $konzern, $bestelldatum, $bestellnummer, $bestellart, $lieferant,
                $bez_kdnr, $bez_auftragsnr, $teilenr, $description, $teileart,
                $bestell_menge, $bestell_wert, $rueck_menge, $rueck_wert,
                $bh_code, $bh_text,
                $rueck_ab_date, $rueckstand_relevant, $rueck_rule_note,
                $r, hex2bin($hash)
            ];

            $ok = $this->db->rawQuery($sqlUpsert, $params);
            if ($ok === false) {
                $this->out("FEHLER DB in Zeile $r");
                continue;
            }
            $this->rowsOk++;

            // Merk-Key für späteres Löschen
            $this->seenKeys[$this->key($konzern, $bestellnummer, $teilenr)] = true;

            if ($this->debug && $this->rowsOk <= 5) {
                $this->out("OK Zeile $r: $konzern | $bestellnummer | $teilenr | rueck_ab_date={$rueck_ab_date}");
            }
        }

        // Statistik aktualisieren
        $this->db->rawQuery("UPDATE import_runs SET rows_total=?, rows_ok=? WHERE id=?",
            [$this->rowsTotal, $this->rowsOk, $this->importRunId]);

        // --- Mark-and-sweep (global, da Datei alle Konzerne enthält) ---
        $this->db->rawQuery("DROP TEMPORARY TABLE IF EXISTS tmp_seen_keys");
        $this->db->rawQuery("CREATE TEMPORARY TABLE tmp_seen_keys (
            bestellkonzern VARCHAR(50) NOT NULL,
            bestellnummer  VARCHAR(100) NOT NULL,
            teile_nr       VARCHAR(120) NOT NULL,
            PRIMARY KEY (bestellkonzern, bestellnummer, teile_nr)
        ) ENGINE=MEMORY");

        if (!empty($this->seenKeys)) {
            $insSql = "INSERT INTO tmp_seen_keys (bestellkonzern,bestellnummer,teile_nr) VALUES ";
            $vals = []; $bind = [];
            foreach ($this->seenKeys as $key => $_) {
                [$k,$bn,$tn] = explode('|', $key, 3);
                $vals[] = "(?, ?, ?)";
                array_push($bind, $k, $bn, $tn);
            }
            $this->db->rawQuery($insSql . implode(',', $vals), $bind);
        }

        // Kandidaten, die heute NICHT geliefert wurden
        $sqlToDelete = "
          SELECT o.id, o.bestellkonzern, o.bestellnummer, o.teile_nr, a.serviceberater
          FROM backlog_orders o
          LEFT JOIN tmp_seen_keys t
            ON t.bestellkonzern = o.bestellkonzern
           AND t.bestellnummer  = o.bestellnummer
           AND t.teile_nr       = o.teile_nr
          LEFT JOIN backlog_annotations a
            ON a.order_id = o.id
          WHERE t.bestellnummer IS NULL
        ";
        $candidates = $this->db->rawQuery($sqlToDelete);

        // PLATZHALTER: Hier ggf. Serviceberater benachrichtigen, bevor gelöscht wird
        if ($this->debug) {
            $this->out('Lösch-Kandidaten: ' . count($candidates));
            foreach (array_slice($candidates, 0, 5) as $row) {
                $sb = $row['serviceberater'] ?? '(kein SB)';
                $this->out("Würde löschen: {$row['bestellkonzern']} | {$row['bestellnummer']} | {$row['teile_nr']} | SB=$sb");
            }
        } else {
            // Hier später: Mailversand implementieren
        }

        if (!empty($candidates)) {
            $ids = array_column($candidates, 'id');
            foreach (array_chunk($ids, 1000) as $chunk) {
                $place = implode(',', array_fill(0, count($chunk), '?'));
                $this->db->rawQuery("DELETE FROM backlog_orders WHERE id IN ($place)", $chunk);
            }
        }

        $this->db->rawQuery("COMMIT");

        // PG wieder schließen
        if ($this->pg) { @pg_close($this->pg); $this->pg = null; }

        return [
            'status'        => 'ok',
            'import_run_id' => $this->importRunId,
            'rows_total'    => $this->rowsTotal,
            'rows_ok'       => $this->rowsOk,
            'deleted'       => isset($candidates) ? count($candidates) : 0,
        ];
    }
}
