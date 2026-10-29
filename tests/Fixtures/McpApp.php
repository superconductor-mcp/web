<?php

namespace SuperconductorMCP\SSE\Tests\Fixtures;

use Mariner\NutsAndBolts\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Voyager\Contracts\Console\Kernel;
use Voyager\Core\Bootstrap\HandleExceptions;
use Voyager\Core\RenderedInstance;
use Voyager\Vessel\ControlPanel;

/**
 * A Venusian app booted from tests/Fixtures/app with Mariner, the MCP core and this transport registered,
 * as an application that installs superconductor/web would boot.
 */
final class McpApp
{
    /** @var array<string, mixed> the fixture app's config/sse.php, read as the app boots */
    public static array $sse = [];

    public static function boot(): RenderedInstance
    {
        $app = require __DIR__.'/app/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /** Forgets the app and the facade roots, and hands PHPUnit back the error handlers the boot replaced. */
    public static function tearDown(TestCase $test): void
    {
        self::$sse = [];
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        HandleExceptions::flushState($test);
        ControlPanel::setInstance(null);
    }
}
