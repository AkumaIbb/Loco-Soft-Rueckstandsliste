<?php
require_once __DIR__ . '/index.php';

$postgresserver = getenv('POSTGRES_HOST');
$postgresport   = getenv('POSTGRES_PORT') ?: '5432';
$postgresdb     = getenv('POSTGRES_DB') ?: '';
$postgresuser   = getenv('POSTGRES_USER') ?: '';
$postgrespw     = getenv('POSTGRES_PASSWORD') ?: '';

$postgresdata = 'host='.$postgresserver.
                ' port='.$postgresport.
                ' dbname='.$postgresdb.
                ' user='.$postgresuser.
                ' password='.$postgrespw;
