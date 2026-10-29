<?php

namespace SuperconductorMCP\SSE\Tests\Fixtures;

use Fabricate\NutsAndBolts\Attributes\Server;
use Fabricate\Server\MCPServer;

// a second registered server: a session opened on one is not valid on the other
#[Server('other')]
final class OtherServer extends MCPServer
{
    protected array $entity_info = ['name' => 'other', 'version' => '1.0.0'];

    protected array $entity_capabilities = ['tools' => []];
}
