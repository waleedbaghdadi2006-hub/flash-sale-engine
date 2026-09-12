<?php

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6379);
$db = (int) (getenv('REDIS_STOCK_DB') ?: 2);

$itemId = (int) ($argv[1] ?? 0);
$quantity = (int) ($argv[2] ?? 1);

$redis = new Redis();
$redis->connect($host, $port);

if (function_exists('putenv')) {
    // no-op; environment is already inherited
}

$redis->select($db);

$script = <<<'LUA'
local stock = tonumber(redis.call('GET', KEYS[1]))
if stock == nil then return -1 end
if stock < tonumber(ARGV[1]) then return 0 end
redis.call('DECRBY', KEYS[1], ARGV[1])
return 1
LUA;

$key = "flashsale:{$itemId}:stock";

$result = $redis->eval(
    $script,
    [$key, $quantity],
    1
);

echo $result . PHP_EOL;