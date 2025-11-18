<?php

namespace Websyspro\Core\Shareds\Server;

use ReflectionClass;
use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Decorations\Server\AllowAnonymous;
use Websyspro\Core\Decorations\Server\Authenticate;
use Websyspro\Core\Enums\Server\ContentType;
use Websyspro\Core\Enums\Server\RequestMethod;
use Websyspro\Core\Enums\Server\RequestStatus;
use Websyspro\Core\Enums\Server\ResponseType;
use Websyspro\Core\Exceptions\Error;

class Request
{
  public string $uri;
  public string $module;
  public string $controller;
  public Collection $endpoints;
  public RequestData $requestData;
  public ContentType $contentType;
  public Collection|Api $controllers;
  public RequestMethod $requestMethod;
  public RequestStatus $requestStatus;
  public StructureController|null $structureController;
  public StructureRoute|null $structureRoute;

  public function __construct(
    public Api|Web|Collection $ApiOrWebOrCollection,
    public string|null $prefixBase = null
  ){
    $this->start();
    $this->startUri();
    $this->startContentType();
    $this->startRequestMethod();
    $this->startRequestEndpoint();
    $this->startRequestControllers();
    $this->startRequestFindController();
    $this->startRequestFindEndpointInController();
    $this->startRequestWithEndpointData();
    $this->startClear();
  }

  private function start(
  ): void {
    if(isset($_SERVER) === true){
      [ "REQUEST_URI" => $this->uri ] = $_SERVER;

      $this->requestStatus = RequestStatus::Ok;
    }
  }

  private function startUri(
  ): void {
    $this->uri = preg_replace(
      "#(^/)|(/$)#", "", preg_replace(
        "#\?.*#", "", $this->uri
      )
    );    
  }

  private function startContentType(
  ): void {
    if(isset($_SERVER["CONTENT_TYPE"]) === true){
      $this->contentType = ContentType::fromValue(
        $_SERVER[ "CONTENT_TYPE" ]
      );
    }    
  }

  private function startRequestMethod(
  ): void {
    if(isset($_SERVER["REQUEST_METHOD"]) === true){
      $this->requestMethod = RequestMethod::fromValue(
        $_SERVER["REQUEST_METHOD"]
      );
    }
  }

  private function getIsControllersFromModule(
  ): bool {
    return (
      $this->ApiOrWebOrCollection instanceof Api ||
      $this->ApiOrWebOrCollection instanceof Web
    );    
  }

  private function setModuleControllerEndpoint(
  ): void {
    [ $this->module, $this->controller ] = $this->endpoints->all();
    $this->endpoints = $this->endpoints->slice(2);    
  }

  private function setModuleController(
  ): void {
    [ $this->module, $this->controller ] = $this->endpoints->all();
  }
  
  private function setModule(
  ): void {
    [ $this->module ] = $this->endpoints->all();
  } 
  
  private function setControllerEndpoint(
  ): void {
    [ $this->controller ] = $this->endpoints->all();
    $this->endpoints = $this->endpoints->slice(1);
  }  

  private function startRequestEndpoint(
  ): void {
    $this->endpoints = new Collection(
      $this->prefixBase !== null 
        ? preg_split( "#/#", preg_replace(
            "#(^/)|(/$)#", "", preg_replace(
              "#^{$this->prefixBase}#", "", $this->uri
            )
          )) 
        : preg_split( "#/#", $this->uri )
    );

    if($this->getIsControllersFromModule() === true){
      switch($this->endpoints->count()){
         case 2: $this->setModuleController();
         case 1: $this->setModule();
        default: $this->setModuleControllerEndpoint();
      }
    } else {
      $this->setControllerEndpoint();
    }
  }

  private function startRequestControllers(
  ): void {
    if($this->requestStatus === RequestStatus::Ok){
      if($this->getIsControllersFromModule()){
        $this->controllers = $this->ApiOrWebOrCollection
          ->getControllers($this->module);
      }
  
      if($this->controllers->count() === 0){
        $this->requestStatus = RequestStatus::ModuleNotFound;
      }    
  
      $this->controllers = $this->controllers->mapper(
        fn(string $controller) => new StructureController(
          new ReflectionClass($controller)
        )
      );
    }
  }

  private function startRequestFindController(
  ): void {
    if($this->requestStatus === RequestStatus::Ok){
      $this->structureController = $this->controllers->find(
        fn(StructureController $structureController) => $structureController->isValid($this)
      );
  
      if($this->structureController === null){
        $this->requestStatus = RequestStatus::ControllerNotFound;
      }
    }
  }

  private function startRequestFindEndpointInController(
  ): void {
    if($this->requestStatus === RequestStatus::Ok){
      if($this->structureController){
        $this->structureRoute = $this->structureController->endpoints->find(
          fn(StructureRoute $structureRoute) => $structureRoute->valid($this)
        );

        if($this->structureRoute === null){
          $this->requestStatus = RequestStatus::EndpointNotFound;
        }
      }
    }
  }

  private function startRequestWithEndpointData(
  ): void {
    if($this->requestStatus === RequestStatus::Ok){
      $this->requestData = new RequestData($this);
    }
  }

  private function startClear(
  ): void {
    unset($this->controllers);
  }  

  private function getMiddlewares(
  ): Collection {
    return (
      $this->structureController->getMiddlewares()
        ->where(function(object $middleware){
          return ($middleware instanceof Authenticate) ? (
            $this->structureRoute->getMiddlewares()->where(
              fn(object $middleware) => (
                $middleware instanceof AllowAnonymous
              )
            )->exist() === false
          ) : true;
        })
        ->merge($this->structureRoute->getMiddlewares())
    );
  }

  private function getParameters(
  ): Collection {
    return $this->structureRoute->getParameters()->mapper(fn(object $parameter) => (
      $parameter->instance->execute($this, $parameter->instanceType)
    ));
  }

  private function getInstance(
  ): object {
    $hasMethodConstruct = method_exists(
      $this->structureRoute->reflect->class, "__construct"
    );

    if($hasMethodConstruct === true){
      return InstanceDependences::gets($this->structureRoute->reflect->class);
    } else return new $this->structureRoute->reflect->class;
  }
  
  private function getMethodName(
  ): string {
    return $this->structureRoute->reflect->getName();
  }

  private function getModule(
  ): string {
    return $this->module;
  }

  private function getController(
  ): string {
    return $this->controller;
  }

  private function getEndpoint(
  ): string {
    return $this->endpoints->join("/");
  }

  private function getValidStatus(
  ): void {
    switch($this->requestStatus){
      case RequestStatus::ModuleNotFound:
        Error::notFound("Module [{$this->getModule()}] not found");      
      case RequestStatus::ControllerNotFound:
        Error::notFound("Controller [{$this->getController()}] not found");
      case RequestStatus::EndpointNotFound:
        Error::notFound("Route [{$this->getEndpoint()}] not found");    
    }
  }
  
  public function getEndpointExecute(
  ): Response {
    $this->getValidStatus();
    $this->getMiddlewares()->mapper(
      fn(object $middleware) => $middleware->execute($this)
    );

    return call_user_func_array([
      $this->getInstance(), $this->getMethodName()
    ], $this->getParameters()->all());
  }
}