<?php

$host = "localhost";
$port = 3307;
$user = "root";
$pass = "qazwsx";
$db   = "test";

// ---------------------------------------------------------
// AUXILIARES
// ---------------------------------------------------------

function read_exact($sock, int $len)
{
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

function mysql_read_packet($sock)
{
    $hdr = read_exact($sock, 4);
    $len = (ord($hdr[0]) | (ord($hdr[1]) << 8) | (ord($hdr[2]) << 16));
    $seq = ord($hdr[3]);
    $payload = read_exact($sock, $len);
    return [$seq, $payload];
}

function mysql_send_raw($sock, string $payload, int $seq)
{
    $len = strlen($payload);
    $header =
        chr($len & 0xFF) .
        chr(($len >> 8) & 0xFF) .
        chr(($len >> 16) & 0xFF) .
        chr($seq & 0xFF);

    fwrite($sock, $header . $payload);
}

// ---------------------------------------------------------
// HASH caching_sha2_password
// ---------------------------------------------------------

function mysql_caching_sha2_password(string $password, string $salt)
{
    $p1 = hash("sha256", $password, true);
    $p2 = hash("sha256", $p1, true);
    $p3 = hash("sha256", $p2 . $salt, true);
    return ($p1 ^ $p3);
}

function define_if_not($name, $value) {
    if (!defined($name)) define($name, $value);
}

// ---------------------------------------------------------
// LOGIN PACKET
// ---------------------------------------------------------

function mysql_build_login_packet(array $h, string $user, string $pass, string $db = "")
{
    define_if_not('CLIENT_LONG_PASSWORD', 0x00000001);
    define_if_not('CLIENT_LONG_FLAG',     0x00000004);
    define_if_not('CLIENT_CONNECT_WITH_DB', 0x00000008);
    define_if_not('CLIENT_PROTOCOL_41',   0x00000200);
    define_if_not('CLIENT_SECURE_CONNECTION', 0x00008000);
    define_if_not('CLIENT_PLUGIN_AUTH',   0x00080000);
    define_if_not('CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA', 0x00200000);

    $client_flags =
        CLIENT_LONG_PASSWORD |
        CLIENT_LONG_FLAG |
        CLIENT_PROTOCOL_41 |
        CLIENT_SECURE_CONNECTION |
        CLIENT_PLUGIN_AUTH |
        CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;

    if ($db !== "") $client_flags |= CLIENT_CONNECT_WITH_DB;

    $max_packet = 16777216;
    $charset    = $h['character_set'] ?? 33;

    $auth       = mysql_caching_sha2_password($pass, $h["auth_plugin_salt_raw"]);

    $payload  = pack("V", $client_flags);
    $payload .= pack("V", $max_packet);
    $payload .= chr($charset);
    $payload .= str_repeat("\0", 23);

    $payload .= $user . "\0";

    // auth
    $payload .= chr(strlen($auth)) . $auth;

    if ($db !== "") $payload .= $db . "\0";

    $plugin = $h["auth_plugin_name"] ?: "caching_sha2_password";
    $payload .= $plugin . "\0";

    return $payload;
}

// ---------------------------------------------------------
// 1) CONNECT
// ---------------------------------------------------------

$sock = fsockopen($host, $port, $errno, $errstr, 3);
echo "[OK] Conectado ao MySQL\n";

// ---------------------------------------------------------
// 2) HANDSHAKE
// ---------------------------------------------------------

list($seq, $hs) = mysql_read_packet($sock);

$i = 0;
$protocol = ord($hs[$i++]);

$server_version = "";
while ($hs[$i] !== "\0") $server_version .= $hs[$i++];
$i++;

$connection_id = unpack("V", substr($hs, $i, 4))[1];
$i += 4;

$salt1 = substr($hs, $i, 8);
$i += 9;

$cap_low = unpack("v", substr($hs, $i, 2))[1];
$i += 2;

$charset = ord($hs[$i++]);
$status  = unpack("v", substr($hs, $i, 2))[1];
$i += 2;

$cap_upper = unpack("v", substr($hs, $i, 2))[1];
$i += 2;

$auth_len = ord($hs[$i++]);
$i += 10;

$salt2 = substr($hs, $i, max(13, $auth_len - 8));
$i += strlen($salt2);

$plugin = "";
while ($i < strlen($hs) && $hs[$i] !== "\0") {
    $plugin .= $hs[$i++];
}

$salt = $salt1 . $salt2;

echo "[Handshake OK] Server=$server_version Plugin=$plugin\n";

$h = [
    "character_set" => $charset,
    "auth_plugin_name" => $plugin,
    "auth_plugin_salt_raw" => $salt,
];

function mysql_translate_error_hex(string $hex): string
{
    // Remove espaços e normaliza
    $hex = strtolower(trim($hex));
    $hex = preg_replace('/[^0-9a-f]/', '', $hex);

    // Converte HEX → binário
    $bin = hex2bin($hex);
    if ($bin === false) {
        return "Erro: HEX inválido.";
    }

    // Pacote ERR:
    // byte[0] = 0xFF
    // byte[1-2] = error code (LE)
    // byte[3] = '#' (sqlstate marker)
    // byte[4-8] = SQLSTATE (5 chars)
    // byte[9...] = mensagem ASCII

    if (strlen($bin) < 10 || $bin[0] !== "\xFF") {
        return "O pacote informado não é um ERR Packet válido.";
    }

    // código do erro
    $errCode = unpack("v", substr($bin, 1, 2))[1];

    // SQLSTATE
    $sqlState = substr($bin, 4, 5);

    // mensagem original em inglês
    $msg = substr($bin, 9);

    return "Erro MySQL $errCode ($sqlState): $msg";
}

// ---------------------------------------------------------
// 3) SEND LOGIN
// ---------------------------------------------------------

$loginPayload = mysql_build_login_packet($h, $user, $pass, $db);
mysql_send_raw($sock, $loginPayload, 1);
echo "[Login enviado]\n";

// ---------------------------------------------------------
// 4) RESPOSTA (AUTH MORE DATA / OK / ERROR)
// ---------------------------------------------------------

list($seq2, $resp) = mysql_read_packet($sock);
$type = ord($resp[0]);

echo "[RECV] " . bin2hex($resp) . "\n";

if ($type === 0x00) {
    echo "[OK] Login imediato\n";
    exit;
}

if ($type === 0x01) {
		// AuthMoreData
    $sub = ord($resp[1]);
    echo "[AUTH-MORE-DATA] subcode=0x" . dechex($sub) . "\n";

    if ($sub === 0x04) {
        echo "[DEBUG] Fast auth falhou -> pedindo RSA public key...\n";

        // enviar "0x02"
        mysql_send_raw($sock, "\x02", $seq2 + 1);

        echo "[RSA-REQUEST enviado]\n";

        // Ler a KEY
        list($seq3, $rsaResp) = mysql_read_packet($sock);
        echo "[RSA-KEY HEX] " . bin2hex($rsaResp) . "\n";

        // rsaResp deve conter:
        // 0x01 <public_key_bytes>
        // precisamos remover o 0x01 prefixo
        $server_key = substr($rsaResp, 1);

        // Agora criptografar a senha
        openssl_public_encrypt($pass . "\0", $enc, $server_key, OPENSSL_PKCS1_OAEP_PADDING);

        // Enviar senha criptografada
        mysql_send_raw($sock, $enc, $seq3 + 1);

        echo "[Encrypted password enviado]\n";

        // ler resposta final
        list($seq4, $final) = mysql_read_packet($sock);

        if (ord($final[0]) === 0x00) {
            echo "[OK] Login concluído!\n";
        } else {
            echo "[ERRO FINAL] " . bin2hex($final) . "\n";
						echo mysql_translate_error_hex(bin2hex($final)) . "\n";
        }
    }
	}

if ($type === 0xFF) {
    echo "[ERRO] " . substr($resp, 3) . "\n";
    exit;
}

if ($type === 0x03) {
    echo "[STEP] Server pediu 'public key'.\n";

    // enviar byte 0x02 (request public key)
    mysql_send_raw($sock, "\x02", $seq2 + 1);

    list($seq3, $pkresp) = mysql_read_packet($sock);

    if (ord($pkresp[0]) !== 0x01) {
        die("ERRO: Resposta inesperada do servidor ao pedir public key\n");
    }

    $public_key_pem = substr($pkresp, 1);
    echo "[PublicKey recebida]\n";

    // ---------------------------------------------------------
    // Enviar senha criptografada RSA
    // ---------------------------------------------------------

    $salt = $h["auth_plugin_salt_raw"];
    $xor  = $pass . "\0";

    $xor = $xor ^ $salt;

    openssl_public_encrypt($xor, $encrypted, $public_key_pem, OPENSSL_PKCS1_OAEP_PADDING);

    mysql_send_raw($sock, chr(strlen($encrypted)) . $encrypted, $seq3 + 1);

    echo "[Senha criptografada enviada]\n";

    list($seq4, $final) = mysql_read_packet($sock);

    if (ord($final[0]) === 0x00) {
        echo "[OK] Login completo funcionou!\n";
    } else {
        echo "[ERRO FINAL] " . bin2hex($final) . "\n";
				echo mysql_translate_error_hex(bin2hex($final)) . "\n";
    }

    exit;
}

echo "[??] Tipo desconhecido\n";
