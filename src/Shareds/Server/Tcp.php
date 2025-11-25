<?php

namespace Websyspro\Core\Shareds\Server;

use Socket;
use Websyspro\Core\Exceptions\Error;

class Tcp
{
	private Socket $socket;

  public function __construct(
		private string $host,
		private int $port
	){}

	private function create(
	): void {
		$this->socket = socket_create(
			AF_INET, 
			SOCK_STREAM, 
			SOL_TCP
		);
		
		$connected = socket_connect(
			$this->socket, 
			$this->host, 
			$this->port
		);

		if ($connected === false) {
			Error::internalServerError(
				"Erro ao conectars: {$this->host}:{$this->port}"
			);
		}
	}

	public function open(
	): void {
		$this->create();
		$this->send();
	}

	public function close(
	): void {}

	public function send(
		string|null $data = null,
		string $response = ""
	): string {
		socket_write(
			$this->socket,
			$data, 
			strlen(
				$data
			)
		);

		while (true) {
			$chuck = socket_read(
				$this->socket,
				1024
			);

			if($chuck === false || $chuck === ""){
				break;
			};

			var_dump($chuck);

			$response .= $chuck;
		}

		return $response;
	}
}