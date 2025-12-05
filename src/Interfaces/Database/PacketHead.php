<?php

namespace Websyspro\Core\Interfaces\Database;

class PacketHead
{
	public function __construct(
		public int $sequence,
		public int $size		
	){}

	public function sequenceNext(): int {
		return ++$this->sequence;
	}	
}