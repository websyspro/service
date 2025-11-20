<?php

namespace Websyspro\Core\Shareds\Server;

use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Commons\Utils;
use Websyspro\Core\Enums\Server\ContentType;
use Websyspro\Core\Enums\Server\RequestMethod;
use Websyspro\Core\Enums\Server\RequestStatus;
use Websyspro\Core\Enums\Server\RequestType;

class RequestData
{
  public array $body = [];
  public array $query = [];
  public array $params = [];
  public array $files = [];

  public function __construct(
    public Request $request
  ){
    $this->initialBodys();
    $this->initialParams();
    $this->initialQuerys();
    $this->initialFiles();
    $this->initialClear();
  }

  private function initialBodys(
  ): void {
    if(isset($this->request->contentType) === false){
      $this->body = [];
    } else {
      if($this->request->requestMethod === RequestMethod::Post){
        if(in_array($this->request->contentType, [
          ContentType::applicationJson,
          ContentType::multipartFormData,
          ContentType::applicationXWwwFormUrlencoded
        ])){
          if($this->request->contentType !== ContentType::applicationJson){
            $this->body = $_POST;
          } else {
            $this->body = json_decode(
              $this->getPhpInput(), true
            );
          }
        }
      } else {
        $this->body = $this->contentFromFile(
          RequestType::body
        );
      }
    }
  }

  private function initialParams(
  ): void {
    if($this->request->getIsOk() && $this->request->getIsNotOptions()){
      if($this->request->structureRoute){
        $this->request->structureRoute->endpoints->mapper(
          function(string $path, int $i){
            $hasParams = (bool)preg_match(
              "#(^\{.*\}$)|(^\{.*\}\?$)|(^:.*)|(^:.*\?$)#", $path
            );
    
            if($hasParams === true){
              $valueFromRequest = $this->request->endpoints
                ->eq($i)->first();

              if((bool)preg_match( "#\?#", $path )){
                if( empty($valueFromRequest) === true ){
                  $valueFromRequest = null;
                } 
              }

              $type = preg_match("#^\{.*:.*\}?#", $path) === 1 
                ? preg_replace(["#.*:#", "#\}?#"], "", $path) : null;

              $this->params[
                preg_replace("#(^\{)|(^:)|(\}$)|(\}\?$)|(\?$)|(:.*)#", "", $path)
              ] = $this->parseValueFromType($valueFromRequest, $type);
            }
          }
        );
      }
    }
  }

  private function initialQuerys(
  ): void {
    $this->query = $_GET;
  }

  private function initialFiles(
  ): void {
    if(isset($this->request->contentType)){
      if(in_array($this->request->contentType, [
        ContentType::applicationPdf,
        ContentType::applicationXls,
        ContentType::applicationXlsx,
        ContentType::textJavascript,
        ContentType::textPlain,
        ContentType::textCss,
        ContentType::textCsv
      ])){
        $this->files = $this->contentFromBinary();
      } else {
        $this->files = $this->request->requestMethod !== RequestMethod::Post
          ? $this->contentFromFile(RequestType::file)
          : $this->contentPostFile();
      }
    }
  }

  private function getPhpInput(
  ): string {
    return file_get_contents("php://input");
  }
  
  private function getPhpInputType(
  ): string {
    return $this->request->contentType->value;
  }   

  private function contentLoadFileList(
    array $bufferArr = []
  ): array {
    $inputHandle = fopen("php://input", "r");
    while(($buffer = fgets( $inputHandle, 4096 )) !== false) {
      $bufferArr[] = $buffer;
    }

    return array_slice(
      $bufferArr, 0, sizeof(
        $bufferArr
      ) - 1
    );
  }
  
  private function extractName(
    string $value
  ): string {
    [, $value] = explode(";", $value);
    return preg_replace("/(^name=\")|(\"$)/", "", trim($value));
  }

  private function extractFile(
    string $value
  ): string {
    $fileArgs = new Collection(
      explode( ";", $value)
    );

    if($fileArgs->count() === 3){
      $value = $fileArgs->first();
    }

    if(is_null( $value )){
      return "";
    }

    return preg_replace("/(^filename=\")|(\"$)/", "", trim($value));
  }	
  
  private function extractType(
    string $value
  ): string {
    return preg_replace( "/^Content-Type: /", "", trim($value));
  }

  private function extractSize(
    array $value
  ): float {
    return (float)strlen(
      implode( "", array_slice( $value, 3 ))
    ) - 2;
  }

  private function extractBody(
    array $value
  ): string {
    return base64_encode(
      implode( "", array_slice(
        $value, 3
      ))
    );
  }

  private function contentFromBinary(
    array $content = []
  ): array {
    $buffer = $this->getPhpInput();
    $bufferType = $this->getPhpInputType();

    $content["binary"] = [
      "name" => "binary-file",
      "type" => $bufferType,
      "size" => strlen($buffer),
      "body" => base64_encode($buffer)
    ];

    return $content;
  }

  private function contentFromFile(
    RequestType $requestType,
    array $content = [],
    int $cursor = -1
  ): array {
    if($this->request->contentType === ContentType::multipartFormData){
      foreach($this->contentLoadFileList() as $buffer){
        if( preg_match("/^-{28}\d+$/", trim($buffer)) === 1 ){
          ++$cursor;
        }

        $data[$cursor][] = $buffer;
      }

      foreach( Utils::mapper($data, fn(array $groupBuffer) => array_slice($groupBuffer, 1)) as $contextBuffers ){
        [	$contextDetails, $contextType, $contextValue ] = $contextBuffers;

        $contextDetailsName = $this->extractName($contextDetails);
        $contextDetailsFile = $this->extractFile($contextDetails);
        $contentSize = $this->extractSize($contextBuffers);
        $contentBody = $this->extractBody($contextBuffers);
        $contentType = $this->extractType($contextType);
        
        if( $requestType === RequestType::body && empty(trim($contextType)) === true ){
          $content[ $contextDetailsName ] = trim($contextValue);
        }

        if( $requestType === RequestType::file && empty(trim($contextType)) === false ){
          $contentBody = $this->extractBody($contextBuffers);

          $content[ $contextDetailsName ] = [
            "name" => $contextDetailsFile,
            "type" => $contentType,
            "size" => $contentSize,
            "body" => $contentBody
          ];						
        }
      }

      return $content;
    } else if ($this->request->contentType === ContentType::multipartFormData) {
      parse_str($this->getPhpInput(), $content);
      return $content;
    } else if ($this->request->contentType === ContentType::applicationJson){
      if($requestType !== RequestType::file){
        return json_decode($this->getPhpInput(), true);
      }
    }
    
    return [];
  }

  private function contentPostFile(
  ): array {
    return Utils::mapper(
      $_FILES, fn( $file ) => [
        "name" => $file["name"],
        "type" => $file["type"],
        "size" => $file["size"],
        "body" => base64_encode(
          file_get_contents(
            $file["tmp_name"]
          )
        )
      ]
    );
  }

  private function parseValueFromType(
    string $value,
    string $type
  ): mixed {
    switch (strtolower($type)) {
      case 'int':
      case 'integer': return (int)$value;
      case 'float':
      case 'double':
      case 'real': return (float)$value;
      case 'bool':
      case 'boolean': return (bool)($value);
      case 'string': 
      case "text": return (string) $value;
      default: return $value;
    }
  }
  
  private function initialClear(
  ): void {
    unset($this->request);
  }
}