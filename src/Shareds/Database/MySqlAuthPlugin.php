<?php

namespace Websyspro\Core\Shareds\Database;

use Websyspro\Core\Exceptions\Error;

enum MySqlAuthPlugin: string 
{
	case MYSQL_NATIVE_PASSWORD = "mysql_native_password";
	case CACHING_SHA2_PASSWORD = "caching_sha2_password";

	public static function fromString(
		string $pluginName
	): MySqlAuthPlugin {
		return match ($pluginName) {
			"mysql_native_password" => self::MYSQL_NATIVE_PASSWORD,
			"caching_sha2_password" => self::CACHING_SHA2_PASSWORD,
			default => Error::internalServerError(
				"Unsupported MySQL auth plugin: {$pluginName}"
			),
		};
	}

	public function generateAuth(
		string $password,
		string $salt
	): string {
		return match ($this) {
			self::MYSQL_NATIVE_PASSWORD => $this->nativePasswordAuth($password, $salt),
			self::CACHING_SHA2_PASSWORD => $this->sha2PasswordAuth($password, $salt),
		};
	}

	private function nativePasswordAuth(
		string $password,
		string $salt
	): string {
		$saltBin = hex2bin($salt);
		$hash1 = sha1($password, true);
		$hash2 = sha1($hash1, true);
		$hash3 = sha1($saltBin . $hash2, true);
		return $hash1 ^ $hash3;
	}

	private function sha2PasswordAuth(
		string $password,
		string $salt
	): string {
		$password1 = hash("sha256", $password, true);
		$password2 = hash("sha256", $password1, true);
		$password3 = hash("sha256", $password2 . $salt, true);
		return $password1 ^ $password3;
	}
}