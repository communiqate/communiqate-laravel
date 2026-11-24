<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Facades;

use CommuniQate\Workspaces\Client;
use CommuniQate\Workspaces\MessageBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Client forWorkspace(string $workspace, ?string $apiKey = null)
 * @method static MessageBuilder text(string $text)
 * @method static MessageBuilder email(array $email)
 * @mixin Client
 */
final class CommuniQate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
