# Loco-Soft Rückstandsliste

Source Code für das **Loco-Soft Rückstandslistenprojekt**.  
Ziel ist es, einen schnellen Überblick für Serviceberater:innen und Teiledienstmitarbeiter:innen zu geben und Rückstände im Blick zu behalten.

---
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

```shell
sudo ./setup_rueckstand.sh
```




---
Dieses Projekt steht unter der Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0).
