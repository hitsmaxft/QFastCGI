<?php

include __DIR__ . "/connection.php";


$fastcgi_conn = new QFastcgi\Connection();
$data = file_get_contents("fastcgi.text");
$fastcgi_conn->setData($data);
//$data = fopen("fastcgi.text","rb");
//$fastcgi_conn->setSteam($data);
$fastcgi_conn->onRead();
var_dump($fastcgi_conn->getRequests());
