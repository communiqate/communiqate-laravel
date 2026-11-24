<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Mail;

use CommuniQate\Workspaces\Contracts\MessageSender;
use CommuniQate\Workspaces\Exception\ApiException;
use CommuniQate\Workspaces\Exception\NetworkException;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class MailTransport extends AbstractTransport
{
    public function __construct(
        private readonly MessageSender $client,
        private readonly string $channelId,
        private readonly bool $sensitive = false,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'communiqate';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email || count($email->getTo()) !== 1) {
            throw new TransportException('CommuniQate mail requires one primary To address.');
        }
        if ($email->getAttachments() !== []) {
            throw new TransportException('For attachments use uploadFile() and email.attachments. Use HTTPS URLs for inline images.');
        }
        $headers = $email->getHeaders();
        foreach ($headers->all() as $header) {
            if (!in_array(strtolower($header->getName()), ['from', 'to', 'cc', 'bcc', 'subject', 'date', 'x-communiqate-idempotency-key'], true)) {
                throw new TransportException('Unsupported CommuniQate mail header: '.$header->getName().'.');
            }
        }
        $addresses = static fn (Address $address): string => $address->getAddress();
        $to = $email->getTo()[0]->getAddress();
        $cc = array_map($addresses, $email->getCc());
        $bcc = array_map($addresses, $email->getBcc());
        $expected = [$to, ...$cc, ...$bcc];
        $actual = array_map($addresses, $message->getEnvelope()->getRecipients());
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            throw new TransportException('The mail envelope recipients must match To, Cc and Bcc.');
        }

        $content = ['subject' => $email->getSubject()];
        foreach (['html' => $email->getHtmlBody(), 'text' => $email->getTextBody()] as $name => $body) {
            if (is_resource($body)) {
                if (stream_get_meta_data($body)['seekable']) {
                    rewind($body);
                }
                $body = stream_get_contents($body);
            }
            if ($body !== null) {
                $content[$name] = $body;
            }
        }
        if ($cc !== []) {
            $content['cc'] = $cc;
        }
        if ($bcc !== []) {
            $content['bcc'] = $bcc;
        }
        if ($this->sensitive) {
            $content['sensitive'] = true;
        }
        try {
            $key = $headers->get('X-CommuniQate-Idempotency-Key')?->getBodyAsString();
            $result = $this->client->sendEmail($this->channelId, $to, $content, $key);
        } catch (NetworkException|ApiException|ClientExceptionInterface|\InvalidArgumentException|\JsonException $e) {
            throw new TransportException('CommuniQate mail request failed: '.$e->getMessage(), 0, $e);
        }
        if (!is_string($result['message_id'] ?? null) || $result['message_id'] === '') {
            throw new TransportException('CommuniQate acceptance response is missing message_id.');
        }
        $message->setMessageId($result['message_id']);
    }
}
