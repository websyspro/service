<?php

namespace Websyspro\Core\Decorations\Server;

use Attribute;
use Websyspro\Core\Enums\Server\ControllerType;

#[Attribute(Attribute::TARGET_CLASS)]
class Controller
{
  public ControllerType $controllerType = ControllerType::Controller;

  public function __construct(
    public string $name
  ){}
}