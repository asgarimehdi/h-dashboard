<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Ai\Traits\AiAccessScope;
use App\Models\Hardware;

class ExportHardwareTool extends Tool
{
    use AiAccessScope;

    public function name(): string
    {
        return 'export_hardware';
    }

    public function description(): string
    {
        return 'Export hardware inventory as CSV. Supports optional filters by type, os, cpu, shutdown status, person name, or unit. Respects organizational access scope.';
    }

    public function parameters(): array
    {
        return [
            'format' => [
                'type' => 'string',
                'enum' => ['csv'],
                'description' => 'Export format (csv only for now)',
            ],
            'type' => [
                'type' => 'string',
                'description' => 'Optional: filter by device type (pc, laptop, server, etc.)',
            ],
            'os' => [
                'type' => 'string',
                'description' => 'Optional: filter by operating system',
            ],
            'cpu' => [
                'type' => 'string',
                'description' => 'Optional: filter by CPU model',
            ],
            'shutdown' => [
                'type' => 'boolean',
                'description' => 'Optional: filter by shutdown status (true/false)',
            ],
            'person' => [
                'type' => 'string',
                'description' => 'Optional: filter by person name or n_code',
            ],
            'unit' => [
                'type' => 'string',
                'description' => 'Optional: filter by unit name',
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $query = $this->scopedHardwareQuery()->with('person.unit');

        // Apply optional filters (AND logic)
        if (! empty($arguments['type'])) {
            $type = $arguments['type'];
            $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
            $type = $typeAliases[$type] ?? $type;
            $query->where('type', 'LIKE', "%{$type}%");
        }

        if (! empty($arguments['os'])) {
            $query->where('os', 'LIKE', "%{$arguments['os']}%");
        }

        if (! empty($arguments['cpu'])) {
            $query->where('cpu', 'LIKE', "%{$arguments['cpu']}%");
        }

        if (isset($arguments['shutdown']) && $arguments['shutdown'] !== '') {
            $query->where('shutdown', filter_var($arguments['shutdown'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($arguments['person'])) {
            $normalized = \App\Traits\PersianNormalizer::normalizeForSearch($arguments['person']);
            $query->whereHas('person', function ($q) use ($normalized) {
                $q->where('f_name', 'LIKE', "%{$normalized}%")
                  ->orWhere('l_name', 'LIKE', "%{$normalized}%")
                  ->orWhere('n_code', 'LIKE', "%{$normalized}%");
            });
        }

        if (! empty($arguments['unit'])) {
            $normalized = \App\Traits\PersianNormalizer::normalizeForSearch($arguments['unit']);
            $query->whereHas('person.unit', function ($q) use ($normalized) {
                $q->where('name', 'LIKE', "%{$normalized}%");
            });
        }

        $records = $query->orderBy('id')->get();

        if ($records->isEmpty()) {
            return 'No hardware records found matching the criteria within your access scope.';
        }

        return $this->toCsv($records);
    }

    private function toCsv($records): string
    {
        $headers = ['ID', 'PC Name', 'Type', 'OS', 'IP (Valid)', 'IP (Local)', 'MAC', 'CPU', 'RAM', 'HDD', 'Net Type', 'Shutdown', 'Marked', 'Owner Name', 'Owner Unit'];

        $lines = [implode(',', array_map(fn($h) => $this->escapeCsv($h), $headers))];

        foreach ($records as $hw) {
            $lines[] = implode(',', [
                $hw->id,
                $this->escapeCsv($hw->pc_name),
                $this->escapeCsv($hw->type),
                $this->escapeCsv($hw->os),
                $this->escapeCsv($hw->ip_valid),
                $this->escapeCsv($hw->ip_local),
                $this->escapeCsv($hw->mac),
                $this->escapeCsv($hw->cpu),
                $this->escapeCsv($hw->ram),
                $this->escapeCsv($hw->hdd),
                $this->escapeCsv($hw->net_type),
                $hw->shutdown ? 'Yes' : 'No',
                $hw->mark ? 'Yes' : 'No',
                $this->escapeCsv($hw->person ? trim($hw->person->f_name . ' ' . $hw->person->l_name) : ''),
                $this->escapeCsv($hw->person?->unit?->name ?? ''),
            ]);
        }

        return "```csv\n" . implode("\n", $lines) . "\n```";
    }

    private function escapeCsv(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Escape double quotes and wrap in quotes if contains comma, quote, or newline
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}