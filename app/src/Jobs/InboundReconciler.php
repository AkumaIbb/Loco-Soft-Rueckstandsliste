<?php
declare(strict_types=1);

namespace App\Jobs;

use PDODb;
use DateTime;

class InboundReconciler
{
    private PDODb $db;
    /** @var resource|null */
    private $pg = null;
    private bool $debug = false;
    private string $dbName = 'Rueckstaende';

    public function __construct(PDODb $db, bool $debug = false)
    {
        $this->db = $db;
        $this->debug = $debug;
    }

    private function out(string $msg): void
    {
        if ($this->debug) {
            echo htmlspecialchars('[' . date('Y-m-d H:i:s') . "] $msg") . "<br>";
            @ob_flush(); @flush();
        }
    }

    private function pgConnect(): void
    {
        if ($this->pg) return;
        $PROJECT_ROOT = realpath(__DIR__ . '/../../');
        require $PROJECT_ROOT . '/config/postgres_data.php'; // $postgresdata
        $this->pg = @pg_connect($postgresdata);             // Port/Parameter NICHT überschreiben
        if (!$this->pg) {
            throw new \RuntimeException('Postgres-Verbindung fehlgeschlagen (parts_inbound_delivery_notes).');
        }
    }

    /**
     * Lieferscheine der letzten $daysBack Tage ziehen.
     * @return array<int, array{
     *     part_number: string|null,
     *     amount: float|null,
     *     delivery_note_date: string|null,
     *     parts_order_number: ?string,
     *     referenced_order_number: ?string
     * }>
     */
    private function fetchInboundNotes(int $daysBack): array
    {
        $this->pgConnect();

        // Wir holen per Datum; Uhrzeit ist in PG ein DATE-Feld (also 00:00:00 implizit)
        $sql = "SELECT
                    part_number,
                    amount::text AS amount,
                    delivery_note_date::text AS delivery_note_date,
                    parts_order_number::text AS parts_order_number,
                    referenced_order_number::text AS referenced_order_number
                FROM parts_inbound_delivery_notes
                WHERE delivery_note_date >= CURRENT_DATE - INTERVAL '%d days'
                ORDER BY delivery_note_date DESC";
        $res = @pg_query($this->pg, sprintf($sql, max(0, $daysBack)));
        if (!$res) throw new \RuntimeException('PG-Query auf parts_inbound_delivery_notes fehlgeschlagen.');

        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $pn = $r['part_number'] !== null ? trim($r['part_number']) : null; // führende Leerzeichen entfernen
            $amount = $r['amount'] !== null ? (float)str_replace(',', '.', $r['amount']) : null;
            $rows[] = [
                'part_number' => $pn,
                'amount' => $amount,
                'delivery_note_date' => $r['delivery_note_date'] ?? null,
                'parts_order_number' => $r['parts_order_number'] ?? null,
                'referenced_order_number' => $r['referenced_order_number'] ?? null,
            ];
        }
        return $rows;
    }

    public function run(int $daysBack = 3): array
    {
        $notes = $this->fetchInboundNotes($daysBack);
        $this->out("Gefundene Lieferscheine (letzte {$daysBack} Tage): " . count($notes));

        $deleted = 0;
        $updated = 0;
        $skipped = 0;
        $actions = [];

        // Wir fassen gleiche Matches zusammen (z.B. mehrere Lieferscheine zur selben Position)
        // Schlüssel: bestellRefType|bestellRefNo|teile_nr
        $agg = []; // [ key => total_amount ]
        foreach ($notes as $n) {
            $teil = $n['part_number'] ?? '';
            if ($teil === '' || ($n['amount'] ?? 0) <= 0) { $skipped++; continue; }

            // 1) bevorzugt parts_order_number (Bestellnummer)
            if (!empty($n['parts_order_number'])) {
                $key = 'B|' . trim($n['parts_order_number']) . '|' . $teil;
                $agg[$key] = ($agg[$key] ?? 0) + (float)$n['amount'];
            }

            // 2) alternativ referenced_order_number (Bezugs-Auftrags-Nr.)
            if (!empty($n['referenced_order_number'])) {
                $key = 'A|' . trim($n['referenced_order_number']) . '|' . $teil;
                $agg[$key] = ($agg[$key] ?? 0) + (float)$n['amount'];
            }

            if (empty($n['parts_order_number']) && empty($n['referenced_order_number'])) {
                $skipped++;
                if ($this->debug) $this->out("SKIP: kein Bestell-/Auftragsbezug für Teil {$teil}");
            }
        }

        if (!$agg) {
            $this->out("Nichts zu verarbeiten.");
            return compact('deleted','updated','skipped','actions');
        }

        $this->db->rawQuery("START TRANSACTION");

        foreach ($agg as $key => $qtyDelivered) {
            [$type, $ref, $teil] = explode('|', $key, 3);
            $teilNorm = $teil; // MySQL-Seite enthält idR sauber, daher nur TRIM in PG reicht

            // Match-Query vorbereiten
            if ($type === 'B') { // parts_order_number -> bestellnummer
                $rows = $this->db->rawQuery("
                    SELECT id, bestellnummer, bezugs_auftrags_nr, teile_nr, rueckstands_menge
                    FROM {$this->dbName}.backlog_orders
                    WHERE teile_nr = ?
                      AND bestellnummer = ?
                    ORDER BY id
                ", [ $teilNorm, $ref ]);
            } else {             // referenced_order_number -> bezugs_auftrags_nr
                $rows = $this->db->rawQuery("
                    SELECT id, bestellnummer, bezugs_auftrags_nr, teile_nr, rueckstands_menge
                    FROM {$this->dbName}.backlog_orders
                    WHERE teile_nr = ?
                      AND bezugs_auftrags_nr = ?
                    ORDER BY id
                ", [ $teilNorm, $ref ]);
            }

            if (!$rows) {
                $skipped++;
                if ($this->debug) $this->out("Kein Match in backlog_orders für [$type/$ref/$teilNorm], Menge {$qtyDelivered}");
                continue;
            }

            // Verteilte Menge über ggf. mehrere Positionen (seltener Fall)
            $remaining = $qtyDelivered;
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $open = $r['rueckstands_menge'] !== null ? (float)$r['rueckstands_menge'] : null;

                if ($open === null) {
                    // Keine offene Menge gepflegt -> als vollständige Erledigung annehmen
                    $this->db->rawQuery("DELETE FROM {$this->dbName}.backlog_orders WHERE id = ?", [ $id ]);
                    $deleted++;
                    $actions[] = "DEL id={$id} (ohne rueckstands_menge)";
                    if ($this->debug) $this->out("DEL id={$id} (ohne rueckstands_menge)");
                    continue;
                }

                if ($remaining <= 0) break;

                if ($remaining >= $open - 1e-6) {
                    // Vollständig erledigt
                    $this->db->rawQuery("DELETE FROM {$this->dbName}.backlog_orders WHERE id = ?", [ $id ]);
                    $deleted++;
                    $actions[] = "DEL id={$id} (war {$open}, geliefert {$remaining})";
                    if ($this->debug) $this->out("DEL id={$id} (war {$open}, geliefert {$remaining})");
                    $remaining -= $open;
                } else {
                    // Teil-Lieferung -> offene Menge reduzieren
                    $newOpen = max(0, $open - $remaining);
                    $this->db->rawQuery("
                        UPDATE {$this->dbName}.backlog_orders
                           SET rueckstands_menge = ?
                         WHERE id = ?
                    ", [ $newOpen, $id ]);
                    $updated++;
                    $actions[] = "UPD id={$id} offen {$open} -> {$newOpen} (geliefert {$remaining})";
                    if ($this->debug) $this->out("UPD id={$id} offen {$open} -> {$newOpen} (geliefert {$remaining})");
                    $remaining = 0.0;
                }
            }

            if ($remaining > 1e-6 && $this->debug) {
                $this->out("INFO: Restmenge {$remaining} nicht zuordenbar (Mehrlieferung?) für [$type/$ref/$teilNorm]");
            }
        }

        // Platzhalter: Benachrichtigung
        // Hier könntest du je gelöschter/aktualisierter Position den zuständigen Serviceberater ermitteln:
        // SELECT a.serviceberater, o.bestellnummer, o.teile_nr FROM backlog_orders o LEFT JOIN backlog_annotations a ON a.order_id=o.id WHERE o.id IN (...)
        // und dann via PHPMailer eine Nachricht senden.

        $this->db->rawQuery("COMMIT");

        // Aufräumen
        if ($this->pg) { @pg_close($this->pg); $this->pg = null; }

        return compact('deleted','updated','skipped','actions');
    }
}
