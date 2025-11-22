<?php

namespace Websyspro\Core\Enums\Database;

enum ConnectionType {
	case MySQL;
	case PostgreSQL;
	case MSSQL;
}