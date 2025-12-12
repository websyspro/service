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

function buildHeader($length, $type) {
    $totalLen = $length + 8;
    return
        chr($type) .
        chr(0x01) .
        pack("n", $totalLen) .
        pack("n", 0) .
        chr(0) .
        chr(0);
}

function encryptPassword($pwd) {
    $chars = array_map("ord", str_split($pwd));
    foreach ($chars as &$c) {
        $c = (($c << 4) & 0xF0) | (($c >> 4) & 0x0F);
        $c = $c ^ 0xA5;
    }
    return pack("C*", ...$chars);
}

function buildPreLogin() {
    $versionData = "\x00\x00\x00\x00\x00\x00";
    $encryptData = "\x02";
    $offsetVersion = 0x0008;
    $offsetEncrypt = $offsetVersion + strlen($versionData);

    $header =
        "\x00" . pack("n", $offsetVersion) . pack("n", strlen($versionData)) .
        "\x01" . pack("n", $offsetEncrypt) . pack("n", strlen($encryptData)) .
        "\xFF";

    return $header . $versionData . $encryptData;
}

function buildLogin7($user, $password, $database) {
    $userUtf = iconv("UTF-8", "UTF-16LE", $user);
    $dbUtf   = iconv("UTF-8", "UTF-16LE", $database);
    $appName = iconv("UTF-8", "UTF-16LE", "php");
    $hostName = iconv("UTF-8", "UTF-16LE", "localhost");

    $passEnc = encryptPassword($password);
    $passEncUcs2 = '';
    for ($i = 0; $i < strlen($passEnc); $i++) {
        $passEncUcs2 .= $passEnc[$i] . "\x00";
    }

    $login7 = pack("V", 4096);
    $login7 .= pack("V", 0x0F000000);
    $login7 .= pack("V", getmypid() ?: 1234);
    $login7 .= pack("V", 0);
    $login7 .= "\x20\x00\x00\x00";
    $login7 .= pack("V", 0xFFFFFFFF);
    $login7 .= pack("V", 1033);

    $login7 .= str_repeat("\x00", 36);

    // Offsets em BYTES a partir do início do LOGIN7
    $pos = 64;
    
    $login7 .= pack("v", $pos) . pack("v", strlen($hostName)); $pos += strlen($hostName);
    $login7 .= pack("v", $pos) . pack("v", strlen($userUtf)); $pos += strlen($userUtf);
    $login7 .= pack("v", $pos) . pack("v", strlen($passEncUcs2)); $pos += strlen($passEncUcs2);
    $login7 .= pack("v", $pos) . pack("v", strlen($appName)); $pos += strlen($appName);
    $login7 .= pack("v", 0) . pack("v", 0);
    $login7 .= pack("v", 0) . pack("v", 0);
    $login7 .= pack("v", $pos) . pack("v", strlen($dbUtf));

    $login7 .= $hostName . $userUtf . $passEncUcs2 . $appName . $dbUtf;

    return $login7;
}

$pre = buildPreLogin();
$header = buildHeader(strlen($pre), 0x12);
fwrite($socket, $header . $pre);

echo "PreLogin enviado!\n";

stream_set_timeout($socket, 2);
$preResp = fread($socket, 4096);
echo "Resposta PreLogin (" . strlen($preResp) . " bytes)\n";
if (strlen($preResp) > 0) {
    echo "PreLogin response (hex): " . bin2hex($preResp) . "\n";
} else {
    echo "Nenhuma resposta ao PreLogin.\n";
    fclose($socket);
    exit(0);
}

$login = buildLogin7($user, $pass, $data);
$header = buildHeader(strlen($login), 0x10);
fwrite($socket, $header . $login);

echo "Login7 enviado!\n";

stream_set_timeout($socket, 10);
$resp = fread($socket, 8192);

echo "Response: " . strlen($resp) . " bytes\n";
if (strlen($resp) > 0) {
    echo "Response (hex): " . bin2hex($resp) . "\n";
} else {
    echo "Nenhuma resposta após LOGIN7.\n";
}

fclose($socket);
