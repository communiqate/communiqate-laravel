<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Notifications;

use CommuniQate\Workspaces\ChannelType;
use CommuniQate\Workspaces\Contracts\MessageSender;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

abstract class MessagingChannel
{
    public function __construct(private readonly MessageSender $client) {}

    public function send(object $notifiable, Notification $notification): ?array
    {
        $to = $this->recipient($notifiable, $notification);
        if ($to === null) {
            return null;
        }

        $channel = static::CHANNEL;
        $method = 'toCommuniQate'.$channel->name;
        $content = $notification->$method($notifiable);
        $channelId = $content['channel_id'] ?? null;
        if (!is_string($channelId) || trim($channelId) === '') {
            throw new InvalidArgumentException("Return a non-empty workspace channel_id from {$method}().");
        }
        unset($content['channel_id']);

        $key = $notification->id
            ? $channel->value.':'.hash('sha256', json_encode([$notification->id, $channelId, $to], JSON_THROW_ON_ERROR))
            : null;

        return $channel === ChannelType::Email
            ? $this->client->sendEmail($channelId, $to, $content, $key)
            : $this->client->sendOnChannel($channel, $channelId, $to, $content, $key);
    }

    protected function recipient(object $notifiable, Notification $notification): ?string
    {
        $name = static::CHANNEL->name;
        $to = $notifiable->routeNotificationFor('communiqate'.$name, $notification);
        if ($to === null || $to === '') {
            return null;
        }
        if (!is_string($to)) {
            throw new InvalidArgumentException("Route the $name notification to a single channel-specific recipient ID.");
        }
        return $to;
    }
}
