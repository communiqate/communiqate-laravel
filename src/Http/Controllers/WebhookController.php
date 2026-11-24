<?php

declare(strict_types=1);

namespace CommuniQate\Laravel\Http\Controllers;

use CommuniQate\Laravel\Events\WebhookEvent;
use CommuniQate\Workspaces\Exception\InvalidWebhookSignature;
use CommuniQate\Workspaces\Webhooks\WebhookVerifier;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;
use UnexpectedValueException;

final class WebhookController
{
    public function __invoke(Request $request, WebhookVerifier $verifier, Dispatcher $events): Response
    {
        try {
            $delivery = $verifier->verify($request->getContent(), $request->header(WebhookVerifier::SIGNATURE_HEADER));
        } catch (InvalidWebhookSignature) {
            return new Response('', 401);
        } catch (JsonException|UnexpectedValueException) {
            return new Response('', 422);
        }

        $events->dispatch($delivery);
        $events->dispatch(WebhookEvent::fromDelivery($delivery));

        return new Response('', 204);
    }
}
