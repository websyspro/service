<?php

namespace Websyspro\Core\Shareds\Server;

use Exception;
use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Enums\Server\LoggerType;

class Api extends Application
{
  private Request $request;
  private Envs $envs;

  public function start(
  ): void {
    $this->startEnvs();
    $this->startApi();
  }

  public function startEnvs(
  ): void {
    $this->envs = new Envs();
    $this->envs->start();
  }

  public function startApi(
  ): void {
    php_sapi_name() === "cli" 
      ? $this->startApiClient()
      : $this->startApiServer();
  }

  public function startApiClient(
  ): void {
    Logger::message(
      LoggerType::context, 
      getenv("API")
    );
  }

  public function startApiServer(
  ): void {
    $this->request = new Request(
      $this, 
      "api/v1"
    );

    try {
      Response::send(
        $this->request
          ->getEndpointExecute()
          ->get()
      );
    } catch (Exception $error) {
      Response::send(
        Response::json(
          $error->getMessage(), 
          $error->getCode()
        )->get()
      );
    }
  }

  public static function module(
    mixed ...$modules
  ): Api {
    return new static(
      new Collection(
        $modules
      )
    );
  }
}