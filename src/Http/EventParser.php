<?php

namespace SuperconductorMCP\SSE\Http;

// SSE framing: "field: value" lines, a blank line dispatches the event; "id:" lines set the last event id for a resume
final class EventParser
{
    public ?string $last_id = null;

    private string $buffer = '';

    private string $name = '';

    /** @var list<string> */
    private array $data = [];

    /** @return list<array{0: string, 1: string}> the events completed by this text: [event name, data] */
    public function feed(string $text): array
    {
        $this->buffer .= $text;
        $events = [];

        while (($at = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $at), "\r");
            $this->buffer = substr($this->buffer, $at + 1);

            if ($line === '') {
                if ($this->data !== []) {
                    $events[] = [$this->name, implode("\n", $this->data)];
                }

                [$this->name, $this->data] = ['', []];

                continue;
            }

            if ($line[0] === ':') {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = ltrim($value, ' ');

            match ($field) {
                'event' => $this->name = $value,
                'data' => $this->data[] = $value,
                // the SSE standard drops an id holding a NUL
                'id' => $this->last_id = str_contains($value, "\0") ? $this->last_id : $value,
                default => null,
            };
        }

        return $events;
    }
}
