<?php
$user = "sa";
$pass = "@Qazwsx190483";
$data = "test";

function createHeader(
	int $length, 
	int $type
): string {
	return join(
		"", 
		[
			chr( $type ),
			chr( 0x01 ),
			pack( "n", $length + 8 ),
			pack( "n", 0 ),
			chr( 0 ),
			chr( 0 )
		]
	);
}

function createPreLogin(	
): string {
	$versionData = "\x00\x00\x00\x00\x00\x00";
	$encryptData = "\x02";
	$offsetVersion = 0x0008;
	$offsetEncrypt = $offsetVersion + strlen($versionData);

	$header = join(
		"", 
		[
			"\x00", 
			pack( "n", $offsetVersion ),
			pack( "n", strlen( $versionData )),			
			"\x01",
			pack( "n", $offsetEncrypt ),
			pack( "n", strlen( $encryptData )),
			"\xFF"		
		]
	);

	return join(
		"", 
		[
			$header,
			$versionData,
			$encryptData
		]
	);
}

function parsePrelogin(
	string $bin
): array {
	$offset = 0;
	$header = [
		"type" => ord( $bin[$offset++] ),
		"status" => ord( $bin[$offset++] ),
		"length" => unpack( "n", substr($bin, $offset, 2) )[1],
		"spid" => unpack( "n", substr($bin, $offset + 2, 2) )[1],
		"packet_id" => ord( $bin[$offset + 4] ),
		"window" => ord( $bin[$offset + 5] ),
	];
	
	$offset += 6;

	$options = [];
	while (true)
	{
		$token = ord(
			$bin[
				$offset++
			]
		);

		if( $token === 0xff ) {
			break;
		};

		$optOffset = unpack( 
			"n",
			substr(
				$bin,
				$offset,
				2
			)
		)[1];

		$optLength = unpack(
			"n",
			substr(
				$bin,
				$offset + 2,
				2
			)
		)[1];
		
		$offset += 4;
		$options[] = [
			"token"  => $token,
			"offset" => $optOffset,
			"length" => $optLength
		];
	}

	$dataStart = 8;
	$data = [
		"header" => $header
	];

	foreach( $options as $opt )
	{
		$pos = $dataStart + $opt["offset"];
		if( $opt[ "token" ] === 0x00 ){
			$data[ "version" ] = join( ".", [
				ord( $bin[$pos] ),
				ord( $bin[$pos + 1] ),
				unpack( "n", substr($bin, $pos + 2, 2))[1],
				unpack( "n", substr($bin, $pos + 4, 2))[1],
			]);
		}
		if( $opt["token"] === 0x01 ){
			$map = [
				0x00 => "ENCRYPT_OFF",
				0x01 => "ENCRYPT_ON",
				0x02 => "ENCRYPT_NOT_SUP",
				0x03 => "ENCRYPT_REQ"
			];

			$data[ "encryption" ] = $map[
				ord($bin[$pos])
			] ?? "UNKNOWN";
		}
	}

	return $data;
}

function createSocket(
  string $host_name,
	int $port,
	bool $use_tls = false,
	string $error_code = "",
	string $error_message = ""
): array {
	$socket = @fsockopen(
		"tcp://{$host_name}", 
		$port, 
		$error_code, 
		$error_message, 
		5
	);

	if( !$socket ){
		return [
			"error_code" => $error_code,
			"error_message" => $error_message
		];
	}

	$createPreLogin = createPreLogin();
	$createHeader = createHeader(
		strlen(
			$createPreLogin
		), 
		0x12
	);

	fwrite(
		$socket,
		join(
			"", [
				$createHeader,
				$createPreLogin
			]
		)
	);

	$packetPreLogin = fread(
		$socket,
		4096
	);
	
	$server = parsePrelogin($packetPreLogin);
	
	if($use_tls) {
		echo "\n=== Iniciando TLS Handshake ===\n";
		$result = stream_socket_enable_crypto(
			$socket,
			true,
			STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
		);
		
		if($result === true) {
			echo "TLS estabelecido com sucesso!\n";
			$server['tls'] = 'enabled';
		} else {
			echo "Falha ao estabelecer TLS\n";
			$server['tls'] = 'failed';
		}
	}

	return [
		"socket" => $socket,
		"server" => $server
	];
}

echo "=== Teste SEM TLS ===\n";
$createSocket = createSocket(
	"localhost", 
	1433,
	false
);
print_r($createSocket);

echo "\n\n=== Teste COM TLS ===\n";
$createSocketTLS = createSocket(
	"localhost", 
	1433,
	true
);
print_r($createSocketTLS);