<?php

use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Decorations\Server\AllowAnonymous;
use Websyspro\Core\Decorations\Server\Get;
use Websyspro\Core\Decorations\Server\Post;
use Websyspro\Core\Decorations\Server\Controller;
use Websyspro\Core\Decorations\Server\FileValidade;
use Websyspro\Core\Decorations\Server\Authenticate;
use Websyspro\Core\Decorations\Server\Body;
use Websyspro\Core\Decorations\Server\Module;
use Websyspro\Core\Decorations\Server\Param;
use Websyspro\Core\Exceptions\Error;
use Websyspro\Core\Shareds\Server\Api;
use Websyspro\Core\Shareds\Server\Request;
use Websyspro\Core\Shareds\Server\Response;

#[Controller("user")]
#[Authenticate()]
class UserController
{
  public function __construct(
  ){}

  #[Post("{testId:bool}/details")]
  #[AllowAnonymous()]
  public function all(  
    #[Body()] array $testId
  ): Response {
    //Error::unauthorized("badRequest");
    return Response::json(
      $testId
    );
  }
}

#[Controller("perfils")]
#[Authenticate()]
#[FileValidade()]
class PerfilController
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

#[Module(
  controllers: [
    UserController::class,
    PerfilController::class
  ]
)]
class AccountsModule {}

Api::module(
  AccountsModule::class,
);

// $api = (
//   Api::module([
//     AccountsModule::class,
//   ])
// );

// $request = new Request(
//   /*
//   new Collection([
//      Users::class,
//      Perfils::class
//   ]), "api/v1" */
//   $api, "api/v1"
// );