<?php

namespace Websyspro\Core\Shareds\Server;

use ReflectionMethod;
use Websyspro\Core\commons\Collection;

class StructureController
extends AbstractStructure
{
  public string $name;
  public Collection $middlewares;
  public Collection $endpoints;

  public function start(
  ): void {
    $this->startController();
    $this->startEndpoints();
  }

  private function startController(
  ): void {
    $this->name = $this->attributes->first()->newInstance()->name;
  }

  private function startEndpoints(   
  ): void {
    $this->endpoints = $this->getMethods()
      ->where(fn(ReflectionMethod $method) => $this->isValidMethod($method))
      ->mapper(fn(ReflectionMethod $method) => $this->structureRoute($method));
  }

  public function isValid(
    Request $request 
  ): bool {
    return $this->name === $request->controller;
  }
}