<?php

namespace Websyspro\Server\Enums\Server;

enum ControllerType {
  case Controller;
  case Middleware;
  case Endpoint;
}