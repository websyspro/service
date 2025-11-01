<?php

namespace Websyspro\Core\Enums\Server;

enum RequestMethod:string
{
  case Get = 'GET';
  case Post = 'POST';
  case Put = 'PUT';
  case Patch = 'PATCH';
  case Delete = 'DELETE';
  case Head = 'HEAD';
  case Options = 'OPTIONS';
  case Trace = 'TRACE';
  case Connect = 'CONNECT';

  public static function fromValue(
    string $value
  ): RequestMethod {
    foreach (self::cases() as $case) {
      if(strcasecmp($case->value, $value) === 0) {
        $requestMethod = $case;
      }
    }

    return $requestMethod;
  }  
}