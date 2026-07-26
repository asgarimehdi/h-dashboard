<?php

namespace App\Ai\Agents;

use App\Ai\Agent;
use App\Ai\Tools\Hardware\HardwareStatsTool;
use App\Ai\Tools\Hardware\PersonHardwareTool;
use App\Ai\Tools\Hardware\SearchHardwareTool;
use App\Ai\Tools\Hardware\UpdateHardwareTool;

class HardwareAgent extends Agent
{
    public function __construct()
    {
        $this->withInstructions(
            'You are a hardware inventory assistant. ' .
            'Search and manage hardware records (PC name, IP, MAC, CPU, RAM, HDD, OS, owner). ' .
            'Always respond in the same language the user writes in. ' .
            'Format data clearly.'
        );

        $this->withTool(new SearchHardwareTool)
            ->withTool(new HardwareStatsTool)
            ->withTool(new PersonHardwareTool)
            ->withTool(new UpdateHardwareTool);
    }
}
