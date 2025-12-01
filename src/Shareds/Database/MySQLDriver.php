<?php

namespace Websyspro\Core\Shareds\Database;

use Websyspro\Core\Interfaces\Database\DriverOpts;
use Websyspro\Core\Interfaces\Database\PacketResult;

class MySQLDriver 
extends AbstractDriver
{
  private DriverOpts $driverOpts;

  public function __construct(
    private string $host, 
    private int $port,
    private string $user,
    private string $pass,
    private string $data
  ){
    return parent::__construct(
      $host, $port
    );
  }

  public function readFull(
  ): PacketResult {
    $readPartial = $this->readPartial(4);

    $length = ord($readPartial[0]) 
            | ord($readPartial[1]) << 8
            | ord($readPartial[2]) << 16;

    $sequence = ord($readPartial[3]);
    $payload = $this->readPartial($length);
    
    return new PacketResult(
      $sequence, 
      $payload
    );
  }

  private function createDriverOpts(
  ): void {
    $this->readFull();

    $characterSet = "";
    $authPluginName = "";
    $authPluginNameRaw = "";
    $capabilities = "";    

    $this->driverOpts = new DriverOpts(
      "",
      "",
      "",
      ""
    );
  }

  public function connect(    
  ): bool {
    $this->createSocket();
    $this->createDriverOpts();

    $readFull = $this->readFull();

    print_r($readFull);

    return false;
  }
}