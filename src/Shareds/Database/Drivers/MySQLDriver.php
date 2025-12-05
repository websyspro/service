<?php

namespace Websyspro\Core\Shareds\Database\Drivers;

use Exception;
use Websyspro\Core\Interfaces\Server\Database\MySQLPacket;

class MySQLDriver
{
	public mixed $socket;
	public int|null $erron = null;
	public string|null $error = null;

	private int $timeOut = 5;

	public function __construct(
		private string $host,
		private string $port,
		private string $user,
		private string $pass,
		private string|null $data = null
	){}

	private function createSocket(		
	): mixed {
		$this->socket = @fsockopen(
			$this->host,
			$this->port,
			$this->erron,
			$this->error,
			$this->timeOut
		);

		return $this->socket;
	}

	private function readExact(
		int $size,
		string $data = "" 
	): string {
		while( strlen($data) < $size ) {
			$chunk = fread(
				$this->socket, 
				$size - strlen($data)
			);

			print_r($chunk);

			if($chunk === false || $chunk === ""){
				throw new Exception( "Socket closed while reading!" );
			}
			
			$data .= $chunk;
    }

    return $data;
	}

	private function packetSize(
		string $packet
	): int {
		return ord($packet[0]) 
			   | ord($packet[1]) << 8 
				 | ord($packet[2]) << 16;
	}

	private function packetSequence(
		string $packet
	): int {
		return \ord(
			$packet[3]
		);
	}

	private function readPacket(
	): MySQLPacket {
    $packet = $this->readExact( 4 );
		$size = $this->packetSize( $packet );
		$sequence = $this->packetSequence( $packet );
    $payload = $this->readExact( $size );
		
		return new MySQLPacket( 
			$sequence, 
			$payload
		);
	}

	private function handshake(
	): MySQLPacket {
		return $this->readPacket();
	}

	public function connect(
	): bool {
		if($this->createSocket() === false){
			return false;
		}

		print_r($this->handshake());

		return true;
	}
}