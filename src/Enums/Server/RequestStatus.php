<?php

namespace Websyspro\Core\Enums\Server;

enum RequestStatus
{
  case Ok;
  case ModuleNotFound;
  case ControllerNotFound;
  case EndpointNotFound;
}