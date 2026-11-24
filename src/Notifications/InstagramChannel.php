<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Notifications;

use CommuniQate\Workspaces\ChannelType;

final class InstagramChannel extends MessagingChannel
{
    public const CHANNEL = ChannelType::Instagram;
}
