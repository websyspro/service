<?php

namespace Websyspro\Core\Shareds\Server;

use ReflectionAttribute;
use ReflectionMethod;
use Websyspro\Core\commons\Collection;
use Websyspro\Core\Decorations\Server\Get;
use Websyspro\Core\Enums\Server\ControllerType;
use Websyspro\Core\Enums\Server\MethodType;

class StructureRoute
{
  public string $descriptor;
  public MethodType $methodType;
  public Collection $middlewares;

  public function __construct(
    public ReflectionMethod $method
  ){
    $this->initial();
    $this->initialRoute();
  }

  private function initial(
  ): void {}

  private function initialRoute(
  ): void {
    $collectionRoute = new Collection(
      $this->method->getAttributes()
    );

    if($collectionRoute->exist()){
      $collectionRoute = $collectionRoute->mapper(fn(ReflectionAttribute $reflectionAttribute) => $reflectionAttribute->newInstance());
      $collectionRoute = $collectionRoute->where(fn(Get $entpoint) => $entpoint->controllerType === ControllerType::Endpoint);
      
      if($collectionRoute->exist()){
        $this->descriptor = $collectionRoute->first()->descriptor;
        $this->methodType = $collectionRoute->first()->methodType;
      }
    }
  }
}