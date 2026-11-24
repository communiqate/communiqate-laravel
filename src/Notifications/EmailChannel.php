<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Notifications;

use CommuniQate\Workspaces\ChannelType;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

final class EmailChannel extends MessagingChannel
{
    public const CHANNEL = ChannelType::Email;

    protected function recipient(object $notifiable, Notification $notification): ?string
    {
        $to = $notifiable->routeNotificationFor('communiqateEmail', $notification)
            ?? $notifiable->routeNotificationFor('mail', $notification);
        if ($to === null || $to === '' || $to === []) {
            return null;
        }
        if (is_array($to) && count($to) === 1) {
            $to = array_is_list($to) ? reset($to) : array_key_first($to);
        }
        if (!is_string($to)) {
            throw new InvalidArgumentException('Route a CommuniQate email notification to one address.');
        }
        return $to;
    }
}
