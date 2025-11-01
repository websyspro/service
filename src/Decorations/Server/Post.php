<?php

namespace Websyspro\Core\Decorations\Server;

use Attribute;
use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Enums\Server\ControllerType;
use Websyspro\Core\Enums\Server\RequestMethod;

#[Attribute(Attribute::TARGET_METHOD)]
class Post
{
  public RequestMethod $requestMethod = RequestMethod::Post;
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