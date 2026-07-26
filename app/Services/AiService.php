<?php

namespace App\Services;

use App\Ai\Agent;
use App\Ai\Tools\GetHardwareTool;
use App\Ai\Tools\GetTicketsTool;
use App\Ai\Tools\GetTodosTool;
use App\Ai\Tools\SearchPersonsTool;
use App\Ai\Tools\SearchUnitsTool;

class AiService
{
    public function chat(string $message, ?string $model = null): string
    {
        $agent = (new Agent())
            ->withInstructions(<<<'PROMPT'
                You are a helpful AI assistant for a health dashboard application (داشبورد سلامت).
                You can access organizational data through tools: persons, units, tickets, todos, and hardware.
                Always respond in the same language the user writes in.
                When showing data, format it clearly.
                If a tool returns empty results, tell the user.
            PROMPT)
            ->withTool(new SearchPersonsTool)
            ->withTool(new SearchUnitsTool)
            ->withTool(new GetTicketsTool)
            ->withTool(new GetTodosTool)
            ->withTool(new GetHardwareTool);

        return $agent->prompt($message, $model);
    }
}
