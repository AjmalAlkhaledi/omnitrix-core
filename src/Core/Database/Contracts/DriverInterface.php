<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Contracts;

use Omnitrix\Core\Database\Config\DatabaseConfig;

/** Creates physical connections for one database technology. */
interface DriverInterface
{
    public function name(): string;

    /** @throws \Omnitrix\Core\Database\Exception\ConnectionException */
    public function connect(DatabaseConfig $config): ConnectionInterface;

    public function grammar(): GrammarInterface;
}
