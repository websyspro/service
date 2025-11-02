<?php

namespace Websyspro\Core\Decorations\Server;

use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Enums\Server\RequestMethod;
use Websyspro\Core\Enums\Server\ControllerType;

abstract class AbstractEndpoint
{
  public RequestMethod $requestMethod = RequestMethod::Get;
  public ControllerType $controllerType = ControllerType::Endpoint;

  public function __construct(
    public string $descriptor
  ){
    $this->descriptor = preg_replace(
      "#(^/)|(/$)#", "", $this->descriptor
    );
  }

  public function getEndpoints(
  ): Collection {
    return new Collection(
      preg_split("#/#", $this->descriptor)
    );
  }

  public function getRequestMethod(
  ): RequestMethod {
    return $this->requestMethod;
  }   
}