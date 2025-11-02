<?php

namespace Websyspro\Core\Decorations\Server;

use Attribute;
use Websyspro\Core\Enums\Server\ControllerType;
use Websyspro\Core\Shareds\Server\Request;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Param extends AbstractParameter
{
  public ControllerType $controllerType = ControllerType::Parameter;

  public function __construct(
    public readonly string | null $key = null
  ){}
  
  public function execute(
    Request $request
  ): mixed {
    return $this->getValue(
      $request->requestData->params, $this->key
    );
  }
}