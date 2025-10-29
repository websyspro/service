<?php

namespace Websyspro\Server\Decorations\Server;

use Attribute;
use Websyspro\Server\Enums\Server\ControllerType;
use Websyspro\Server\Enums\Server\MethodType;

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