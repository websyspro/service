<?php

namespace Websyspro\Core\Interfaces\Database;

class PacketResult
{
  public function __construct(
    public int $sequence,
    public string $payload
  ){}
}