#!/usr/bin/env bash
set -Eeuo pipefail

# --- erzwinge Socket, verhindere TCP via ENV ---
SOCK="/var/run/mysqld/mysqld.sock"
unset MYSQL_HOST MYSQL_TCP_PORT          # verhindert TCP-Default
export MYSQL_UNIX_PORT="$SOCK"           # bevorzugter Socket-Pfad für mysql-Tools

# Optional fürs Debugging (bei Bedarf aktivieren):
# set -x

# --- Warten bis der lokale mysqld über den Socket antwortet ---
for i in {1..90}; do
  if mysqladmin --socket="$SOCK" -uroot -p"$MYSQL_ROOT_PASSWORD" ping --silent 2>/dev/null; then
    break
  fi
  sleep 1
done

# finaler Check (bricht mit Exit!=0 ab, wenn nicht erreichbar)
mysqladmin --socket="$SOCK" -uroot -p"$MYSQL_ROOT_PASSWORD" ping --silent

# --- sichere Quoting für SQL ---
esc() { printf "%s" "$1" | sed "s/'/''/g"; }
DB="$(esc "${MYSQL_DATABASE}")"
USR="$(esc "${MYSQL_USER}")"
PW="$(esc "${MYSQL_PASSWORD}")"

# --- User/Pass/Grants über den Socket setzen (kein -h irgendwo!) ---
mysql --socket="$SOCK" -uroot -p"$MYSQL_ROOT_PASSWORD" <<EOSQL
CREATE DATABASE IF NOT EXISTS \`$DB\`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER  USER              '$USR'@'%' IDENTIFIED BY '$PW';

GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$USR'@'%';
FLUSH PRIVILEGES;
EOSQL
