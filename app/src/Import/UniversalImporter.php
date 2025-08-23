<?php
declare(strict_types=1);

namespace App\Import;

use DateTime;
use DateTimeInterface;
use PDODb;
use PhpOffice\PhpSpreadsheet\IOFactory;

class UniversalImporter
{
    private PDODb $db;
    private array $import;
    private array $mapping;
    private bool $debug;
    private string $importFolder;

    public function __construct(PDODb $db, array $import, array $mapping, bool $debug = false)
    {
        $this->db = $db;
        $this->import = $import;
        $this->mapping = $mapping;
        $this->debug = $debug;
        $this->importFolder = '/mnt/Import-Folder';
    }

    private function out(string $msg): void
    {
        if ($this->debug) {
            echo htmlspecialchars('[' . date('Y-m-d H:i:s') . "] $msg") . "<br>";
            @ob_flush(); @flush();
        }
    }

    private function extractOrder(string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '') return null;
        if (preg_match('/(\d+)/', $ref, $m)) {
            return $m[1];
        }
        return null;
    }

    private function parseDate($val): ?string
    {
        if ($val === null || $val === '') return null;
        if ($val instanceof DateTimeInterface) return $val->format('Y-m-d');
        $s = trim((string)$val);
        if ($s === '') return null;
        $dt = DateTime::createFromFormat('Y-m-d', $s);
        if (!$dt) $dt = DateTime::createFromFormat('d.m.Y', $s);
        if ($dt) return $dt->format('Y-m-d');
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function dec($val): ?string
    {
        if ($val === null || $val === '') return null;
        if (is_numeric($val)) return (string)$val;
        $s = str_replace(['.', ' '], ['', ''], (string)$val);
        $s = str_replace(',', '.', $s);
        return $s === '' ? null : $s;
    }

    /** Whitespace zusammenfassen */
    private function squashSpaces(?string $s): ?string
    {
        if ($s === null) return null;
        $s = preg_replace('~\s+~u', ' ', trim((string)$s));
        return $s;
    }

    /** Leerstring -> null */
    private function nullIfEmpty($s)
    {
        $s = trim((string)$s);
        return $s === '' ? null : $s;
    }

    /**
     * Erzeugt einen binären (32 Byte) SHA-256-Hash über normalisierte, inhaltsrelevante Felder
     * – kompatibel zur OTLG-Logik (ksort + JSON_UNESCAPED_UNICODE).
     */
    private function rowHash(array $data): string
    {
        // Felder, die in den Hash einfließen (normalisiert)
        $norm = [
            'partnernummer'     => $this->nullIfEmpty($data['partnernummer'] ?? null),
            'auftragsnummer'    => $this->nullIfEmpty($data['auftragsnummer'] ?? null),
            // wie im Beispiel: Leerzeichen aus Teilenummer entfernen
            'teilenummer'       => $this->nullIfEmpty(isset($data['teilenummer']) ? str_replace(' ', '', (string)$data['teilenummer']) : null),
            'kundenreferenz'    => $this->nullIfEmpty($data['kundenreferenz'] ?? null),
            'anlagedatum'       => $this->nullIfEmpty($data['anlagedatum'] ?? null),
            'auftragsart'       => $this->nullIfEmpty($data['auftragsart'] ?? null),
            'bestellte_menge'   => $this->nullIfEmpty($data['bestellte_menge'] ?? null),
            'bestaetigte_menge' => $this->nullIfEmpty($data['bestaetigte_menge'] ?? null),
            'offene_menge'      => $this->nullIfEmpty($data['offene_menge'] ?? null),
            'vsl_lt_sap'        => $this->nullIfEmpty($data['vsl_lt_sap'] ?? null),
            'vsl_lt_vz'         => $this->nullIfEmpty($data['vsl_lt_vz'] ?? null),
            // Info normalisieren (Spaces squashen) und leere -> null
            'info_vz'           => $this->nullIfEmpty($this->squashSpaces($data['info_vz'] ?? null)),
            'aenderungsdatum'   => $this->nullIfEmpty($data['aenderungsdatum'] ?? null),
            'teilelocator'      => $this->nullIfEmpty($data['teilelocator'] ?? null),
        ];

        ksort($norm);
        $json = json_encode($norm, JSON_UNESCAPED_UNICODE);
        // 32-Byte Rückgabe (binär), passend für BINARY(32)
        return hex2bin(hash('sha256', $json));
    }

    public function run(): array
    {
        $pattern = $this->importFolder . '/' . $this->import['filename'];
        $files = glob($pattern);
        if (!$files) {
            throw new \RuntimeException('Keine Datei gefunden für Muster ' . $this->import['filename']);
        }
        rsort($files);
        $file = $files[0];
        $this->out('Verwende Datei: ' . $file);

        $filetype = ($this->import['fields'] ?? 'csv') === 'excel' ? 'excel' : 'csv';

        $rows = [];
        if ($filetype === 'excel') {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getSheet(0);
            $rows = $sheet->toArray();
        } else {
            $fh = fopen($file, 'r');
            if (!$fh) {
                throw new \RuntimeException('CSV konnte nicht geöffnet werden');
            }
            while (($r = fgetcsv($fh, 0, ';')) !== false) {
                $rows[] = $r;
            }
            fclose($fh);
        }
        if (!$rows) return ['status'=>'ok','rows'=>0];

        $header = array_shift($rows);
        $index = [];
        foreach ($header as $i => $h) {
            $index[trim((string)$h)] = $i;
        }

        $map = [];
        foreach ($this->mapping as $field => $headerName) {
            if ($field === 'id') continue;
            if ($headerName === null || $headerName === '') continue;
            if (isset($index[$headerName])) {
                $map[$field] = $index[$headerName];
            }
        }

        $inserted = 0; $rowNum = 1;
        foreach ($rows as $r) {
            $rowNum++;
            $data = [
                'brand' => $this->import['brand'],
                'partnernummer' => null,
                'auftragsnummer' => null,
                'teilenummer' => null,
                'kundenreferenz' => null,
                'anlagedatum' => null,
                'auftragsart' => null,
                'bestellte_menge' => null,
                'bestaetigte_menge' => null,
                'offene_menge' => null,
                'vsl_lt_sap' => null,
                'vsl_lt_vz' => null,
                'info_vz' => null,
                'aenderungsdatum' => null,
                'teilelocator' => null,
            ];
            foreach ($map as $field => $idx) {
                $val = $r[$idx] ?? null;
                switch ($field) {
                    case 'anlagedatum':
                    case 'vsl_lt_sap':
                    case 'vsl_lt_vz':
                    case 'aenderungsdatum':
                        $data[$field] = $this->parseDate($val);
                        break;
                    case 'bestellte_menge':
                    case 'bestaetigte_menge':
                    case 'offene_menge':
                        $data[$field] = $this->dec($val);
                        break;
                    case 'kundenreferenz':
                        $data[$field] = $this->extractOrder((string)$val);
                        break;
                    case 'teilenummer':
                        // wie im Beispiel: Leerzeichen entfernen
                        $s = str_replace(' ', '', (string)$val);
                        $s = trim($s);
                        $data[$field] = $s === '' ? null : $s;
                        break;
                    case 'info_vz':
                        $s = $this->squashSpaces((string)$val);
                        $data[$field] = $this->nullIfEmpty($s);
                        break;
                    default:
                        $s = trim((string)$val);
                        $data[$field] = $s === '' ? null : $s;
                }
            }
            if (!$data['auftragsnummer'] || !$data['anlagedatum'] || !$data['kundenreferenz']) {
                continue;
            }

            $hashBin = $this->rowHash($data);

            $sql = "INSERT INTO supplier_import_items (
                brand, partnernummer, auftragsnummer, teilenummer, kundenreferenz, anlagedatum,
                auftragsart, bestellte_menge, bestaetigte_menge, offene_menge,
                vsl_lt_sap, vsl_lt_vz, info_vz, aenderungsdatum, teilelocator,
                source_row, row_hash
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?, ?)
            ON DUPLICATE KEY UPDATE
                brand=VALUES(brand), partnernummer=VALUES(partnernummer),
                kundenreferenz=VALUES(kundenreferenz), anlagedatum=VALUES(anlagedatum),
                auftragsart=VALUES(auftragsart), bestellte_menge=VALUES(bestellte_menge),
                bestaetigte_menge=VALUES(bestaetigte_menge), offene_menge=VALUES(offene_menge),
                vsl_lt_sap=VALUES(vsl_lt_sap), vsl_lt_vz=VALUES(vsl_lt_vz), info_vz=VALUES(info_vz),
                aenderungsdatum=VALUES(aenderungsdatum), teilelocator=VALUES(teilelocator),
                source_row=VALUES(source_row), row_hash=VALUES(row_hash)";

            $params = [
                $data['brand'], $data['partnernummer'], $data['auftragsnummer'], $data['teilenummer'], $data['kundenreferenz'], $data['anlagedatum'],
                $data['auftragsart'], $data['bestellte_menge'], $data['bestaetigte_menge'], $data['offene_menge'],
                $data['vsl_lt_sap'], $data['vsl_lt_vz'], $data['info_vz'], $data['aenderungsdatum'], $data['teilelocator'],
                $rowNum, $hashBin // <-- binär (32 Byte)
            ];
            $ok = $this->db->rawQuery($sql, $params);
            if ($ok !== false) $inserted++;
        }

        return ['status' => 'ok', 'rows' => $inserted];
    }
}
