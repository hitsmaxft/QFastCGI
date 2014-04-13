<?php
include "vendor/autoload.php";

ini_set("error_reporting", E_ALL);

$loop = React\EventLoop\Factory::create();
$socket = new React\Socket\Server($loop);

$conns = new \SplObjectStorage();

$i =0;
$socket->on('connection', function ($conn) use ($conns, &$i) {
    $conns->attach($conn);

    $conn->on('data', function ($data) use ($conn, &$conns) {
        $fastcgi_conn = new QFastCGI\Connection();
        $fastcgi_conn->setData($data);
        $fastcgi_conn->onRead();
        $conn->write("finish");
        //$conn->write($fastcgi_conn->getAnswer());
    });

    $conn->on('end', function () use (&$conns, $conn, &$file) {
        $conns->detach($conn);
    });
});

$socket->listen(9900);

$loop->run();
