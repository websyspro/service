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

enum MySqlAuthPlugin: string {
	case MYSQL_NATIVE_PASSWORD = "mysql_native_password";
	case CACHING_SHA2_PASSWORD = "caching_sha2_password";

	public static function fromString(
		string $pluginName
	): MySqlAuthPlugin {
		return match ($pluginName) {
			"mysql_native_password" => self::MYSQL_NATIVE_PASSWORD,
			"caching_sha2_password" => self::CACHING_SHA2_PASSWORD,
			default => Error::internalServerError(
				"Unsupported MySQL auth plugin: {$pluginName}"
			),
		};
	}

	public function generateAuth(
		string $password,
		string $salt
	): string {
		return match ($this) {
			self::MYSQL_NATIVE_PASSWORD => $this->nativePasswordAuth($password, $salt),
			self::CACHING_SHA2_PASSWORD => $this->sha2PasswordAuth($password, $salt),
		};
	}

	private function nativePasswordAuth(
		string $password,
		string $salt
	): string {
		$saltBin = hex2bin($salt);
		$hash1 = sha1($password, true);
		$hash2 = sha1($hash1, true);
		$hash3 = sha1($saltBin . $hash2, true);
		return $hash1 ^ $hash3;
	}

	private function sha2PasswordAuth(
		string $password,
		string $salt
	): string {
		$password1 = hash("sha256", $password, true);
		$password2 = hash("sha256", $password1, true);
		$password3 = hash("sha256", $password2 . $salt, true);
		return $password1 ^ $password3;
	}
}

class ExecuteResult
{
	public function __construct(
		public int $affectedRows,
		public int|string $lastInsertId
	){}
}

class QueryResult
{
	public function __construct(
		public int $rowsCount,
		public array $rows
	){}	
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
		MySQLPacketHead $packetHead,
		MySQLPacketBody $packetBody
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
		MySQLPacketHead $packetHead,
		MySQLPacketBody $packetBody
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

	public function execute(
		string $sql
	): ExecuteResult {
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

	public function query(
		string $sql
	): QueryResult {
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
			Error::internalServerError(
				substr(
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

$mysqlConnector = new MySQLConnector(
	"localhost",
	3306, 
	"root",
	"@Qazwsx190483",
	"edocente"
);

$startConnect = microtime(true);
$mysqlConnector->connect();
$endConnect = microtime(true);

$connectTime = round(($endConnect - $startConnect) * 1000, 2);

if ($mysqlConnector->isConnected()) {
	echo "✓ Conectado ao MySQL com sucesso em {$connectTime}ms!\n";
	
	// Teste SELECT
	$startQuery = microtime(true);
	$queryResult = $mysqlConnector->query("select ID as post_ID, post_title from wp_posts limit 100");
	print_r(	$queryResult);
	$endQuery = microtime(true);
	$queryTime = round(($endQuery - $startQuery) * 1000, 2);
	echo "Query executada em {$queryTime}ms\n";
	
	// Teste INSERT
	/*
	$startExecute = microtime(true);
	$result = $mysqlConnector->execute("INSERT INTO wp_posts (post_title, post_content, post_status, post_excerpt) VALUES ('Teste', 'Conteúdo teste', 'draft', '')");
	$endExecute = microtime(true);
	$executeTime = round(($endExecute - $startExecute) * 1000, 2);
	*/

	//echo "Execute executado em {$executeTime}ms\n";
	//echo "Linhas afetadas: {$result['affected_rows']}\n";
	//echo "Last Insert ID: {$result['last_insert_id']}\n";
	
} else {
	echo "✗ Falha na conexão\n";
}