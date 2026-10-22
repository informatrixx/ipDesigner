<?php
declare(strict_types=1);

require_once __DIR__ . '/IpMath.php';
require_once __DIR__ . '/DnsName.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/NetworkCapacity.php';
require_once __DIR__ . '/NetworkCheck.php';
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/NetworkPlanner.php';
require_once __DIR__ . '/DynamicReplanner.php';
require_once __DIR__ . '/Ipv4Calculator.php';

use IpDesigner\Database;
use IpDesigner\Repository;
use IpDesigner\NetworkPlanner;
use IpDesigner\DynamicReplanner;
use IpDesigner\Ipv4Calculator;

function app_database(): PDO
{
    static $db;
    if (!$db) {
        $db = Database::connect();
        Database::assertReady($db);
    }
    return $db;
}

function app_network_check(): IpDesigner\NetworkCheck
{
    return new IpDesigner\NetworkCheck(app_database());
}

function app_repository(): Repository
{
    static $repository;
    return $repository ??= new Repository(app_database());
}

function app_planner(): NetworkPlanner
{
    static $planner;
    return $planner ??= new NetworkPlanner(app_database());
}

function app_replanner(): DynamicReplanner
{
    static $replanner;
    return $replanner ??= new DynamicReplanner(app_database());
}

function app_ipv4_calculator(): Ipv4Calculator
{
    static $calculator;
    return $calculator ??= new Ipv4Calculator();
}
