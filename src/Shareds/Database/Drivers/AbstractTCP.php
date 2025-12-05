<?php

namespace Websyspro\Core\Shareds\Database\Drivers;

use Socket;
use Websyspro\Core\Exceptions\Error;

abstract class AbstractTCP
{
  public Socket $socket;

  public function __construct(
    private string $host,
    private int $port
  ){}

  public function open(
  ): void {
    $this->socket = socket_create(
      AF_INET, SOCK_STREAM, SOL_TCP
    );

    if( $this->socket instanceof Socket === false ){
      Error::internalServerError( "Erro ao criar socket" );
    }

    if( socket_connect( $this->socket, $this->host, $this->port) === false ){
      Error::internalServerError( "Erro ao conectar ao MySQL {$this->host}:{$this->port}" );
    }
  } 

  public function close( 
  ): void {
    socket_close(
      $this->socket
    );
  }
}