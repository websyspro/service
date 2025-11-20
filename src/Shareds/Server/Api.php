<?php

namespace Websyspro\Core\Shareds\Server;

use Exception;
use ReflectionClass;
use ReflectionAttribute;
use ReflectionClassConstant;
use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Decorations\Server\Module;
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

  private function parseModule(
    string $module
  ): string {
    return preg_replace( 
      "#Module$#",
      "",
      $module
    );
  }

  private function parseController(
    string $controller
  ): string {
    return preg_replace( 
      "#Controller$#",
      "",
      $controller
    );
  }

  private function loggerMapModule(
    string $module
  ): void {
    Logger::message(
      LoggerType::controller, 
      sprintf( 
        "Map module %s", 
        $this->parseModule( $module )
      )
    );   
  }

  private function loggerMapControllerFromModule(
    string $module, string $controller
  ): void {
    Logger::message(
      LoggerType::controller, 
      sprintf( 
        "Map %s controller from module %s", ...[
          $this->parseController($controller), 
          $this->parseModule( $module )
        ]
      )
    );
  }

  private function loggerEndpointsFromController(
    StructureController $structureController,
    string $module, 
    string $controller
  ): void {
    $this->loggerMapControllerFromModule(
      $module, $controller
    );

    $structureController->endpoints->mapper(
      function(StructureRoute $structureRoute){
        Logger::message(
          LoggerType::controller, 
          sprintf( 
            "Map route {%s, %s}", ...[
              $structureRoute->requestMethod->value,
              $structureRoute->endpoints->join(DIRECTORY_SEPARATOR)
            ]
          )
        );        
      }
    );
  }

  public function startApiClient(
  ): void {
    $this->modules->mapper(
      function( string $module ) {
        $reflectionClass = new ReflectionClass(
          $module
        );

        $this->loggerMapModule( $module );

        $getAttributes = new Collection(
          $reflectionClass->getAttributes(
            Module::class
          )
        );
        
        $getAttributes = $getAttributes->mapper(
          function( ReflectionAttribute $reflectionAttribute ) use ($module) {
            $controllers = new Collection(
              $reflectionAttribute
                ->newInstance()
                ->controllers
            );

            $controllers->mapper(
              fn(string $controller) => (
                $this->loggerEndpointsFromController(
                  new StructureController(
                    new ReflectionClass($controller)
                  ), $module, $controller
                )
              )
            );
          }
        );
      }
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