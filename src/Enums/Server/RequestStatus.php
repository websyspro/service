<?php

namespace Websyspro\Core\Enums\Server;

enum RequestStatus
{
  case Ok;
  case ModuleNotFound;
  case ModuleEmpty;
  case ControllerNotFound;
  case ControllerEmpty;
  case EndpointNotFound;
}