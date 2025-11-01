<?php

namespace Websyspro\Core\Decorations\Server;

use Attribute;
use Websyspro\Core\Enums\Server\ControllerType;
use Websyspro\Core\Shareds\Server\Request;

#[Attribute(Attribute::TARGET_METHOD)]
class AllowAnonymous
{
  public ControllerType $controllerType = ControllerType::Middleware;

  public function execute(
    Request $request
  ): void {}
}