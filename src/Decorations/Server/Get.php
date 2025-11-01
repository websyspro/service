<?php

namespace Websyspro\Core\Decorations\Server;

use Attribute;
use Websyspro\Core\Enums\Server\ControllerType;
use Websyspro\Core\Enums\Server\MethodType;

#[Attribute(Attribute::TARGET_METHOD)]
class Get
{
  public MethodType $methodType = MethodType::Get;
  public ControllerType $controllerType = ControllerType::Endpoint;

  public function __construct(
    public string $descriptor
  ){
    $this->descriptor = preg_replace(
      "#(^/)|(/$)#", "", $this->descriptor
    );
  }
}