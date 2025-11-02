<?php

use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Decorations\Server\AllowAnonymous;
use Websyspro\Core\Decorations\Server\Get;
use Websyspro\Core\Decorations\Server\Post;
use Websyspro\Core\Decorations\Server\Controller;
use Websyspro\Core\Decorations\Server\FileValidade;
use Websyspro\Core\Decorations\Server\Authenticate;
use Websyspro\Core\Decorations\Server\Param;
use Websyspro\Core\Shareds\Server\Request;

#[Controller("accounts")]
#[Authenticate()]
class Accounts
{
  public function __construct(
  ){}

  #[Post(":test?/user/details")]
  #[AllowAnonymous()]
  public function all(  
    #[Param("test")] string $test
  ): array {
    return [ "fafsdafsda" ];
  }
}

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

  #[Post("list/products")]
  public function products(    
  ): array {
    return [];
  }  
}

$request = new Request(
  new Collection([
    Accounts::class,
    Perfils::class
  ]), "api/v1"
);

print_r($request->getEndpointExecute());

//print_r($request);