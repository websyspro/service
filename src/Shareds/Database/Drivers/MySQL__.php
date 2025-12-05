<?php

namespace  Websyspro\Core\Shareds\Database\Drivers;

use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Exceptions\Error;

class MySQL extends AbstractTCP
{
  private string $protocolo;
  private string $serverVersion;
  private string $threadId;
  private string $charset;

  public function __construct(
    private string $host,
    private int $port,
    private string $dbname,
    private string $username,
    private string $password
  ){
    parent::__construct(
      $host, 
      $port
    );
  }

  private function sha256(
    string $data
  ): string {
    return hash(
      "sha256", 
      $data,
      true
    );
  }

  private function packetInvalid(
    string $packet
  ): bool {
    return $packet === false || strlen($packet) === 0;
  }

  private function packetSize(
    string $packet
  ): int {
    return ord($packet[0]) 
        | (ord($packet[1]) << 8) 
        | (ord($packet[2]) << 16);
  }

  private function setWritePacket(
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

		socket_write(
			$this->socket, 
			$header . $payload
		);
	}  

  private function getReadPacket(
    string $payload = ""
  ): string {
    $packet = socket_read(
			$this->socket, 
			4
		);
    
    if($this->packetInvalid($packet)) {
      return "";
		}
    
    $packetSize = $this->packetSize(
      $packet
    );

    $sequence = ord(
      $packet[3]
    );

    while(strlen( $payload ) < $packetSize) {
			$chunk = socket_read(
        $this->socket,
        $packetSize - strlen(
          $payload
        )
      );

			if($this->packetInvalid($chunk)) break; 
      $payload .= $chunk;
		}

    return $payload;
  }

  private function generateAuthResponse(
    string $scramble,
    string $password
  ): string {
    $digest1 = hash("sha256", $password, true);
    $digest2 = hash("sha256", $digest1, true);
    $digest3 = hash("sha256", $digest2 . $scramble, true);

    $authResponse = $digest1 ^ $digest3;
    return $authResponse;
  }

  private function login(
    string $scramble,
    string $authPlugin
  ): void {
    $clientFlags   = 0x00008000 | 0x00080000 | 0x8000 | 0x00080000;
    $packetMaxSize = 0x01000000;
    $clientCharset = 0x21;

    $authResponse = $this->generateAuthResponse(
      $scramble, $this->password
    );

    $packet = pack("V", $clientFlags);
    $packet .= pack("V", 16777216); // max packet size
    $packet .= chr(33); // charset
    $packet .= str_repeat("\x00", 23); // filler
    $packet .= $this->username . "\x00";
    $packet .= chr(strlen($authResponse)) . $authResponse;
    $packet .= $this->dbname . "\x00";
    $packet .= $authPlugin . "\x00";

    $len = strlen($packet);
    $header = chr($len & 0xFF) . chr(($len >> 8) & 0xFF) . chr(($len >> 16) & 0xFF);
    $sequence = chr(1);

    socket_write($this->socket, $header . $sequence . $packet);

    $resp = socket_read($this->socket, 2048);

    $type = ord($resp[4]);

    if($type === 0xFE) {
      $scrambleFE = substr($resp, 5, 21);
      $authFE = $this->generateAuthResponse($scrambleFE, $this->password);
      $headerFE = chr(strlen($authFE) & 0xFF) 
                . chr((strlen($authFE) >> 8) & 0xFF) 
                . chr((strlen($authFE) >> 16) & 0xFF);
      $seqFE = chr(ord($resp[3]) + 1);
      socket_write($this->socket, $headerFE . $seqFE . $authFE);
      $resp2 = socket_read($this->socket,1024);

      // Gerou FALSO
      var_dump(ord($resp2[4]) === 0x00); 
    }

    if($type === 0xFF){
      $errorCode = unpack("v", substr($resp, 5, 2))[1];
      $errorMsg  = substr($resp, 9);
      
      Error::internalServerError($errorMsg);
    }
  }

  private function greeting(
    string $plugin = ""
  ): void {
    $greeting = socket_read(
      $this->socket, 2048
    );

    if($greeting === false || strlen($greeting) === 0){
      Error::internalServerError( "Erro ao receber greeting." );
    }

    $payload = substr($greeting, 4);
    $protocol = ord( $payload[0] );

    $cursor = strpos($payload, "\x00", 1);
    $serverVersion = substr($payload, 1, $cursor - 1);

    $offset = $cursor + 1;

    $threadId = unpack("V", substr($payload, $offset, 4))[1];
    $offset += 4;

    $scramble1 = substr($payload, $offset, 8);
    $offset += 8 + 1; // 8 bytes + filler

    $capability1 = unpack("v", substr($payload, $offset, 2))[1]; $offset += 2;
    $charset = ord($payload[$offset]); $offset += 1;
    $statusFlags = unpack("v", substr($payload, $offset, 2))[1]; $offset += 2;
    $capability2 = unpack("v", substr($payload, $offset, 2))[1]; $offset += 2;

    $authLength = ord($payload[$offset]); 
    $offset += 1 + 10; // auth plugin length + reserved

    $scramble2 = substr($payload, $offset, max(13, $authLength - 8));
    $scramble = $scramble1 . $scramble2;

    $offset += strlen($scramble2);

    // Plugin de autenticação
    $pluginEnd = strpos($payload, "\x00", $offset);
    $authPlugin = substr($payload, $offset, $pluginEnd - $offset);

    $this->login(
      $scramble,
      $authPlugin
    );
  }

  public function connect(
  ): bool {
    $this->open();
    $this->greeting();

    return true;
  }
}