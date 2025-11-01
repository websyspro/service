<?php

namespace Websyspro\Core\Shareds\Server;

use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Enums\Server\ContentType;
use Websyspro\Core\Enums\Server\RequestMethod;
use Websyspro\Core\Enums\Server\RequestStatus;

class Request
{
  public string $uri;
  public string $controller;
  public Collection $endpoints;
  public RequestData $requestData;
  public ContentType $contentType;
  public RequestMethod $requestMethod;
  public RequestStatus $requestStatus;
  public StructureController|null $structureController;
  public StructureRoute|null $structureRoute;

  public function __construct(
    public Collection $controllers,
    public string|null $prefixBase = null 
  ){
    $this->initial();
    $this->initialUri();
    $this->initialContentType();
    $this->initialRequestMethod();
    $this->initialRequestEndpoint();
    $this->initialRequestControllers();
    $this->initialRequestFindController();
    $this->initialRequestFindEndpointInController();
    $this->initialRequestWithEndpointData();
    $this->initialClear();
  }

  private function initial(
  ): void {
    if(isset($_SERVER) === true){
      [ "REQUEST_URI" => $this->uri ] = $_SERVER;

      $this->requestStatus = RequestStatus::Ok;
    }
  }

  private function initialUri(
  ): void {
    $this->uri = preg_replace(
      "#(^/)|(/$)#", "", preg_replace(
        "#\?.*#", "", $this->uri
      )
    );    
  }

  private function initialContentType(
  ): void {
    if(isset($_SERVER["CONTENT_TYPE"]) === true){
      $this->contentType = ContentType::fromValue(
        $_SERVER[ "CONTENT_TYPE" ]
      );
    }    
  }

  private function initialRequestMethod(
  ): void {
    if(isset($_SERVER["REQUEST_METHOD"]) === true){
      $this->requestMethod = RequestMethod::fromValue(
        $_SERVER["REQUEST_METHOD"]
      );
    }
  }

  private function initialRequestEndpoint(
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

    $this->controller = $this->endpoints->first();
    $this->endpoints = $this->endpoints->slice(1);
  }

  private function initialRequestControllers(
  ): void {
    $this->controllers = $this->controllers->mapper(
      fn(string $controller) => new StructureController($controller)
    );
  }

  private function initialRequestFindController(
  ): void {
    $this->structureController = $this->controllers->find(
      fn(StructureController $structureController) => $structureController->valid($this)
    );

    if($this->structureController === null){
      $this->requestStatus = RequestStatus::ControllerNotFound;
    }
  }

  private function initialRequestFindEndpointInController(
  ): void {
    if($this->structureController){
      $this->structureRoute = $this->structureController->endpoints->find(
        fn(StructureRoute $structureRoute) => $structureRoute->valid($this)
      );

      if($this->structureRoute === null){
        $this->requestStatus = RequestStatus::EndpointNotFound;
      }
    }
  }

  private function initialRequestWithEndpointData(
  ): void {
    $this->requestData = new RequestData($this);
  }
  
  public function getEndpointExecute(
  ): mixed {
    return [];
  }

  private function initialClear(
  ): void {
    unset($this->controllers);
  }

}