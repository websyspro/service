<?php
$user = "sa";
$pass = "@Qazwsx190483";
$data = "test";

$errno = 0;
$error = "";
$socket = @fsockopen("tcp://localhost", 1433, $errno, $error, 5);

if ($socket === false) {
    exit("Erro na conexão: {$errno} - {$error}\n");
}

echo "Conexão realizada com sucesso!\n";

/* ============================================================
   Helpers
============================================================ */

function buildHeader($length, $type) {
    // TDS Header (8 bytes):
    // 1 byte Type, 1 byte Status, 2 bytes Length, 2 bytes SPID, 1 byte PacketID, 1 byte Window
    $totalLen = $length + 8;
    return
        chr($type) .         // Tipo do pacote
        chr(0x01) .          // Status (EOM)
        pack("n", $totalLen) . // Length (2 bytes, network order)
        pack("n", 0) .       // SPID (2 bytes)
        chr(0) .             // PacketID
        chr(0);              // Window
}

function encryptPassword($pwd) {
    $chars = array_map("ord", str_split($pwd));
    foreach ($chars as &$c) {
        // swap nibbles then xor with 0xA5 (TDS password obfuscation)
        $c = (($c << 4) & 0xF0) | (($c >> 4) & 0x0F);
        $c = $c ^ 0xA5;
    }
    return pack("C*", ...$chars);
}

function buildPreLogin()
{
    // Prelogin tokens: VERSION(0x00), ENCRYPTION(0x01), TERMINATOR(0xFF)
    $versionData = "\x00\x00\x00\x00\x00\x00";  // 6 bytes

    // <-- alterado para ENCRYPT_NOT_SUPPORTED (0x02)
    // 0x00 = ENCRYPT_OFF, 0x01 = ENCRYPT_ON, 0x02 = ENCRYPT_NOT_SUPP, 0x03 = ENCRYPT_REQ
    $encryptData = "\x02"; // 0x02 = ENCRYPT_NOT_SUPPORTED

    $offsetVersion = 0x0008;
    $offsetEncrypt = $offsetVersion + strlen($versionData);

    $header =
        // VERSION token (0x00)
        "\x00" . pack("n", $offsetVersion) . pack("n", strlen($versionData)) .
        // ENCRYPTION token (0x01)
        "\x01" . pack("n", $offsetEncrypt) . pack("n", strlen($encryptData)) .
        // TERMINATOR
        "\xFF";

    return $header . $versionData . $encryptData;
}

function buildLogin7($user, $password, $database)
{
    // user and db in UCS-2 (UTF-16LE)
    $userUtf = iconv("UTF-8", "UTF-16LE", $user);
    $dbUtf   = iconv("UTF-8", "UTF-16LE", $database);

    // password: apply encryptPassword (binary), then convert to UCS-2 by interleaving \x00
    $passEnc = encryptPassword($password);
    $passEncUcs2 = '';
    for ($i = 0; $i < strlen($passEnc); $i++) {
        $passEncUcs2 .= $passEnc[$i] . "\x00";
    }

    // Fixed-length header fields for LOGIN7 (minimal viable)
    $packetSize = pack("V", 4096);      // int32
    $clientProgVer = pack("V", 0);      // int32
    $clientPID = pack("V", getmypid() ?: 1234);
    $connectionID = pack("V", 0);
    $flags1 = "\x00";                   // 1 byte
    $flags2 = "\x00";                   // 1 byte
    $typeFlags = "\x00";                // 1 byte
    $flags3 = "\x00";                   // 1 byte
    $clientTimezone = pack("V", 0);     // int32
    $clientLCID = pack("V", 1033);      // int32

    $fixedPart = $packetSize . $clientProgVer . $clientPID . $connectionID
               . $flags1 . $flags2 . $typeFlags . $flags3
               . $clientTimezone . $clientLCID;

    // Prepare offsets area placeholders (we will compute offsets in BYTES)
    $appName = iconv("UTF-8", "UTF-16LE", "php-tds-client");
    $hostName = iconv("UTF-8", "UTF-16LE", gethostname() ?: "php-host");

    // Build variable data buffer (strings in the order we'll append)
    $varBuffer = $hostName . $userUtf . $passEncUcs2 . $appName . $dbUtf;

    // The offset area comes after fixedPart and a fixed reserved area.
    $reserved = str_repeat("\x00", 36);

    // Now compute base offset (bytes from start of login payload)
    $baseOffset = strlen($fixedPart) + strlen($reserved);

    // Calculate offsets (byte positions)
    $pos = $baseOffset;
    $hostOffset = $pos; $hostLen = strlen($hostName); $pos += $hostLen;
    $userOffset = $pos; $userLen = strlen($userUtf); $pos += $userLen;
    $passOffset = $pos; $passLen = strlen($passEncUcs2); $pos += $passLen;
    $appOffset  = $pos; $appLen  = strlen($appName); $pos += $appLen;
    $dbOffset   = $pos; $dbLen   = strlen($dbUtf); $pos += $dbLen;

    // Pack offsets and lengths as 2-byte little-endian (counts in UCS-2 characters)
    $offsetArea = "";
    $offsetArea .= pack("v", intval($hostOffset / 2)) . pack("v", intval($hostLen / 2));
    $offsetArea .= pack("v", intval($userOffset / 2)) . pack("v", intval($userLen / 2));
    $offsetArea .= pack("v", intval($passOffset / 2)) . pack("v", intval($passLen / 2));
    $offsetArea .= pack("v", intval($appOffset / 2)) . pack("v", intval($appLen / 2));
    $offsetArea .= pack("v", 0) . pack("v", 0); // servername empty
    $offsetArea .= pack("v", 0) . pack("v", 0); // unused
    $offsetArea .= pack("v", intval($dbOffset / 2)) . pack("v", intval($dbLen / 2));

    // Compose full login payload
    $loginPayload = $fixedPart . $reserved . $offsetArea . $varBuffer;

    return $loginPayload;
}

/* ============================================================
   1) PRELOGIN
============================================================ */

$pre = buildPreLogin();
$header = buildHeader(strlen($pre), 0x12);
fwrite($socket, $header . $pre);

echo "PreLogin enviado!\n";

// evitar bloqueio excessivo
stream_set_timeout($socket, 2);
$preResp = fread($socket, 4096);
echo "Resposta PreLogin (" . strlen($preResp) . " bytes)\n";
if (strlen($preResp) > 0) {
    echo "PreLogin response (hex): " . bin2hex($preResp) . "\n";
} else {
    echo "Nenhuma resposta ao PreLogin — o servidor fechou a conexão ou não entendeu o packet.\n";
    fclose($socket);
    exit(0);
}

/* ============================================================
   2) LOGIN7
============================================================ */

$login = buildLogin7($user, $pass, $data);
$header = buildHeader(strlen($login), 0x10);
fwrite($socket, $header . $login);

echo "Login7 enviado!\n";

stream_set_timeout($socket, 3);
$resp = fread($socket, 8192);

echo "Response: " . strlen($resp) . " bytes\n";
if (strlen($resp) > 0) {
    echo "Response (hex): " . bin2hex($resp) . "\n";
    if (strpos(bin2hex($resp), "ad") !== false) {
        echo "Login OK (detectado token LoginAck 0xAD)!\n";
    } elseif (strpos(bin2hex($resp), "aa") !== false) {
        echo "Erro de autenticação (token 0xAA detectado).\n";
    } else {
        echo "Resposta recebida, mas o token esperado não foi identificado. Verifique hex acima.\n";
    }
} else {
    echo "Nenhuma resposta após LOGIN7. O servidor pode ter fechado a conexão.\n";
}

fclose($socket);
