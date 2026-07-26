<?php

namespace App\Ai;

use App\Ai\Tools\Tool;
use OpenAI\Factory;

class Agent
{
    /** @var Tool[] */
    private array $tools = [];

    private string $instructions = '';

    public function withInstructions(string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function withTool(Tool $tool): static
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    public function prompt(string $message, ?string $model = null): string
    {
        $model ??= config('ai.model', 'code');

        $client = (new Factory())
            ->withApiKey(config('ai.providers.openai.key'))
            ->withBaseUri(config('ai.providers.openai.url'))
            ->make();

        $messages = [
            ['role' => 'system', 'content' => $this->instructions ?: 'You are a helpful assistant for a health dashboard application. Use the available tools to answer questions about persons, units, tickets, todos, and hardware.'],
            ['role' => 'user', 'content' => $message],
        ];

        $functions = array_map(fn (Tool $t) => $t->toFunction(), $this->tools);

        $maxIterations = 10;

        while ($maxIterations-- > 0) {
            $params = [
                'model' => $model,
                'stream' => false,
                'messages' => $messages,
            ];

            if ($functions !== []) {
                $params['tools'] = $functions;
                $params['tool_choice'] = 'auto';
            }

            $response = $client->chat()->create($params);

            $choice = $response->choices[0];
            $assistantMessage = $choice->message;

            $messages[] = [
                'role' => 'assistant',
                'content' => $assistantMessage->content,
                'tool_calls' => $assistantMessage->toolCalls ? array_map(fn ($tc) => [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $tc->function->name,
                        'arguments' => $tc->function->arguments,
                    ],
                ], $assistantMessage->toolCalls) : null,
            ];

            if ($choice->finishReason === 'stop' || empty($assistantMessage->toolCalls)) {
                return $assistantMessage->content ?? '';
            }

            foreach ($assistantMessage->toolCalls as $toolCall) {
                $toolName = $toolCall->function->name;
                $args = json_decode($toolCall->function->arguments, true) ?? [];

                $result = $this->tools[$toolName]->execute($args);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return 'Agent reached maximum iterations without a final response.';
    }
}
