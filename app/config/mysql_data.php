<?php
require_once __DIR__ . '/index.php';

global $db;
$db = new PDODb([
    'type'     => 'mysql',
    'host'     => getenv('MYSQL_HOST') ?: 'localhost',
    'username' => getenv('MYSQL_USER') ?: '',
    'password' => getenv('MYSQL_PASSWORD') ?: '',
    'dbname'   => getenv('MYSQL_DATABASE') ?: '',
    'port'     => (int)(getenv('MYSQL_PORT') ?: 3306),
    'charset'  => 'utf8'
]);
