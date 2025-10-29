<?php

use Websyspro\Server\commons\Collection;
use Websyspro\Server\Decorations\Server\Authenticate;
use Websyspro\Server\Decorations\Server\Controller;
use Websyspro\Server\Decorations\Server\FileValidade;
use Websyspro\Server\Decorations\Server\Get;
use Websyspro\Server\Decorations\Server\Shareds\StructureController;

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

