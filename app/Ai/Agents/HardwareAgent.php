<?php

namespace App\Ai\Agents;

use App\Ai\Agent;
use App\Ai\Tools\Hardware\CreateHardwareTool;
use App\Ai\Tools\Hardware\DeleteHardwareTool;
use App\Ai\Tools\Hardware\ExportHardwareTool;
use App\Ai\Tools\Hardware\HardwareStatsTool;
use App\Ai\Tools\Hardware\PersonHardwareTool;
use App\Ai\Tools\Hardware\SearchHardwareTool;
use App\Ai\Tools\Hardware\UpdateHardwareTool;
use App\Ai\Tools\SearchPersonsTool;
use App\Ai\Tools\SearchUnitsTool;

class HardwareAgent extends Agent
{
    public function __construct()
    {
        $this->withInstructions(
            'You are a hardware inventory assistant. ' .
            'Search and manage hardware records (PC name, IP, MAC, CPU, RAM, HDD, OS, owner). ' .
            'Always respond in the same language the user writes in. ' .
            'Format data clearly. ' .
            'If you need to create or delete a record, specify the required fields or ask for confirmation.'
        );

        $this->withTool(new SearchHardwareTool)
            ->withTool(new HardwareStatsTool)
            ->withTool(new PersonHardwareTool)
            ->withTool(new UpdateHardwareTool)
            ->withTool(new CreateHardwareTool)
            ->withTool(new DeleteHardwareTool)
            ->withTool(new ExportHardwareTool)
            ->withTool(new SearchPersonsTool)
            ->withTool(new SearchUnitsTool);
    }
}

