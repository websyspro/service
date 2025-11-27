<?php
$host = "127.0.0.1";
$port = 3307;
$user = "root";
$pass = "qazwsx";
$db   = "test";

// ------------------------- AUXILIARES -------------------------
function read_exact($sock, int $len) {
    $data = "";
    while (strlen($data) < $len) {
        $chunk = fread($sock, $len - strlen($data));
        if ($chunk === false || $chunk === "") {
            throw new Exception("Socket closed while reading!");
        }
        $data .= $chunk;
    }
    return $data;
}

function mysql_read_packet($sock) {
    $hdr = read_exact($sock, 4);
    $len = (ord($hdr[0]) | (ord($hdr[1]) << 8) | (ord($hdr[2]) << 16));
    $seq = ord($hdr[3]);
    $payload = read_exact($sock, $len);
    return [$seq, $payload];
}

function mysql_send_raw($sock, string $payload, int $seq = 1) {
    $len = strlen($payload);
    $header = chr($len & 0xFF) . chr(($len >> 8) & 0xFF) . chr(($len >> 16) & 0xFF) . chr($seq & 0xFF);
    $w = fwrite($sock, $header . $payload);
    if ($w === false) throw new Exception("Erro ao escrever no socket");
    return $w;
}

function define_if_not($name, $value) { if (!defined($name)) define($name, $value); }

// ------------------------- CACHING SHA2 -------------------------
function mysql_caching_sha2_password(string $password, string $salt) {
    $p1 = hash("sha256", $password, true);
    $p2 = hash("sha256", $p1, true);
    $p3 = hash("sha256", $p2 . $salt, true);
    return $p1 ^ $p3;
}

// ------------------------- LOGIN PACKET -------------------------
function mysql_build_login_packet(array $h, string $user, string $pass, string $db = "") {
    define_if_not('CLIENT_LONG_PASSWORD', 0x00000001);
    define_if_not('CLIENT_FOUND_ROWS',  0x00000002);
    define_if_not('CLIENT_LONG_FLAG',   0x00000004);
    define_if_not('CLIENT_CONNECT_WITH_DB', 0x00000008);
    define_if_not('CLIENT_PROTOCOL_41', 0x00000200);
    define_if_not('CLIENT_SECURE_CONNECTION', 0x00008000);
    define_if_not('CLIENT_PLUGIN_AUTH', 0x00080000);
    define_if_not('CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA', 0x00200000);

    $client_flags = CLIENT_LONG_PASSWORD | CLIENT_LONG_FLAG | CLIENT_PROTOCOL_41 |
                    CLIENT_SECURE_CONNECTION | CLIENT_PLUGIN_AUTH |
                    CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;
    if ($db !== "") $client_flags |= CLIENT_CONNECT_WITH_DB;

    $max_packet = 16777216;
    $charset = $h['character_set'] ?? 33;
    $salt = $h['auth_plugin_salt_raw'];
    $auth = mysql_caching_sha2_password($pass, $salt);

    $payload  = pack("V", $client_flags);
    $payload .= pack("V", $max_packet);
    $payload .= chr($charset);
    $payload .= str_repeat("\0", 23);
    $payload .= $user . "\0";
    $payload .= chr(strlen($auth)) . $auth;
    if ($db !== "") $payload .= $db . "\0";
    $plugin = $h['auth_plugin_name'] ?: 'caching_sha2_password';
    $payload .= $plugin . "\0";

    return $payload;
}

// ------------------------- RSA ENCRYPT -------------------------
function rsa_encrypt_password(string $password, string $salt, string $publicKeyPEM) {
    $pass_bytes = $password . "\0";
    $xored = $pass_bytes ^ $salt;
    $pubkey = openssl_pkey_get_public($publicKeyPEM);
    if (!$pubkey) throw new Exception("Falha ao ler RSA public key");
    if (!openssl_public_encrypt($xored, $encrypted, $pubkey, OPENSSL_PKCS1_OAEP_PADDING)) {
        throw new Exception("Falha ao criptografar senha com RSA");
    }
    return $encrypted;
}

// ------------------------- PLAIN TEXT XOR -------------------------
function send_plain_xor($sock, string $password, string $salt, int $seq) {
    $pass_bytes = $password . "\0";
    $encrypted = $pass_bytes ^ $salt;
    mysql_send_raw($sock, $encrypted, $seq);
    return mysql_read_packet($sock);
}

// ------------------------- FUNÇÃO PARA EXECUTAR QUERY -------------------------
function read_length_encoded_string($payload, &$pos) {
    $first = ord($payload[$pos]);
    $pos++;
    if ($first < 0xFB) {
        return substr($payload, $pos, $first);
        $pos += $first;
    } elseif ($first === 0xFB) {
        return null; // NULL
    } else {
        throw new Exception("Strings longas não implementadas ainda");
    }
}

function mysql_query($sock, string $sql) {
    mysql_send_raw($sock, chr(0x03) . $sql, 0);

    list($seq, $header) = mysql_read_packet($sock);
    $column_count = ord($header[0]);

    $columns = [];
    for ($i = 0; $i < $column_count; $i++) {
        list($seq, $col) = mysql_read_packet($sock);
        $pos = 0;
        read_length_encoded_string($col, $pos); // catalog
        read_length_encoded_string($col, $pos); // schema
        read_length_encoded_string($col, $pos); // table
        read_length_encoded_string($col, $pos); // org_table
        $name = read_length_encoded_string($col, $pos); // name
        $columns[] = $name;
    }

    list($seq, $eof) = mysql_read_packet($sock); // EOF header

    $rows = [];
    while (true) {
        list($seq, $row) = mysql_read_packet($sock);
        if (ord($row[0]) === 0xFE && strlen($row) < 9) break; // EOF

        $pos = 0;
        $row_data = [];
        foreach ($columns as $col) {
            $row_data[$col] = read_length_encoded_string($row, $pos);
        }
        $rows[] = $row_data;
    }

    return $rows;
}

// ------------------------- CONEXÃO -------------------------
$sock = fsockopen($host, $port, $errno, $errstr, 3);
if (!$sock) die("Erro ao conectar: $errstr\n");
echo "[OK] Conectado ao MySQL\n";

// 1) HANDSHAKE
list($seq, $handshake) = mysql_read_packet($sock);
$protocol_version = ord($handshake[0]);
$server_version = ""; $i=1;
while(isset($handshake[$i]) && $handshake[$i] !== "\0") { $server_version .= $handshake[$i]; $i++; } $i++;
$connection_id = unpack("V", substr($handshake,$i,4))[1]; $i+=4;
$salt1 = substr($handshake,$i,8); $i+=8; $i++;
$cap_low = unpack("v", substr($handshake,$i,2))[1]; $i+=2;
$charset = ord($handshake[$i]);
$status  = unpack("v", substr($handshake,$i+1,2))[1]; $i+=3;
$cap_upper = unpack("v", substr($handshake,$i,2))[1]; $capabilities = ($cap_upper<<16)|$cap_low; $i+=2;
$auth_len = ord($handshake[$i]); $i+=1; $i+=10;
$salt2 = substr($handshake,$i, max(13,$auth_len-8)); $i+=max(13,$auth_len-8);
$plugin = ""; $j=$i; while(isset($handshake[$j]) && $handshake[$j] !== "\0") { $plugin .= $handshake[$j]; $j++; }
$salt = $salt1 . rtrim($salt2, "\x00");

echo "[Handshake OK] Server=$server_version Plugin=$plugin\n";

$h = [
    "character_set"=>$charset,
    "auth_plugin_name"=>$plugin,
    "auth_plugin_salt_raw"=>$salt,
    "capabilities"=>$capabilities
];

// 2) LOGIN PACKET
$loginPayload = mysql_build_login_packet($h,$user,$pass,$db);
mysql_send_raw($sock,$loginPayload,1);
echo "[Login enviado]\n";

// 3) RESPONDER SERVER
list($seq2, $resp) = mysql_read_packet($sock);
$type = ord($resp[0]);
if ($type===0x00) {
    echo "[OK] Login bem-sucedido!\n";
} elseif ($type===0x01 || $type===0x03 || $type===0x04) {
    echo "[AUTH-MORE-DATA] subcode=0x".dechex($type)."\n";
    if ($type===0x01) { // plaintext XOR
        list($seq3, $resp2) = send_plain_xor($sock,$pass,$salt,$seq2+1);
        $t2 = ord($resp2[0]);
        if ($t2===0x00) {
					echo "[OK] Login bem-sucedido após XOR!\n";
				} else if ($t2===0xFF) { $msg=substr($resp2,3); echo "[ERRO FINAL] ".trim($msg)."\n"; }
    } elseif ($type===0x04) { // RSA
        $rsaKeyHex = substr($resp,1);
        $rsaKeyPEM = "-----BEGIN PUBLIC KEY-----\n" .
                     chunk_split(bin2hex($rsaKeyHex),64,"\n") .
                     "-----END PUBLIC KEY-----\n";
        $enc_pass = rsa_encrypt_password($pass, $salt, $rsaKeyPEM);
        mysql_send_raw($sock, $enc_pass,$seq2+1);
        echo "[Encrypted password enviado]\n";
        list($seq3,$resp2) = mysql_read_packet($sock);
        $t2 = ord($resp2[0]);
        if ($t2===0x00) echo "[OK] Login bem-sucedido após RSA!\n";
        elseif ($t2===0xFF) { $msg=substr($resp2,3); echo "[ERRO FINAL] ".trim($msg)."\n"; }
    }
} elseif ($type===0xFF) {
    $msg = substr($resp,3);
    echo "[ERRO] ".trim($msg)."\n";
} else {
    echo "[??] Resposta desconhecida: type=0x".dechex($type)." payload=".bin2hex($resp)."\n";
}

$results = mysql_query($sock, "select * from solicitacoes");

fclose($sock);
