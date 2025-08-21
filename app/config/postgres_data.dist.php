<?php
// Beispiel-Konfiguration für Postgres – bitte nach config/postgres_data.php kopieren und anpassen.
$postgresserver = "127.0.0.1";
$postgresport   = "5434";                    // ggf. 5432
$postgresdb     = "loco_auswertung_db";
$postgresuser   = "loco_auswertung_benutzer";
$postgrespw     = "CHANGE_ME";

$postgresdata = "host=".$postgresserver.
                " port=".$postgresport.
                " dbname=".$postgresdb.
                " user=".$postgresuser.
                " password=".$postgrespw;
