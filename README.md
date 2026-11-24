# CommuniQate for Laravel

Use CommuniQate as your application’s mail provider, send Laravel Notifications over email, WhatsApp, Instagram and Messenger, and handle verified webhooks as Laravel events. The package extends the framework-independent PHP SDK. Its facade exposes all 38 public workspace operations.

## Installation

Run this command in the root of your Laravel application to install the package from Packagist.

```sh
composer require communiqate/communiqate-laravel
```

Composer installs the PHP SDK as a dependency. Laravel package discovery registers the service provider. The package supports Laravel 12 and 13 on PHP 8.3 through 8.6. Follow the [installation guide](docs/installation.md) to configure workspace credentials, mail and webhooks.

## Use the facade or inject a service

```php
use CommuniQate\Laravel\Facades\CommuniQate;

$channels = CommuniQate::channels()->list(['filter' => ['type' => 'whatsapp']]);
foreach (CommuniQate::contacts()->iterate(['per_page' => 100]) as $contact) {
    // Process one contact.
}
```

Inject `CommuniQate\Workspaces\Client` for resource access or `CommuniQate\Workspaces\Contracts\MessageSender` for sending alone. The service provider binds both to the configured SDK client. Resource methods, scope requirements and idempotency behavior are documented in the [PHP SDK](https://github.com/communiqate/communiqate-php).

## Build and send a message with the facade

```php
use CommuniQate\Laravel\Facades\CommuniQate;

$accepted = CommuniQate::forWorkspace($workspaceId)
    ->text('Your order is ready.')
    ->to($phone)
    ->channel($whatsAppChannelId)
    ->send(idempotencyKey: $deliveryId);
```

Pass the workspace and sending channel as UUIDv7 strings. In this example the recipient is a WhatsApp phone number. For Instagram and Messenger use the recipient ID scoped to the sending account or page. For an email, start with `email(['subject' => 'Receipt', 'text' => 'Payment received.'])` and pass an email address to `to()`.

The facade uses the configured API key. To use a different workspace, supply its own key via `CommuniQate::forWorkspace($workspaceId, apiKey: $workspaceKey)`. This works without a configured default workspace. If you configured a default workspace and key, you can also start directly with `CommuniQate::text(...)`. Keep the returned draft to send later, or call `build()` to inspect its payload locally. Each draft keeps its own workspace, channel and recipient. [PHP SDK message builder](https://github.com/communiqate/communiqate-php#build-for-a-workspace-and-channel)

## Use CommuniQate as the Laravel mail driver

The package registers the `communiqate` transport through Laravel's mail manager. After setting the workspace credentials, add a named mailer to the existing `mailers` array in your application's `config/mail.php` for each mailbox you want to use. Each `channel_id` is the ID of a workspace **email channel**. The mailer names and environment variable names are yours to choose. See [Laravel's custom transport documentation](https://laravel.com/framework/docs/13.x/mail#custom-transports).

```php
// Entries in config/mail.php → mailers
'orders' => [
    'transport' => 'communiqate',
    'channel_id' => env('ORDERS_EMAIL_CHANNEL_ID'),
],
'support' => [
    'transport' => 'communiqate',
    'channel_id' => env('SUPPORT_EMAIL_CHANNEL_ID'),
    'from' => ['address' => 'support@example.com', 'name' => 'Customer Support'],
],
'account-security' => [
    'transport' => 'communiqate',
    'channel_id' => env('ORDERS_EMAIL_CHANNEL_ID'),
    'sensitive' => true,
],
```

Set Laravel's default mailer to the **name** of the entry you want for ordinary mail. In a standard Laravel application, `mail.default` reads `MAIL_MAILER`.

```dotenv
MAIL_MAILER=orders
MAIL_FROM_ADDRESS=orders@example.com
MAIL_FROM_NAME="Example Store"
ORDERS_EMAIL_CHANNEL_ID=<orders-workspace-channel-id>
SUPPORT_EMAIL_CHANNEL_ID=<support-workspace-channel-id>
```

Align Laravel's From address with the connected mailbox. CommuniQate uses the selected channel as the sender. When deploying configuration changes, rebuild the configuration cache with `php artisan config:cache` and restart long-running queue workers with `php artisan queue:restart`.

### Send an existing Blade Mailable

```php
use App\Mail\OrderShipped;
use App\Mail\PasswordReset;
use App\Mail\SupportReply;
use Illuminate\Support\Facades\Mail;

Mail::to($buyer)->send(new OrderShipped($order)); // Uses the default orders mailbox.
Mail::mailer('support')->to($buyer)->send(new SupportReply($ticket));
Mail::mailer('account-security')->to($buyer)->send(new PasswordReset($resetUrl));
```

Laravel renders the existing Blade views. The transport sends the subject, `email.html` and any plain-text alternative. Each message takes one primary recipient. Ordinary mail supports cc and bcc. A successful send exposes the API message ID on Laravel's sent message. Subscribe to `messages.status_updated` for delivery results.

If the mailbox belongs to a store or tenant in your database, you can build a mailer for that send. Supply its From address explicitly. For queued work, pass the selected channel ID and sender details to a job and build the mailer inside its `handle()` method.

```php
$mailer = Mail::build(['transport' => 'communiqate', 'channel_id' => $store->email_channel_id]);
$mailer->alwaysFrom($store->sender_email, $store->name);
$mailer->to($buyer)->sendNow(new OrderShipped($order));
```

The `sensitive` mailer option sets the boolean `email.sensitive`. Use it for transactional secrets. CommuniQate masks content in API reads, webhooks and the inbox. Copies, attachments, reply references and scheduling are incompatible with this mode. Your application owns token validity and the contents of its queues and logs.

The transport rejects unsupported MIME attachments, inline CID images, multiple primary recipients, Reply-To, custom headers it cannot represent and conflicting envelope recipients. Use HTTPS image URLs. For an ordinary attachment, upload the file with `CommuniQate::files()->upload($path)` and pass its ID in `attachments` through `EmailChannel` or the SDK.

For a retried Mailable, persist one idempotency key per intended send, including its sending mailbox. Add this method to the Mailable:

```php
use Illuminate\Mail\Mailables\Headers;

public function headers(): Headers
{
    return new Headers(text: ['X-CommuniQate-Idempotency-Key' => $this->deliveryId]);
}
```

### Send an existing Laravel mail notification

A notification returning `['mail']` from `via()` uses the default mailer. To choose a mailbox or sensitive mode for one notification, select the corresponding mailer on its `MailMessage`:

```php
use Illuminate\Notifications\Messages\MailMessage;

public function toMail(object $notifiable): MailMessage
{
    return (new MailMessage())
        ->mailer('account-security')
        ->subject('Your sign-in link')
        ->action('Sign in', $this->loginUrl);
}
```

## Send through a CommuniQate notification channel

Return the Laravel channel class from `via()`. Each `toCommuniQate…()` method returns a top-level `channel_id` for the **sender** alongside its content. Pass the sender's workspace channel ID from the store, mailbox or conversation associated with this notification. The notifiable's route supplies the **recipient**. A missing recipient skips that channel. For queued notifications, pass the chosen ID into the notification when dispatching it so retries keep the same sender. The generated idempotency key includes the notification ID, channel type, sender channel ID and recipient.

| Channel | Route on a notifiable model | On-demand route |
| --- | --- | --- |
| Email | `routeNotificationForCommuniQateEmail()`, with Laravel's `mail` route as a fallback | `communiqateEmail` |
| WhatsApp | `routeNotificationForCommuniQateWhatsApp()` | `communiqateWhatsApp` |
| Instagram | `routeNotificationForCommuniQateInstagram()` | `communiqateInstagram` |
| Messenger | `routeNotificationForCommuniQateMessenger()` | `communiqateMessenger` |

### Email

Use `EmailChannel` for workspace templates or API email fields such as `sensitive` and uploaded attachment IDs. Return email fields alongside `channel_id`. A workspace template takes `email_template_id` and `data`. A rendered message takes `subject` and `html` or `text`.

```php
use CommuniQate\Laravel\Notifications\EmailChannel;
use Illuminate\Notifications\Notification;

final class OrderPacked extends Notification
{
    public function __construct(
        public string $channelId,
        public string $templateId,
        public string $orderNumber,
    ) {}

    public function via(object $notifiable): array
    {
        return [EmailChannel::class];
    }

    public function toCommuniQateEmail(object $notifiable): array
    {
        return [
            'channel_id' => $this->channelId,
            'email_template_id' => $this->templateId,
            'data' => ['orderNumber' => $this->orderNumber],
        ];
    }
}
```

Send to a model with an email address using `$buyer->notify(new OrderPacked($store->orders_email_channel_id, $templateId, $orderNumber))`. For a one-off recipient, use `Notification::route('communiqateEmail', $email)->notify(...)` on the Laravel notification facade.

### WhatsApp

Select the workspace channel connected to the sending WhatsApp number. Route to the recipient's international phone number or known WhatsApp business-scoped user ID. Use free-form text within the service window. Outside the 24-hour window, use an approved template available to the selected account.

```php
use CommuniQate\Laravel\Notifications\WhatsAppChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

final class OrderShippedWhatsApp extends Notification
{
    public function __construct(public string $channelId, public string $orderNumber) {}

    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toCommuniQateWhatsApp(object $notifiable): array
    {
        return [
            'channel_id' => $this->channelId,
            'type' => 'whatsapp_template',
            'whatsapp_template' => [
                'message_template' => 'order_shipped',
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $this->orderNumber]],
                ]],
            ],
        ];
    }
}

Notifications::route('communiqateWhatsApp', $phone)
    ->notify(new OrderShippedWhatsApp($store->whatsapp_channel_id, $orderNumber));
```

### Instagram

Store the workspace channel ID and the Instagram-scoped user ID from the conversation. Reply through that Instagram channel to a contact who messaged it first. An ordinary text reply is limited to 1,000 UTF-8 bytes and the 24-hour reply window.

```php
use CommuniQate\Laravel\Notifications\InstagramChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

final class InstagramReply extends Notification
{
    public function __construct(public string $channelId, public string $text) {}

    public function via(object $notifiable): array
    {
        return [InstagramChannel::class];
    }

    public function toCommuniQateInstagram(object $notifiable): array
    {
        return ['channel_id' => $this->channelId, 'type' => 'text', 'text' => $this->text];
    }
}

Notifications::route('communiqateInstagram', $instagramScopedUserId)
    ->notify(new InstagramReply($conversation->channel_id, 'Yes, that size is in stock.'));
```

### Messenger

Select the workspace channel for the Facebook Page that received the incoming message. Route the reply to that sender's **page-scoped user ID**. Ordinary replies have a 24-hour window, and a Messenger text message accepts up to 2,000 UTF-8 bytes. Both Messenger and Instagram also support image, video, audio and document messages.

```php
use CommuniQate\Laravel\Notifications\MessengerChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

final class MessengerReply extends Notification
{
    public function __construct(public string $channelId, public string $text) {}

    public function via(object $notifiable): array
    {
        return [MessengerChannel::class];
    }

    public function toCommuniQateMessenger(object $notifiable): array
    {
        return ['channel_id' => $this->channelId, 'type' => 'text', 'text' => $this->text];
    }
}

Notifications::route('communiqateMessenger', $pageScopedUserId)
    ->notify(new MessengerReply($conversation->channel_id, 'Your pickup is ready.'));
```

## Receive webhook events

Package discovery registers the named route `communiqate.webhook` at `POST /api/webhooks/communiqate`. Register the HTTPS URL returned by `route('communiqate.webhook')` in your workspace and set `COMMUNIQATE_WEBHOOK_SECRET` to that subscription's signing secret. The [installation guide](docs/installation.md#configure-the-webhook-route) covers the domain, path, middleware and opt-out settings.

For each verified delivery the controller dispatches the SDK's `WebhookDelivery` for existing catch-all listeners, followed by one specific Laravel event. The specific event exposes the same immutable envelope as `$event->delivery`, including its ID, event-specific `data`, workspace ID, subscription ID and test flag.

| Workspace webhook | Laravel event in `CommuniQate\Laravel\Events` |
| --- | --- |
| `messages.inbound` | `MessageInbound` |
| `messages.outbound` | `MessageOutbound` |
| `messages.status_updated` | `MessageStatusUpdated` |
| `templates.created` | `TemplateCreated` |
| `templates.status_changed` | `TemplateStatusChanged` |
| `templates.category_changed` | `TemplateCategoryChanged` |
| `templates.deleted` | `TemplateDeleted` |

For example, place this listener in `app/Listeners/UpdateDeliveryStatus.php`. Laravel's event discovery uses the `handle()` parameter type to subscribe it only to message status changes.

```php
namespace App\Listeners;

use CommuniQate\Laravel\Events\MessageStatusUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;

final class UpdateDeliveryStatus implements ShouldQueue
{
    public function handle(MessageStatusUpdated $event): void
    {
        $delivery = $event->delivery;
        if ($delivery->isTest) {
            return;
        }

        // Apply $delivery->data['status'] to $delivery->data['message_id'].
        // Use $delivery->data['occurred_at'] to order changes.
        // Record $delivery->id and the update in the same transaction.
    }
}
```

The controller verifies the original body against the `Signature` header before dispatching either event. Invalid signatures yield HTTP 401. Invalid signed envelopes yield 422. Successful dispatch returns 204. Configure a persistent queue and worker for queued listeners. CommuniQate can retry a delivery with the same ID, so deduplicate at the point where your listener changes application state.
