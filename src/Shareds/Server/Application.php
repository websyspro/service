<?php

namespace Websyspro\Core\Shareds\Server;

use ReflectionClass;
use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Decorations\Server\Module;

abstract class Application
{
  public function __construct(
    public Collection $modules = new Collection()
  ){
    $this->start();
  }

  public function start(
  ): void {}

  public function getControllers(
    string $findModule
  ): Collection {
    $module = $this->modules->find(fn(string $module) => (
      mb_strtolower( preg_replace( "#Module$#", "", $module )) === 
      mb_strtolower( $findModule )
    ));

    if($module === null){
      return new Collection();
    }

    [ $reflectAttribute ] = (
      new ReflectionClass($module)
    )->getAttributes();

    if($reflectAttribute === null){
      return new Collection();
    }

    $moduleInstance = $reflectAttribute->newInstance();
    if(($moduleInstance instanceof Module) === false){
      return new Collection();
    }

    return new Collection(
      $moduleInstance->controllers
    );
  }
}