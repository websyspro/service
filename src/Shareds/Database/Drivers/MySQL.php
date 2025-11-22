<?php

namespace Websyspro\Core\Shareds\Database\Drivers;

use Websyspro\Core\Exceptions\Error;

class MySQL extends AbstractTCP
{
    private int $sequence = 0;
    private bool $debug = false;

    public function __construct(
        private string $host,
        private int $port,
        private string $dbname,
        private string $username,
        private string $password
    ) {
        parent::__construct($host, $port);
    }

    /** DEBUG */
    private function dbg(string $label, string $bin): void {
        if (!$this->debug) return;
        echo "=== $label ===\n";
        echo "len: " . strlen($bin) . "\n";
        echo bin2hex($bin) . "\n\n";
    }

    /** Lê packet MySQL */
    private function readPacket(): string
    {
        $hdr = '';
        while (strlen($hdr) < 4) {
            $buf = socket_read($this->socket, 4 - strlen($hdr));
            if ($buf === false || $buf === '') {
                Error::internalServerError("Erro lendo header do socket.");
            }
            $hdr .= $buf;
        }

        $len = ord($hdr[0]) | (ord($hdr[1]) << 8) | (ord($hdr[2]) << 16);
        $seq = ord($hdr[3]);
        $this->sequence = $seq;

        $payload = '';
        while (strlen($payload) < $len) {
            $chunk = socket_read($this->socket, $len - strlen($payload));
            if ($chunk === false || $chunk === '') {
                Error::internalServerError("Erro lendo payload do socket.");
            }
            $payload .= $chunk;
        }

        $full = $hdr . $payload;
        $this->dbg("RECEIVED packet (seq={$seq})", $full);
        return $full;
    }

    /** Envia packet MySQL */
    private function writePacket(string $payload, int $sequence): void
    {
        $len = strlen($payload);

        $header =
            chr($len & 0xFF) .
            chr(($len >> 8) & 0xFF) .
            chr(($len >> 16) & 0xFF) .
            chr($sequence);

        $full = $header . $payload;
        $this->dbg("SENDING packet (seq={$sequence})", $full);

        if (socket_write($this->socket, $full) === false) {
            Error::internalServerError("Erro ao escrever no socket.");
        }
    }

    private function sha256bin(string $d): string {
        return hash("sha256", $d, true);
    }

    private function authCachingSha2(string $pwd, string $scramble): string
    {
        $d1 = $this->sha256bin($pwd);
        $d2 = $this->sha256bin($d1);
        $d3 = $this->sha256bin($d2 . $scramble);
        return $d1 ^ $d3;
    }

    /** Parse handshake/greeting */
    private function parseGreeting(string $packet): array
    {
        $p = substr($packet, 4);

        $protocol = ord($p[0]);
        $pos = strpos($p, "\x00", 1);
        $serverVersion = substr($p, 1, $pos - 1);
        $offset = $pos + 1;

        $connectionId = unpack("V", substr($p, $offset, 4))[1];
        $offset += 4;

        $scr1 = substr($p, $offset, 8);
        $offset += 8;

        $offset += 1; // filler

        $cap1 = unpack("v", substr($p, $offset, 2))[1];
        $offset += 2;

        $charset = ord($p[$offset]);
        $offset += 1;

        $status = unpack("v", substr($p, $offset, 2))[1];
        $offset += 2;

        $cap2 = unpack("v", substr($p, $offset, 2))[1];
        $offset += 2;

        $caps = $cap1 | ($cap2 << 16);

        $authLen = ord($p[$offset]);
        $offset += 1;

        $offset += 10; // reserved

        $scr2 = substr($p, $offset, max(0, $authLen - 8));
        $scr = $scr1 . $scr2;

        $offset += strlen($scr2);

        $pluginEnd = strpos($p, "\x00", $offset);
        $plugin = substr($p, $offset, $pluginEnd - $offset);

        return [
            "protocol" => $protocol,
            "serverVersion" => $serverVersion,
            "connectionId" => $connectionId,
            "scramble" => $scr,
            "authPlugin" => $plugin,
            "capabilities" => $caps,
            "charset" => $charset
        ];
    }

    /**
     * 🔐 Converte a chave enviada pelo servidor em PEM válido.
     * Esta é a versão CORRETA que funciona no OpenSSL 3.x e PHP 8.2–8.4.
     */
    private function parseServerPublicKey(string $packet): string
    {
        $payload = substr($packet, 4);
        $payload = trim($payload, "\0\r\n ");

        // SE já houver delimitadores PEM → normaliza e retorna
        if (strpos($payload, "-----BEGIN PUBLIC KEY-----") !== false) {

            $lines = preg_split("/\r\n|\n|\r/", $payload);
            $pem = implode("\n", $lines);

            if (!str_ends_with($pem, "\n")) {
                $pem .= "\n";
            }

            return $pem;
        }

        /**
         * SE não houver PEM → o servidor enviou a chave em RAW (DER puro).
         * MySQL 8 pode fazer isso. Precisamos converter manualmente.
         */

        // muitos servidores enviam um padding estranho, vamos limpar:
        $payload = trim($payload, "\0");

        // base64 → PEM
        $b64 = base64_encode($payload);
        $b64 = chunk_split($b64, 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n" .
               $b64 .
               "-----END PUBLIC KEY-----\n";
    }

    /** 🔐 Criptografia RSA com OAEP */
    private function encryptPasswordRSA(string $password, string $scramble, string $publicPem): string
    {
        // RECONSTRUÇÃO COMPLETA DO PEM
        $raw = str_replace(["\r", "\n"], "", $publicPem);
        $raw = str_replace(
            ["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----"],
            "",
            $raw
        );

        $raw = trim($raw);

        $pem =
            "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split($raw, 64, "\n") .
            "-----END PUBLIC KEY-----\n";

        // Carregar chave pública com OpenSSL 3.x
        $pub = openssl_pkey_get_public($pem);

        if ($pub === false) {
            $err = openssl_error_string();
            Error::internalServerError("Falha ao carregar chave pública do servidor: $err");
        }

        // XOR da senha + NUL com scramble
        $msg = $password . "\0";
        $result = "";

        $scrLen = strlen($scramble);
        for ($i = 0; $i < strlen($msg); $i++) {
            $result .= chr(ord($msg[$i]) ^ ord($scramble[$i % $scrLen]));
        }

        // Encriptar com OAEP (obrigatório para MySQL 8)
        $encrypted = "";
        $ok = openssl_public_encrypt($result, $encrypted, $pub, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$ok) {
            $err = openssl_error_string();
            Error::internalServerError("Falha ao cifrar senha RSA: $err");
        }

        return $encrypted;
    }

    /** 🔥 Conecta e Autentica */
    public function connectAndLogin(bool $debug = false)
    {
        $this->debug = $debug;
        $this->open();

        $pkt = $this->readPacket();
        $hello = $this->parseGreeting($pkt);

        /** CLIENT FLAGS */
        $CLIENT_LONG_PASSWORD     = 0x00000001;
        $CLIENT_PROTOCOL_41       = 0x00000200;
        $CLIENT_SECURE_CONNECTION = 0x00008000;
        $CLIENT_PLUGIN_AUTH       = 0x00080000;
        $CLIENT_CONNECT_WITH_DB   = 0x00000008;

        $flags =
            $CLIENT_LONG_PASSWORD |
            $CLIENT_PROTOCOL_41 |
            $CLIENT_SECURE_CONNECTION |
            $CLIENT_PLUGIN_AUTH |
            $CLIENT_CONNECT_WITH_DB;

        $payload = "";

        $payload .= pack("V", $flags);
        $payload .= pack("V", 0x01000000);
        $payload .= chr(0x21);
        $payload .= str_repeat("\0", 23);

        $payload .= $this->username . "\0";

        $auth = $this->authCachingSha2($this->password, $hello['scramble']);
        $payload .= chr(strlen($auth)) . $auth;

        $payload .= $this->dbname . "\0";
        $payload .= $hello["authPlugin"] . "\0";

        $this->writePacket($payload, 1);

        $resp = $this->readPacket();
        $type = ord($resp[4]);

        if ($type === 0x00) { return true; } // OK
        if ($type === 0x02) { return true; } // Fast auth

        if ($type === 0xFF) {
            $err = unpack("v", substr($resp, 5, 2))[1];
            $msg = substr($resp, 9);
            Error::internalServerError("MySQL ERR: #$err - $msg");
        }

        if ($type === 0x01) {
            // Full auth (RSA)
            $seq = ord($resp[3]) + 1;
            $this->writePacket("\x02", $seq);

            $pubPkt = $this->readPacket();

            $pem = $this->parseServerPublicKey($pubPkt);

            $encrypted = $this->encryptPasswordRSA(
                $this->password,
                $hello['scramble'],
                $pem
            );

            $next = ord($pubPkt[3]) + 1;
            $this->writePacket($encrypted, $next);

            $final = $this->readPacket();

            if (ord($final[4]) === 0x00) return true;

            Error::internalServerError("Falha ao finalizar RSA auth.");
        }

        Error::internalServerError("Pacote inesperado no handshake: 0x" . dechex($type));
    }
}
