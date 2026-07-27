<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HardwareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'n_code' => $this->n_code,
            'pc_name' => $this->pc_name,
            'type' => $this->type,
            'os' => $this->os,
            'ip_valid' => $this->ip_valid,
            'ip_local' => $this->ip_local,
            'mac' => $this->mac,
            'net_type' => $this->net_type,
            'switch' => $this->switch,
            'port' => $this->port,
            'shutdown' => (bool) $this->shutdown,
            'vlan' => $this->vlan,
            'motherboard' => $this->motherboard,
            'cpu' => $this->cpu,
            'ram' => $this->ram,
            'hdd' => $this->hdd,
            'comments' => $this->comments,
            'mark' => (bool) $this->mark,
            'clean_at' => $this->clean_at?->format('Y-m-d'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'person' => $this->whenLoaded('person', fn () => [
                'n_code' => $this->person->n_code,
                'name' => trim($this->person->f_name . ' ' . $this->person->l_name),
                'unit' => $this->person->unit?->name,
            ]),
        ];
    }
}