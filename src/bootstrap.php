<?php
declare(strict_types=1);

require_once __DIR__ . '/IpMath.php';
require_once __DIR__ . '/DnsName.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/NetworkPlanner.php';
require_once __DIR__ . '/DynamicReplanner.php';
require_once __DIR__ . '/Ipv4Calculator.php';

use IpDesigner\Database;
use IpDesigner\Repository;
use IpDesigner\NetworkPlanner;
use IpDesigner\DynamicReplanner;
use IpDesigner\Ipv4Calculator;

function app_repository(): Repository
{
    static $repository;
    return $repository ??= new Repository(Database::connect());
}

function app_planner(): NetworkPlanner
{
    static $planner;
    return $planner ??= new NetworkPlanner(Database::connect());
}

function app_replanner(): DynamicReplanner
{
    static $replanner;
    return $replanner ??= new DynamicReplanner(Database::connect());
}

function app_ipv4_calculator(): Ipv4Calculator
{
    static $calculator;
    return $calculator ??= new Ipv4Calculator();
}
