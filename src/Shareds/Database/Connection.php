<?php

namespace Websyspro\Core\Shareds\Database;

use Exception;
use Websyspro\Core\Enums\Database\ConnectionType;

class Connection
{
	private int|null $erron = null;
	private string|null $error = null;
	private mixed $socketResource;
	private mixed $socketTimeOut = 5;

  public function __construct(
		private string $host,
		private int $port,
		private string $database,	
		private string $username,
		private string $password,
		private ConnectionType $connectionType
	){
		$this->setOpenConnection();
	}

	private function setOpenConnection(
	): void {
		if($this->connectionType === ConnectionType::MySQL){
			$this->socketResource = stream_socket_client(
				"tcp://{$this->host}:{$this->port}", 
				$this->erron,
				$this->error,
				$this->socketTimeOut, 
				STREAM_CLIENT_CONNECT
			);

			[ $_, $handShake ] = $this->getReadPacket();

			$offSet = 0;
			$protocol = ord(
				$handShake[
					$offSet++
				]
			);

			$serverVersion = "";
			while($handShake[$offSet] !== "\0"){
				$serverVersion .= $handShake[
					$offSet++
				];
			}

			$offSet++;

			[ , $threadId ] = unpack(
				"V", 
				substr(
					$handShake, 
					$offSet, 
					4
				)
			);

			$offSet += 4;

			$saltFirst = substr(
				$handShake, 
				$offSet, 8
			);

			$offSet += 9;

			[ , $capLow ] = unpack(
				"v", 
				substr(
					$handShake, 
					$offSet, 
					2
				)
			);

			$offSet += 2;

			$charset = ord(
				$handShake[
					$offSet++
				]
			);

			[ , $status ] = unpack(
				"v", 
				substr(
					$handShake, 
					$offSet, 
					2
				)
			);
			
			$offSet += 2;

			[ , $capHigh ] = unpack(
				"v", 
				substr(
					$handShake, 
					$offSet, 
					2
				)
			);
			
			$offSet += 2;

			$authLen = ord(
				$handShake[
					$offSet++
				]
			);

			$offSet += 10;

			$saltSecund = substr($handShake, $offSet, 13);
			$saltSecund = rtrim($saltSecund, "\0");
			$offSet += strlen($saltSecund) + 1;

			$saltEnd = substr(
				$saltFirst . $saltSecund, 
				0, 20
			);

			$plugin = "";
			while ($offSet < strlen($handShake) && $handShake[$offSet] !== "\0") {
					$plugin .= $handShake[$offSet++];
			}

			$this->login(
				$saltEnd, 
				$plugin
			);
		}
	}

	private function mysql_native_auth(
		string $password, 
		string $saltEnd
	): string {
		if ($password === "") return "";
		$s1 = sha1($password, true);
		$s2 = sha1($s1, true);
		$scr = sha1($saltEnd . $s2, true);
		return $s1 ^ $scr;
	}
	
	private function setSendPacket(
		int $sequence, 
		string $payload
	): void {
		$len = strlen(
			$payload
		);

		$header =
				chr($len & 0xFF) .
				chr(($len >> 8) & 0xFF) .
				chr(($len >> 16) & 0xFF) .
				chr($sequence);

		fwrite(
			$this->socketResource, 
			$header . $payload
		);
	}	

	private function login(
		string $saltEnd, 
		string $plugin
	): void {
		$flags =
				1 | // CLIENT_LONG_PASSWORD
				4 | // CLIENT_LONG_FLAG
				0x0200 | // CLIENT_PROTOCOL_41
				0x8000 | // CLIENT_SECURE_CONNECTION
				0x80000; // CLIENT_PLUGIN_AUTH

		$max_packet = 0x01000000;
		$charset_cli = 0x21;

		$auth = $this->mysql_native_auth($this->password, $saltEnd);

		$payload = pack("V", $flags);
		$payload .= pack("V", $max_packet);
		$payload .= chr($charset_cli);
		$payload .= str_repeat("\0", 23);
		$payload .= $this->username . "\0";
		$payload .= chr(strlen($auth));
		$payload .= $auth;
		$payload .= "mysql_native_password\0";

		$this->setSendPacket(
			1, 
			$payload
		);

		[ $_, $response ] = $this->getReadPacket();

		if ($response === "" || strlen($response) === 0) {
			throw new Exception(
				"Resposta vazia no login"
			);
		}

		$first = ord($response[0]);
		if($first === 0x00){
			// OK
			return;
		}
		if ($first === 0xFF) {
			//$this->throwErrorFromPacket($response);
		}

		// other responses (EOF etc) we treat as success
	}	

	public function getReadPacket(
	): array {
		$header = fread(
			$this->socketResource, 
			4
		);

		if($header === false || strlen($header) < 4) {
			return [0, ""];
		}

		$length = (
			 ord($header[0]) |
      (ord($header[1]) << 8) |
      (ord($header[2]) << 16)
		);

		$sequence = ord($header[3]);

		$payload = "";
		while (strlen($payload) < $length) {
				$chunk = fread($this->socketResource, $length - strlen($payload));
				if($chunk === false || $chunk === "") break;
				$payload .= $chunk;
		}

		return [$sequence, $payload];
	}

	private function readLenEncInt(
		string $data, int &$off
	): int {
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
	
	private function sanitizeString(
		string $subject
	): string {
		return preg_replace(
			"#[^\x20-\x7E\s]#", 
			"?", 
			$subject
		);
	}	

	private function parseOkPacket(
		string $packet
	): array {
			// pkt[0] == 0x00
			$offset = 1;
			$affected = $this->readLenEncInt($packet, $offset);
			$insertId = $this->readLenEncInt($packet, $offset);

			// status flags (2 bytes) and warnings (2 bytes) may not exist in older protocols,
			// but MariaDB 10+ sends them.
			$status = 0;
			$warnings = 0;
			if ($offset + 1 < strlen($packet)) {
					$status = unpack("v", substr($packet, $offset, 2))[1];
					$offset += 2;
			}
			if ($offset + 1 < strlen($packet)) {
					$warnings = unpack("v", substr($packet, $offset, 2))[1];
					$offset += 2;
			}

			// optional message
			$message = ($offset < strlen($packet)) ? substr($packet, $offset) : '';

			return [
					'affectedRows' => $affected,
					'lastInsertId' => $insertId,
					'statusFlags' => $status,
					'warnings' => $warnings,
					'message' => $this->sanitizeString($message),
			];
	}

	private function readLenEncString(
		string $data, 
		int &$offSet
	): string|null {
		$c = ord($data[$offSet]);
		if ($c === 0xFB) {
				$offSet;
				return null;
		}
		$len = $this->readLenEncInt($data, $offSet);
		$s = substr($data, $offSet, $len);
		$offSet += $len;
		return $s;
	}

	public function query(
		string $query
	): array {
		$this->setSendPacket(
			0, 
			chr(0x03) . $query
		);

		// read first packet (could be OK/ERR or resultset header)
		[ $_, $packet ] = $this->getReadPacket();
		if ($packet === "" || strlen($packet) === 0) {
				throw new Exception("Resposta vazia do servidor");
		}

		$first = ord(
			$packet[0]
		);

		if( $first === 0x00 ) {
			$ok = $this->parseOkPacket($packet);
			return ['ok' => true, 'okPacket' => $ok];
		}

		if($first === 0xFF) {
			//$this->throwErrorFromPacket($pkt);
		}

		// otherwise it's a Resultset Header: first is column count (len-enc int)
		$off = 0;
		$columnCount = $this->readLenEncInt($packet, $off);

		// read column packets
		$columns = [];
		for ($i = 0; $i < $columnCount; $i++) {
				[ $_, $packetColumn ] = $this->getReadPacket();
				$offSetColumn = 0;

				// catalog, schema, table, org_table
				$catalog = $this->readLenEncString($packetColumn, $offSetColumn);
				$schema = $this->readLenEncString($packetColumn, $offSetColumn);
				$table = $this->readLenEncString($packetColumn, $offSetColumn);
				$orgTable = $this->readLenEncString($packetColumn, $offSetColumn);
				$name = $this->readLenEncString($packetColumn, $offSetColumn);
				$orgName = $this->readLenEncString($packetColumn, $offSetColumn);

				// skip next fixed-length fields (filler, charset, length, type, flags, decimals)
				// to keep it simple, we won't parse them all now
				$columns[] = $name;
		}

		// EOF packet after columns
		list($seqE, $eofPkt) = $this->getReadPacket();
		if (ord($eofPkt[0]) === 0xFF){
			//$this->throwErrorFromPacket($eofPkt);
		}

		// read rows
		$rows = [];
		while (true) {
				list($seqR, $rowPkt) = $this->getReadPacket();
				if ($rowPkt === "" || strlen($rowPkt) === 0) break;

				$firstByte = ord($rowPkt[0]);
				// EOF (old) or OK end
				if ($firstByte === 0xFE && strlen($rowPkt) < 9) break;
				//if ($firstByte === 0xFF) $this->throwErrorFromPacket($rowPkt);

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
}