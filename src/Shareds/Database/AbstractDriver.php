<?php

namespace Websyspro\Core\Shareds\Database;

use Websyspro\Core\Interfaces\Database\PacketResult;
use Websyspro\Core\Exceptions\Error;
use Exception;

abstract class AbstractDriver
{
  private mixed $socket;
  private int|null $errno = null;
  private string|null $error = null;
  private int $timeout = 5;

  public function __construct(
    private string $host,
    private int $port
  ){}

  public function createSocket(
  ): void {
    $this->socket = @fsockopen(
      $this->host, 
      $this->port, 
      $this->errno, 
      $this->error, 
      $this->timeout
    );
  }
  
  public function readPartial(
    int $length,
    string $chunks = ""
  ): string {
    while (strlen($chunks) < $length) {
      $chunk = fread(
        $this->socket,
        $length - strlen(
          $chunks
        )
      );

      if($chunk === false || $chunk === "") {
        Error::internalServerError(
          "Socket closed while reading!"
        );
      }
      
      $chunks .= $chunk;
    }
    return $chunks;
  }

  abstract public function readFull(
  ): PacketResult;

  abstract public function connect(
  ): bool;
}