<?php
declare(strict_types=1);

namespace App\Services;

use PDODb;
use Throwable;

class ServiceberaterSync
{
    private PDODb $db;
    private $pg; // pgsql resource

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
        // /config/postgres_data.php stellt $postgresserver,$postgresport,$postgresdb,$postgresuser,$postgrespw,$postgresdata bereit
        $PROJECT_ROOT = realpath(__DIR__ . '/../../');
        require $PROJECT_ROOT . '/config/postgres_data.php';

		$this->pg = @pg_connect($postgresdata);
		if (!$this->pg) {
			throw new \RuntimeException("Postgres-Verbindung fehlgeschlagen mit DSN: $postgresdata");
		}
    }

    /**
     * Holt alle Orders des letzten Importlaufs (source_system='main')
     * und setzt den Serviceberater-Namen aus PG.
     *
     * @param bool $forceOverwrite true: vorhandene Serviceberater überschreiben
     */
    public function run(bool $forceOverwrite = false): array
    {
        $this->pgConnect();

        // Letzter Importlauf
        $run = $this->db->rawQuery(
            "SELECT id FROM import_runs
             WHERE source_system='main'
             ORDER BY imported_at DESC, id DESC
             LIMIT 1"
        );
        if (!$run) {
            throw new \RuntimeException('Kein Importlauf gefunden.');
        }
        $runId = (int)$run[0]['id'];
        $this->out("Arbeite Importlauf ID: {$runId}");

        // Alle Orders dieses Laufs
		$orders = $this->db->rawQuery(
			"SELECT o.id, o.bezugs_auftrags_nr
			   FROM backlog_orders o
			  WHERE o.import_run_id = ?
				AND o.bezugs_auftrags_nr IS NOT NULL
				AND o.bezugs_auftrags_nr <> ''",
			[ $runId ]
		);
        $total = count($orders);
        $this->out("Gefundene Rückstände im Lauf: {$total}");

        $updated = 0; $skipped = 0; $notFound = 0;

        // Optional: vorhandene SB nur überschreiben, wenn $forceOverwrite
        $existingMap = [];
        if (!$forceOverwrite) {
            $rows = $this->db->rawQuery(
                "SELECT a.order_id, a.serviceberater
                   FROM backlog_annotations a
                   JOIN backlog_orders o ON o.id = a.order_id
                  WHERE o.import_run_id = ?", [ $runId ]
            );
            foreach ($rows as $r) {
                if (!empty($r['serviceberater'])) {
                    $existingMap[(int)$r['order_id']] = $r['serviceberater'];
                }
            }
        }

        $this->db->rawQuery("START TRANSACTION");

        foreach ($orders as $row) {
            $orderId = (int)$row['id'];
            if (!$forceOverwrite && isset($existingMap[$orderId])) {
				$skipped++;
				if ($this->debug) {
					$this->out("SKIP: Order {$orderId} (bezugs_auftrags_nr={$row['bezugs_auftrags_nr']}) bereits Serviceberater='{$existingMap[$orderId]}' gesetzt");
				}
				continue;
			}

            $num = $row['bezugs_auftrags_nr'];
			if (!$num) { $notFound++; if ($this->debug) $this->out("SKIP: leere bezugs_auftrags_nr für Order-ID {$orderId}"); continue; }

            // 1) Mitarbeiter-Nr. aus orders
            $empNo = $this->pgEmpNoForOrder($num);
            if (!$empNo) {
                $notFound++;
                if ($this->debug) $this->out("Kein created_employee_no für Auftragsnr={$num}");
                continue;
            }

            // 2) Name aus employees
            $name = $this->pgEmployeeName($empNo);
            if (!$name) {
                $notFound++;
                if ($this->debug) $this->out("Kein Name in employees für employee_number={$empNo}");
                continue;
            }

            // 3) Upsert in backlog_annotations
            $this->upsertAnnotation($orderId, $name);
            $updated++;
            if ($this->debug && $updated <= 5) {
                $this->out("Setze SB: Order {$orderId} ← {$name} (bezugs_auftrags_nr={$num})");
            }
        }

        $this->db->rawQuery("COMMIT");

        return [
            'status' => 'ok',
            'import_run_id' => $runId,
            'total_orders' => $total,
            'updated' => $updated,
            'skipped_existing' => $skipped,
            'not_found' => $notFound
        ];
    }

    private function pgEmpNoForOrder(string $orderNumber): ?string
    {
        // Achtung SQL-Injection vermeiden → pg_query_params
        $sql = "SELECT created_employee_no
                  FROM orders
                 WHERE number = $1
                 LIMIT 1";
        $res = pg_query_params($this->pg, $sql, [ $orderNumber ]);
        if (!$res) return null;
        $row = pg_fetch_assoc($res);
        return $row && !empty($row['created_employee_no']) ? trim((string)$row['created_employee_no']) : null;
    }

    private function pgEmployeeName(string $employeeNumber): ?string
    {
        $sql = "SELECT name
                  FROM employees
                 WHERE employee_number = $1
                 LIMIT 1";
        $res = pg_query_params($this->pg, $sql, [ $employeeNumber ]);
        if (!$res) return null;
        $row = pg_fetch_assoc($res);
        return $row && !empty($row['name']) ? trim((string)$row['name']) : null;
    }

    private function upsertAnnotation(int $orderId, string $serviceberater): void
    {
        // INSERT … ON DUPLICATE KEY UPDATE
        $sql = "INSERT INTO backlog_annotations (order_id, serviceberater, updated_by)
                VALUES (?, ?, 'pg-sync')
                ON DUPLICATE KEY UPDATE
                  serviceberater = VALUES(serviceberater),
                  updated_by = 'pg-sync',
                  updated_at = NOW()";
        $this->db->rawQuery($sql, [ $orderId, $serviceberater ]);
    }
}
