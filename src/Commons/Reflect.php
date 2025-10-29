<?php

namespace Websyspro\Server\Commons;

use ReflectionClass;

class Reflect
{
  public function __construct(
    private object|string $class
  ){
    $this->class = new ReflectionClass($class);
  }

  public function getAttributes(
    string|null $attribute = null
  ): mixed {
    return $this->class->getAttributes($attribute);
  }

  public function getMethods(): array {
    return $this->class->getMethods();
  }
}