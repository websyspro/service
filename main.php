<?php

use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Decorations\Server\AllowAnonymous;
use Websyspro\Core\Decorations\Server\Body;
use Websyspro\Core\Decorations\Server\Get;
use Websyspro\Core\Decorations\Server\Post;
use Websyspro\Core\Decorations\Server\Controller;
use Websyspro\Core\Decorations\Server\FileValidade;
use Websyspro\Core\Decorations\Server\Authenticate;
use Websyspro\Core\Decorations\Server\Module;
use Websyspro\Core\Decorations\Server\Param;
use Websyspro\Core\Exceptions\Error;
use Websyspro\Core\Shareds\Server\Application;
use Websyspro\Core\Shareds\Server\Request;
use Websyspro\Core\Shareds\Server\Response;

#[Controller("accounts")]
#[Authenticate()]
class Accounts
{
  public function __construct(
  ){}

  #[Get("user/:test?/details")]
  #[AllowAnonymous()]
  public function all(  
    #[Param()] array $test
  ): Response {
    //Error::unauthorized("badRequest");

    return Response::json(
      $test
    );
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

#[Module(
  controllers: [
    Accounts::class,
    Perfils::class
  ]
)]
class AccountModule {}

// Application::module([
//   AccountModule::class
// ]);

$request = new Request(
  new Collection([
    Accounts::class,
    Perfils::class
  ]), "api/v1", true
);

try {
  exit(
    json_encode(
      $request->getEndpointExecute()->get(),
      JSON_PRETTY_PRINT
    )
  );
} catch (Exception $e) {
  exit(
    json_encode(
      Response::json($e->getMessage(), $e->getCode())->get(),
      JSON_PRETTY_PRINT
    )
  );
}