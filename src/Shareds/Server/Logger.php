<?php

namespace Websyspro\Core\Shareds\Server;

use Websyspro\Core\Enums\Server\LoggerType;

class Logger
{
  public static float $startTimer;

  private static function setStartTimer(
  ): void {
    Logger::$startTimer = microtime(true);
  }

  private static function getNowTimer(
  ): int { 
    $starDiff = round(( 
      microtime(true) - Logger::$startTimer
    ) * 1000);

    Logger::setStartTimer(); 
    return $starDiff;
  }

  private static function getNow(
  ): string {
    return date( "[D M d H:i:s Y]" );
  }

  private static function getOrigem(
  ): string {
    $remoteAddr = null;
    $serverPort = null;

    if(isset($_SERVER["REMOTE_ADDR"])){
      [ "REMOTE_ADDR" => $remoteAddr, 
        "SERVER_PORT" => $serverPort ] = $_SERVER;

      if($serverPort !== null){
        $serverPort = str_pad(
          $serverPort, 5, "0", STR_PAD_LEFT
        );
      }  
    }
  
    return $remoteAddr !== null && $serverPort !== null
      ? "[{$remoteAddr}]:{$serverPort}"
      : "[::1]:00000";
  }

  private static function isStartTimer(
  ): void {
    if(isset(Logger::$startTimer) === false){
      Logger::setStartTimer();
    }
  }
  
  public static function message(
    LoggerType $type,
    string $text 
  ): bool {
    Logger::isStartTimer();
    fwrite( fopen('php://stdout', 'w'), (
      sprintf("\x1b[37m%s %s\x1b[32m LOG \x1b[33m[{$type->value}] \x1b[32m{$text}\x1b[37m \x1b[37m+%sms\n", 
        Logger::getNow(),
        Logger::getOrigem(),
        Logger::getNowTimer(),
      )
    ));

    return true;
  }

  public static function error(
    LoggerType $type,
    string $text     
  ): bool {
    Logger::isStartTimer();
    fwrite( fopen('php://stdout', 'w'), (
      sprintf( "\x1b[37m%s %s\x1b[32m LOG \x1b[33m[{$type->value}] \x1b[31m{$text} \x1b[37m+%sms\n",
        Logger::getNow(),
        Logger::getOrigem(),
        Logger::getNowTimer(),
      )
    ));

    return false;
  }
}