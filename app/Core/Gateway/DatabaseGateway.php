<?php

namespace App\Core\Gateway;

use App\Core\Contracts\DatabaseInterface;
use App\Core\Database;
use PDO;

/** Default adapter: delegata alla singleton `Database::connection()`. */
final class DatabaseGateway implements DatabaseInterface
{
    public function connection(): PDO
    {
        return Database::connection();
    }
}
