<?php

namespace Websyspro\Core\Shareds\Server;

use ReflectionAttribute;
use Websyspro\Core\commons\Collection;
use Websyspro\Core\Enums\Server\ControllerType;
use Websyspro\Core\Enums\Server\RequestMethod;

class StructureRoute
extends AbstractStructure
{
  public Collection $endpoints;
  public Collection $middlewares;
  public RequestMethod $requestMethod;

  public function start(
  ): void {
    $this->startEndpoint();
  }

  private function startEndpoint(
  ): void {
    $methodAttributes = $this->attributes
      ->where(fn(ReflectionAttribute $reflectionAttribute) => $this->isControllerType($reflectionAttribute, ControllerType::Endpoint))
      ->mapper(fn(ReflectionAttribute $reflectionAttribute) => $this->createInstance($reflectionAttribute));

    if($methodAttributes->exist()){
      $this->requestMethod = $methodAttributes->first()->getRequestMethod();
      $this->endpoints = $methodAttributes->first()->getEndpoints();
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