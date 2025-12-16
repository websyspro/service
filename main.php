<?php

$host = '0.0.0.0';
$port = 3001;
$maxClients = 500;

$socket = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$socket) {
    die("Erro ao criar servidor: $errstr ($errno)\n");
}

stream_set_blocking($socket, false);
echo "Servidor rodando em http://$host:$port (max: $maxClients conexões)\n";

$clients = [];
$buffers = [];

while (true) {
    $read = array_merge([$socket], $clients);
    $write = null;
    $except = null;
    
    if (stream_select($read, $write, $except, 0, 200000) < 1) {
        continue;
    }
    
    if (in_array($socket, $read)) {
        $conn = stream_socket_accept($socket, 0);
        if ($conn) {
            if (count($clients) >= $maxClients) {
                $http = "HTTP/1.1 503 Service Unavailable\r\n";
                $http .= "Content-Type: application/json\r\n";
                $body = json_encode(['error' => 'Servidor cheio']);
                $http .= "Content-Length: " . strlen($body) . "\r\n";
                $http .= "Connection: close\r\n\r\n$body";
                fwrite($conn, $http);
                fclose($conn);
            } else {
                stream_set_blocking($conn, false);
                $clients[(int)$conn] = $conn;
                $buffers[(int)$conn] = '';
            }
        }
        unset($read[array_search($socket, $read)]);
    }
    
    foreach ($read as $conn) {
        $data = fread($conn, 8192);
        $id = (int)$conn;
        
        if ($data === false || $data === '') {
            fclose($conn);
            unset($clients[$id], $buffers[$id]);
            continue;
        }
        
        $buffers[$id] .= $data;
        
        if (strpos($buffers[$id], "\r\n\r\n") !== false) {
            preg_match('/^(\w+)\s+([^\s]+)\s+HTTP/', $buffers[$id], $matches);
            $method = $matches[1] ?? 'GET';
            $path = $matches[2] ?? '/';
            $body = explode("\r\n\r\n", $buffers[$id], 2)[1] ?? '';
            
            $response = handleRequest($method, $path, $body);
            
            $http = "HTTP/1.1 {$response['status']}\r\n";
            $http .= "Content-Type: {$response['content_type']}\r\n";
            $http .= "Content-Length: " . strlen($response['body']) . "\r\n";
            $http .= "Connection: close\r\n\r\n";
            $http .= $response['body'];
            
            fwrite($conn, $http);
            fclose($conn);
            unset($clients[$id], $buffers[$id]);
        }
    }
}

function handleRequest($method, $path, $body) {
    if ($path === '/' && $method === 'GET') {
        return [
            'status' => '200 OK',
            'content_type' => 'application/json',
            'body' => json_encode(['message' => 'API funcionando'])
        ];
    }
    
    if ($path === '/api/test' && $method === 'GET') {
        return [
            'status' => '200 OK',
            'content_type' => 'application/json',
            'body' => json_encode(['data' => 'teste', 'timestamp' => time()])
        ];
    }
    
    if ($path === '/api/echo' && $method === 'POST') {
        return [
            'status' => '200 OK',
            'content_type' => 'application/json',
            'body' => json_encode(['received' => $body])
        ];
    }
    
    return [
        'status' => '404 Not Found',
        'content_type' => 'application/json',
        'body' => json_encode(['error' => 'Rota não encontrada'])
    ];
}
