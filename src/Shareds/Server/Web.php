<?php

namespace Websyspro\Core\Shareds\Server;

use Websyspro\Core\Commons\Collection;

class Web extends Application
{
  public static function module(
    array $modules = []
  ): Web {
    return new static(
      new Collection($modules)
    );
  }
}