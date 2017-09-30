<?php

namespace GuzzleRetry\TestServer;

use React\EventLoop\Factory;
use React\Http\Response;
use React\Http\Server;

require_once(__DIR__ . '/../../vendor/autoload.php');

$loop = Factory::create();

$goSlow = true;

// Every other request
$server = new Server(function () use (&$goSlow) {

    // Wait 2 secs and deliver response
    if ($goSlow) {
        sleep(3);
    }

    $goSlow = ! $goSlow; // toggle goSlow
    return new Response(200, ['Content-Type' => 'text/plain'], "Hello World!\n");

});

try {
    $socket = new \React\Socket\Server(8081, $loop);
    $server->listen($socket);

    echo "Server listening at 8081";
    $loop->run();
}
catch (\RuntimeException $e) {
    echo $e->getMessage();
    exit(1);
}