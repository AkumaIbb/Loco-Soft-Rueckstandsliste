<?php
declare(strict_types=1);

/**
 * Installer – legt Konfigurationsdateien und Cron-Datei im Projekt an.
 * - /config/postgres_data.php
 * - /config/mysql_data.php
 * - /cron/rueckstand
 * Überschreibt existierende Dateien nur, wenn ausdrücklich erlaubt (mit Backup).
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$projectRoot = __DIR__;
$configDir   = $projectRoot . '/config';
$cronDir     = $projectRoot . '/cron';
$cronFile    = $cronDir . '/rueckstand';

require_once $projectRoot . '/src/Csrf.php';
csrf_token();

// Auto-Detect eines SQL-Files im Projekt-Root (für Ausgabe der Import-Zeile)
$detectedSqlFiles = glob($projectRoot . '/*.sql');
$defaultSqlFile   = $detectedSqlFiles ? basename($detectedSqlFiles[0]) : 'schema.sql';

// Helpers
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Konnte Verzeichnis nicht anlegen: $dir");
        }
    }
}
/**
 * Schreibt $content nach $path.
 * - Überschreibt nur, wenn $allowOverwrite = true
 * - Legt vorher Backup an: <file>.bak.YYYYMMDD_HHMMSS
 * - Fügt Statusmeldungen in $notes hinzu
 */
function write_file_safe(string $path, string $content, bool $allowOverwrite, array &$notes): void {
    $exists = file_exists($path);
    if ($exists && !$allowOverwrite) {
        $notes[] = "Übersprungen (existiert bereits): $path";
        return;
    }
    if ($exists && $allowOverwrite) {
        $bak = $path . '.bak.' . date('Ymd_His');
        if (!@copy($path, $bak)) {
            throw new RuntimeException("Backup fehlgeschlagen: $bak");
        }
        $notes[] = "Backup erstellt: $bak";
    }
    if (@file_put_contents($path, $content) === false) {
        throw new RuntimeException("Konnte Datei nicht schreiben: $path");
    }
    $notes[] = ($exists ? "Überschrieben: " : "Erstellt: ") . $path;
}

$errors = [];
$notes  = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Bad Request');
    }
    csrf_token(true);
    // --- Eingaben ---
    $domain = trim((string)($_POST['domain'] ?? ''));
    $domain = preg_replace('~\s+~', '', $domain);

    // Postgres
    $pg_host = trim((string)($_POST['pg_host'] ?? ''));
    $pg_port = trim((string)($_POST['pg_port'] ?? '5432'));
    $pg_db   = trim((string)($_POST['pg_db']   ?? 'loco_auswertung_db'));
    $pg_user = trim((string)($_POST['pg_user'] ?? 'loco_auswertung_benutzer'));
    $pg_pass = (string)($_POST['pg_pass'] ?? '');

    // MySQL
    $my_user = trim((string)($_POST['my_user'] ?? ''));
    $my_pass = (string)($_POST['my_pass'] ?? '');
    $my_db   = trim((string)($_POST['my_db']   ?? ''));
    $my_port = trim((string)($_POST['my_port'] ?? '3306'));

    $allowOverwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

    // --- Validierung ---
    if ($domain === '') $errors[] = 'Bitte die Domain angeben (z. B. rueckstand.baeumer.local).';
    if ($pg_host === '') $errors[] = 'Postgres Host/IP darf nicht leer sein.';
    if ($pg_pass === '') $errors[] = 'Postgres Passwort darf nicht leer sein.';
    if ($my_user === '') $errors[] = 'MySQL Benutzername darf nicht leer sein.';
    if ($my_pass === '') $errors[] = 'MySQL Passwort darf nicht leer sein.';
    if ($my_db   === '') $errors[] = 'MySQL Datenbankname darf nicht leer sein.';

    if (!$errors) {
        try {
            // Verzeichnisse erzeugen
            ensureDir($configDir);
            ensureDir($cronDir);

            // ---- Postgres-Konfig (mit Port im DSN) ----
            $postgresPhp =
"<?php
\$postgresserver = " . var_export($pg_host, true) . ";
\$postgresport   = " . var_export($pg_port, true) . "; // Default: \"5432\"
\$postgresdb     = " . var_export($pg_db, true) . ";   // Default: \"loco_auswertung_db\"
\$postgresuser   = " . var_export($pg_user, true) . "; // Default: \"loco_auswertung_benutzer\"
\$postgrespw     = " . var_export($pg_pass, true) . ";

\$postgresdata = \"host=\".\$postgresserver.
                 \" port=\".\$postgresport.
                 \" dbname=\".\$postgresdb.
                 \" user=\".\$postgresuser.
                 \" password=\".\$postgrespw;
?>";

            $pgPath = $configDir . '/postgres_data.php';
            write_file_safe($pgPath, $postgresPhp, $allowOverwrite, $notes);
            @chmod($pgPath, 0640);

            // ---- MySQL-Konfig ----
            $mysqlPhp =
"<?php
global \$db;
\$db = new PDODb(['type' => 'mysql',
                 'host' => 'localhost',
                 'username' => " . var_export($my_user, true) . ",
                 'password' => " . var_export($my_pass, true) . ",
                 'dbname'=> " . var_export($my_db, true) . ",
                 'port' => " . var_export($my_port, true) . ", // Default:3306
                 'charset' => 'utf8']);
?>";

            $myPath = $configDir . '/mysql_data.php';
            write_file_safe($myPath, $mysqlPhp, $allowOverwrite, $notes);
            @chmod($myPath, 0640);

            // ---- Cron-Datei ----
            $baseUrl = "http://" . $domain;
            $cronContent =
"# /etc/cron.d/rueckstand (symlinked from projectroot/cron/rueckstand)
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=\"\"
CURLUA=\"curl -fsS --max-time 600 -H 'User-Agent: rueckstand-cron/1.0'\"


# 1) Excel-Import (täglich 18:10)
10 18 * * * root flock -n /var/lock/rueckstand_import.lock -c \"\$CURLUA '{$baseUrl}/public/ImportExcel.php' >/dev/null\"

# 2) Enrichment: Serviceberater (18:20)
20 18 * * * root flock -n /var/lock/rueckstand_enr_sb.lock -c \"\$CURLUA '{$baseUrl}/public/enrich_service_advisors.php' >/dev/null\"

# 3) Enrichment: Fälligkeiten (18:30)
30 18 * * * root flock -n /var/lock/rueckstand_enr_due.lock -c \"\$CURLUA '{$baseUrl}/public/enrich_due_dates.php' >/dev/null\"

# 4) Wareneingang-Reconcile (06–18 Uhr alle 2h)
0 6-18/2 * * * root flock -n /var/lock/rueckstand_reconcile.lock -c \"\$CURLUA '{$baseUrl}/public/reconcile_inbound.php?days=5' >/dev/null\"
";
            write_file_safe($cronFile, $cronContent, $allowOverwrite, $notes);
            @chmod($cronFile, 0644); // sinnvoll auch im Repo

            // ---- Befehle anzeigen ----
            $sqlFileShown = $defaultSqlFile;
            $mysqlCmds =
"# 1) MySQL Datenbank + Benutzer anlegen
sudo mysql -u root <<'SQL'
CREATE DATABASE `{$my_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '{$my_user}'@'localhost' IDENTIFIED BY '{$my_pass}';
GRANT ALL PRIVILEGES ON `{$my_db}`.* TO '{$my_user}'@'localhost';
FLUSH PRIVILEGES;
SQL

# 2) Cron-Datei verlinken und Cron neu laden
sudo ln -sf \"{$projectRoot}/cron/rueckstand\" /etc/cron.d/rueckstand
sudo chown root:root /etc/cron.d/rueckstand
sudo chmod 0644 /etc/cron.d/rueckstand
sudo systemctl reload cron || sudo service cron reload

# 3) (Optional) DB-Schema importieren
# Passe ggf. den Dateinamen an, falls dein Dump anders heißt:
mysql -u {$my_user} -p'{$my_pass}' -h 127.0.0.1 -P {$my_port} {$my_db} < \"{$projectRoot}/{$sqlFileShown}\"
";

            $done = true;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Installer – Rueckstand</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Noto Sans",sans-serif;line-height:1.4;margin:2rem;}
    form{max-width:900px}
    fieldset{border:1px solid #ddd;border-radius:8px;margin:1rem 0;padding:1rem}
    legend{font-weight:600}
    label{display:block;margin:.5rem 0 .2rem}
    input[type=text],input[type=password]{width:100%;padding:.55rem;border:1px solid #ccc;border-radius:6px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
    .err{background:#ffecec;border:1px solid #f5a7a7;color:#900;padding:.75rem;border-radius:6px;margin-bottom:1rem}
    .ok{background:#eefbf1;border:1px solid #bfe7c7;color:#0a5c2f;padding:.75rem;border-radius:6px;margin-bottom:1rem}
    pre{background:#111;color:#eee;padding:1rem;border-radius:8px;overflow:auto}
    code{white-space:pre-wrap}
    .muted{color:#666}
    .btn{display:inline-block;background:#0a66c2;color:#fff;padding:.6rem 1rem;border-radius:6px;text-decoration:none;border:none;cursor:pointer}
  </style>
</head>
<body>
  <h1>Installer</h1>

  <?php if ($errors): ?>
    <div class="err">
      <strong>Bitte prüfen:</strong>
      <ul>
        <?php foreach ($errors as $er): ?>
          <li><?=h($er)?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!$done): ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <fieldset>
        <legend>Cron / Domain</legend>
        <label for="domain">Domain (ohne Protokoll)</label>
        <input type="text" id="domain" name="domain" placeholder="rueckstand.baeumer.local" value="<?=h($_POST['domain'] ?? '')?>" required>
        <p class="muted">Wird für die Cron-URLs als <code>http://&lt;domain&gt;/public/…</code> verwendet.</p>
      </fieldset>

      <fieldset>
        <legend>Postgres (für Teilebeschreibung)</legend>
        <div class="row">
          <div>
            <label for="pg_host">Host / IP</label>
            <input type="text" id="pg_host" name="pg_host" placeholder="127.0.0.1" value="<?=h($_POST['pg_host'] ?? '')?>" required>
          </div>
          <div>
            <label for="pg_port">Port</label>
            <input type="text" id="pg_port" name="pg_port" value="<?=h($_POST['pg_port'] ?? '5432')?>">
          </div>
        </div>
        <div class="row">
          <div>
            <label for="pg_db">Datenbank</label>
            <input type="text" id="pg_db" name="pg_db" value="<?=h($_POST['pg_db'] ?? 'loco_auswertung_db')?>">
          </div>
          <div>
            <label for="pg_user">Benutzer</label>
            <input type="text" id="pg_user" name="pg_user" value="<?=h($_POST['pg_user'] ?? 'loco_auswertung_benutzer')?>">
          </div>
        </div>
        <label for="pg_pass">Passwort</label>
        <input type="password" id="pg_pass" name="pg_pass" value="<?=h($_POST['pg_pass'] ?? '')?>" required>
        <p class="muted">Die Datei wird exakt im gewünschten Format erzeugt (mit <code>port=</code> im DSN).</p>
      </fieldset>

      <fieldset>
        <legend>MySQL (Anwendung)</legend>
        <div class="row">
          <div>
            <label for="my_user">Benutzer</label>
            <input type="text" id="my_user" name="my_user" value="<?=h($_POST['my_user'] ?? '')?>" required>
          </div>
          <div>
            <label for="my_db">Datenbank</label>
            <input type="text" id="my_db" name="my_db" value="<?=h($_POST['my_db'] ?? '')?>" required>
          </div>
        </div>
        <div class="row">
          <div>
            <label for="my_pass">Passwort</label>
            <input type="password" id="my_pass" name="my_pass" value="<?=h($_POST['my_pass'] ?? '')?>" required>
          </div>
          <div>
            <label for="my_port">Port</label>
            <input type="text" id="my_port" name="my_port" value="<?=h($_POST['my_port'] ?? '3306')?>">
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>Schreibschutz</legend>
        <label>
          <input type="checkbox" name="overwrite" value="1" <?= !empty($_POST['overwrite']) ? 'checked' : '' ?>>
          Bestehende Dateien überschreiben (vorher Backup anlegen)
        </label>
        <p class="muted">Ohne Haken werden vorhandene Dateien <strong>nicht</strong> überschrieben.</p>
      </fieldset>

      <button class="btn" type="submit">Konfiguration erstellen</button>
    </form>
  <?php else: ?>
    <div class="ok">
      <strong>Erfolg!</strong> Die Dateien wurden erstellt/aktualisiert.
    </div>

    <?php if (!empty($notes)): ?>
      <h3>Aktionen</h3>
      <ul>
        <?php foreach ($notes as $n): ?>
          <li><?= h($n) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>Nächste Schritte (auf dem Server ausführen)</h2>
    <pre><code><?=
      h("# Dateien verlinken & Cron neu laden, DB anlegen & Schema importieren:\n\n" .
        "# (siehe unten vollständige Befehle)\n\n")
    ?></code></pre>

    <h3>Komplette Befehle</h3>
    <pre><code><?=h(
"# 1) MySQL Datenbank + Benutzer anlegen
sudo mysql -u root <<'SQL'
CREATE DATABASE `{$my_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '{$my_user}'@'localhost' IDENTIFIED BY '{$my_pass}';
GRANT ALL PRIVILEGES ON `{$my_db}`.* TO '{$my_user}'@'localhost';
FLUSH PRIVILEGES;
SQL

# 2) Cron-Datei verlinken und Cron neu laden
sudo ln -sf \"{$projectRoot}/cron/rueckstand\" /etc/cron.d/rueckstand
sudo chown root:root /etc/cron.d/rueckstand
sudo chmod 0644 /etc/cron.d/rueckstand
sudo systemctl reload cron || sudo service cron reload

# 3) (Optional) DB-Schema importieren
mysql -u {$my_user} -p'{$my_pass}' -h 127.0.0.1 -P {$my_port} {$my_db} < \"{$projectRoot}/{$defaultSqlFile}\"
")?></code></pre>

    <p class="muted">
      Hinweis: Achte darauf, dass <code><?=h($cronFile)?></code> <strong>root:root</strong> gehört und <strong>0644</strong> ist (die Datei selbst, nicht der Link).
      Die <code>config/*.php</code>-Dateien sind auf <strong>0640</strong> gesetzt.
    </p>
  <?php endif; ?>
</body>
</html>
