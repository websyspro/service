<?php

namespace Websyspro\Core\Decorations\Server;

use Attribute;
use Websyspro\Core\Enums\Server\RequestMethod;
use Websyspro\Core\Enums\Server\ControllerType;

#[Attribute(Attribute::TARGET_METHOD)]
class Delete extends AbstractEndpoint
{
  public RequestMethod $requestMethod = RequestMethod::Delete;
  public ControllerType $controllerType = ControllerType::Endpoint;
}