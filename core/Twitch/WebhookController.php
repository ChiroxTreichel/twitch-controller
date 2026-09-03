<?php

declare(strict_types=1);

namespace TwitchController\Core\Twitch;

use DateTimeImmutable;
use TwitchController\Core\App;
use TwitchController\Core\Chat\Chat;
use TwitchController\Core\Events\Normalizer;
use TwitchController\Core\Http\Request;
use TwitchController\Core\Http\Response;
use Throwable;

/**
 * Eingang fuer Twitch-EventSub: POST /hooks/twitch
 *
 * Reihenfolge ist wichtig - erst Signatur pruefen, dann Alter, dann
 * verarbeiten. Twitch erwartet eine schnelle Antwort, deshalb wird hier
 * nur normalisiert und gespeichert; die eigentliche Reaktion passiert
 * ueber den Hook 'core.event.stored'.
 */
final class WebhookController
{
    public function __construct(private readonly App $app)
    {
    }

    public function handle(Request $request): Response
    {
        $secret = $this->app->settings->secret('twitch_webhook_secret');
        $eventSub = $this->app->twitch->eventSub();

        if (!$eventSub->verify($request, $secret)) {
            $this->app->log(translate('twitch.bad_signature'));

            return Response::text('Bad signature', 403);
        }

        if (!$eventSub->isFresh($request)) {
            $this->app->log('EventSub: Nachricht zu alt (Replay?).');

            return Response::text('Stale', 412);
        }

        $body = $request->json();
        $messageType = $request->header('twitch-eventsub-message-type');

        // Twitch bestaetigt ein neues Abo, indem es die challenge
        // unveraendert als Klartext zurueckerwartet.
        if ($messageType === 'webhook_callback_verification') {
            return Response::text((string) ($body['challenge'] ?? ''), 200);
        }

        if ($messageType === 'revocation') {
            $type = (string) ($body['subscription']['type'] ?? '?');
            $status = (string) ($body['subscription']['status'] ?? '?');
            $this->app->log("EventSub: Abo \"{$type}\" wurde entzogen ({$status}).");
            $this->app->hooks->dispatch('core.eventsub.revoked', $type, $status);

            return Response::noContent();
        }

        if ($messageType !== 'notification') {
            return Response::noContent();
        }

        try {
            $this->store($request, $body);
        } catch (Throwable $e) {
            $this->app->log('EventSub: Verarbeitung fehlgeschlagen: ' . $e->getMessage());

            return Response::text('Error', 500);
        }

        return Response::noContent();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function store(Request $request, array $body): void
    {
        $subscriptionType = (string) ($body['subscription']['type'] ?? '');
        if ($subscriptionType === '') {
            return;
        }

        $event = is_array($body['event'] ?? null) ? $body['event'] : [];
        $eventType = 'twitch.' . $subscriptionType;

        $timestamp = $request->header('twitch-eventsub-message-timestamp');
        try {
            $occurredAt = (new DateTimeImmutable($timestamp))->format('Y-m-d H:i:sP');
        } catch (Throwable) {
            $occurredAt = date('Y-m-d H:i:sP');
        }

        // Chat geht seinen eigenen Weg. Er ist um Groessenordnungen mehr
        // als alles andere hier - in 'events' waere er Laerm im
        // Aktivitaeten-Feed und liesse die Tabelle ohne Grenze wachsen.
        if (in_array($subscriptionType, Chat::TYPES, true)) {
            $this->chat($subscriptionType, $event, $occurredAt);

            return;
        }

        $externalId = Normalizer::externalId(
            $eventType,
            $event,
            $request->header('twitch-eventsub-message-id')
        );

        $this->app->events->store(
            'twitch',
            $eventType,
            $externalId,
            $occurredAt,
            $this->app->normalizer->normalize('twitch', $eventType, $event),
            $event,
            $request->rawBody,
        );
    }

    /**
     * Die drei Chat-Abos.
     *
     * @param array<string, mixed> $event
     */
    private function chat(string $subscriptionType, array $event, string $occurredAt): void
    {
        $chat = $this->app->chat;

        switch ($subscriptionType) {
            case 'channel.chat.message':
                $chat->store($event, $occurredAt);

                return;

            case 'channel.chat.message_delete':
                $chat->markDeleted((string) ($event['message_id'] ?? ''));

                return;

            case 'channel.chat.clear':
                $chat->markCleared();

                return;
        }
    }
}
