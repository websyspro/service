<?php

use Websyspro\Core\Exceptions\Error;
use Websyspro\Core\Shareds\Database\Drivers\MySQL;

abstract class SocketUtils
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
	): MySQLPacketBody {
		while(strlen($data) < $size) {
      $chunk = fread(
				$this->socket, 
				$size - strlen(
					$data
				)
			);

      if( $this->invalidChunck( $chunk )) {
        throw new Exception(
					"Socket closed while reading!"
				);
      }

      $data .= $chunk;
    }

		return new MySQLPacketBody(
			$data
		);
	}

	public function connect(
	): void {
		$this->socket = @fsockopen(
			$this->host,
			$this->port, 
			$this->erron, 
			$this->error,
			$this->timeout
		);

		if( $this->socket ){
			$this->connectAfter();
		}
	}

	abstract public function connectOptios(): MySQLPacketHead;
	abstract public function connectAfter(): void;
	abstract public function connectSession(
		MySQLPacketHead $packetHead
	): void;
}

class MySQLPacketHead
{
	public function __construct(
		public int $sequence,
		public int $size		
	){}

	public function sequenceNext(): int {
		return ++$this->sequence;
	}	
}

class MySQLPacketBody
{
	public function __construct(
		public string $data		
	){}
}

class MySQLPacket
{
	public function __construct(
		public int $sequence,
		public MySQLPacketBody $packetBody
	){}
}

enum MySqlAuthResponse: int
{
	case OK = 0x00;
	case ERR = 0xFF;
	case AUTH_SWITCH_REQUEST = 0xFE;
	case AUTH_MORE_DATA = 0x01;
	case PUBLIC_KEY_REQUEST = 0x02;
	case FAST_AUTH_SUCCESS = 0x03;
	case FULL_AUTH_REQUIRED = 0x04;

	public static function fromByte(
		string $byte
	): MySqlAuthResponse {
		$value = ord($byte);

		return match ($value) {
			0x00 => self::OK,
			0xFF => self::ERR,
			0xFE => self::AUTH_SWITCH_REQUEST,
			0x01 => self::AUTH_MORE_DATA,
			0x02 => self::PUBLIC_KEY_REQUEST,
			0x03 => self::FAST_AUTH_SUCCESS,
			0x04 => self::FULL_AUTH_REQUIRED,

			default => Error::internalServerError(
				"Unknown MySQL login response type: 0x" . dechex($value)
			),
		};
	}
}

enum MySqlAuthMoreData: int {
	case FAST_AUTH_SUCCESS = 0x03;
	case FULL_AUTH_REQUIRED = 0x04;
	case REQUEST_PUBLIC_KEY = 0x02;
	case ADDITIONAL_DATA = 0x01;
	case PUBLIC_KEY_DATA = 0x30;

	public static function fromByte(
		string $byte
	): MySqlAuthMoreData {
		$value = ord($byte);

		return match (true) {
			$value === 0x03 => self::FAST_AUTH_SUCCESS,
			$value === 0x04 => self::FULL_AUTH_REQUIRED,
			$value === 0x02 => self::REQUEST_PUBLIC_KEY,
			$value === 0x01 => self::ADDITIONAL_DATA,
			$value  >= 0x30 => self::PUBLIC_KEY_DATA,

			default => Error::internalServerError(
				"Unknown MySQL AUTH_MORE_DATA subtype: 0x" . dechex($value)
			),
		};
	}
}

class MySQLConnector 
extends SocketUtils 
{
	private string $protocolVersion = "";
	private string $serverVersion = "";
	private string $charset = "";
	private string $plugin = "";
	private string $authPluginSaltRaw = "";
	private int $packetMax = 16777216;
	private bool $connected = false;

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
	): MySQLPacketHead {
		$packetBody = $this->readPacketBody(4);
	
		return new MySQLPacketHead(
			(int)ord($packetBody->data[3]), 
			ord($packetBody->data[0]) 
			    | ord($packetBody->data[1]) << 8 
					| ord($packetBody->data[2]) << 16
		);		
	}

	public function connectOptios(
	): MySQLPacketHead {
		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);

		$this->protocolVersion = ord(
			$packetBody->data[0]
		); $i = 0;

		while($packetBody->data[$i] !== "\0"){
			$this->serverVersion .= trim(
				$packetBody->data[$i]
			); $i++; 
		} $i+=5;

		$salt1 = substr(
			$packetBody->data,
			$i,
			8
		); $i+=9;

		$this->charset = ord(
			$packetBody->data[$i]
		); $i+=8;

		$authLen = ord(
			$packetBody->data[$i]
		); $i+=11;
		
		$salt2 = substr( 
			$packetBody->data,
			$i,
			max(
				13,$authLen-8
			)
		); $i += max(13,$authLen-8);

		$j=$i; 
		while(isset($packetBody->data[$j]) && $packetBody->data[$j] !== "\0"){ 
			$this->plugin .= $packetBody->data[$j]; $j++;
		}

		$this->authPluginSaltRaw = bin2hex(
			$salt1 . rtrim( $salt2, "\x00")
		);

		return $packetHead;
	}

	private function clientAuth() {
    $password1 = hash("sha256", $this->pass, true);
    $password2 = hash("sha256", $password1, true);
    $password3 = hash("sha256", $password2 . $this->authPluginSaltRaw, true);
    return $password1 ^ $password3;
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
		MySQLPacketHead $packetHead,
		MySQLPacketBody $packetBody
	): void {
		$salt = substr(
			$packetBody->data,
			strpos(
				$packetBody->data, 
				"\x00", 
				1
			) + 1
		);

		$scramble = $this->scramble_sha256(
			$this->pass, $salt
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
		MySQLPacketHead $packetHead
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
		if (!$publicKey) {
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

		if(openssl_public_encrypt(
			$xorData,
			$encrypted,
			$publicKey,
			OPENSSL_PKCS1_OAEP_PADDING
		) === false){
			throw new RuntimeException(
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
		MySQLPacketHead $packetHead,
		MySQLPacketBody $packetBody
	): void {
		if( strlen($packetBody->data) < 2 ){
      Error::internalServerError(
				"Pacote AUTH_MORE_DATA inválido: esperado pelo menos 2 bytes."
			);
    }

		$secundByte = MySqlAuthMoreData::fromByte(
			$packetBody->data[1]
		);

		switch( $secundByte ){
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
		MySQLPacketHead $packetHead
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
		MySQLPacketHead $packetHead,
		MySQLPacketBody $packetBody,
	): void {
		switch(MySqlAuthResponse::fromByte($packetBody->data[0])){
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
			"#^8\.*#", $this->serverVersion
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

	private function columnDetail(
		MySQLPacketBody $packetBody,
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

	private function columnValue(
		MySQLPacketBody $packetBody,
		int &$offset
	): string|null {
		return $this->columnDetail($packetBody, $offset);
	}

	public function query(
		string $sql
	): void {
		$payloadBody = chr(0x03) . $sql;
		
		$this->sendPacket(
			$payloadBody,
			0
		);

		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);

		// Verificar se é pacote de erro (0xFF)
		if(ord($packetBody->data[0]) === 0xFF){
			$errorCode = unpack('v', substr($packetBody->data, 1, 2))[1];
			$errorMessage = substr($packetBody->data, 9);
			Error::internalServerError(
				"MySQL Error #{$errorCode}: {$errorMessage}"
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
		
		// Ler pacote EOF após definições de coluna
		$packetHead = $this->readPacketHead();
		$packetBody = $this->readPacketBody(
			$packetHead->size
		);
		
		$rows = [];
		
		// Ler linhas de dados
		while(true){
			$packetHead = $this->readPacketHead();
			$packetBody = $this->readPacketBody(
				$packetHead->size
			);
			
			// Verificar se é pacote EOF (fim dos dados)
			if(ord($packetBody->data[0]) === 0xFE && $packetHead->size < 9){
				break;
			}
			
			$offset = 0;
			$row = [];
			for($c=0; $c<$queryCollumns; $c++){
				$value = $this->columnValue($packetBody, $offset);
				$row[$columns[$c]] = $value;
			}

			$rows[] = $row;
		}
		
		print_r($rows);
	}
}

$mysqlConnector = new MySQLConnector(
	"127.0.0.1",
	3307, 
	"root",
	"qazwsx",
	"test"
);

$startConnect = microtime(true);
$mysqlConnector->connect();
$endConnect = microtime(true);
$connectTime = round(($endConnect - $startConnect) * 1000, 2);

if ($mysqlConnector->isConnected()) {
	echo "✓ Conectado ao MySQL com sucesso em {$connectTime}ms!\n";
	
	$startQuery = microtime(true);
	$mysqlConnector->query("select host, user, plugin, authentication_string from mysql.user");
	$endQuery = microtime(true);
	$queryTime = round(($endQuery - $startQuery) * 1000, 2);
	
	echo "Query executada em {$queryTime}ms\n";
} else {
	echo "✗ Falha na conexão\n";
}