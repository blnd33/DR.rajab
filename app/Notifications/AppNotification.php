<?php

namespace App\Notifications;

use App\Enums\NotificationChannelType;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Base for every OrthoTrack notification (Module 25): in-app always (unless the user turned the
 * event off), web push when the user subscribed a device. Texts are rendered in the recipient's
 * language (User implements HasLocalePreference). A WhatsApp channel can be added here later.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Key used in notification_preferences, e.g. "stock.expiry_level".
     */
    abstract public function eventKey(): string;

    abstract public function title(): string;

    abstract public function body(): string;

    public function url(): ?string
    {
        return null;
    }

    public function icon(): string
    {
        return 'heroicon-o-bell';
    }

    /**
     * @return list<string|class-string>
     */
    public function via(User $notifiable): array
    {
        $channels = [];

        if ($this->enabled($notifiable, NotificationChannelType::Database)) {
            $channels[] = 'database';
        }

        if (filled(config('webpush.vapid.public_key'))
            && $this->enabled($notifiable, NotificationChannelType::WebPush)
            && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => $this->eventKey(),
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'icon' => $this->icon(),
        ];
    }

    public function toWebPush(User $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->body($this->body())
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->tag($this->eventKey())
            ->data(['url' => $this->url() ?? url('/app/notifications')]);
    }

    private function enabled(User $user, NotificationChannelType $channel): bool
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event_key', $this->eventKey())
            ->where('channel', $channel)
            ->value('enabled');

        return $preference === null ? true : (bool) $preference;
    }
}
