#!/usr/bin/env php
<?php

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\Websocket\Server;
use Swoole\Table;

// default port
$port = 0;
$cookie = '';

for ($i = 0; $i < count($argv); $i++) {
    if ($argv[$i] == '-p' && !empty($argv[$i + 1])) {
        $port = intval($argv[$i + 1]);
        continue;
    }
    if (substr($argv[$i], 0, 7) == '--port=') {
        $kv = explode('=', $argv[$i]);
        $port = intval($kv[1]);
        continue;
    }
    if ($argv[$i] == '-c' && !empty($argv[$i + 1])) {
        $cookie = $argv[$i + 1];
        continue;
    }
    if (substr($argv[$i], 0, 9) == '--cookie=') {
        $kv = explode('=', $argv[$i]);
        $cookie = $kv[1];
        continue;
    }
    if ($argv[$i] == '-h' || substr($argv[$i], 0, 6) == '--help') {
        help();
    }
}

function help()
{
    echo 'Collaborative markdown editor' . PHP_EOL;
    echo 'usage:' . PHP_EOL;
    echo '    php ' . basename(__FILE__) . ' -p 8080 -c we-write' . PHP_EOL;
    echo 'inspect data:' . PHP_EOL;
    echo '    users: curl --cookie "inspect-cookie=we-write;inspect-data=users" http://localhost:8080/' . PHP_EOL;
    echo '    texts: curl --cookie "inspect-cookie=we-write;inspect-data=texts" http://localhost:8080/' . PHP_EOL;
    echo 'flags:' . PHP_EOL;
    echo '    -p | --port      Server listing port' . PHP_EOL;
    echo '    -c | --cookie    Server inspect cookie' . PHP_EOL;
    echo '    -h | --help      Print help' . PHP_EOL;
    exit(0);
}

// save fd => id
$userTable = new Table(256);
$userTable->column('fd', Table::TYPE_INT, 64);
$userTable->column('id', Table::TYPE_STRING, 64);
$userTable->create();

// save id => text
$textTable = new Table(1);
$textTable->column('text', Table::TYPE_STRING, 1048576);
$textTable->create();

if ($port == 0) {
    echo 'Server port not set' . PHP_EOL . PHP_EOL;
    help();
    exit(0);
}
// web socket server
$server = new Server('0.0.0.0', $port);

$server->on('start', function () use ($port, $cookie) {
    if (empty($cookie)) {
        echo "WebSocket Server is started at ws://0.0.0.0:$port" . PHP_EOL;
    } else {
        echo "WebSocket Server is started at ws://0.0.0.0:$port with cookie $cookie" . PHP_EOL;
    }
});

$server->on('request', function (Request $request, Response $response) use ($cookie, $userTable, $textTable) {
    $userTable->set($request->fd, ['fd' => $request->fd, 'id' => '']);
    $uri = $request->server['request_uri'];
    if ($uri == '/') {
        $inspectCookie = $request->cookie['inspect-cookie'] ?? null;
        // inspect data
        if ($cookie === $inspectCookie) {
            $data = [];
            $inspectData = $request->cookie['inspect-data'] ?? '';
            // get users
            if ($inspectData === 'users') {
                $users = [];
                foreach ($userTable as $row) {
                    $users[] = $row;
                }
                $data['users'] = $users;
            }
            // get texts
            if ($inspectData === 'texts') {
                $texts = [];
                foreach ($textTable as $row) {
                    $texts[] = $row;
                }
                $data['texts'] = $texts;
            }
            $response->write(json_encode($data));
        } else if (empty($request->get['id'])) {
            // take from cookie or generate new
            $id = $request->cookie['id'] ?? randomPassword(16);
            $response->redirect('index.html?id=' . $id);
        } else {
            $response->sendfile('index.html');
        }
    } else {
        $path = './' . $uri;
        if (file_exists($path)) {
            $response->sendfile($path);
        } else {
            $response->status(404);
        }
    }
});

$server->on('message', function (Server $server, Frame $frame) use ($userTable, $textTable) {
    $json = json_decode($frame->data, true) ?? [];
    switch ($json['type']) {
        case 'get':
            $id = $json['id'];
            if (empty($id))
                break;
            // text
            $text = $textTable->get($id, 'text');
            if (is_bool($text)) {
                // new
                $server->push($frame->fd, json_encode(['type' => 0, 'text' => $text]));
            } else {
                // old
                $server->push($frame->fd, json_encode(['type' => 1, 'text' => $text]));
            }
            // save
            $userTable->set($frame->fd, ['fd' => $frame->fd, 'id' => $id]);
            break;
        case 'push':
            $id = $json['id'];
            if (empty($id))
                break;
            $text = $json['text'];
            if (empty($text))
                break;
            // push
            foreach ($server->connections as $fd) {
                // skip self
                if ($fd == $frame->fd)
                    continue;
                // skip http or other diff id client
                if ($id != $userTable->get($fd, 'id'))
                    continue;
                // same shares client
                $server->push($fd, json_encode(['type' => 2, 'text' => $text]));
            }
            // save text
            $textTable->set($id, ['text' => $text]);
            break;
        default:
            break;
    }
});

$server->on('close', function (Server $server, int $fd) use ($userTable, $textTable) {
    $id = $userTable->get($fd, 'id');
    // remove user
    $userTable->del($fd);
    // cleanup
    if ($userTable->count() == 0) {
        $textTable->del($id);
    }
});

$server->start();

function randomPassword(int $length): string
{
    $charArray = [
        // number
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
        // upper case
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
        // lower case
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
    ];
    $size = count($charArray);
    $text = '';
    for ($i = 0; $i < $length; $i++) {
        $text .= $charArray[rand(1, $size) - 1];
    }
    return $text;
}
