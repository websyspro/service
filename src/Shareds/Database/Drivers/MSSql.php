<?php

namespace Websyspro\Core\Shareds\Database\Drivers;

use Socket;
use Websyspro\Core\Exceptions\Error;

class MSSql
{
	private Socket $socket;
	private int $timeout = 5;
	private int|null $erron = null;
	private string|null $error = null;

	public function __construct(
		private string $host,
		private int $port,
		private string $database,
		private string $username,
		private string $password
	){}

	public function buildLogin7($username, $password, $database) {
    // Strings em UTF-16LE
    $user = mb_convert_encoding($username, 'UTF-16LE');
    $pass = mb_convert_encoding($this->xorPassword($password), 'UTF-16LE'); // aplicar XOR
    $db   = mb_convert_encoding($database, 'UTF-16LE');

    // Calcula offsets
    $offsetUser = 86; // exemplo, precisa somar cabeçalho e anteriores
    $offsetPass = $offsetUser + strlen($user);
    $offsetDb   = $offsetPass + strlen($pass);

    // Cabeçalho Login7
    $packet  = pack('V', 0x74000004); // TDS Version 7.4
    $packet .= pack('V', 4096);       // PacketSize
    $packet .= pack('V', 0);          // ClientProgVer
    $packet .= pack('V', 0);          // ClientPID
    $packet .= pack('V', 0);          // ConnectionID
    $packet .= "\x00\x00\x00\x00";    // OptionFlags1
    $packet .= "\x00\x00\x00\x00";    // OptionFlags2
    $packet .= "\x00";                 // TypeFlags
    $packet .= "\x00\x00";             // Reserved

    // Offsets e lengths das strings
    $packet .= pack('v', strlen($user)/2) . pack('v', $offsetUser); // User
    $packet .= pack('v', strlen($pass)/2) . pack('v', $offsetPass); // Password
    $packet .= pack('v', strlen($db)/2)   . pack('v', $offsetDb);   // Database

    // Append strings
    $packet .= $user . $pass . $db;

    // Cabeçalho TDS final
    $tdsPacket = "\x10\x01" . pack('n', strlen($packet)+8) . "\x00\x00\x00\x00" . $packet;

    return $tdsPacket;
	}

	private function xorPassword($pass) {
    $bytes = unpack('C*', $pass);
    foreach ($bytes as &$b) {
        $b = (($b >> 4) | ($b << 4)) ^ 0xA5; // TDS password XOR
    }
    return implode(array_map("chr", $bytes));
	}	

	private function buildPreLogin(
	): string {
		$payload = '';

    // Version
    $versionValue = pack('C4', 0, 15, 0, 0); // ex: 15.0.0.0
    $payload .= "\x00" . pack('n', strlen($payload) + 6) . pack('n', strlen($versionValue));

    // Encryption
    $encryptionValue = "\x00"; // 0 = OFF
    $payload .= "\x01" . pack('n', strlen($payload) + 6) . pack('n', strlen($encryptionValue));

    // Terminator
    $payload .= "\xFF\x00\x00\x00\x00"; // fim da lista de opções

    // Cabeçalho TDS
    $type = "\x12";   // Pre-Login
    $status = "\x01"; // EOM
    $length = pack('n', strlen($payload) + 8);
    $spid = pack('n', 0);
    $packet = $type . $status . $length . $spid . "\x00\x00" . $payload;

    return $packet;		
	}

	public function connect(
	): void {
		$this->socket = socket_create(
			AF_INET, 
			SOCK_STREAM, 
			SOL_TCP
		);

		socket_connect(
			$this->socket, 
			$this->host, 
			$this->port
		);	

		if( !$this->socket ){
			Error::internalServerError(
				"Erro ao conectar: {$this->erron} ({$this->error})"
			);
		}

		$buildPreLogin = $this->buildPreLogin();
		socket_write($this->socket, $buildPreLogin, strlen($buildPreLogin));
		$response = socket_read($this->socket, 1024);

		print_r($response);
	}	

	public function close(
	): void {
		if( is_resource( $this->socket)){
			socket_close( $this->socket);
		}
	}
}
