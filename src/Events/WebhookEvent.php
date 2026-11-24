<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Events;

use CommuniQate\Workspaces\Webhooks\WebhookDelivery;
use CommuniQate\Workspaces\Webhooks\WebhookEvent as EventType;

abstract readonly class WebhookEvent
{
    public function __construct(public WebhookDelivery $delivery) {}

    public static function fromDelivery(WebhookDelivery $delivery): self
    {
        return match ($delivery->event) {
            EventType::MessagesInbound => new MessageInbound($delivery),
            EventType::MessagesOutbound => new MessageOutbound($delivery),
            EventType::MessagesStatusUpdated => new MessageStatusUpdated($delivery),
            EventType::TemplatesCreated => new TemplateCreated($delivery),
            EventType::TemplatesStatusChanged => new TemplateStatusChanged($delivery),
            EventType::TemplatesCategoryChanged => new TemplateCategoryChanged($delivery),
            EventType::TemplatesDeleted => new TemplateDeleted($delivery),
        };
    }
}
