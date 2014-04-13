<?php
require dirname(__DIR__). "/vendor/autoload.php";

//$fastcgi_conn = new QFastcgi\Connection();
//$data = file_get_contents("fastcgi.text");
//$fastcgi_conn->setData($data);
//$data = fopen("fastcgi.text","rb");
//$fastcgi_conn->setSteam($data);
$fastcgi_conn = new \QFastCGI\StringParser();
$data = file_get_contents(__DIR__ ."/fastcgi.text");
$fastcgi_conn->parse($data);
var_dump($fastcgi_conn);
