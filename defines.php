<?php

/*
 * define rootDir
 **/
if( defined( "rootDir" ) === false ){
  define( "rootDir", dirname( __FILE__ ));
}

/*
 * define private
 **/
if( defined( "privateKey" ) === false ){
  if( file_exists( rootDir . "/certs/private.pem" )){
    define( "privateKey", file_get_contents( rootDir . "/certs/private.pem" ));
  }
}

/*
 * define publickey
 **/
if( defined( "publicKey" ) === false ){
  if( file_exists( rootDir . "/certs/public.pem" )){
    define( "publicKey", file_get_contents( rootDir . "/certs/public.pem" ));
  }
}

/*
 * define default timezone
 **/
date_default_timezone_set("America/Sao_Paulo");