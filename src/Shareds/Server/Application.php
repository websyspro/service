<?php

namespace Websyspro\Core\Shareds\Server;

use Websyspro\Core\Commons\Collection;

class Application
{
  public function __construct(
    public Collection $modules = new Collection()
  ){}

  public static function module(
    array $modules = []
  ): Application {
    return new static(
      modules: new Collection($modules)
    );
  }
}