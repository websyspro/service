<?php

namespace Websyspro\Core\Shareds\Server;

use ReflectionMethod;
use ReflectionAttribute;
use Websyspro\Core\commons\Collection;
use Websyspro\Core\Enums\Server\ControllerType;
use Websyspro\Core\Enums\Server\RequestMethod;

class StructureRoute
{
  public Collection $endpoints;
  public Collection $middlewares;
  public RequestMethod $requestMethod;

  public function __construct(
    public ReflectionMethod $method
  ){
    $this->initialRoute();
  }

  private function initialRoute(
  ): void {
    $collectionRoute = new Collection(
      $this->method->getAttributes()
    );

    if($collectionRoute->exist()){
      $collectionRoute = $collectionRoute->mapper(fn(ReflectionAttribute $reflectionAttribute) => $reflectionAttribute->newInstance());
      $collectionRoute = $collectionRoute->where(fn(mixed $entpoint) => $entpoint->controllerType === ControllerType::Endpoint);
      
      if($collectionRoute->exist()){
        $this->endpoints = $collectionRoute->first()->getEndpoints();
        $this->requestMethod = $collectionRoute->first()->getRequestMethod();
      }
    }
  }

  private function validRequestMethod(
    Request $request
  ): bool {
    return $this->requestMethod === $request->requestMethod;
  }

  private function validRequestPaths(
    Request $request
  ): bool {
    $paths = $this->endpoints->mapper(
      function(string $path, int $i) use($request){
        $hasParams = (bool)preg_match(
          "#(^\{.*\}$)|(^\{.*\}\?$)|(^:.*)|(^:.*\?$)#", $path
        );

        if($hasParams === true){
          return $hasParams;
        }
        
        return $path === $request->endpoints->eq($i)->first();
      }
    );

    return $paths->where(
      fn(bool $val) => $val === false
    )->exist() === false;
  }

  public function valid(
    Request $request
  ): bool {
    return $this->validRequestMethod($request)
        && $this->validRequestPaths($request);
  }  
}