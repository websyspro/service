<?php

use Websyspro\Core\Exceptions\Error;
use Websyspro\Core\Interfaces\Database\PacketBody;
use Websyspro\Core\Interfaces\Database\PacketHead;
use Websyspro\Core\Shareds\Database\ConnectDriver;
use Websyspro\Core\Shareds\Database\ConnectResult;

class SqlServerDriver
extends ConnectDriver
{
	public function __construct(
		private string $host,
		private int $port, 
		private string $user, 
		private string $pass,
		private string $data		
	){
		parent::__construct(
			$this->host, 
			$this->port
		);		
	}

	private function invalidChunck(string $chunk): bool {
		return $chunk === false || $chunk === "";
	}

	private function readPacketHead(): PacketHead {
		$packetBody = $this->readPacketBody(8);
	
		$size = (ord($packetBody->data[2]) << 8) | ord($packetBody->data[3]);
		$sequence = ord($packetBody->data[7]);
	
		return new PacketHead(
			$sequence,
			$size
		);
	}

	public function readPacketBody(
		int $size,
		string $data = ""
		): PacketBody {
		while(strlen($data) < $size) {
      $chunk = fread(
				$this->getSocket(), 
				$size - strlen($data)
			);

      if($this->invalidChunck($chunk)) {
        Error::internalServerError(
					"Socket closed while reading!"
				);
      }

      $data .= $chunk;
    }

		return new PacketBody($data);
	}

	public function connectOptios(
	): PacketHead {
		return new PacketHead(
			0, 0
		);
	}

	public function connectAfter(		
	): ConnectResult {
		$packetHead = $this->connectOptios();		
		return new ConnectResult();
	}
	
	public function connectSession(
		PacketHead $packetHead
	): ConnectResult{
		return new ConnectResult();
	}
}

$conn = new SqlServerDriver(
	"localhost",
	1433,
	"sa",
	"@Qazwsx190483",
	"pnld_crm_api_production"
);

$conn->connect();

print_r($conn);
