<?php

namespace Websyspro\Core\Enums\Server;

enum RequestType
{
  case body;
  case file;
  case params;
  case query;
}