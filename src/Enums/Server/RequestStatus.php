<?php

namespace Websyspro\Core\Enums\Server;

enum RequestStatus
{
  case Ok;
  case ControllerNotFound;
  case EndpointNotFound;
}