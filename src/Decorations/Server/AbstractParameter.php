<?php

namespace Websyspro\Core\Decorations\Server;

abstract class AbstractParameter
{
  public function getValue(
    array $requestData,
    string $key
  ): mixed {
    return $requestData;
  }
}