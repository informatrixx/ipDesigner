#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Database.php';
IpDesigner\Database::connect();
fwrite(STDOUT, "SQLite-Schema ist aktuell.\n");
