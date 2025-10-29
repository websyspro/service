<?php

namespace Websyspro\Server\Commons;

use ReflectionFunction;

class Utils
{
  public static function generationUuid(
  ): string {
    $guid    = random_bytes(16);
    $guid[6] = chr(ord($guid[6]) & 0x0f | 0x40);
    $guid[8] = chr(ord($guid[8]) & 0x3f | 0x80);

    return vsprintf(
      "%s%s-%s-%s-%s-%s%s%s", str_split(
        bin2hex($guid), 4
      )
    );
  }

  public static function generationHash(
    int $length = 16,
    bool $useUpper = true,
    bool $useNumbers = true,
    bool $useSymbols = true,
    bool $avoidAmbiguous = true
  ): string {
    $lower = "abcdefghijklmnopqrstuvwxyz";
    $upper = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $symbols = "!@#$%^&*()-_=+[]{}<>?,./";
    $numbers = "0123456789";

    if( $avoidAmbiguous ){
      $ambiguous = ["0","O","o","1","l","I"];
      foreach( $ambiguous as $ch ) {
        $lower = str_replace($ch, "", $lower);
        $upper = str_replace($ch, "", $upper);
        $numbers = str_replace($ch, "", $numbers);
      }
    }

    $alphabet = $lower;
    if( $useUpper ) $alphabet .= $upper;
    if( $useNumbers ) $alphabet .= $numbers;
    if( $useSymbols ) $alphabet .= $symbols;

    $maxIndex = strlen($alphabet) - 1;
    $password = "";

    for($i = 0; $i < $length; $i++) {
      $idx = random_int(0, $maxIndex);
      $password .= $alphabet[$idx];
    }

    return $password;;
  }

  public static function argsCount(
    callable $callable
  ): int {
    return (new ReflectionFunction($callable))
      ->getNumberOfParameters();
  }

  public static function mapper(
    iterable $iterable,
    callable $callable
  ): array|object {
    if(is_array($iterable)){
      foreach($iterable as $key => $val){
        $iterable[$key] = Utils::argsCount($callable) === 2 
          ? $callable($val, $key) : $callable($val);
      }
    } else
    if(is_object($iterable)){
      foreach($iterable as $key => $val){
        $iterable->{$key} = Utils::argsCount($callable) === 2 
          ? $callable($val, $key) : $callable($val);
      }      
    }

    return $iterable;
  }

  public static function where(
    iterable $iterable,
    callable $callable
  ): array {
    $iterableNew = [];
    $callableArgsCount = Utils::argsCount($callable);
    foreach($iterable as $key => $value){
      if($callableArgsCount === 2){
        if($callable($value, $key)){
          $iterableNew[$key] = $value;
        }
      } else {
        if($callable($value)){
          $iterableNew[$key] = $value;
        }
      }
    }

    return $iterableNew;
  }

  public static function find(
    iterable $iterable,
    callable $callable
  ): mixed {
    $callableArgsCount = Utils::argsCount($callable);
    foreach($iterable as $key => $value){
      if($callableArgsCount === 2){
        if($callable($value, $key)){
          return $value;
        }
      } else {
        if($callable($value)){
          return $value;
        }
      }
    }

    return null;
  }
  
  public static function reduce(
    mixed $current,     
    iterable $iterable,
    callable $callable
  ): mixed {
    return array_reduce(
      $iterable, $callable, $current
    );      
  }

  public static function chunk(
    iterable $iterable,
    int $length
  ): array {
    return array_chunk(
      $iterable, $length
    );
  }

  public static function isEquals(
    array $first,
    array $second
  ): bool {
    if(sizeof($first) !== sizeof($second)){
      return false;
    }

    sort($first);
    sort($second);

    return array_values($first)
       === array_values($second);
  }

  public static function toCamelCase(
    string $string
  ): string {
    return ctype_lower($string[0])
      ? $string : lcfirst($string);
  }
  
  public static function isPrimitiveType(
    string $primitiveType
  ): bool {
    return in_array($primitiveType, [
      "int", "integer", "float", "double", 
      "string", "bool", "boolean", "array", "null"
    ], true);
  }  
}