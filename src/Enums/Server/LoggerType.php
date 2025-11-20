<?php

namespace Websyspro\Core\Enums\Server;

enum LoggerType: string {
  case module = "Module";
  case service = "Service";
  case entity = "Entity";
  case context = "Context";
  case controller = "Controller";
  case database = "Database";
  case import = "Import";
  case queryContext = "QueryContext";
}