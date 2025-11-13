<?php

namespace Websyspro\Core\Enums\Server;

enum Headers:string {
  case contentType = "CONTENT_TYPE";
  case applicationJSON = "Content-Type: application/json; charset=utf-8";
  case textHtml = "Content-Type: text/html; charset=UTF-8";
  case accessControlAllowOrigin = "Access-Control-Allow-Origin: *";
  case accessControlAllowHeaders = "Access-Control-Allow-Headers: *";
  case accessControlAllowMethods = "Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS";    
}