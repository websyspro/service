<?php

namespace Websyspro\Server\Decorations\Server;

use Attribute;
use Websyspro\Server\Enums\Server\ControllerType;

#[Attribute(Attribute::TARGET_CLASS)]
class Controller
{
  public ControllerType $controllerType = ControllerType::Controller;

  public function __construct(
    public string $name
  ){}
}