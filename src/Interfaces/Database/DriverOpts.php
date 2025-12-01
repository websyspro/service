<?php

namespace Websyspro\Core\Interfaces\Database;

class DriverOpts
{
  public function __construct(
    public string $characterSet,
    public string $authPluginName,
    public string $authPluginNameRaw,
    public string $capabilities    
  ){}
}