<?php

namespace App\Services\Movement;

use App\Models\User;
use App\Notifications\DocumentEvent;
use App\Services\Stock\AlertRecipients;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a DocumentEvent to everyone holding a permission in a branch (optionally without
 * the person who did the action), plus extra named users.
 */
class Notifier
{
    public function __construct(private readonly AlertRecipients $recipients) {}

    /**
     * @param  list<User|null>  $alsoNotify
     */
    public function toBranch(string $permission, int $branchId, DocumentEvent $event, ?User $except = null, array $alsoNotify = []): void
    {
        $users = $this->recipients->for($permission, $branchId);

        foreach ($alsoNotify as $user) {
            if ($user !== null && ! $users->contains('id', $user->id)) {
                $users->push($user);
            }
        }

        if ($except !== null) {
            $users = $users->reject(fn (User $u) => $u->id === $except->id)->values();
        }

        if ($users->isNotEmpty()) {
            Notification::send($users, $event);
        }
    }

    public function toUser(?User $user, DocumentEvent $event): void
    {
        if ($user !== null && $user->is_active) {
            $user->notify($event);
        }
    }
}
