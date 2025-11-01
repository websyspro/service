<?php

namespace Websyspro\Core\Enums\Server;

enum ControllerType {
  case Controller;
  case Middleware;
  case Endpoint;
}