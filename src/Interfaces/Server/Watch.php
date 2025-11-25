<?php

namespace Websyspro\Core\Interfaces\Server;

class Watch
{
  public function __construct(
    public string $hash,
    public string $timestemp,
    public string $file
  ){}
}