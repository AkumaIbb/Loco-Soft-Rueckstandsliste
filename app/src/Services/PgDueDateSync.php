<?php
declare(strict_types=1);

namespace App\Services;

use PDODb;
use Throwable;

class PgDueDateSync
{
    private PDODb $db;
    private $pg; // pgsql connection
    private bool $debug;

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
        $PROJECT_ROOT = realpath(__DIR__ . '/../../');
        require $PROJECT_ROOT . '/config/postgres_data.php'; // stellt $postgresdata bereit
        $this->pg = @pg_connect($postgresdata);             // NICHT am DSN rumfummeln
        if (!$this->pg) {
            throw new \RuntimeException("Postgres-Verbindung fehlgeschlagen");
        }
    }

    /**
     * Setzt für VOLV/POLE (Bestellart 5–8) das rueck_ab_date = DATE(order_date) + 1 Tag,
     * ausschließlich basierend auf bezugs_auftrags_nr -> orders.number.
     *
     * Standard: nur der letzte Importlauf (source_system='main').
     * Mit $allRuns=true kannst du alles updaten (z. B. Backfill).
     */
    public function run(bool $allRuns = false): array
    {
        $this->pgConnect();

        $params = [];
        $whereRun = '';
        if (!$allRuns) {
            $run = $this->db->rawQuery(
                "SELECT id FROM import_runs
                 WHERE source_system='main'
                 ORDER BY imported_at DESC, id DESC
                 LIMIT 1"
            );
            if (!$run) throw new \RuntimeException('Kein Importlauf gefunden.');
            $runId = (int)$run[0]['id'];
            $whereRun = "AND o.import_run_id = ?";
            $params[] = $runId;
            $this->out("Arbeite Importlauf ID: {$runId}");
        } else {
            $this->out("Arbeite alle Läufe (Backfill)");
        }

        // Kandidaten: VOLV/POLE, Bestellart 5–8, mit bezugs_auftrags_nr
        $orders = $this->db->rawQuery(
            "SELECT o.id, o.bezugs_auftrags_nr, o.rueck_ab_date
               FROM backlog_orders o
              WHERE UPPER(o.bestellkonzern) IN ('VOLV','POLE')
                AND o.bezugs_auftrags_nr IS NOT NULL
                AND o.bezugs_auftrags_nr <> ''
                AND CAST(o.bestellart AS UNSIGNED) BETWEEN 5 AND 8
                $whereRun",
            $params
        );

        $total = count($orders);
        $updated = 0; $missingPg = 0; $same = 0;

        $this->db->rawQuery("START TRANSACTION");

        foreach ($orders as $row) {
            $orderId = (int)$row['id'];
            $num = trim((string)$row['bezugs_auftrags_nr']);

            // Hole order_date aus PG (orders.number)
            $info = $this->pgOrderDate($num);
            if ($info === null) {
                $missingPg++;
                if ($this->debug) $this->out("PG: Kein orders.number={$num} gefunden (Order-ID {$orderId})");
                continue;
            }

            $due = (new \DateTime($info))->modify('+1 day')->format('Y-m-d');
            $cur = $row['rueck_ab_date'] ?? null;

            if ($cur === $due) {
                $same++;
                continue;
            }

            // Update in MySQL
            $this->db->rawQuery(
                "UPDATE backlog_orders
                    SET rueck_ab_date = ?, rueck_rule_note = NULL, updated_at = NOW()
                  WHERE id = ?",
                [ $due, $orderId ]
            );
            $updated++;

            if ($this->debug && $updated <= 5) {
                $this->out("Setze rueck_ab_date: Order {$orderId} ← {$due} (aus PG orders.order_date + 1)");
            }
        }

        $this->db->rawQuery("COMMIT");

        return [
            'status' => 'ok',
            'total_candidates' => $total,
            'updated_due' => $updated,
            'same_already' => $same,
            'missing_in_pg' => $missingPg
        ];
    }

    private function pgOrderDate(string $orderNumber): ?string
    {
        $sql = "SELECT order_date
                  FROM orders
                 WHERE number = $1
                 LIMIT 1";
        $res = pg_query_params($this->pg, $sql, [ $orderNumber ]);
        if (!$res) return null;
        $row = pg_fetch_assoc($res);
        if (!$row || empty($row['order_date'])) return null;
        // Beispiel: '2018-11-30 00:00:00'
        return $row['order_date'];
    }
}
