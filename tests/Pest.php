<?php

use Voyager\Config\Repository;
use Voyager\Vessel\ControlPanel;
use Voyager\IOPools\EventLoop;
use Voyager\IOPools\LoopWaiter;
use Voyager\IOPools\ResourceRegistry;
use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\Waiter\StreamSelectWaiterBackend;
use Voyager\IOPools\PromiseEngines\GuzzlePromiseEngine;
use SuperconductorMCP\SSE\Tests\Fixtures\FakeLog;

function testLoop(int $pace_ms = 5): EventLoop
{
    $registry = new ResourceRegistry();

    return new EventLoop($registry, new LoopWaiter($registry, new StreamSelectWaiterBackend(), $pace_ms * 1_000_000), new GuzzlePromiseEngine());
}

// a container holding only what the packages read: the mcp config, a log that records, and the loop
function bareApp(array $mcp = [], ?EventLoop $loop = null, array $web = []): ControlPanel
{
    $app = new ControlPanel();
    ControlPanel::setInstance($app);

    $app->registerInstance('config', new Repository([
        'mcp' => array_replace_recursive(require dirname(__DIR__, 2).'/mcp/config/mcp.php', $mcp),
        'web' => array_replace_recursive(require dirname(__DIR__).'/config/web.php', $web),
    ]));
    $app->registerInstance('log', new FakeLog());
    $app->registerInstance(Loop::class, $loop ?? testLoop());

    return $app;
}

function log_entries(): array
{
    return app('log')->entries;
}

/** starts a fixture server on a free port and returns [process, port, stdout]; the process is killed by the test */
function fixtureServer(string ...$args): array
{
    return spawnFixture('sse-server.php', ...$args);
}

function spawnFixture(string $script, string ...$args): array
{
    $port = random_int(20000, 60000);
    $process = proc_open([PHP_BINARY, __DIR__.'/Fixtures/'.$script, (string) $port, ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[1], true);
    fgets($pipes[1]);   // the server prints "listening" once bound
    stream_set_blocking($pipes[1], false);

    return [$process, $port, $pipes[1]];
}
