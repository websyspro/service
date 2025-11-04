<?php

namespace Websyspro\Core\Exceptions;

use Exception;
use Websyspro\Core\Shareds\Server\Response;

class Error
{
  public static function badRequest(
    string $message
  ): Exception {
    return throw new Exception(
      message: $message, code: Response::HTTP_BAD_REQUEST
    );
  }

  public static function notFound(
    string $message
  ): Exception {
    return throw new Exception(
      message: $message, code: Response::HTTP_NOT_FOUND
    );
  }

  public static function internalServerError(
    string $message
  ): Exception {
    return throw new Exception(
      message: $message, code: Response::HTTP_INTERNAL_SERVER_ERROR
    );
  }

  public static function unauthorized(
    string $message
  ): Exception {
    return throw new Exception(
      message: $message, code: Response::HTTP_UNAUTHORIZED
    );
  }
}