# Loco-Soft Rückstandsliste

Source Code für das **Loco-Soft Rückstandslistenprojekt**.  
Ziel ist es, einen schnellen Überblick für Serviceberater:innen und Teiledienstmitarbeiter:innen zu geben und Rückstände im Blick zu behalten.

---

## Voraussetzungen

- **Apache Webserver** – stellt das Root-Verzeichnis bereit  
- **PHP mit Erweiterungen**  
  `php-mysql`, `php-pgsql`, `php-mbstring`, `php-xml`, `php-zip`, `php-gd`, `php-curl`, `php-cli`  
- **Composer** – zur Installation der PHP-Abhängigkeiten  
- **MySQL** – primäre Anwendungsdatenbank (PostgreSQL optional)  
- **Cron-Umgebung** – ruft zeitgesteuerte Endpunkte per `curl` auf

### Komplettinstallation via `apt`

```bash
sudo apt update && \
sudo apt install apache2 mysql-server php php-cli php-mbstring php-xml \
                   php-zip php-gd php-curl php-mysql php-pgsql composer
```

### Installation

1) Projekt klonen
```bash
cd /var/www
sudo git clone https://github.com/AkumaIbb/Loco-Soft-Rueckstandsliste.git rueckstand
sudo chown www-data:www-data rueckstand
```

2) Virtuellen Host anlegen
```bash
sudo sed \
    -e 's|#ServerName www\\.example\\.com|ServerName <deine.domain.intern>|' \
    -e 's|DocumentRoot /var/www/html|DocumentRoot /var/www/rueckstand|' \
    /etc/apache2/sites-available/000-default.conf > /etc/apache2/sites-available/rueckstand.conf
```
3) vHost aktivieren
```bash
sudo a2ensite rueckstand.conf
sudo systemctl reload apache2
```

4) SQL Datenbank anlegen
```bash
 sudo mysql -u root -e "
CREATE DATABASE IF NOT EXISTS rueckstand;
CREATE USER IF NOT EXISTS 'rueckstand'@'localhost' IDENTIFIED BY '<DEIN_PASSWORT>';
GRANT ALL PRIVILEGES ON rueckstand.* TO 'rueckstand'@'localhost';
FLUSH PRIVILEGES;"
```
```bash
mysql -u rueckstand -p rueckstand < /var/www/rueckstand/docker-entrypoint-initdb.d/schema.sql
```

5) Installation abschließen
Im Browser http://<deine.domain.intern>/install.php aufrufen und die Datenbank-Verbindungsdaten eintragen.
Dies trägt die Datenbank-Daten in die Konfigurationsdateien ein und erstellt die Cron-Jobs mit der korrekten Domain.

7) Den Cron-Job aktivieren & Berechtigungen setzen
```bash
sudo cp /var/www/rueckstand/cron/rueckstand /etc/cron.d/rueckstand
sudo chown root:root /etc/cron.d/rueckstand
sudo chmod 644 /etc/cron.d/rueckstand
```

8) Installationsdatei löschen oder verschieben
Die Datei ist zwar so eingerichtet, dass sie bestehende Konfigurationen nicht überschreibt, aber sicher ist sicher.
```bash
sudo rm /var/www/rueckstand/install.php
```

Dieses Projekt steht unter der Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0).
