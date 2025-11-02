<?php

namespace Websyspro\Core\Shareds\Server;

class StructureParam
{
  public function __construct(
    public object $instance,
    public string $instanceType
  ){}
}