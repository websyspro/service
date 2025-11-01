<?php

namespace Websyspro\Core\Enums\Server;

enum MethodType
{
  case Get;
  case Post;
  case Put;
  case Delete;
  case Options;
}