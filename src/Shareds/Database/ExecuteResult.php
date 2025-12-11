<?php

namespace Websyspro\Core\Shareds\Database;

class ExecuteResult
{
	public function __construct(
		public int $affectedRows,
		public int|string $lastInsertId,
		public string|null $error = null
	){}
	
	public function isError(
	): bool {
		return $this->error !== null;
	}

	public function getError(
	): string {
		return $this->error;
	}
}