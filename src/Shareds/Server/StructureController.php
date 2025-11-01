<?php

namespace Websyspro\Core\Shareds\Server;

use ReflectionMethod;
use ReflectionAttribute;
use Websyspro\Core\commons\Collection;
use Websyspro\Core\Commons\Reflect;
use Websyspro\Core\Decorations\Server\Controller;
use Websyspro\Core\Enums\Server\ControllerType;
use Websyspro\Core\Shareds\Server\StructureRoute;

class StructureController
{
  public string $name;
  public Reflect $reflect;
  public Collection $middlewares;
  public Collection $endpoints;

  public function __construct(
    public string $class
  ){
    $this->initial();
    $this->initialController();
    $this->initialMiddlewares();
    $this->initialEndpoints();
    $this->initialClear();
  }

  private function initial(
  ): void {
    $this->reflect = new Reflect($this->class);
  }

  private function initialController(
  ): void {
    $collectionController = new Collection(
      $this->reflect->getAttributes(Controller::class)
    );

    if($collectionController->exist()){
      if($collectionController->first()->newInstance() instanceof Controller){
        $this->name = $collectionController->first()->newInstance()->name;
      }
    }
  }

  private function initialMiddlewares(
  ): void {
    $this->middlewares = new Collection(
      $this->reflect->getAttributes()
    );

    if($this->middlewares->exist()){
      $this->middlewares = $this->middlewares->where(
        fn(ReflectionAttribute $reflectionAttribute) => (
          $reflectionAttribute->newInstance()->controllerType === ControllerType::Middleware
        )
      );
    }
  }

  private function initialEndpoints(   
  ): void {
    $this->endpoints = new Collection(
      $this->reflect->getMethods()
    );

    if($this->endpoints->exist()){
      $this->endpoints = $this->endpoints->where(fn(ReflectionMethod $method) => $method->getName() !== "__construct");
      $this->endpoints = $this->endpoints->mapper(fn(ReflectionMethod $method) => new StructureRoute($method));
    }
  }

  private function initialClear(
  ): void {
    unset($this->reflect);
  }
}