<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Postback;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AcceptPostback
{
    public function accept(array $data): ?Postback
    {
        $amount = DecimalAmount::normalize($data['amount']);
        $hash = $this->identityHash($data, $amount);
        $key = 'postback:'.$hash;

        if ($this->isCachedDuplicate($key)) {
            return null;
        }

        [$postback, $acceptedAt] = $this->acceptInDatabase($data, $amount, $hash);
        $this->cacheAcceptance($key, $acceptedAt);

        return $postback;
    }

    private function identityHash(array $data, string $amount): string
    {
        return hash('sha256', json_encode(
            [$data['click_id'], $data['event'], $amount],
            JSON_THROW_ON_ERROR,
        ));
    }

    private function isCachedDuplicate(string $key): bool
    {
        try {
            $expiresAt = Cache::store(config('partner.dedup_store'))->get($key);

            return is_string($expiresAt)
                && CarbonImmutable::parse($expiresAt)->isAfter(CarbonImmutable::now());
        } catch (Throwable) {
            Log::warning('dedup_cache_unavailable', ['operation' => 'read']);

            return false;
        }
    }

    /** @return array{0: ?Postback, 1: CarbonImmutable} */
    private function acceptInDatabase(array $data, string $amount, string $hash): array
    {
        // Keep the identity lock, duplicate check and both writes in one transaction.
        return DB::transaction(function () use ($data, $amount, $hash): array {
            $acceptedAt = $this->lockIdentity($hash);
            $now = CarbonImmutable::now();

            if ($acceptedAt !== null && $acceptedAt->addDay()->isAfter($now)) {
                return [null, $acceptedAt];
            }

            $postback = $this->createPostback($data, $amount, $hash, $now);
            DB::table('postback_identities')->where('hash', $hash)->update([
                'accepted_at' => $now->format('Y-m-d H:i:s.u'),
            ]);

            return [$postback, $now];
        }, 3);
    }

    private function lockIdentity(string $hash): ?CarbonImmutable
    {
        // An upsert acquires an exclusive key lock; INSERT IGNORE can deadlock
        // when concurrent readers upgrade duplicate-key shared locks.
        DB::table('postback_identities')->upsert(
            [['hash' => $hash, 'accepted_at' => null]], ['hash'], ['hash'],
        );

        $identity = DB::table('postback_identities')
            ->where('hash', $hash)
            ->lockForUpdate()
            ->first();

        return $identity->accepted_at === null
            ? null
            : CarbonImmutable::parse($identity->accepted_at);
    }

    private function createPostback(array $data, string $amount, string $hash, CarbonImmutable $receivedAt): Postback
    {
        return Postback::create([
            'identity_hash' => $hash,
            'click_id' => $data['click_id'],
            'event' => $data['event'],
            'original_amount' => $data['amount'],
            'amount' => $amount,
            'received_at' => $receivedAt,
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    private function cacheAcceptance(string $key, CarbonImmutable $acceptedAt): void
    {
        // Called only after commit; cache recovery must preserve the original expiry.
        $expiresAt = $acceptedAt->addDay();

        try {
            Cache::store(config('partner.dedup_store'))->put($key, $expiresAt->toISOString(), $expiresAt);
        } catch (Throwable) {
            Log::warning('dedup_cache_unavailable', ['operation' => 'write']);
        }
    }
}
