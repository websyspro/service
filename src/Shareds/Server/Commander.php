<?php

namespace Websyspro\Core\Shareds\Server;

class Commander
{
  public function __construct(
		private Envs $envs
	){
		switch($this->envs->args()->command){
			case "server": $this->server();
				break;
		}
	}

	private function server(
	): void {
		$port = $this->envs->args()->flags["port"] 
			?? getenv("PORT");
		
		exec("php -S localhost:{$port}");
	}
}