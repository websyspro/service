<?php

use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Decorations\Server\Get;
use Websyspro\Core\Decorations\Server\Controller;
use Websyspro\Core\Decorations\Server\FileValidade;
use Websyspro\Core\Decorations\Server\Authenticate;
use Websyspro\Core\Shareds\Server\StructureController;

#[Controller("account")]
class Accounts {}

#[Controller("perfils")]
#[Authenticate()]
#[FileValidade()]
class Perfils
{
  public function __construct(
  ){}

  #[Get("list/get/{productId}")]
  public function all(    
  ): array {
    return [];
  }
}

$collection = new Collection([
  Accounts::class,
  Perfils::class
]);

$collection = $collection->mapper(fn(string $class) => new StructureController($class));

print_r($collection);