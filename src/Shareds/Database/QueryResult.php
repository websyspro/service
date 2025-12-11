<?php

namespace Websyspro\Core\Shareds\Database;

class QueryResult
{
	public function __construct(
		public int $rowsCount,
		public array $rows,
    public string|null $error = null
	){}

	public function exists(
	): bool {
		return $this->rowsCount !== 0;
	}
}