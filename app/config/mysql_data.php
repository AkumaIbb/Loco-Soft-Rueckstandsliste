<?php

global $db;
$db = new PDODb([
    'type'     => 'mysql',
    'host'     => getenv('MYSQL_HOST') ?: 'mysql',
    'username' => getenv('MYSQL_USER') ?: '',
    'password' => getenv('MYSQL_PASSWORD') ?: '',
    'dbname'   => getenv('MYSQL_DATABASE') ?: 'rueckstand',
    'port'     => (int)(getenv('MYSQL_PORT') ?: 3306),
    'charset'  => 'utf8'
]);