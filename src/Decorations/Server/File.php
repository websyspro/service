<?php

namespace Websyspro\Core\Decorations\Server;

use Attribute;
use Websyspro\Core\Enums\Server\ControllerType;
use Websyspro\Core\Shareds\Server\Request;

#[Attribute(Attribute::TARGET_PARAMETER)]
class File extends AbstractParameter
{
  public ControllerType $controllerType = ControllerType::Parameter;

  public function __construct(
    public readonly string|null $key = null
  ){}
  
  public function execute(
    Request $request,
    string $instanceType
  ): mixed {
    return $this->getValue(
      $request->requestData->files, 
      $instanceType, 
      $this->key
    );
  }
}