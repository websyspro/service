<?php
/**
 * Analisador do fluxo real capturado no Wireshark
 */

$packets = [
    ['proto' => 'TCP', 'src' => 55199, 'dst' => 1433, 'hex' => '02000000450000349a754000800600007f0000017f000001d79f0599b6ba1ccb000000008002ffffc62b00000204ffd70103030801010402'],
    ['proto' => 'TCP', 'src' => 1433, 'dst' => 55199, 'hex' => '02000000450000349a764000800600007f0000017f0000010599d79fc9d6d1a5b6ba1ccc8012ffff2a9e00000204ffd70103030801010402'],
    ['proto' => 'TCP', 'src' => 55199, 'dst' => 1433, 'hex' => '02000000450000289a774000800600007f0000017f000001d79f0599b6ba1cccc9d6d1a6501000ff64960000'],
    ['proto' => 'TDS', 'src' => 55199, 'dst' => 1433, 'hex' => '020000004500006b9a784000800600007f0000017f000001d79f0599b6ba1cccc9d6d1a6501800ff151600001201004300000000000010000601001600010500170024ff0d020001000000ce2134ecfa122d488363f761b9cdce585a08839bc67f3a4f95f0854dd264dc6d01000000'],
    ['proto' => 'TCP', 'src' => 1433, 'dst' => 55199, 'hex' => '02000000450000289a794000800600007f0000017f0000010599d79fc9d6d1a6b6ba1d0f501000ff64530000'],
    ['proto' => 'TDS', 'src' => 1433, 'dst' => 55199, 'hex' => '02000000450000479a7a4000800600007f0000017f0000010599d79fc9d6d1a6b6ba1d0f501800ff0b8e00000401001f00000100000010000601001600010500170000ff0f001167000000'],
];

function analyzeTCP($bytes, $num) {
    $tcp_start = 24;
    $flags = ord($bytes[$tcp_start + 13]);
    
    $flag_names = [];
    if ($flags & 0x02) $flag_names[] = 'SYN';
    if ($flags & 0x10) $flag_names[] = 'ACK';
    if ($flags & 0x08) $flag_names[] = 'PSH';
    
    $seq = unpack('N', substr($bytes, $tcp_start + 4, 4))[1];
    $ack = unpack('N', substr($bytes, $tcp_start + 8, 4))[1];
    
    echo "  Flags: " . implode('+', $flag_names) . "\n";
    echo "  Seq: 0x" . dechex($seq) . "\n";
    echo "  Ack: 0x" . dechex($ack) . "\n";
    
    return implode('+', $flag_names);
}

function analyzeTDS($bytes) {
    // Pular IP + TCP headers (54 bytes no total com loopback)
    $tds_start = 54;
    
    if (strlen($bytes) <= $tds_start) {
        return null;
    }
    
    $type = ord($bytes[$tds_start]);
    $status = ord($bytes[$tds_start + 1]);
    $length = unpack('n', substr($bytes, $tds_start + 2, 2))[1];
    
    $types = [
        0x01 => 'SQL_BATCH',
        0x04 => 'RESPONSE',
        0x10 => 'LOGIN7',
        0x12 => 'PRELOGIN'
    ];
    
    echo "  TDS Type: 0x" . dechex($type) . " (" . ($types[$type] ?? 'UNKNOWN') . ")\n";
    echo "  Status: 0x" . dechex($status) . " (" . ($status & 0x01 ? 'EOM' : 'MORE') . ")\n";
    echo "  Length: $length bytes\n";
    
    // Extrair payload TDS
    $payload = substr($bytes, $tds_start, $length);
    echo "  TDS Payload: " . bin2hex($payload) . "\n";
    
    // Se for PRELOGIN, decodificar
    if ($type === 0x12 || $type === 0x04) {
        echo "\n  === PRELOGIN Options ===\n";
        $offset = 8; // Pular header TDS
        while ($offset < strlen($payload)) {
            $token = ord($payload[$offset++]);
            if ($token === 0xff) break;
            
            $opt_offset = unpack('n', substr($payload, $offset, 2))[1];
            $opt_length = unpack('n', substr($payload, $offset + 2, 2))[1];
            $offset += 4;
            
            $tokens = [0x00 => 'VERSION', 0x01 => 'ENCRYPTION', 0x05 => 'MARS'];
            echo "  Token 0x" . dechex($token) . " (" . ($tokens[$token] ?? 'UNKNOWN') . "): ";
            echo "offset=$opt_offset, length=$opt_length\n";
            
            // Ler dados
            $data_pos = 8 + $opt_offset;
            if ($token === 0x01 && $data_pos < strlen($payload)) {
                $enc_value = ord($payload[$data_pos]);
                $enc_map = [0x00 => 'OFF', 0x01 => 'ON', 0x02 => 'NOT_SUP', 0x03 => 'REQ'];
                echo "    Value: 0x" . dechex($enc_value) . " (ENCRYPT_" . ($enc_map[$enc_value] ?? 'UNKNOWN') . ")\n";
            }
        }
    }
    
    return $types[$type] ?? 'UNKNOWN';
}

echo "=== ANÁLISE DO FLUXO COMPLETO ===\n\n";

foreach ($packets as $i => $pkt) {
    $num = $i + 1;
    echo "--- Pacote $num: {$pkt['proto']} ({$pkt['src']} → {$pkt['dst']}) ---\n";
    
    $bytes = hex2bin($pkt['hex']);
    
    if ($pkt['proto'] === 'TCP') {
        $flags = analyzeTCP($bytes, $num);
        
        if ($num === 1) echo "  → TCP Three-Way Handshake: Cliente inicia conexão\n";
        if ($num === 2) echo "  → TCP Three-Way Handshake: Servidor aceita\n";
        if ($num === 3) echo "  → TCP Three-Way Handshake: Conexão estabelecida ✓\n";
    }
    
    if ($pkt['proto'] === 'TDS') {
        analyzeTCP($bytes, $num);
        echo "\n";
        $tds_type = analyzeTDS($bytes);
        
        if ($num === 4) echo "\n  → Cliente envia PRELOGIN (solicita conexão TDS)\n";
        if ($num === 6) echo "\n  → Servidor responde PRELOGIN (configurações aceitas)\n";
    }
    
    echo "\n";
}

echo "=== RESUMO ===\n";
echo "Pacote 1-3: TCP Three-Way Handshake (SYN, SYN-ACK, ACK)\n";
echo "Pacote 4: Cliente → PRELOGIN (inicia protocolo TDS)\n";
echo "Pacote 5: Servidor → ACK (confirma recebimento)\n";
echo "Pacote 6: Servidor → PRELOGIN Response (responde configurações)\n";
echo "\nPróximo passo: LOGIN7 ou TLS Handshake (dependendo da configuração)\n";
