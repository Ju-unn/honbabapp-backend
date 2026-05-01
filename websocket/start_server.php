<?php

/**
 * WebSocket 서버 시작 진입점
 *
 * 실행: php websocket/start_server.php
 * 접속: ws://3.37.127.69:8080
 */

// autoload 먼저
require_once __DIR__ . '/../vendor/autoload.php';

// RoomManager → ChatServer
require_once __DIR__ . '/RoomManager.php';
require_once __DIR__ . '/ChatServer.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

echo "====================================\n";
echo " 혼밥 모여 WebSocket 서버 시작\n";
echo " ws://3.37.127.69:8080\n";
echo "====================================\n";

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ChatServer()
        )
    ),
    8080,
    '0.0.0.0'
);

echo "[서버] 대기 중... (종료: Ctrl+C)\n";

$server->run();