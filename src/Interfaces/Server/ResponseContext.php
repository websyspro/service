<?php

namespace Websyspro\Core\Interfaces\Server;

class ResponseContext
{
  public function __construct(
    public bool $success,
    public mixed $content
  ){}
}