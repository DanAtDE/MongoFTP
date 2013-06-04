#!/usr/bin/php
<?php
set_include_path(__DIR__ . '/src' . PATH_SEPARATOR . get_include_path());

require_once 'MongoFTP/Server.php';
require_once 'MongoFTP/Client.php';
require_once 'MongoFTP/Log.php';

$mongo = new MongoClient();

$server = new MongoFTP_Server($mongo->selectDb('ftp'), new MongoFTP_Log($mongo->selectDB('ftp')));

$server->run();
