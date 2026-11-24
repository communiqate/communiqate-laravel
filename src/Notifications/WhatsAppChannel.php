<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Notifications;

use CommuniQate\Workspaces\ChannelType;

final class WhatsAppChannel extends MessagingChannel
{
    public const CHANNEL = ChannelType::WhatsApp;
}
