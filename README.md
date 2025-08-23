# Loco-Soft Rückstandsliste

Source Code für das **Loco-Soft Rückstandslistenprojekt**.  
Ziel ist es, einen schnellen Überblick für Serviceberater:innen und Teiledienstmitarbeiter:innen zu geben und Rückstände im Blick zu behalten.

---
# Grundbedingungen
Es wird benötigt: 
- Eine Virtuelle Maschine (Linux, ich empfehle Ubuntu) oder ein vergleichbares Gerät (z.B. Raspberry Pi)
- Ein Netzwerkshare (Also eine Netzwerkfreigabe), auf dem die Bestelldatei abgelegt wird.
	Ich verwende hier das Beispiel: **\\loco.autohaus.local\Abteilungsordner\Ersatzteillager\Bestellungen**  als Beispiel für den Netzwerk-Share.
- Ein wenig Linux-Affinität.

## Installation und Einrichtung von Docker
siehe https://docs.docker.com/engine/install/ubuntu/ für die aktuellsten Informationen

```shell
# Add Docker's official GPG key:
sudo apt-get update
sudo apt-get install ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

# Add the repository to Apt sources:
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update

# Install latest Docker version:
sudo apt-get install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
 
```

Den aktuellen Benutzer als Docker-Benutzer festlegen.
Danach muss der Benutzer sich einmal neu einloggen.
```shell
sudo usermod -aG docker $username
exit
```

## Installation von Git Download des Containers
```shell
sudo apt update
sudo apt install git
cd /opt/
sudo git clone https://github.com/AkumaIbb/Loco-Soft-Rueckstandsliste
cd Loco-Soft-Rueckstandsliste
```

## Berechtigungen anpassen und Installationsscript ausführbar machen
```shell
sudo chown -R $username:$username /opt/Loco-Soft-Rueckstandsliste
```

## Installationsscript ausführen
Das Installationsscript macht den Rest:
- Die Zugangsdaten für die interne Datenbank des Tools können festgelegt werden (Alternativ einfach bestätigen)
- Die Zugangsdaten für die Loco-Soft Postgres-Datenbank müssen festgelegt werden.
- Zugangsdaten für eine Netzwerkfreigabe müssen eingegeben werden

(!!!) Fallstrick: Bitte wie folgt angeben:
Samba Domäne: Die Windows-Domäne (z.b. autohaus.local)
Samba Benutzername: Benutzername für die Windows-Domäne (optimalerweise ein Dienstkonto, dass das Passwort nicht ändert)
Samba Server: Name des Servers, auf dem später die Bestelldatei bereitgestellt wird. (z.B. loco)
Samba Share Pfad: Pfad zum Freigabeverzeichnis OHNE führenden / (z.B. Abteilungsordner/Ersatzteillager/Bestellungen)
				  Hier muss die Linux-Schreibweise mit / statt \ verwendet werden!
Samba Passwort: Das Passwort des Windows-Benutzers
```shell
sudo ./setup_rueckstand.sh
```

## Sollte der Mount fehlschlagen bitte die folgenden Dateien kontrollieren:
```shell
sudo nano /etc/fstab # => Korrekter Pfad angegeben?
sudo nano /root//root/.rueckstand-smbcred # => Korrekte Benutzerdaten angegeben?
```

Danach manuell ausführen:
```shell
sudo systemctl daemon-reload
sudo mount -a
```

## Docker Container Starten:
```shell
docker compose build   #Initialisieren (nur beim ersten Start benötigt)
docker compose up -d   #Starten
```

Nun kann das Projekt auf der gewählten Domain mit Port 8080 aufgerufen werden.
z.B. http://rueckstand.autohaus.local:8080

## Port anpassen / SSL / Reverse Proxy

Der einfachste Weg den Port anzupassen ist über die docker-compose.yml:
```shell
nano docker-compose.yml
```

Dort 
```shell
services:
  web:
    build: .
    container_name: app-web
    ports:
      - "8080:80"
``` 
ändern in:
```shell
    ports:
      - "8080:80"
``` 

# Bedienung
Die Bestelldatei wird im Loco-Soft in der 572 erstellt.
Hier gibt es die Option "Bestellübersicht drucken (F17)".
Im "Eingrenzung"-Teil wie in Loco-Soft gewohnt alles von 0 - 999999.. bzw " " bis "ÜÜÜÜÜÜ..." füllen (Standardeinstellung)
"Gedruckt werden sollen..." -> Alles außer "bereits erledigte Bestellungen".

Nun muss die Datei als "LocoBestellung.xlsx" im Netzwerk-Share gespeichert werden.
Die Datei wird dann automatisch nach Feierabend eingelesen.

#Einstellungen
**Die Einstellungen sollten vor dem ersten Import gesetzt werden.**

Über das Zahnrad können die Einstellungen aufgerufen werden.
Hier können hinterlegt werden:
1) Lieferkonditionen
Wie viele Werktage nach Bestellung muss Ware eintreffen um nicht als Rückständig markiert zu werden (Standard: 1)
Als "Nummer" muss hier die Nummer eingetragen werden, die in der 572 als Lieferkondition angezeigt wird.

2) Ignores
Hier können Marken eingetragen werden, die beim Import ignoriert werden sollen. 
Der Name muss dem 4-Stelligen Format aus der Kopfzeile in der 572 entsprechen, z.B. "HYUN"

3) SMTP Server
Vorbereitung, noch nicht implementiert

**Neuen Import anlegen:**
Über den Link kommt ihr auf die Einstellungen für einen CSV/Excel-Importer.
Hier kann eine Datei Probe-Hochgeladen werden, der Importer ließt dann die Spaltenbezeichnungen aus und ermöglicht diese mit der Datenbank zu matchen.
Um eine Bestellung zu verknüpfen sind mindestens erforderlich:
Teilenummer 
Kundenreferenz
Anlagedatum
Die Kundenreferenz wird hierbei bereinigt und macht z.B. aus den OTLG Referenzen ("A206058/1;K27502;F31") eine Auftragsnummer.

Über einen Dateinamenfilter können die Dateien automatisch Marken zugewiesen werden.
Ich empfehle etwas wie "LIEFERANT Rückstandsliste datum.csv" mit dem entsprechenden Filter "LIEFERANT Rückstandsliste *.csv"

Die Datei muss anschließend ins gleiche Verzeichnis gelegt werden wie die LocoBestellung.xlsx
Die Datei wird halbstündlich eingelesen und anschließend gelöscht.

	-

## ToDo
- E-Mail-Benachrichtigung bei Rückstand


---
Dieses Projekt steht unter der Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0).
Es ist ein frei entwickeltes Hobbyprojekt und der Entwickler hat keinerlei geschäftliche Beziehungen zur Loco-Soft Vertriebs GmbH.
Es handelt sich also nicht um ein offizielles Release des Herstellers.