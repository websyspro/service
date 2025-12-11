<?php

namespace Websyspro\Core\Shareds\Database;

class ConnectResult
{
  public function __construct(
    public bool $connected = false,
    public string|null $error = null,
  ){}
}