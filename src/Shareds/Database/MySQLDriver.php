<?php

namespace Websyspro\Core\Shareds\Database;

use Websyspro\Core\Exceptions\Error;
use Websyspro\Core\Interfaces\Database\PacketHead;
use Websyspro\Core\Interfaces\Database\PacketBody;

class MySQLDriver
extends ConnectDriver
{
	public string $protocolVersion = "";
	public string $serverVersion = "";
	public string $charset = "";
	public string $plugin = "";
	public string $authPluginSaltRaw = "";
	public int $packetMax = 16777216;
	public bool $connected = false;

	public function __construct(
		private string $host,
		private int $port, 
		private string $user, 
		private string $pass,
		private string|null $data = null
	){
		parent::__construct(
			$this->host, 
			$this->port
		);
	}

	private function readPacketHead(
	): PacketHead {
		$packetBody = $this->readPacketBody(4);
	
		return new PacketHead(
			(int)ord($packetBody->data[3]), 
			ord($packetBody->data[0]) 
			    | ord($packetBody->data[1]) << 8 
					| ord($packetBody->data[2]) << 16
		);		
	}

	public function connectOptios(
	): PacketHead {
		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);

		$i = 0;
		$this->protocolVersion = ord(
			$packetBody->data[$i++]
		);

		$this->serverVersion = "";
		while($packetBody->data[$i] !== "\0"){
			$this->serverVersion .= $packetBody->data[$i++];
		} $i += 5;

		$salt1 = substr(
			$packetBody->data,
			$i,
			8
		); $i += 11;

		$this->charset = ord(
			$packetBody->data[$i++]
		); $i += 4;

		$authLen = ord(
			$packetBody->data[$i++]
		);

		$i += 10;

		$salt2 = substr(
			$packetBody->data, 
			$i,
			max(
				13,
				$authLen - 8
			)
		); 
		
		$i += max(
			13,
			$authLen - 8
		);

		while($i < strlen($packetBody->data) && $packetBody->data[$i] !== "\0"){
			$this->plugin .= $packetBody->data[$i++];
		}

		$this->authPluginSaltRaw = bin2hex(
			$salt1 . rtrim(
				$salt2,
				"\x00"
			)
		);

		return $packetHead;
	}

	private function clientAuth(
	): string {
		return MySqlAuthPlugin::fromString( $this->plugin )->generateAuth(
			$this->pass, $this->authPluginSaltRaw
		);
	}

	private function clientFlags(
	): int {
		$clientFlags = 0x00000001|0x00000004|0x00000200|0x00008000|0x00080000|0x00200000;
		
		if($this->data){
			$clientFlags |= 0x00000008;
		}

		return $clientFlags;
	}

	public function sendPacket(
		string $payloadBody,
		int $sequence
	): void {
		$payloadLen = strlen(
			$payloadBody
		);

		$payloadHead = chr( $payloadLen & 0xFF ) 
								 . chr( $payloadLen >> 8 & 0xFF ) 
								 . chr( $payloadLen >> 16 & 0xFF ) 
								 . chr( $sequence & 0xFF );

		$this->writePacket(
			$payloadHead . $payloadBody
		);
	}

	public function scramble_sha256(
		string $pass,
		string $salt
	): string {
    if( str_ends_with( $pass, "\0" )) {
      $pass = substr($pass, 0, -1);
    }

    $h1 = hash('sha256', "{$pass}", true);
    $h2 = hash('sha256', "{$h1}", true);
    $h3 = hash('sha256', "{$h2}{$salt}", true);

    return $h1 ^ $h3;
	}	

	public function connectSessionAuthSwitchRequest(
		PacketHead $packetHead,
		PacketBody $packetBody
	): void {
		$scramble = $this->scramble_sha256(
			$this->pass, substr(
				$packetBody->data,
				strpos(
					$packetBody->data, 
					"\x00", 
					1
				) + 1
			)
		);

		$this->sendPacket(
			$scramble,
			$packetHead->sequenceNext()
		);

		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);

		$this->loginResponseValid(
			$packetHead, 
			$packetBody,
		);
	}

	private function connectSessionfullAuthRequired(
		PacketHead $packetHead
	): void {
		$this->sendPacket(
			"\x02",
			$packetHead->sequenceNext()
		);

		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);

		if( ord($packetBody->data[0]) === 0x01 ){
			$publicKeyRaw = substr(
				$packetBody->data, 
				1
			);
		}

		$publicKey = openssl_pkey_get_public($publicKeyRaw);
		if( $publicKey === false ){
			Error::internalServerError(
				"Failed to load public key: " . openssl_error_string()
			);
		}

		$salt = hex2bin( $this->authPluginSaltRaw );
		$passwordWithNull =  "{$this->pass}\0";
		$xorData = $passwordWithNull ^ str_repeat(
			$salt, (int)ceil(
				strlen(
					$passwordWithNull 
				) / strlen( $salt )
			)
		);

		$openSSLPublicEncrypt = openssl_public_encrypt( 
			$xorData, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING
		);

		if( $openSSLPublicEncrypt === false ){
			Error::internalServerError(
				"Falha ao criptografar senha: " . openssl_error_string()
			);
		}

		$this->sendPacket(
			$encrypted,
			$packetHead->sequenceNext(),
		);

		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);

		$this->loginResponseValid(
			$packetHead, 
			$packetBody
		);		
	}

	public function connectSessionAuthMoreData(
		PacketHead $packetHead,
		PacketBody $packetBody
	): void {
		if( strlen($packetBody->data) < 2 ){
      Error::internalServerError(
				"Pacote AUTH_MORE_DATA inválido: esperado pelo menos 2 bytes."
			);
    }

		$mySqlAuthMoreData = MySqlAuthMoreData::fromByte(
			$packetBody->data[1]
		);

		switch( $mySqlAuthMoreData ){
			case MySqlAuthMoreData::FAST_AUTH_SUCCESS:
					$this->connected = true;
				break;
			case MySqlAuthMoreData::FULL_AUTH_REQUIRED:
					$this->connectSessionfullAuthRequired(
						$packetHead
					);
				break;
			case MySqlAuthMoreData::REQUEST_PUBLIC_KEY:
					Error::internalServerError( 
						"MySQL Error: AuthMoreData request public key"
					);
				break;
			case MySqlAuthMoreData::ADDITIONAL_DATA:
					Error::internalServerError( 
						"MySQL Error: AuthMoreData additional data"
					);
				break;
			case MySqlAuthMoreData::PUBLIC_KEY_DATA:
					Error::internalServerError(
						"MySQL Error: AuthMoreData public key data"
					);
				break;
		}
	}

	public function connectSession(
		PacketHead $packetHead
	): void {
		$payloadBody  = pack("V", $this->clientFlags());
    $payloadBody .= pack("V", $this->packetMax);
    $payloadBody .= chr($this->charset);
    $payloadBody .= str_repeat("\0", 23);
    $payloadBody .= $this->user . "\0";
    $payloadBody .= chr(
			strlen($this->clientAuth())
		) . $this->clientAuth();
    
		if($this->data !== ""){
			$payloadBody .= "{$this->data}\0";
		}

    $payloadBody .= "{$this->plugin}\0";

		$this->sendPacket(
			$payloadBody, 
			$packetHead->sequenceNext()
		);

		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);

		$this->loginResponseValid(
			$packetHead, 
			$packetBody
		);
	}

	private function loginResponseValid(
		PacketHead $packetHead,
		PacketBody $packetBody,
	): void {
		$mysqlAuthResponse = MySqlAuthResponse::fromByte(
			$packetBody->data[0]
		);

		if($mysqlAuthResponse === MySqlAuthResponse::ERR){
			Error::internalServerError(
				substr(
					$packetBody->data, 
					9
				)
			);
		}

		switch($mysqlAuthResponse){
			case MySqlAuthResponse::OK:
					$this->connected = true;
				break;
			case MySqlAuthResponse::AUTH_SWITCH_REQUEST:
					$this->connectSessionAuthSwitchRequest(
						$packetHead, 
						$packetBody
					);
				break;
			case MySqlAuthResponse::AUTH_MORE_DATA:
					$this->connectSessionAuthMoreData(
						$packetHead, 
						$packetBody
					);
				break;	
			case MySqlAuthResponse::FULL_AUTH_REQUIRED:
					$this->connectSessionfullAuthRequired(
						$packetHead
					);
				break;
		}
	}

	private function isNotValidVersion(
	): bool {
		return $this->protocolVersion !== "" && preg_match(
			"#^[5-9]\.*#", $this->serverVersion
		) === 0;
	}

	public function connectAfter(
	): void {
		$packetHead = $this->connectOptios();
		if($this->isNotValidVersion()){
			Error::internalServerError( 
				"MySQL version {$this->serverVersion} not compatible"
			);
		}

		$this->connectSession(
			$packetHead
		);
	}

	public function isConnected(
	): bool {
		return $this->connected;
	}

	public function startTransaction(): void {
		$this->execute( "start transaction" );
	}

	public function commit(): void {
		$this->execute( "commit" );
	}

	public function rollback(): void {
		$this->execute( "rollback" );
	}

	public function execute(
		string $sql,
		array $prepareds = []
	): ExecuteResult {
		if (!empty($prepareds)) {
			$sql = $this->preparedParams(
				$sql, $prepareds
			);
		}
		
		$payloadBody = chr(
			0x03
		) . $sql;
		
		$this->sendPacket(
			$payloadBody,
			0
		);

		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);

		if(ord( $packetBody->data[0]) === 0xFF ){
			Error::internalServerError(
				substr(
					$packetBody->data, 
					9
				)
			);
		}

		if(ord($packetBody->data[0]) === 0x00){
			$offset = 1;
			
			return new ExecuteResult(
				$this->readLengthEncodedInteger( $packetBody->data, $offset),
				$this->readLengthEncodedInteger( $packetBody->data, $offset)
			);
		}
		
		return new ExecuteResult(
			0, 
			0
		);
	}

	private function columnDetail(
		PacketBody $packetBody,
		int &$offset	
	): string|null {
		$firstByte = ord(
			$packetBody->data[$offset]
		);

		if( $firstByte === 0xFB ){
			$offset += 1;
			return null;			
		} 

		if( $firstByte < 0xFB ){
			$length = $firstByte;
			$offset += 1;
    } else
		if( $firstByte === 0xFC ){
        [, $length] = unpack(
					'v', 
					substr(
						$packetBody->data,
						$offset + 1, 
						2
					)
				); $offset += 3;
    } else
		if( $firstByte === 0xFD ){
				[, $length ] = unpack(
					'V',
					substr(
						$packetBody->data . "\0", 
						$offset + 1,
						3
					)
				) & 0xFFFFFF; $offset += 4;
    } else
		if( $firstByte === 0xFE ) {
        [, $length] = unpack(
					'P',
					substr(
						$packetBody->data,
						$offset + 1,
						8
					)
				); $offset += 9;
    } else {
			Error::internalServerError(
				"Byte inesperado na leitura length-encoded string: 0x" . dechex($firstByte)
			);
    }

    $columnValue = substr(
			$packetBody->data, 
			$offset, 
			$length
		);

    $offset += $length;
    return $columnValue;
	}	

	public function query(
		string $sql,
		array $prepareds = []
	): QueryResult {
		if (!empty($prepareds)) {
			$sql = $this->preparedParams(
				$sql, $prepareds
			);
		}
		
		$payloadBody = chr(0x03) . $sql;
		
		$this->sendPacket(
			$payloadBody,
			0
		);

		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);

		if(ord($packetBody->data[0]) === 0xFF){
			return new QueryResult(
				0, [], substr(
					$packetBody->data, 
					9
				)
			);
		}

		$queryCollumns = (int)ord(
			$packetBody->data
		);
		
		$columns = [];
		for($c=0; $c<$queryCollumns; $c++){
			$packetHead = $this->readPacketHead();
			$packetBody = $this->readPacketBody(
				$packetHead->size
			);

			$offset = 0;
			$catalog = $this->columnDetail($packetBody, $offset);
			$schema = $this->columnDetail($packetBody, $offset);
			$table = $this->columnDetail($packetBody, $offset);
			$orgTable = $this->columnDetail($packetBody, $offset);
			$name = $this->columnDetail($packetBody, $offset);
			$orgName = $this->columnDetail($packetBody, $offset);
			
			$columns[] = $name;
		}
		
		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);
		
		$rows = [];
		
		while(true){
			$packetHead = $this->readPacketHead();
			$packetBody = $this->readPacketBody(
				$packetHead->size
			);

			$hasPacketEOF = ord($packetBody->data[0]) 
				 					=== 0xFE && $packetHead->size < 9;
			
			if( $hasPacketEOF ){
				break;
			}
			
			$offset = 0;
			$row = [];

			for($c=0; $c<$queryCollumns; $c++){
				$row[$columns[$c]] = $this->columnDetail(
					$packetBody, 
					$offset
				);
			}

			$rows[] = $row;
		}
		
		return new QueryResult(
			sizeof(
				$rows
			), $rows
		);
	}

	private function preparedParams(
		string $sql,
		array $params
	): string {
		foreach ($params as $param) {
			if (is_string($param)) {
				$escaped = addslashes($param);
				$sql = preg_replace(
					'/\?/', 
					"'$escaped'", 
					$sql, 
					1
				);
			} else
			if (is_int( $param ) || is_float( $param )){
				$sql = preg_replace(
					'/\?/', 
					$param,
					$sql,
					1
				);
			} else
			if (is_null($param)) {
				$sql = preg_replace(
					'/\?/', 
					'NULL', 
					$sql, 
					1
				);
			}
		}
		
		return $sql;
	}
	private function readLengthEncodedInteger(
		string $data,
		int &$offset
	): int {
		$firstByte = ord($data[$offset]);
		
		if($firstByte < 0xFB){
			$offset++;
			return $firstByte;
		}
		
		if($firstByte === 0xFC){
			$value = unpack(
				'v',
				substr(
					$data,
					$offset + 1,
					2
				)
			)[1]; $offset += 3;
			return $value;
		}
		
		if($firstByte === 0xFD){
			$value = unpack(
				'V',
				substr(
					$data . "\0",
					$offset + 1,
					3
				)
			)[1] & 0xFFFFFF; $offset += 4;
			return $value;
		}
		
		if($firstByte === 0xFE){
			$value = unpack(
				'P', 
				substr(
					$data,
					$offset + 1,
					8
				)
			)[1]; $offset += 9;
			return $value;
		}
		
		return 0;
	}
}