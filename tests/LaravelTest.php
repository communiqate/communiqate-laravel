<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Tests;

use CommuniQate\Workspaces\Client;
use CommuniQate\Laravel\Facades\CommuniQate;
use CommuniQate\Laravel\Notifications\InstagramChannel;
use CommuniQate\Laravel\Notifications\MessengerChannel;
use CommuniQate\Laravel\CommuniQateServiceProvider;
use CommuniQate\Laravel\Notifications\EmailChannel;
use CommuniQate\Laravel\Notifications\WhatsAppChannel;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\NotificationServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

final class LaravelTest extends TestCase
{
    private const WORKSPACE_ID = '019ad5a2-7740-7080-9dd8-47a000000001';
    private const OTHER_WORKSPACE_ID = '019ad5a2-7740-7080-9dd8-47a000000002';

    private Application $app;
    private string $temp;
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp = sys_get_temp_dir().'/communiqate-test-'.bin2hex(random_bytes(8));
        mkdir($this->temp.'/views', 0777, true);
        $this->app = new Application($this->temp);
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'key' => str_repeat('x', 32)],
            'view' => ['paths' => [__DIR__.'/views'], 'compiled' => $this->temp.'/views'],
            'mail' => [
                'default' => 'orders',
                'from' => ['address' => 'shop@example.com', 'name' => 'Shop'],
                'mailers' => [
                    'orders' => ['transport' => 'communiqate', 'channel_id' => 'orders-id'],
                    'accounts' => ['transport' => 'communiqate', 'channel_id' => 'accounts-id', 'sensitive' => true],
                ],
            ],
            'communiqate' => ['webhook_secret' => 'secret'],
        ]));
        foreach ([BusServiceProvider::class, EventServiceProvider::class, FilesystemServiceProvider::class, ViewServiceProvider::class,
            TranslationServiceProvider::class, MailServiceProvider::class, NotificationServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $manifest = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        self::assertSame([CommuniQateServiceProvider::class], $manifest['extra']['laravel']['providers']);
        foreach ($manifest['extra']['laravel']['providers'] as $provider) {
            $this->app->register($provider);
        }
        $this->app->boot();
        $handler = HandlerStack::create(new MockHandler(array_fill(0, 3, new Response(201, [], '{"message_id":"api-id"}'))));
        $handler->push(Middleware::history($this->history));
        $this->app->instance(Client::class, new Client('key', self::WORKSPACE_ID, new Guzzle(['handler' => $handler])));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->temp);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $this->app->flush();
        Application::setInstance(null);
        parent::tearDown();
    }

    private function body(int $i = 0): array
    {
        return json_decode((string) $this->history[$i]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testMessageSenderBinding(): void
    {
        self::assertSame($this->app->make(Client::class), $this->app->make(\CommuniQate\Workspaces\Contracts\MessageSender::class));
    }

    public function testFacadeDelegatesToConfiguredSdk(): void
    {
        CommuniQate::listChannels(['filter' => ['type' => 'instagram']]);
        self::assertSame('GET', $this->history[0]['request']->getMethod());
        self::assertSame('/workspaces/v1/'.self::WORKSPACE_ID.'/channels', $this->history[0]['request']->getUri()->getPath());
    }

    public function testFacadeCanSendFromASelectedWorkspaceAndChannel(): void
    {
        $draft = CommuniQate::forWorkspace(self::OTHER_WORKSPACE_ID, apiKey: 'second-key')
            ->text('Your order is ready.')->to('+31600000000')->channel('store-whatsapp');
        self::assertSame([], $this->history);
        self::assertSame(['message_id' => 'api-id'], $draft->send());
        self::assertSame([
            'channel_id' => 'store-whatsapp', 'to' => '+31600000000', 'type' => 'text', 'text' => 'Your order is ready.',
        ], $this->body());
        $scoped = $this->history[0]['request'];
        self::assertSame('/workspaces/v1/'.self::OTHER_WORKSPACE_ID.'/messages', $scoped->getUri()->getPath());
        self::assertSame('Bearer second-key', $scoped->getHeaderLine('Authorization'));
        CommuniQate::text('Another message')->channel('primary')->to('+31600000001')->send();
        self::assertSame('/workspaces/v1/'.self::WORKSPACE_ID.'/messages', $this->history[1]['request']->getUri()->getPath());
        self::assertSame('Bearer key', $this->history[1]['request']->getHeaderLine('Authorization'));
    }

    public function testDiscoveredProviderAcceptsExplicitWorkspaceCredentialsWithoutDefaults(): void
    {
        $this->app->forgetInstance(Client::class);
        $payload = CommuniQate::forWorkspace(self::OTHER_WORKSPACE_ID, apiKey: 'tenant-key')
            ->email(['subject' => 'Receipt', 'text' => 'Paid'])->channel('billing')->to('buyer@example.com')->build();
        self::assertSame('billing', $payload['channel_id']);
        self::assertSame('Paid', $payload['email']['text']);
        self::assertArrayNotHasKey('channels', $this->app['config']->get('communiqate'));
    }

    public function testInstagramAndMessengerNotificationsUseTheirOwnRoutesAndKeys(): void
    {
        $notification = new class extends Notification {
            public function via(object $notifiable): array { return [InstagramChannel::class, MessengerChannel::class]; }
            public function toCommuniQateInstagram(object $notifiable): array { return ['channel_id' => 'ig', 'type' => 'text', 'text' => 'Instagram reply']; }
            public function toCommuniQateMessenger(object $notifiable): array { return ['channel_id' => 'fb', 'type' => 'text', 'text' => 'Messenger reply']; }
        };
        $recipient = (new AnonymousNotifiable())->route('communiqateInstagram', 'ig-id')->route('communiqateMessenger', 'psid');
        $recipient->notify($notification);
        self::assertSame(['channel_id' => 'ig', 'to' => 'ig-id', 'type' => 'text', 'text' => 'Instagram reply'], $this->body());
        self::assertSame(['channel_id' => 'fb', 'to' => 'psid', 'type' => 'text', 'text' => 'Messenger reply'], $this->body(1));
        self::assertStringStartsWith('instagram:', $this->history[0]['request']->getHeaderLine('Idempotency-Key'));
        self::assertStringStartsWith('messenger:', $this->history[1]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function testExistingBladeMailableViaSensitiveMailer(): void
    {
        $sent = $this->app['mail.manager']->mailer('accounts')->to('buyer@example.com')->send(new ReceiptMail());
        self::assertSame('api-id', $sent->getMessageId());
        $body = $this->body();
        self::assertSame(['accounts-id', 'email', 'buyer@example.com'], [$body['channel_id'], $body['type'], $body['to']]);
        self::assertTrue($body['email']['sensitive']);
        self::assertSame('Your receipt', $body['email']['subject']);
        self::assertStringContainsString('<h1>Receipt 42</h1>', $body['email']['html']);
        self::assertSame('Receipt 42', trim($body['email']['text']));

        $this->app['mail.manager']->to('buyer@example.com')->send(new ReceiptMail());
        self::assertSame('orders-id', $this->body(1)['channel_id']);
        self::assertArrayNotHasKey('sensitive', $this->body(1)['email']);
    }

    public function testMailerRequiresAnExplicitChannelId(): void
    {
        $this->app['config']->set('mail.mailers.incomplete', ['transport' => 'communiqate']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('channel_id');
        $this->app['mail.manager']->mailer('incomplete');
    }

    public function testOnDemandMailboxDoesNotChangeTheDefaultMailer(): void
    {
        $mailer = Mail::build(['transport' => 'communiqate', 'channel_id' => 'store-mailbox']);
        $mailer->alwaysFrom('store@example.com', 'Store');
        $mailer->to('buyer@example.com')->sendNow(new ReceiptMail());
        Mail::to('buyer@example.com')->send(new ReceiptMail());
        self::assertSame('store-mailbox', $this->body()['channel_id']);
        self::assertSame('orders-id', $this->body(1)['channel_id']);
    }

    public function testOrdinaryMailCopiesAndIdempotency(): void
    {
        $this->app['mail.manager']->raw('Body', function ($message): void {
            $message->to('a@example.com')->cc('c@example.com')->bcc('b@example.com')->subject('News');
            $message->getHeaders()->addTextHeader('X-CommuniQate-Idempotency-Key', 'delivery-123');
        });
        $email = $this->body()['email'];
        self::assertSame(['c@example.com'], $email['cc']);
        self::assertSame(['b@example.com'], $email['bcc']);
        self::assertArrayNotHasKey('sensitive', $email);
        self::assertSame('delivery-123', $this->history[0]['request']->getHeaderLine('Idempotency-Key'));
    }

    public static function unsupportedMail(): iterable
    {
        yield 'two primary recipients' => ['to'];
        yield 'reply-to header' => ['reply'];
        yield 'MIME attachment' => ['attachment'];
        yield 'sensitive copy' => ['cc'];
    }

    #[DataProvider('unsupportedMail')]
    public function testUnsupportedMailFailsBeforeHttp(string $feature): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Body')->subject('Subject');
        match ($feature) {
            'to' => $email->addTo('c@example.com'),
            'reply' => $email->replyTo('support@example.com'),
            'attachment' => $email->attach('bytes', 'file.pdf'),
            'cc' => $email->cc('c@example.com'),
        };
        try {
            $this->app['mail.manager']->mailer('accounts')->getSymfonyTransport()->send($email);
            self::fail();
        } catch (TransportException $e) {
            self::assertNotSame('', $e->getMessage());
            self::assertCount(0, $this->history);
        }
    }

    public function testExistingMailNotificationUsesConfiguredMailer(): void
    {
        $notification = new class extends Notification {
            public ?string $mailer = null;
            public function via(object $notifiable): array { return ['mail']; }
            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage())->mailer($this->mailer)->subject('Your receipt')->view('receipt', ['number' => 42]);
            }
        };
        $recipient = (new AnonymousNotifiable())->route('mail', 'a@example.com');
        $recipient->notify($notification);
        self::assertSame('orders-id', $this->body()['channel_id']);
        self::assertSame('Your receipt', $this->body()['email']['subject']);
        self::assertStringContainsString('<h1>Receipt 42</h1>', $this->body()['email']['html']);
        $notification->mailer = 'accounts';
        $recipient->notify($notification);
        self::assertSame('accounts-id', $this->body(1)['channel_id']);
        self::assertTrue($this->body(1)['email']['sensitive']);
    }

    public function testNativeEmailAndWhatsAppNotificationRoutes(): void
    {
        $notification = new class extends Notification {
            public function via(object $notifiable): array { return [EmailChannel::class, WhatsAppChannel::class]; }
            public function toCommuniQateEmail(object $notifiable): array
            {
                return ['channel_id' => 'billing', 'email_template_id' => 'template', 'data' => ['number' => 42], 'sensitive' => true];
            }
            public function toCommuniQateWhatsApp(object $notifiable): array
            {
                return ['channel_id' => 'wa-channel', 'type' => 'whatsapp_template', 'whatsapp_template' => ['message_template' => 'receipt']];
            }
        };
        $notifiable = (new AnonymousNotifiable())->route('communiqateEmail', 'a@example.com')->route('communiqateWhatsApp', '+31600000000');
        $this->app->make(Dispatcher::class)->sendNow($notifiable, $notification);
        self::assertSame('a@example.com', $this->body()['to']);
        self::assertSame('billing', $this->body()['channel_id']);
        self::assertArrayNotHasKey('channel_id', $this->body()['email']);
        self::assertTrue($this->body()['email']['sensitive']);
        self::assertSame('wa-channel', $this->body(1)['channel_id']);
        self::assertSame('receipt', $this->body(1)['whatsapp_template']['message_template']);
        self::assertStringStartsWith('whatsapp:', $this->history[1]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function testWhatsAppCanSwitchAccountsAndRetryInTheSameProcess(): void
    {
        $notification = new class extends Notification {
            public string $channelId = 'sales-account';
            public function via(object $notifiable): array { return [WhatsAppChannel::class]; }
            public function toCommuniQateWhatsApp(object $notifiable): array
            {
                return ['channel_id' => $this->channelId, 'type' => 'text', 'text' => 'Hello'];
            }
        };
        $notification->id = 'delivery-42';
        $recipient = (new AnonymousNotifiable())->route('communiqateWhatsApp', '+31600000000');
        $recipient->notify($notification);
        $notification->channelId = 'support-account';
        $recipient->notify($notification);
        $recipient->notify($notification);

        self::assertSame(['sales-account', 'support-account', 'support-account'], [
            $this->body()['channel_id'], $this->body(1)['channel_id'], $this->body(2)['channel_id'],
        ]);
        $keys = array_map(fn ($entry) => $entry['request']->getHeaderLine('Idempotency-Key'), $this->history);
        self::assertNotSame($keys[0], $keys[1]);
        self::assertSame($keys[1], $keys[2]);
    }

    public function testNativeEmailRequiresASenderWhenThereIsARecipient(): void
    {
        $notification = new class extends Notification {
            public function via(object $notifiable): array { return [EmailChannel::class]; }
            public function toCommuniQateEmail(object $notifiable): array { return ['text' => 'Hello']; }
        };
        (new AnonymousNotifiable())->notify($notification);
        self::assertSame([], $this->history);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('channel_id from toCommuniQateEmail()');
        (new AnonymousNotifiable())->route('mail', 'buyer@example.com')->notify($notification);
    }
}

final class ReceiptMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your receipt');
    }

    public function content(): Content
    {
        return new Content(view: 'receipt', text: 'receipt-text', with: ['number' => 42]);
    }
}
