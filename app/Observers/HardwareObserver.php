<?php

namespace App\Observers;

use App\Models\Hardware;
use App\Models\HardwareHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class HardwareObserver
{
    /**
     * Handle the Hardware "created" event.
     */
    public function created(Hardware $hardware): void
    {
        HardwareHistory::create([
            'hardware_id' => $hardware->id,
            'user_id' => Auth::id(),
            'action' => 'created',
            'changes' => $hardware->getAttributes(),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Handle the Hardware "updated" event.
     */
    public function updated(Hardware $hardware): void
    {
        $changes = $hardware->getChanges();
        
        // Remove timestamps and unchanged fields
        unset($changes['updated_at'], $changes['created_at']);
        
        if (empty($changes)) {
            return;
        }

        $diff = [];
        foreach ($changes as $field => $newValue) {
            $oldValue = $hardware->getOriginal($field);
            if ($oldValue !== $newValue) {
                $diff[] = [
                    'field' => $field,
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        if (!empty($diff)) {
            HardwareHistory::create([
                'hardware_id' => $hardware->id,
                'user_id' => Auth::id(),
                'action' => 'updated',
                'changes' => $diff,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
            ]);
        }
    }

    /**
     * Handle the Hardware "deleted" event.
     */
    public function deleted(Hardware $hardware): void
    {
        HardwareHistory::create([
            'hardware_id' => $hardware->id,
            'user_id' => Auth::id(),
            'action' => 'deleted',
            'changes' => $hardware->getAttributes(),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
