<?php

namespace Websyspro\Core\Shareds\Database;

use Websyspro\Core\Exceptions\Error;

enum MySqlAuthMoreData: int 
{
	case FAST_AUTH_SUCCESS = 0x03;
	case FULL_AUTH_REQUIRED = 0x04;
	case REQUEST_PUBLIC_KEY = 0x02;
	case ADDITIONAL_DATA = 0x01;
	case PUBLIC_KEY_DATA = 0x30;

	public static function fromByte(
		string $byte
	): MySqlAuthMoreData {
		$value = ord($byte);

		return match (true) {
			$value === 0x03 => self::FAST_AUTH_SUCCESS,
			$value === 0x04 => self::FULL_AUTH_REQUIRED,
			$value === 0x02 => self::REQUEST_PUBLIC_KEY,
			$value === 0x01 => self::ADDITIONAL_DATA,
			$value  >= 0x30 => self::PUBLIC_KEY_DATA,

			default => Error::internalServerError(
				"Unknown MySQL AUTH_MORE_DATA subtype: 0x" . dechex($value)
			),
		};
	}
}