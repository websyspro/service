<?php

namespace Websyspro\Core\Shareds\Server;

use Websyspro\Core\Commons\Collection;

class Api extends Application
{
  public static function module(
    array $modules = []
  ): Api {
    return new static(
      new Collection($modules)
    );
  }
}