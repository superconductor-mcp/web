<?php

use SuperconductorMCP\SSE\Tests\Fixtures\McpApp;

// What a test sets on McpApp::$sse before booting is the application's own config/sse.php, laid over the package's.
return McpApp::$sse;
