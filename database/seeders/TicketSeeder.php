<?php

namespace Database\Seeders;

use App\Models\TaskActivity;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TicketSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::with('person')->get();

        if ($users->isEmpty()) {
            return;
        }

        $units = \App\Models\Unit::where('is_active', true)->get();

        if ($units->isEmpty()) {
            return;
        }

        $subjects = [
            'نیاز به تعمیرات سیستم تهویه مطبوع',
            'درخواست خرید تجهیزات پزشکی',
            'مشکل در سیستم احراز هویت',
            'نیاز به توسعه ویژگی جدید در پورتال',
            'گزارش خرابی تجهیزات آزمایشگاه',
            'درخواست تخصیص باند اینترنت بیشتر',
            'پیشنهاد بهبود فرآیند ثبت نام بیماران',
            'نیاز به آموزش پرسنل جدید',
            'مشکل در پرینترهای شبکه',
            'درخواست تمدید قرارداد پشتیبانی',
            'نیاز به ارتقای سرورهای دیتابیس',
            'گزارش مشکلات شبکه وای‌فای',
            'درخواست نصب نرم‌افزار تخصصی',
            'پیشنهاد تغییر شیفت کاری',
            'نیاز به تعمیرات سیستم آسانسور',
        ];

        $priorities = ['low', 'normal', 'urgent'];
        $statuses = ['created', 'forwarded', 'accepted', 'completed', 'rejected'];
        $actions = ['created', 'forwarded', 'accepted', 'rejected', 'finished'];

        $now = Carbon::now();
        $startDate = $now->copy()->subMonths(3);

        for ($i = 0; $i < 50; $i++) {
            $creator = $users->random();
            $assignedUser = $users->random();
            $unit = $units->random();
            $status = $statuses[array_rand($statuses)];
            $priority = $priorities[array_rand($priorities)];

            $createdAt = $startDate->copy()->addDays(rand(0, 90))->setTime(rand(8, 16), rand(0, 59));
            $ticketCode = 'TCK-' . $now->format('Ymd') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);

            $ticket = Ticket::create([
                'ticket_code' => $ticketCode,
                'user_id' => $creator->id,
                'unit_id' => $unit->id,
                'subject' => $subjects[array_rand($subjects)],
                'content' => 'توضیحات مربوط به تیکت: ' . $subjects[array_rand($subjects)] . '. لطفاً بررسی و اقدام لازم صورت گیرد.',
                'priority' => $priority,
                'status' => $status,
                'current_assignee_id' => in_array($status, ['accepted', 'forwarded']) ? $assignedUser->id : null,
                'accepted_at' => in_array($status, ['accepted', 'completed']) ? $createdAt->copy()->addHours(rand(1, 24)) : null,
                'completed_at' => $status === 'completed' ? $createdAt->copy()->addDays(rand(1, 7)) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $this->createActivities($ticket, $creator, $assignedUser, $unit, $status, $createdAt);
        }
    }

    private function createActivities(Ticket $ticket, User $creator, User $assignee, $unit, string $status, Carbon $createdAt): void
    {
        TaskActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $creator->id,
            'action' => 'created',
            'description' => 'تیکت توسط ' . ($creator->person?->f_name ?? '') . ' ' . ($creator->person?->l_name ?? '') . ' ایجاد شد.',
            'is_internal' => false,
            'to_unit_id' => $unit->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        if (in_array($status, ['forwarded', 'accepted', 'completed', 'rejected'])) {
            $forwardedAt = $createdAt->copy()->addHours(rand(1, 48));
            TaskActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => $creator->id,
                'action' => 'forwarded',
                'description' => 'تیکت به واحد ' . $unit->name . ' ارجاع داده شد.',
                'is_internal' => false,
                'to_unit_id' => $unit->id,
                'to_user_id' => $assignee->id,
                'created_at' => $forwardedAt,
                'updated_at' => $forwardedAt,
            ]);
        }

        if (in_array($status, ['accepted', 'completed'])) {
            $acceptedAt = $ticket->accepted_at ?? $createdAt->copy()->addHours(rand(24, 72));
            TaskActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => $assignee->id,
                'action' => 'accepted',
                'description' => 'تیکت توسط ' . ($assignee->person?->f_name ?? '') . ' ' . ($assignee->person?->l_name ?? '') . ' پذیرفته شد.',
                'is_internal' => false,
                'to_unit_id' => $unit->id,
                'to_user_id' => $assignee->id,
                'created_at' => $acceptedAt,
                'updated_at' => $acceptedAt,
            ]);
        }

        if ($status === 'completed') {
            $completedAt = $ticket->completed_at ?? $createdAt->copy()->addDays(rand(1, 7));
            TaskActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => $assignee->id,
                'action' => 'finished',
                'description' => 'تیکت انجام شده و بسته گردید.',
                'is_internal' => false,
                'to_unit_id' => $unit->id,
                'to_user_id' => $assignee->id,
                'created_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);
        }

        if ($status === 'rejected') {
            $rejectedAt = $createdAt->copy()->addHours(rand(2, 24));
            TaskActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => $assignee->id,
                'action' => 'rejected',
                'description' => 'تیکت رد شد: نیاز به اطلاعات بیشتر.',
                'is_internal' => false,
                'to_unit_id' => $unit->id,
                'to_user_id' => $assignee->id,
                'created_at' => $rejectedAt,
                'updated_at' => $rejectedAt,
            ]);
        }
    }
}