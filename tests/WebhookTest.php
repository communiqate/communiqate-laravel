<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Tests;

use CommuniQate\Laravel\CommuniQateServiceProvider;
use CommuniQate\Laravel\Events;
use CommuniQate\Workspaces\Webhooks\WebhookDelivery;
use CommuniQate\Workspaces\Webhooks\WebhookEvent;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private Application $app;

    private function boot(array $config = [], bool $routesCached = false): void
    {
        $this->app = new Application(sys_get_temp_dir());
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository([
            'communiqate' => $config + ['webhook_secret' => 'subscription-secret'],
        ]));
        $this->app->register(FilesystemServiceProvider::class);
        $this->app->instance('routes.cached', $routesCached);
        $this->app->register(CommuniQateServiceProvider::class);
        $this->app->boot();
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $this->app->flush();
        Application::setInstance(null);
        parent::tearDown();
    }

    public static function events(): array
    {
        return [
            'inbound message' => [WebhookEvent::MessagesInbound, Events\MessageInbound::class],
            'outbound message' => [WebhookEvent::MessagesOutbound, Events\MessageOutbound::class],
            'message status' => [WebhookEvent::MessagesStatusUpdated, Events\MessageStatusUpdated::class],
            'new template' => [WebhookEvent::TemplatesCreated, Events\TemplateCreated::class],
            'template status' => [WebhookEvent::TemplatesStatusChanged, Events\TemplateStatusChanged::class],
            'template category' => [WebhookEvent::TemplatesCategoryChanged, Events\TemplateCategoryChanged::class],
            'deleted template' => [WebhookEvent::TemplatesDeleted, Events\TemplateDeleted::class],
        ];
    }

    private function route(): ?Route
    {
        return $this->app['router']->getRoutes()->getByName('communiqate.webhook');
    }

    private function post(string $body, ?string $signature): int
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $headers['HTTP_SIGNATURE'] = $signature;
        }
        $request = Request::create('/api/webhooks/communiqate', 'POST', server: $headers, content: $body);
        $this->app->instance('request', $request);
        return $this->app['router']->dispatch($request)->getStatusCode();
    }

    #[DataProvider('events')]
    public function testVerifiedDeliveryDispatchesItsSpecificEvent(WebhookEvent $type, string $class): void
    {
        $this->boot();
        self::assertSame(['POST'], $this->route()->methods());
        self::assertSame('api/webhooks/communiqate', $this->route()->uri());
        $deliveries = [];
        $specific = [];
        $this->app['events']->listen(WebhookDelivery::class, function (WebhookDelivery $delivery) use (&$deliveries): void {
            $deliveries[] = $delivery;
        });
        $this->app['events']->listen('CommuniQate\\Laravel\\Events\\*', function (string $name, array $events) use (&$specific): void {
            $specific[] = $events[0];
        });

        $body = json_encode([
            'id' => 'delivery', 'event' => $type->value, 'version' => 'v1', 'data' => ['field' => 'value'],
            'metadata' => ['workspace_id' => 'workspace', 'subscription_id' => 'subscription', 'is_test' => true],
        ], JSON_THROW_ON_ERROR);
        self::assertSame(204, $this->post($body, hash_hmac('sha256', $body, 'subscription-secret')));
        self::assertCount(1, $deliveries);
        self::assertCount(1, $specific);
        self::assertInstanceOf($class, $specific[0]);
        self::assertSame($deliveries[0], $specific[0]->delivery);
        self::assertSame($type, $specific[0]->delivery->event);
        self::assertTrue($specific[0]->delivery->isTest);
        self::assertSame(['field' => 'value'], $specific[0]->delivery->data);
    }

    public function testRejectedRequestsDispatchNoWebhookEvents(): void
    {
        $this->boot();
        $seen = [];
        $this->app['events']->listen('CommuniQate\\*', function (string $name) use (&$seen): void {
            $seen[] = $name;
        });
        self::assertSame(401, $this->post('{', null));
        self::assertSame(401, $this->post('{', 'wrong'));
        self::assertSame(422, $this->post('{', hash_hmac('sha256', '{', 'subscription-secret')));
        self::assertSame([], $seen);
    }

    public function testRouteCanBeCustomized(): void
    {
        $this->boot([
            'webhook_path' => 'hooks/workspace',
            'webhook_domain' => 'hooks.example.com',
            'webhook_middleware' => ['throttle:120,1'],
        ]);
        $route = $this->route();
        self::assertSame('hooks/workspace', $route->uri());
        self::assertSame('hooks.example.com', $route->getDomain());
        self::assertContains('throttle:120,1', $route->gatherMiddleware());
    }

    public function testRouteCanBeDisabled(): void
    {
        $this->boot(['webhook_route_enabled' => false]);
        self::assertNull($this->route());
    }

    public function testProviderSkipsRegistrationWhenUsingCachedRoutes(): void
    {
        $this->boot(routesCached: true);
        self::assertNull($this->route());
    }
}
