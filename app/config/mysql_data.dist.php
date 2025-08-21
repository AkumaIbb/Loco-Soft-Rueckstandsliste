<?php
// Beispiel-Konfiguration für MySQL – bitte nach config/mysql_data.php kopieren und anpassen.
global $db;
$db = new PDODb([
    'type'     => 'mysql',
    'host'     => 'localhost',
    'username' => 'CHANGE_ME',
    'password' => 'CHANGE_ME',
    'dbname'   => 'CHANGE_ME',
    'port'     => 3306,
    'charset'  => 'utf8'
]);
