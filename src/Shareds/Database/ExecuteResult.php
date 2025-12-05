<?php

namespace Websyspro\Core\Shareds\Database;

class ExecuteResult
{
	public function __construct(
		public int $affectedRows,
		public int|string $lastInsertId
	){}
}