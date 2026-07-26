<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Hardware\HardwareStats;
use App\Ai\Tools\Hardware\PersonHardware;
use App\Ai\Tools\Hardware\SearchHardware;
use App\Ai\Tools\Hardware\UpdateHardware;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class HardwareAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * System instructions for the hardware agent.
     */
    public function instructions(): string
    {
        return <<<'PROMPT'
            You are a hardware inventory assistant (شناسنامه سخت‌افزار) for a health dashboard application.
            You manage and query hardware records including PCs, laptops, and network equipment.

            Each hardware record has these fields:
            - n_code: Owner's national code (FK to persons table)
            - pc_name: PC/hostname
            - type: Device type (desktop, laptop, server, etc.)
            - os: Operating system
            - ip_valid: Public/valid IP address
            - ip_local: Local/private IP address
            - mac: MAC address
            - net_type: Network type
            - switch: Network switch name
            - port: Switch port number
            - shutdown: Whether the device is shut down (boolean)
            - vlan: VLAN identifier
            - motherboard: Motherboard model
            - cpu: Processor info
            - ram: RAM size/info
            - hdd: Storage (HDD/SSD) info
            - comments: Free-text notes
            - mark: Flag/mark (boolean)
            - clean_at: Last cleaning date

            Guidelines:
            - Always respond in the same language the user writes in (Persian or English).
            - When presenting hardware data, format it clearly in a readable table or list.
            - For searches, try multiple fields (pc_name, ip_valid, ip_local, mac, n_code) to maximize results.
            - When updating hardware, confirm the changes clearly before and after.
            - If no results are found, suggest broader search terms.
            - When showing owner info, include the person's full name from the persons table.
        PROMPT;
    }

    /**
     * Tools available to this agent.
     *
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return [
            new SearchHardware,
            new HardwareStats,
            new PersonHardware,
            new UpdateHardware,
        ];
    }
}
