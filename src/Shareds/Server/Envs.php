<?php

namespace Websyspro\Core\Shareds\Server;

use Websyspro\Core\Commons\Collection;

class Envs
{
  private Collection $envs;

  public function dropComments(
    string $env
  ): bool {
    return preg_match(
      "#(\#|;)#",
      $env
    ) === 0;
  }

  public function dropEmptyLine(
    string $env
  ): bool {
    return empty( 
      trim( $env)
    ) === false;
  }

  public function splitEnv(
    string $env
  ): array {
    return explode(
      "=", 
      $env
    );
  }

  public function putEnv(
    string $env
  ): void {
    [$key, $value] = $this->splitEnv($env);

    putenv( sprintf(
      "%s=%s", ... [
        trim( $key), 
        trim( $value, " \t\n\r\0\x0B\"'" )
      ]
    ));
  }

  public function start(
  ) {
    if( defined("rootDir") === true){
      $this->envs = new Collection(
        file( 
          sprintf( 
            "%s%s.env", 
            rootDir, DIRECTORY_SEPARATOR
          )
        )
      );

      $this->envs = $this->envs
        ->where(fn(string $env) => $this->dropComments( $env))
        ->where(fn(string $env) => $this->dropEmptyLine( $env))
        ->mapper(fn(string $env) => $this->putEnv( $env));
    }
  }
}