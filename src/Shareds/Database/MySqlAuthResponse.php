<?php

namespace Websyspro\Core\Shareds\Database;

use Websyspro\Core\Exceptions\Error;

enum MySqlAuthResponse: int
{
	case OK = 0x00;
	case ERR = 0xFF;
	case AUTH_SWITCH_REQUEST = 0xFE;
	case AUTH_MORE_DATA = 0x01;
	case PUBLIC_KEY_REQUEST = 0x02;
	case FAST_AUTH_SUCCESS = 0x03;
	case FULL_AUTH_REQUIRED = 0x04;

	public static function fromByte(
		string $byte
	): MySqlAuthResponse {
		$value = ord($byte);

		return match ($value) {
			0x00 => self::OK,
			0xFF => self::ERR,
			0xFE => self::AUTH_SWITCH_REQUEST,
			0x01 => self::AUTH_MORE_DATA,
			0x02 => self::PUBLIC_KEY_REQUEST,
			0x03 => self::FAST_AUTH_SUCCESS,
			0x04 => self::FULL_AUTH_REQUIRED,

			default => Error::internalServerError(
				"Unknown MySQL login response type: 0x" . dechex($value)
			),
		};
	}
}