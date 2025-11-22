<?php

class MySqlDriver
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private $fp;

    public function __construct(string $host, int $port, string $user, string $pass)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
    }

    /*******************************
     * Helpers
     *******************************/
    private function readPacket()
    {
        $header = fread($this->fp, 4);
        if (!$header || strlen($header) < 4) {
            return [0, ""];
        }

        $len = ord($header[0]) |
            (ord($header[1]) << 8) |
            (ord($header[2]) << 16);

        $seq = ord($header[3]);

        $payload = "";
        while (strlen($payload) < $len) {
            $chunk = fread($this->fp, $len - strlen($payload));
            if ($chunk === false || $chunk === "") break;
            $payload .= $chunk;
        }

        return [$seq, $payload];
    }

    private function sendPacket(int $seq, string $payload): void
    {
        $len = strlen($payload);

        $header =
            chr($len & 0xFF) .
            chr(($len >> 8) & 0xFF) .
            chr(($len >> 16) & 0xFF) .
            chr($seq);

        fwrite($this->fp, $header . $payload);
    }

    private function mysql_native_auth(string $password, string $salt): string
    {
        if ($password === "") return "";
        $s1 = sha1($password, true);
        $s2 = sha1($s1, true);
        $scr = sha1($salt . $s2, true);
        return $s1 ^ $scr;
    }

    private function readLenEncInt(string $data, int &$off): int
    {
        $c = ord($data[$off]);

        if ($c < 0xFB) {
            $off++;
            return $c;
        }
        if ($c === 0xFC) {
            $v = unpack("v", substr($data, $off + 1, 2))[1];
            $off += 3;
            return $v;
        }
        if ($c === 0xFD) {
            // 3 bytes little-endian
            $b = substr($data, $off + 1, 3) . "\0";
            $v = unpack("V", $b)[1];
            $off += 4;
            return $v;
        }
        if ($c === 0xFE) {
            $v = unpack("P", substr($data, $off + 1, 8))[1];
            $off += 9;
            return $v;
        }

        $off++;
        return $c;
    }

    private function readLenEncString(string $data, int &$off): ?string
    {
        // NULL value is sent as 0xfb
        $c = ord($data[$off]);
        if ($c === 0xFB) {
            $off++;
            return null;
        }
        $len = $this->readLenEncInt($data, $off);
        $s = substr($data, $off, $len);
        $off += $len;
        return $s;
    }

    private function hexToAscii(string $hex): string
    {
        $hex = preg_replace('/\s+/', '', $hex);
        // pack with H* returns binary string
        return pack('H*', $hex);
    }

    /*******************************
     * Conectar + Handshake + Login
     *******************************/
    public function connect(): void
    {
        $this->fp = fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$this->fp) {
            throw new Exception("Erro ao conectar: $errstr ($errno)");
        }

        // HANDSHAKE
        list($seq, $hs) = $this->readPacket();
        if ($hs === "") throw new Exception("Handshake vazio");

        $off = 0;
        $protocol = ord($hs[$off++]);

        // Server version
        $server_version = "";
        while ($hs[$off] !== "\0") {
            $server_version .= $hs[$off++];
        }
        $off++;

        $thread_id = unpack("V", substr($hs, $off, 4))[1];
        $off += 4;

        $salt1 = substr($hs, $off, 8);
        $off += 9;

        $cap_low = unpack("v", substr($hs, $off, 2))[1];
        $off += 2;

        $charset = ord($hs[$off++]);
        $status = unpack("v", substr($hs, $off, 2))[1];
        $off += 2;

        $cap_high = unpack("v", substr($hs, $off, 2))[1];
        $off += 2;

        $auth_len = ord($hs[$off++]);
        $off += 10;

        $salt2 = substr($hs, $off, 13);
        $salt2 = rtrim($salt2, "\0");
        $off += strlen($salt2) + 1;

        $salt = substr($salt1 . $salt2, 0, 20);

        // Plugin
        $plugin = "";
        while ($off < strlen($hs) && $hs[$off] !== "\0") {
            $plugin .= $hs[$off++];
        }

        $this->login($salt, $plugin);
    }

    private function login(string $salt, string $plugin): void
    {
        // Flags
        $flags =
            1 | // CLIENT_LONG_PASSWORD
            4 | // CLIENT_LONG_FLAG
            0x0200 | // CLIENT_PROTOCOL_41
            0x8000 | // CLIENT_SECURE_CONNECTION
            0x80000; // CLIENT_PLUGIN_AUTH

        $max_packet = 0x01000000;
        $charset_cli = 0x21;

        $auth = $this->mysql_native_auth($this->pass, $salt);

        $payload = pack("V", $flags);
        $payload .= pack("V", $max_packet);
        $payload .= chr($charset_cli);
        $payload .= str_repeat("\0", 23);
        $payload .= $this->user . "\0";
        $payload .= chr(strlen($auth));
        $payload .= $auth;
        $payload .= "mysql_native_password\0";

        $this->sendPacket(1, $payload);

        list($seq, $resp) = $this->readPacket();

        if ($resp === "" || strlen($resp) === 0) {
            throw new Exception("Resposta vazia no login");
        }

        $first = ord($resp[0]);
        if ($first === 0x00) {
            // OK
            return;
        }
        if ($first === 0xFF) {
            $this->throwErrorFromPacket($resp);
        }

        // other responses (EOF etc) we treat as success
    }

    private function throwErrorFromPacket(string $pkt): void
    {
        // ERR packet format: 0xFF + 2 bytes error code + optional '#' + 5 bytes sqlstate + message
        $code = unpack("v", substr($pkt, 1, 2))[1] ?? 0;
        $msg = '';
        if (isset($pkt[3]) && $pkt[3] === '#') {
            $msg = substr($pkt, 9);
        } else {
            $msg = substr($pkt, 3);
        }
        $msg = $this->sanitizeString($msg);
        throw new Exception("MySQL Error {$code}: {$msg}");
    }

    private function sanitizeString(string $s): string
    {
        // ensure printable
        return preg_replace('/[^\x20-\x7E\s]/', '?', $s);
    }

    /*******************************
     * Parse OK packet (for DML)
     *******************************/
    private function parseOkPacket(string $pkt): array
    {
        // pkt[0] == 0x00
        $off = 1;
        $affected = $this->readLenEncInt($pkt, $off);
        $insertId = $this->readLenEncInt($pkt, $off);

        // status flags (2 bytes) and warnings (2 bytes) may not exist in older protocols,
        // but MariaDB 10+ sends them.
        $status = 0;
        $warnings = 0;
        if ($off + 1 < strlen($pkt)) {
            $status = unpack("v", substr($pkt, $off, 2))[1];
            $off += 2;
        }
        if ($off + 1 < strlen($pkt)) {
            $warnings = unpack("v", substr($pkt, $off, 2))[1];
            $off += 2;
        }

        // optional message
        $message = ($off < strlen($pkt)) ? substr($pkt, $off) : '';

        return [
            'affectedRows' => $affected,
            'lastInsertId' => $insertId,
            'statusFlags' => $status,
            'warnings' => $warnings,
            'message' => $this->sanitizeString($message),
        ];
    }

    /*******************************
     * Public query (SELECT) - returns columns + rows
     *******************************/
    public function query(string $sql): array
    {
        $this->sendPacket(0, chr(0x03) . $sql);

        // read first packet (could be OK/ERR or resultset header)
        list($seq, $pkt) = $this->readPacket();
        if ($pkt === "" || strlen($pkt) === 0) {
            throw new Exception("Resposta vazia do servidor");
        }

        $first = ord($pkt[0]);

        if ($first === 0x00) {
            // OK packet (no rows) — return as execute-like response
            $ok = $this->parseOkPacket($pkt);
            return ['ok' => true, 'okPacket' => $ok];
        }

        if ($first === 0xFF) {
            $this->throwErrorFromPacket($pkt);
        }

        // otherwise it's a Resultset Header: first is column count (len-enc int)
        $off = 0;
        $columnCount = $this->readLenEncInt($pkt, $off);

        // read column packets
        $columns = [];
        for ($i = 0; $i < $columnCount; $i++) {
            list($seqC, $colPkt) = $this->readPacket();
            $offC = 0;

            // catalog, schema, table, org_table
            $catalog = $this->readLenEncString($colPkt, $offC);
            $schema = $this->readLenEncString($colPkt, $offC);
            $table = $this->readLenEncString($colPkt, $offC);
            $orgTable = $this->readLenEncString($colPkt, $offC);
            $name = $this->readLenEncString($colPkt, $offC);
            $orgName = $this->readLenEncString($colPkt, $offC);

            // skip next fixed-length fields (filler, charset, length, type, flags, decimals)
            // to keep it simple, we won't parse them all now
            $columns[] = $name;
        }

        // EOF packet after columns
        list($seqE, $eofPkt) = $this->readPacket();
        if (ord($eofPkt[0]) === 0xFF) $this->throwErrorFromPacket($eofPkt);

        // read rows
        $rows = [];
        while (true) {
            list($seqR, $rowPkt) = $this->readPacket();
            if ($rowPkt === "" || strlen($rowPkt) === 0) break;

            $firstByte = ord($rowPkt[0]);
            // EOF (old) or OK end
            if ($firstByte === 0xFE && strlen($rowPkt) < 9) break;
            if ($firstByte === 0xFF) $this->throwErrorFromPacket($rowPkt);

            $offR = 0;
            $row = [];
            foreach ($columns as $col) {
                $val = $this->readLenEncString($rowPkt, $offR);
                $row[$col] = $val;
            }
            $rows[] = $row;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /*******************************
     * Execute (INSERT/UPDATE/DELETE) - returns affectedRows + lastInsertId
     *******************************/
    public function execute(string $sql): array
    {
        $this->sendPacket(0, chr(0x03) . $sql);

        list($seq, $pkt) = $this->readPacket();
        if ($pkt === "" || strlen($pkt) === 0) {
            throw new Exception("Resposta vazia do servidor");
        }

        $first = ord($pkt[0]);

        if ($first === 0xFF) {
            $this->throwErrorFromPacket($pkt);
        }

        if ($first === 0x00) {
            $ok = $this->parseOkPacket($pkt);
            return $ok;
        }

        // unexpected: a resultset (SELECT) — handle by returning its rows
        return $this->query($sql);
    }

    public function close()
    {
        if ($this->fp) fclose($this->fp);
    }
}
