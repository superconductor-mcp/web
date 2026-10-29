<?php

namespace SuperconductorMCP\SSE\Tests\Fixtures;

use Fabricate\Mechanism\Session\Elicitation;
use Fabricate\NutsAndBolts\Attributes\Server;
use Fabricate\NutsAndBolts\Schema\ObjectSchema;
use Fabricate\NutsAndBolts\Schema\StringProperty;
use Fabricate\NutsAndBolts\Schema\TextProperty;
use Fabricate\NutsAndBolts\ToolDefinition;
use Fabricate\Server\MCPServer;

// the server the transport tests talk to: an echo tool, a tool that asks the user a question, list-change notifications
#[Server('fixture')]
final class FixtureServer extends MCPServer
{
    protected array $entity_info = ['name' => 'fixture', 'version' => '1.0.0'];

    protected array $entity_capabilities = [
        'tools' => ['listChanged' => true],
        'logging' => [],
    ];

    public function __construct()
    {
        $this->tools = [
            new ToolDefinition('echo', 'Echoes its text', ['text' => new StringProperty()], ['text'], fn (string $text): TextProperty => new TextProperty($text)),
            new ToolDefinition('ask', 'Asks the user for a name', action: function (): TextProperty {
                $answer = app(Elicitation::class)->ask('Your name?', new ObjectSchema(['name' => new StringProperty(min_length: 2)], ['name']));

                return new TextProperty($answer->accepted() ? "hello {$answer->content['name']}" : $answer->action->value);
            }),
        ];
    }
}
