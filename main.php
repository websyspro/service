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
use Websyspro\Core\Enums\Database\ConnectionType;
use Websyspro\Core\Exceptions\Error;
use Websyspro\Core\Shareds\Database\Connection;
use Websyspro\Core\Shareds\Database\Drivers\MSSql;
use Websyspro\Core\Shareds\Database\Drivers\MySQL;
use Websyspro\Core\Shareds\Server\Api;
use Websyspro\Core\Shareds\Server\Request;
use Websyspro\Core\Shareds\Server\Response;
use Websyspro\Core\Shareds\Server\Tcp;

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

/*
Api::module(
  AccountsModule::class,
); */


/*
$connection = new Connection(
  "localhost", 
  3306, 
  "edocente", 
  "root", 
  "", 
  ConnectionType::MySQL
); */

// $res = $connection->query("SELECT id, post_name FROM edocente.wp_posts limit 16");
// print_r($res);

try {

    $mysql = new MySQL(
        host: "localhost",
        port: 3306,
        dbname: "edocente",       // coloque um nome de database que existe
        username: "root",
        password: "@Qazwsx190483"
    );

    $ok = $mysql->connectAndLogin(true); // TRUE = mostrar DEBUG HEX

    if ($ok) {
        echo "🔥 LOGIN REALIZADO COM SUCESSO!\n";
    } else {
        echo "❌ Falhou sem exception (algo inesperado)\n";
    }

} catch (Exception $e) {
    echo "❌ ERRO:::: " . $e->getMessage() . "\n";
}

/*
$mssql = new MSSql(
  "localhost",
  1433,
  "test",
  "sa",
  "@Qazwsx190483"  
);

$mssql->connect(); */

///$tcp = new Tcp("localhost", 3306);
//$tcp->open();

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