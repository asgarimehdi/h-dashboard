<?php

namespace App\Services;

use App\Models\User;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    public function shouldSendEmail(User $user): bool
    {
        return ($user->settings['email_notifications'] ?? true) === true;
    }

    public function send(User $user, string $title, ?string $body = null, ?string $url = null): void
    {
        if (! $this->shouldSendEmail($user)) {
            return;
        }

        if (empty($user->email)) {
            return;
        }

        Mail::to($user->email)->send(new NotificationMail($title, $body, $url));
    }
}
