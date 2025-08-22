#!/usr/bin/env bash
# setup_rueckstand.sh
set -Eeuo pipefail
trap 'echo "FEHLER in Zeile $LINENO: $BASH_COMMAND" >&2' ERR

### --- Helper ---
require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "Dieses Script muss als root/sudo laufen." >&2
    exit 1
  fi
}

prompt_default() {
  local p="$1" d="$2" r=""
  read -r -p "$p [$d]: " r || true
  echo "${r:-$d}"
}

prompt_required() {
  local p="$1" r=""
  while [[ -z "$r" ]]; do
    read -r -p "$p: " r || true
    [[ -z "$r" ]] && echo "Eingabe erforderlich."
  done
  echo "$r"
}

prompt_secret_required() {
  local p="$1" r=""
  while [[ -z "$r" ]]; do
    read -r -s -p "$p: " r || true; echo
    [[ -z "$r" ]] && echo "Eingabe erforderlich."
  done
  echo "$r"
}

one_line() {
  # entferne CR/LF (z. B. bei Copy&Paste)
  tr -d '\r\n' <<<"$1"
}

rand_pw() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 24 | tr -d '\n='
  else
    tr -dc 'A-Za-z0-9@#%_+=' </dev/urandom | head -c 32
  fi
}

# Ersetzt KEY=... (falls vorhanden) sonst hängt KEY=VAL an. Robust gegen Sonderzeichen.
replace_or_append() {
  local key="$1" val="$2" tmp
  tmp="$(mktemp)"
  awk -v k="$key" -v v="$val" -F= '
    BEGIN { OFS="="; found=0 }
    $1==k { $0=k"="v; found=1 }
    { print }
    END { if (!found) print k"="v }
  ' "$ENV_OUT" > "$tmp" && mv "$tmp" "$ENV_OUT"
}

detect_user() {
  if [[ -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
    echo "$SUDO_USER"
  else
    if u=$(stat -c %U . 2>/dev/null); then
      echo "$u"
    else
      stat -f %Su . 2>/dev/null || echo root
    fi
  fi
}

detect_group() {
  if [[ -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
    id -gn "$SUDO_USER"
  else
    if g=$(stat -c %G . 2>/dev/null); then
      echo "$g"
    else
      stat -f %Sg . 2>/dev/null || echo root
    fi
  fi
}

### --- Start ---
require_root

# Pfade
ENV_EXAMPLE="docker-compose.env.example"
ENV_OUT=".env"
SCHEMA_SRC="db/init/schema.sql.dist"
SCHEMA_OUT="db/init/schema.sql"
CRON_TEMPLATE="cron/rueckstand.dist"
CRON_TARGET="/etc/cron.d/rueckstand"
SMB_CRED="/root/.rueckstand-smbcred"
MNT_DIR="/mnt/Import-Folder"
FSTAB="/etc/fstab"
FSTAB_BAK="/etc/fstab.rueckstand.bak.$(date +%s)"

# Vorbedingungen
if [[ ! -f "$ENV_EXAMPLE" ]]; then
  echo "Fehlend: $ENV_EXAMPLE (im aktuellen Verzeichnis)." >&2
  exit 1
fi
if [[ ! -f "$SCHEMA_SRC" ]]; then
  echo "Fehlend: $SCHEMA_SRC – lege die .dist-Datei an (und ignoriere db/init/schema.sql im Git)." >&2
  exit 1
fi
if [[ ! -f "$CRON_TEMPLATE" ]]; then
  echo "Fehlend: $CRON_TEMPLATE – Vorlage für /etc/cron.d/rueckstand." >&2
  exit 1
fi

# Beispiel-Datei auf LF normalisieren (falls CRLF aus Windows)
sed -i 's/\r$//' "$ENV_EXAMPLE"

echo "==> MySQL-Konfiguration (DB/User sind fest 'rueckstand')"
MYSQL_HOST="$(one_line "$(prompt_default "MySQL Host" "mysql")")"
MYSQL_PORT="$(one_line "$(prompt_default "MySQL Port" "3306")")"
MYSQL_DATABASE="rueckstand"
MYSQL_USER="rueckstand"

tmp_val="$(prompt_default "MySQL ROOT-Passwort (leer = random)" "")"
MYSQL_ROOT_PASSWORD="$(one_line "$tmp_val")"
[[ -z "$MYSQL_ROOT_PASSWORD" ]] && MYSQL_ROOT_PASSWORD="$(rand_pw)"

tmp_val="$(prompt_default "MySQL Benutzer-Passwort (leer = random)" "")"
MYSQL_PASSWORD="$(one_line "$tmp_val")"
[[ -z "$MYSQL_PASSWORD" ]] && MYSQL_PASSWORD="$(rand_pw)"

echo "==> Postgres (externer Host) – Eingabe erforderlich"
POSTGRES_HOST="$(one_line "$(prompt_required "Postgres Hostname oder IP")")"
POSTGRES_PORT="$(one_line "$(prompt_default "Postgres Port" "5432")")"
POSTGRES_DB="$(one_line "$(prompt_required "Postgres DB-Name")")"
POSTGRES_USER="$(one_line "$(prompt_required "Postgres Benutzer")")"
POSTGRES_PASSWORD="$(one_line "$(prompt_secret_required "Postgres Passwort")")"

echo "==> Basis-URL der Anwendung (Domain/IP, optional mit Port)"
BASE_URL_INPUT="$(one_line "$(prompt_default "Base-URL (z. B. http://example.internal:8080/)" "http://localhost:8080/")")"
# Normalisieren: Schema ergänzen, Slash am Ende anfügen
if [[ ! "$BASE_URL_INPUT" =~ ^https?:// ]]; then
  BASE_URL_INPUT="http://$BASE_URL_INPUT"
fi
[[ "${BASE_URL_INPUT: -1}" != "/" ]] && BASE_URL_INPUT="${BASE_URL_INPUT}/"
BASE_URL="$BASE_URL_INPUT"

# .env aus Example erzeugen/aktualisieren
cp "$ENV_EXAMPLE" "$ENV_OUT"

replace_or_append "MYSQL_HOST" "$MYSQL_HOST"
replace_or_append "MYSQL_PORT" "$MYSQL_PORT"
replace_or_append "MYSQL_ROOT_PASSWORD" "$MYSQL_ROOT_PASSWORD"
replace_or_append "MYSQL_DATABASE" "$MYSQL_DATABASE"
replace_or_append "MYSQL_USER" "$MYSQL_USER"
replace_or_append "MYSQL_PASSWORD" "$MYSQL_PASSWORD"

replace_or_append "POSTGRES_HOST" "$POSTGRES_HOST"
replace_or_append "POSTGRES_PORT" "$POSTGRES_PORT"
replace_or_append "POSTGRES_DB" "$POSTGRES_DB"
replace_or_append "POSTGRES_USER" "$POSTGRES_USER"
replace_or_append "POSTGRES_PASSWORD" "$POSTGRES_PASSWORD"

echo "==> ${ENV_OUT} erstellt/aktualisiert."

# schema.sql aus .dist erzeugen und Passwort ersetzen
echo "==> Erzeuge ${SCHEMA_OUT} aus ${SCHEMA_SRC} und setze Passwort ein…"
cp "$SCHEMA_SRC" "$SCHEMA_OUT"
# Normalisieren (CRLF -> LF)
sed -i 's/\r$//' "$SCHEMA_OUT"
# Passwort für sed-Replacement sicher escapen (/ & \)
PW_ESCAPED="$(printf '%s' "$MYSQL_PASSWORD" | sed -e 's/[\/&\\]/\\&/g')"
sed -i "s/REPLACE_WITH_\${MYSQL_PASSWORD}/$PW_ESCAPED/g" "$SCHEMA_OUT"

# Ownership & Rechte für ENV und Schema setzen (gehören dem späteren Compose-User)
TARGET_USER="$(detect_user)"
TARGET_GROUP="$(detect_group)"
chown "$TARGET_USER:$TARGET_GROUP" "$ENV_OUT" "$SCHEMA_OUT"
chmod 600 "$ENV_OUT"
chmod 644 "$SCHEMA_OUT"

echo "==> ${ENV_OUT} gehört jetzt ${TARGET_USER}:${TARGET_GROUP} (0600)."
echo "==> ${SCHEMA_OUT} gehört jetzt ${TARGET_USER}:${TARGET_GROUP} (0644)."

# Samba-Zugangsdaten (NICHT in .env)
echo "==> Samba-Zugangsdaten (werden in ${SMB_CRED} gespeichert)"
SMB_DOMAIN="$(one_line "$(prompt_default "Samba Domäne (leer falls keine)" "")")"
SMB_USER="$(one_line "$(prompt_required "Samba Benutzername")")"
SMB_HOST="$(one_line "$(prompt_required "Samba Server (Hostname oder IP)")")"
SMB_SHARE_PATH="$(one_line "$(prompt_required "Samba Share-Pfad (z.B. /Abteilungsordner/Ersatzteillager/Bestellungen)")")"
SMB_PASS="$(one_line "$(prompt_secret_required "Samba Passwort")")"

# Credentials-Datei
{
  echo "username=${SMB_USER}"
  echo "password=${SMB_PASS}"
  [[ -n "$SMB_DOMAIN" ]] && echo "domain=${SMB_DOMAIN}"
} > "$SMB_CRED"
chown root:root "$SMB_CRED"
chmod 600 "$SMB_CRED"
echo "==> SMB-Credentials in ${SMB_CRED} mit 0600 gespeichert."

# Mountpoint anlegen und Rechte setzen
echo "==> Erzeuge Mountpoint ${MNT_DIR} (www-data:www-data, 0770)"
mkdir -p "$MNT_DIR"
chown www-data:www-data "$MNT_DIR"
chmod 0770 "$MNT_DIR"

# /etc/fstab Eintrag vorbereiten
UNC="//${SMB_HOST}/${SMB_SHARE_PATH}"
FSTAB_OPTS="credentials=${SMB_CRED},iocharset=utf8,uid=www-data,gid=www-data,file_mode=0660,dir_mode=0770,nounix,noserverino,_netdev,nofail"
FSTAB_LINE="${UNC}  ${MNT_DIR}  cifs  ${FSTAB_OPTS}  0  0"

# Backup & hinzufügen, falls noch nicht vorhanden
if ! grep -qsF "$FSTAB_LINE" "$FSTAB"; then
  cp "$FSTAB" "$FSTAB_BAK"
  echo "$FSTAB_LINE" >> "$FSTAB"
  echo "==> /etc/fstab aktualisiert (Backup: $FSTAB_BAK)"
else
  echo "==> Passender fstab-Eintrag existiert bereits – kein Update nötig."
fi

# cifs-utils sicherstellen
if ! command -v mount.cifs >/dev/null 2>&1; then
  echo "cifs-utils fehlt – wird installiert (apt) ..."
  if command -v apt >/dev/null 2>&1; then
    apt update && apt install -y cifs-utils
  else
    echo "WARN: Paketmanager apt nicht gefunden. Bitte cifs-utils manuell installieren."
  fi
fi

# Sofort mounten
echo "==> Versuche zu mounten..."
if mountpoint -q "$MNT_DIR"; then
  echo "Schon gemountet."
else
  if ! mount "$MNT_DIR"; then
    echo "WARN: Automount via systemd/fstab fehlgeschlagen, versuche direkten CIFS-Mount..."
    mount -t cifs "$UNC" "$MNT_DIR" -o "${FSTAB_OPTS}" || {
      echo "FEHLER: CIFS-Mount fehlgeschlagen. Prüfe Host/Share/Anmeldedaten & Firewall." >&2
      exit 1
    }
  fi
fi
echo "==> Mount aktiv: $MNT_DIR"

# Cron-Datei aus Template erzeugen und Base-URL ersetzen
echo "==> Installiere Cron unter ${CRON_TARGET} (Base-URL: ${BASE_URL})"
TMP_CRON="$(mktemp)"
# CRLF -> LF und Platzhalter ersetzen
sed 's/\r$//' "$CRON_TEMPLATE" > "$TMP_CRON"
BASE_ESCAPED="$(printf '%s' "$BASE_URL" | sed -e 's/[\/&\\]/\\&/g')"
# explizit nur die Beispiel-URL ersetzen (mit trailing slash)
sed -i "s#http://example\.internal/#$BASE_ESCAPED#g" "$TMP_CRON"
# sicherstellen, dass Datei mit NL endet (cron mag das)
tail -c1 "$TMP_CRON" | od -An -t x1 | grep -q '0a' || echo >> "$TMP_CRON"
# installieren
install -o root -g root -m 644 "$TMP_CRON" "$CRON_TARGET"
rm -f "$TMP_CRON"

# cron/neustarten oder reload (je nach Distribution)
if command -v systemctl >/dev/null 2>&1; then
  if systemctl is-active --quiet cron 2>/dev/null; then
    systemctl reload cron || systemctl restart cron || true
  elif systemctl is-active --quiet crond 2>/dev/null; then
    systemctl reload crond || systemctl restart crond || true
  fi
fi
echo "==> Cron installiert/aktualisiert: ${CRON_TARGET}"

# Optional: Watchdog-Service
read -r -p "Watchdog-Service installieren, der den Share überwacht und bei Bedarf neu mountet? [y/N]: " yn
if [[ "${yn:-N}" =~ ^[Yy]$ ]]; then
  WATCH_SCRIPT="/usr/local/bin/rueckstand-smb-watch.sh"
  SERVICE="/etc/systemd/system/rueckstand-smb-watch.service"
  TIMER="/etc/systemd/system/rueckstand-smb-watch.timer"

  cat > "$WATCH_SCRIPT" <<'EOS'
#!/usr/bin/env bash
set -euo pipefail
MNT_DIR="/mnt/Import-Folder"
LOG_TAG="rueckstand-smb-watch"

if ! mountpoint -q "$MNT_DIR"; then
  logger -t "$LOG_TAG" "Share nicht gemountet – versuche mount"
  /usr/bin/mount "$MNT_DIR" || /usr/bin/mount -a || logger -t "$LOG_TAG" "Mount fehlgeschlagen"
fi
EOS
  chmod +x "$WATCH_SCRIPT"

  cat > "$SERVICE" <<EOS
[Unit]
Description=Rueckstand SMB Share Watchdog (remount if unmounted)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=${WATCH_SCRIPT}
User=root
Group=root

[Install]
WantedBy=multi-user.target
EOS

  cat > "$TIMER" <<'EOS'
[Unit]
Description=Run Rueckstand SMB Watchdog every 2 minutes

[Timer]
OnBootSec=1min
OnUnitActiveSec=2min
AccuracySec=30s
Persistent=true
Unit=rueckstand-smb-watch.service

[Install]
WantedBy=timers.target
EOS

  systemctl daemon-reload
  systemctl enable --now rueckstand-smb-watch.timer
  echo "==> Watchdog installiert & aktiviert (alle 2 Minuten). Logs: journalctl -u rueckstand-smb-watch.service -n 50"
else
  echo "==> Watchdog-Service übersprungen."
fi

echo
echo "Fertig. Zusammenfassung:"
echo "  - ${ENV_OUT} erstellt (Owner: ${TARGET_USER}:${TARGET_GROUP}, 0600)"
echo "  - MySQL DB/User: rueckstand / rueckstand"
echo "  - Schema-Datei: ${SCHEMA_OUT} aus ${SCHEMA_SRC} (Passwort ersetzt, 0644)"
echo "  - SMB-Credentials: ${SMB_CRED} (0600)"
echo "  - Mountpoint: ${MNT_DIR}"
echo "  - Cron: ${CRON_TARGET} (Base-URL: ${BASE_URL})"
echo "  - fstab: ${UNC} -> ${MNT_DIR}"
