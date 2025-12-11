<?php

namespace Websyspro\Core\Shareds\Database;

use Websyspro\Core\Exceptions\Error;
use Websyspro\Core\Shareds\Database\ConnectResult;
use Websyspro\Core\Interfaces\Database\PacketBody;
use Websyspro\Core\Interfaces\Database\PacketHead;

abstract class ConnectDriver
{
	private mixed $socket;
	private int|null $erron = null;
	private string|null $error = null;
	private int $timeout = 5;

	public function __construct(
		private string $host,
		private int $port
	){}

	private function invalidChunck(
		string $chunk
	): bool {
		return $chunk === false || $chunk === "";
	}

	public function writePacket(
		string $payload
	): int|bool {
		return fwrite( $this->socket, $payload);
	}

	public function readPacketBody(
		string $size,
		string $data = ""
	): PacketBody {
		while(strlen($data) < $size) {
      $chunk = fread(
				$this->socket, 
				$size - strlen(
					$data
				)
			);

      if( $this->invalidChunck( $chunk )) {
        Error::internalServerError(
					"Socket closed while reading!"
				);
      }

      $data .= $chunk;
    }

		return new PacketBody(
			$data
		);
	}

	public function connect(
	): ConnectResult {
		$this->socket = @fsockopen(
			$this->host,
			$this->port, 
			$this->erron, 
			$this->error,
			$this->timeout
		);

		if( $this->socket ){
			return $this->connectAfter();
		} else {
			return new ConnectResult(
				false, $this->error
			);
		}
	}

	abstract public function connectOptios(
	): PacketHead;

	abstract public function connectAfter(		
	): ConnectResult;
	
	abstract public function connectSession(
		PacketHead $packetHead
	): ConnectResult;
}