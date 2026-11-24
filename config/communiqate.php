<?php

declare(strict_types=1);

use CommuniQate\Workspaces\Client;

return [
    // API credentials for outgoing requests. Webhook verification uses its own secret.
    'api_key' => env('COMMUNIQATE_API_KEY'),
    'workspace' => env('COMMUNIQATE_WORKSPACE'),
    'base_url' => env('COMMUNIQATE_BASE_URL', Client::BASE_URL),

    // Use the signing secret of the subscription pointing to this endpoint.
    'webhook_secret' => env('COMMUNIQATE_WEBHOOK_SECRET'),

    // The path is relative to the application root. There is no implicit API prefix.
    'webhook_route_enabled' => env('COMMUNIQATE_WEBHOOK_ROUTE_ENABLED', true),
    'webhook_path' => env('COMMUNIQATE_WEBHOOK_PATH', 'api/webhooks/communiqate'),
    'webhook_domain' => env('COMMUNIQATE_WEBHOOK_DOMAIN'),
    'webhook_middleware' => [],
];
