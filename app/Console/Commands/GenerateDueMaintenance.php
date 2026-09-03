<?php

namespace App\Console\Commands;

use App\Models\MaintenanceSchedule;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateDueMaintenance extends Command
{
    protected $signature = 'maintenance:generate-due {--dry-run : فقط نمایش موارد سررسید شده، بدون ایجاد تیکت}';

    protected $description = 'Generate due maintenance tickets from preventive maintenance schedules';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $schedules = MaintenanceSchedule::query()
            ->where(function ($q) {
                $q->whereNull('next_due_at')
                    ->orWhere('next_due_at', '<=', now());
            })
            ->get();

        $this->info('Generating due maintenance tasks...');
        $this->line("  Found {$schedules->count()} maintenance schedule(s) due.");

        if ($dryRun) {
            $this->warn('  Dry run — no tickets were created.');
            $this->info('Maintenance task generation complete.');

            return 0;
        }

        $created = 0;
        foreach ($schedules as $schedule) {
            // Issue #533: idempotency guard — skip if an open ticket already exists
            // for this schedule (prevents duplicates on scheduler overlap or crash)
            $existingTicket = Ticket::where('unit_id', $schedule->unit_id)
                ->where('subject', $schedule->title)
                ->where('status', '!=', 'completed')
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            if ($existingTicket) {
                $this->warn("  Skipping schedule #{$schedule->id} — open ticket already exists.");
                // Still advance the schedule to avoid getting stuck
                $interval = max(1, (int) $schedule->recurrence_interval);
                $next = match ($schedule->frequency) {
                    'daily' => now()->addDays($interval),
                    'weekly' => now()->addWeeks($interval),
                    'monthly' => now()->addMonths($interval),
                    default => now()->addMonths($interval),
                };
                $schedule->update([
                    'last_generated_at' => now(),
                    'next_due_at' => $next,
                ]);

                continue;
            }

            $ticket = Ticket::create([
                'ticket_code' => 'T-'.strtoupper(Str::random(8)),
                'subject' => $schedule->title,
                'content' => 'Generated from maintenance schedule #'.$schedule->id,
                'status' => 'created',
                'priority' => 'medium',
                'unit_id' => $schedule->unit_id,
            ]);

            $interval = max(1, (int) $schedule->recurrence_interval);
            $next = match ($schedule->frequency) {
                'daily' => now()->addDays($interval),
                'weekly' => now()->addWeeks($interval),
                'monthly' => now()->addMonths($interval),
                default => now()->addMonths($interval),
            };

            $schedule->update([
                'last_generated_at' => now(),
                'next_due_at' => $next,
            ]);

            $created++;
        }

        $this->line("  Created {$created} maintenance ticket(s).");
        $this->info('Maintenance task generation complete.');

        return 0;
    }
}
