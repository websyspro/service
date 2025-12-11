<?php

namespace Websyspro\Core\Shareds\Database;

class MariaDBDriver
extends MySQLDriver
{
	protected function isNotValidVersion(
	): bool {
		return $this->protocolVersion !== "" && preg_match(
			"#(10.11.)|(11.8.)|(12.0.)#", 
      $this->serverVersion
		) === 0;
	}  
}