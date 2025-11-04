<?php

namespace Websyspro\Core\Decorations\Server;

use Websyspro\Core\Commons\Utils;

abstract class AbstractParameter
{
  public function getValue(
    array $dataValue,
    string $instanceType,
    string|null $key
  ): mixed {
    if(is_array($dataValue)){
      if(is_null($key) === false){
        if(isset($dataValue[$key])){
          if(Utils::isPrimitiveType($instanceType)){
            return $dataValue[$key];
          } else {
            return Utils::hydrateObject(
              $dataValue[$key], $instanceType
            );
          }
        } else return null;
      }

      if(Utils::isPrimitiveType($instanceType)){
        return $dataValue;
      } else return Utils::hydrateObject(
        $dataValue, $instanceType
      );
    }

    return $dataValue;
  }
}