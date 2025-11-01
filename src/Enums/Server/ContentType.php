<?php

namespace Websyspro\Core\Enums\Server;

enum ContentType:string
{
  case textPlain = 'text/plain';
  case textHtml = 'text/html';
  case textCss = 'text/css';
  case textJavascript = 'text/javascript';
  case textCsv = 'text/csv';
  case textXml = 'text/xml';
  case textMarkdown = 'text/markdown';

  case applicationJson = 'application/json';
  case applicationXml = 'application/xml';
  case applicationXWwwFormUrlencoded = 'application/x-www-form-urlencoded';
  case multipartFormData = 'multipart/form-data';
  case applicationGraphql = 'application/graphql';
  case applicationOctetStream = 'application/octet-stream';
  case applicationPdf = 'application/pdf';
  case applicationZip = 'application/zip';
  case applicationTar = 'application/x-tar';
  case applicationMsword = 'application/msword';
  case applicationDocx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
  case applicationXls = 'application/vnd.ms-excel';
  case applicationXlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
  case applicationJsonApi = 'application/vnd.api+json';

  case imageJpeg = 'image/jpeg';
  case imagePng = 'image/png';
  case imageGif = 'image/gif';
  case imageWebp = 'image/webp';
  case imageSvg = 'image/svg+xml';
  case imageBmp = 'image/bmp';
  case imageTiff = 'image/tiff';
  case imageAvif = 'image/avif';

  case audioMp3 = 'audio/mpeg';
  case audioOgg = 'audio/ogg';
  case audioWav = 'audio/wav';
  case audioAac = 'audio/aac';
  case audioFlac = 'audio/flac';

  case videoMp4 = 'video/mp4';
  case videoWebm = 'video/webm';
  case videoOgg = 'video/ogg';
  case videoAvi = 'video/x-msvideo';
  case videoMpeg = 'video/mpeg';

  case applicationJavascript = 'application/javascript';
  case applicationShell = 'application/x-sh';
  case applicationPython = 'application/x-python';
  case applicationPhp = 'application/x-php';
  case applicationYaml = 'application/x-yaml';
  case applicationHttpdPhp = 'application/x-httpd-php';

  public static function fromValue(
    string $value
  ): ContentType {
    foreach (self::cases() as $case) {
      if(strcasecmp($case->value, preg_replace("#;.*$#", "", $value)) === 0) {
        $contentType = $case;
      }
    }

    return $contentType;
  }  
}