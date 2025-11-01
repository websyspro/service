<?php

namespace Websyspro\Core\Decorations\Server;

use Attribute;
use Websyspro\Core\Enums\Server\ControllerType;

#[Attribute(Attribute::TARGET_CLASS)]
class FileValidade
{
  public ControllerType $controllerType = ControllerType::Middleware;

  public function __construct(
  ){}
}