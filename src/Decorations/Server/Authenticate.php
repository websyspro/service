<?php

namespace Websyspro\Server\Decorations\Server;

use Attribute;
use Websyspro\Server\Enums\Server\ControllerType;

#[Attribute(Attribute::TARGET_CLASS)]
class Authenticate
{
  public ControllerType $controllerType = ControllerType::Middleware;

  public function __construct(
  ){}
}