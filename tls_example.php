<?php
/**
 * Exemplo educacional: Como usar stream_socket_client com TLS
 * 
 * NOTA: SQL Server TDS usa TLS encapsulado dentro de pacotes TDS,
 * então este exemplo não funcionará diretamente com SQL Server,
 * mas mostra como TLS funciona em outros protocolos.
 */

echo "=== Exemplo 1: Conexão TLS simples (HTTPS) ===\n";

// Contexto SSL com opções
$context = stream_context_create([
    'ssl' => [
        'verify_peer' => false,       // Não verificar certificado (apenas para testes!)
        'verify_peer_name' => false,  // Não verificar nome do certificado
        'allow_self_signed' => true,  // Permitir certificados auto-assinados
    ]
]);

// Conectar com TLS
$socket = @stream_socket_client(
    'ssl://www.google.com:443',
    $errno,
    $errstr,
    30,
    STREAM_CLIENT_CONNECT,
    $context
);

if (!$socket) {
    echo "Erro: $errstr ($errno)\n";
} else {
    echo "✓ Conexão TLS estabelecida com sucesso!\n";
    
    // Enviar requisição HTTP
    fwrite($socket, "GET / HTTP/1.1\r\nHost: www.google.com\r\nConnection: close\r\n\r\n");
    
    // Ler primeira linha da resposta
    $response = fgets($socket);
    echo "Resposta: $response\n";
    
    fclose($socket);
}

echo "\n=== Exemplo 2: Upgrade de TCP para TLS (STARTTLS) ===\n";

// 1. Conectar sem TLS
$socket = @stream_socket_client(
    'tcp://smtp.gmail.com:587',
    $errno,
    $errstr,
    30
);

if (!$socket) {
    echo "Erro: $errstr ($errno)\n";
} else {
    echo "✓ Conexão TCP estabelecida\n";
    
    // 2. Ler banner
    $banner = fgets($socket);
    echo "Banner: $banner";
    
    // 3. Enviar STARTTLS
    fwrite($socket, "STARTTLS\r\n");
    $response = fgets($socket);
    echo "STARTTLS Response: $response";
    
    // 4. Upgrade para TLS
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);
    
    stream_context_set_option($socket, $context);
    
    $result = stream_socket_enable_crypto(
        $socket,
        true,
        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
    );
    
    if ($result === true) {
        echo "✓ TLS estabelecido com sucesso!\n";
        
        // Agora a comunicação está criptografada
        fwrite($socket, "QUIT\r\n");
        echo fgets($socket);
    } else {
        echo "✗ Falha ao estabelecer TLS\n";
    }
    
    fclose($socket);
}

echo "\n=== Exemplo 3: SQL Server TDS com TLS (conceito) ===\n";
echo "Para SQL Server, o processo seria:\n";
echo "1. Conectar TCP (porta 1433)\n";
echo "2. Enviar PRELOGIN (tipo 0x12) sem criptografia\n";
echo "3. Receber PRELOGIN Response\n";
echo "4. Enviar TLS ClientHello DENTRO de pacote TDS (tipo 0x12)\n";
echo "5. Receber TLS ServerHello DENTRO de pacote TDS (tipo 0x12)\n";
echo "6. Continuar handshake TLS encapsulado em TDS\n";
echo "7. Após TLS estabelecido, enviar LOGIN7 criptografado\n";
echo "\nEste é um protocolo customizado que requer implementação específica.\n";
