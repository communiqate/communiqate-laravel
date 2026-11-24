# Installation and configuration

## Install with Composer

Run this command in the root of your Laravel application to install the integration from Packagist.

```sh
composer require communiqate/communiqate-laravel
```

Use Laravel 12 or 13 with PHP 8.3 through 8.6 and the cURL, JSON and mbstring extensions. Composer installs the PHP SDK automatically. Laravel package discovery registers the service provider.

## Configure the workspace

Create a key under **Settings → API & webhooks**. Grant `messages:write` for sending and `channels:read` if you need to list channels. Use the channel ID from `CommuniQate::channels()->list()`. The connected WhatsApp account or mailbox can have a different ID.

```dotenv
COMMUNIQATE_API_KEY=<workspace-api-key>
COMMUNIQATE_WORKSPACE=<workspace-id>
COMMUNIQATE_WEBHOOK_SECRET=<endpoint-signing-secret>
```

For a webhook-only installation, the subscription signing secret is sufficient. Package discovery registers the route automatically. Publish the optional configuration with `php artisan vendor:publish --tag=communiqate-config`. If your application disables package discovery, register `CommuniQate\Laravel\CommuniQateServiceProvider::class` in `bootstrap/providers.php`.

## Choose sending channels

One workspace can contain several channels of each type. Store their workspace channel IDs alongside the stores, brands or conversations that use them. For example, an Instagram reply needs the channel ID and the recipient ID associated with the same Instagram account.

Supply the sending channel with `channel($channelId)` on a facade message or with `channel_id` in a native notification. Workspace and channel IDs are UUIDv7 strings. A named Laravel mailer takes its own `channel_id` in `config/mail.php`.

```php
use CommuniQate\Laravel\Facades\CommuniQate;

CommuniQate::forWorkspace($store->workspace_id, apiKey: $store->workspace_api_key)
    ->text('Your order is ready.')
    ->to($buyer->phone)
    ->channel($store->whatsapp_channel_id)
    ->send();
```

For applications that look up credentials per workspace, the facade accepts the workspace and API key at call time. Set `COMMUNIQATE_WORKSPACE` and `COMMUNIQATE_API_KEY` for mailers, native notification channels and calls that use the default client. Each notification example shows the sender and its Laravel recipient route. [Email](../README.md#email) · [WhatsApp](../README.md#whatsapp) · [Instagram](../README.md#instagram) · [Messenger](../README.md#messenger)

## Use CommuniQate as the mail provider

1. Add an entry under `mailers` in your application's `config/mail.php`, for example `'orders' => ['transport' => 'communiqate', 'channel_id' => env('ORDERS_EMAIL_CHANNEL_ID')]`.
2. Set `ORDERS_EMAIL_CHANNEL_ID` to the workspace email channel ID, `MAIL_MAILER=orders` and `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` to the mailbox's address and display name.
3. Send with `Mail::to($buyer)->send(new OrderShipped($order))`. To use another mailbox, add a second named mailer and select it with `Mail::mailer('support')`. To send sensitive mail, add `'sensitive' => true` to a dedicated mailer.
4. Rebuild cached configuration with `php artisan config:cache` and restart queue workers with `php artisan queue:restart`.

The [mail driver examples](../README.md#use-communiqate-as-the-laravel-mail-driver) cover multiple mailboxes, Blade Mailables, mail notifications and delivery tracking.

## Configure the webhook route

The default route is `POST /api/webhooks/communiqate`, named `communiqate.webhook`. To inspect it, run `php artisan route:list --name=communiqate.webhook`. Use the public HTTPS URL of this route as the subscription's delivery URL. The package verifies the body with the endpoint's `COMMUNIQATE_WEBHOOK_SECRET`.

| Configuration key in `config/communiqate.php` | Environment variable | Default |
| --- | --- | --- |
| `webhook_route_enabled` | `COMMUNIQATE_WEBHOOK_ROUTE_ENABLED` | `true` |
| `webhook_path` | `COMMUNIQATE_WEBHOOK_PATH` | `api/webhooks/communiqate` |
| `webhook_domain` | `COMMUNIQATE_WEBHOOK_DOMAIN` | Any application host |
| `webhook_middleware` | Set the array in the published config | `[]` |

The path is relative to the application root. To add middleware, publish the config and set, for example, `'webhook_middleware' => ['throttle:120,1']`. Keep the receiver accessible to CommuniQate without a browser session. After changing these settings, rebuild any config and route caches used by your deployment.

## Updating an earlier local checkout

Move the values of the old `COMMUNIQATE_EMAIL_CHANNEL`, `COMMUNIQATE_WHATSAPP_CHANNEL`, `COMMUNIQATE_INSTAGRAM_CHANNEL` and `COMMUNIQATE_MESSENGER_CHANNEL` settings into your named Laravel mailers or application records. If you previously published a channel map, move its sender IDs into your application and remove the `channels` entry from `config/communiqate.php`. Add `channel_id` to each native notification's content method. If you use the earlier `communiqate` or `communiqate-sensitive` mailer names, define those entries in `config/mail.php` with their channel IDs and set `sensitive` on the latter. Finish pending sends with the old workers before upgrading, because native notification retries now include the sender in their idempotency keys.

If you registered the controller manually, remove that route when using the automatic one. To keep your own route, set `COMMUNIQATE_WEBHOOK_ROUTE_ENABLED=false` and point your POST route at `CommuniQate\Laravel\Http\Controllers\WebhookController::class`. Existing `WebhookDelivery` listeners still receive verified deliveries.

Update earlier root-level imports to `CommuniQate\Laravel\Notifications` for channels, `CommuniQate\Laravel\Mail\MailTransport` for the mail transport and `CommuniQate\Laravel\Http\Controllers\WebhookController` for the controller. Run `composer update communiqate/communiqate-laravel` in the consuming app to refresh the package autoload metadata after upgrading.
