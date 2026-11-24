<?php

declare(strict_types=1);

namespace CommuniQate\Laravel;

use CommuniQate\Laravel\Mail\MailTransport;
use CommuniQate\Workspaces\Client;
use CommuniQate\Workspaces\Contracts\MessageSender;
use CommuniQate\Workspaces\Webhooks\WebhookVerifier;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class CommuniQateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/communiqate.php', 'communiqate');
        $this->app->singleton(Client::class, static function ($app): Client {
            $config = $app['config']['communiqate'];
            return new Client($config['api_key'], $config['workspace'], baseUrl: $config['base_url']);
        });
        $this->app->alias(Client::class, MessageSender::class);
        $this->app->singleton(WebhookVerifier::class, fn ($app) => new WebhookVerifier(
            (string) $app['config']['communiqate.webhook_secret']
        ));
    }

    public function boot(): void
    {
        $this->publishes([dirname(__DIR__).'/config/communiqate.php' => config_path('communiqate.php')], 'communiqate-config');
        if ($this->app['config']->get('communiqate.webhook_route_enabled')) {
            $this->loadRoutesFrom(dirname(__DIR__).'/routes/webhooks.php');
        }

        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('communiqate', function (array $config): MailTransport {
                $channelId = $config['channel_id'] ?? null;
                if (!is_string($channelId) || trim($channelId) === '') {
                    throw new InvalidArgumentException('Set channel_id to a workspace email channel ID on the CommuniQate mailer in config/mail.php.');
                }

                return new MailTransport($this->app->make(MessageSender::class), $channelId, $config['sensitive'] ?? false);
            });
        });
    }
}
