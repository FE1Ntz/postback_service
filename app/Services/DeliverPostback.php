<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Postback;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use InvalidArgumentException;

final class DeliverPostback
{
    private const MAX_ATTEMPTS = 3;

    public function deliver(Postback $postback): void
    {
        $url = $this->configuredPartnerUrl();

        if ($url === null) {
            $postback->update(['status' => 'failed', 'last_error' => 'invalid_partner_configuration']);
            Log::error('partner_configuration_invalid', ['postback_id' => $postback->id]);

            return;
        }

        $payload = $this->buildPayload($postback);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->recordAttempt($postback, $attempt);
            [$status, $error] = $this->attemptDelivery($postback, $url, $payload);
            $this->recordOutcome($postback, $status, $error);

            if ($error === null || ! $this->shouldRetry($status, $attempt)) {
                return;
            }

            $this->waitBeforeRetry();
        }
    }

    private function configuredPartnerUrl(): ?string
    {
        $url = config('partner.url');

        if (! Str::isUrl($url, ['http', 'https'])) {
            return null;
        }

        try {
            // Validate the parsed TCP port in addition to Laravel's URL syntax.
            $port = Uri::of($url)->port();

            return $port === null || $port <= 65535 ? $url : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function buildPayload(Postback $postback): string
    {
        $json = json_encode([
            'click_id' => $postback->click_id,
            'event' => $postback->event,
        ], JSON_THROW_ON_ERROR);

        // Emit the canonical decimal as a JSON number without floating point conversion.
        return Str::chopEnd($json, '}').',"amount":'.$postback->amount.'}';
    }

    private function recordAttempt(Postback $postback, int $attempt): void
    {
        // Persist intent before sending, including attempts interrupted by process death.
        $postback->update([
            'status' => 'sending',
            'attempts' => $attempt,
            'last_http_status' => null,
            'last_error' => null,
        ]);
    }

    /** @return array{0: ?int, 1: ?string} */
    private function attemptDelivery(Postback $postback, string $url, string $payload): array
    {
        try {
            $response = $this->sendRequest($postback, $url, $payload);

            return [$response->status(), $response->successful() ? null : 'http_'.$response->status()];
        } catch (ConnectionException) {
            return [null, 'network_error'];
        } catch (RequestException $exception) {
            // A failed transfer can carry an HTTP response, e.g. a truncated 503.
            $status = $exception->response->status();

            return [$status, 'http_'.$status];
        }
    }

    private function sendRequest(Postback $postback, string $url, string $payload): Response
    {
        return Http::connectTimeout(Number::clamp((float) config('partner.connect_timeout'), 0.1, 10.0))
            ->timeout(Number::clamp((float) config('partner.timeout'), 0.1, 30.0))
            ->withoutRedirecting()
            ->withHeaders(['Idempotency-Key' => (string) $postback->id])
            ->withBody($payload, 'application/json')
            ->post($url);
    }

    private function recordOutcome(Postback $postback, ?int $status, ?string $error): void
    {
        $postback->update([
            'last_http_status' => $status,
            'last_error' => $error,
            'status' => $error === null ? 'succeeded' : 'failed',
        ]);

        if ($error !== null) {
            Log::warning('partner_delivery_failed', [
                'postback_id' => $postback->id,
                'attempt' => $postback->attempts,
                'http_status' => $status,
                'error' => $error,
            ]);
        }
    }

    private function shouldRetry(?int $status, int $attempt): bool
    {
        $transient = $status === null || $status === 429 || ($status >= 500 && $status <= 599);

        return $transient && $attempt < self::MAX_ATTEMPTS;
    }

    private function waitBeforeRetry(): void
    {
        Sleep::for(Number::clamp((int) config('partner.retry_delay_ms'), 0, 5000))->milliseconds();
    }
}
