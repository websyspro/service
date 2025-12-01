<?php

namespace Websyspro\Core\Interfaces\Server\Database;

class MySQLPacket
{
  public function __construct(
		public int $sequence,
		public string $payload
	){}
}