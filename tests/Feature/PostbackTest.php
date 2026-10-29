<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Postback;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'partner.url' => 'https://partner.test/events',
            'partner.retry_delay_ms' => 0,
            'partner.dedup_store' => 'array',
            'partner.inspection_token' => '',
        ]);
        Cache::store('array')->flush();
        Http::preventStrayRequests();
        $this->fakePartner(['*' => Http::response(['status' => 'ok'], 200)]);
    }

    private function fakePartner($callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function postback(array $overrides = [])
    {
        return $this->getJson('/postback?'.http_build_query(array_replace([
            'click_id' => 'clk_123', 'event' => 'purchase', 'amount' => '49.90',
        ], $overrides)));
    }

    public function test_accepts_persists_and_forwards_json(): void
    {
        $this->postback()->assertOk()->assertExactJson([
            'status' => 'accepted', 'message' => 'Event received',
        ]);
        $this->assertDatabaseCount('postbacks', 1);
        $this->assertDatabaseHas('postbacks', [
            'click_id' => 'clk_123', 'event' => 'purchase', 'amount' => '49.9',
            'status' => 'succeeded', 'attempts' => 1, 'last_http_status' => 200,
        ]);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://partner.test/events'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['click_id'] === 'clk_123'
            && $request['event'] === 'purchase'
            && $request['amount'] == 49.9);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidParameters')]
    public function test_rejects_invalid_parameters(array $parameters): void
    {
        $this->getJson('/postback?'.http_build_query($parameters))
            ->assertStatus(422)->assertExactJson([
                'status' => 'error', 'message' => 'Invalid parameters',
            ]);
        $this->assertDatabaseCount('postbacks', 0);
        Http::assertNothingSent();
    }

    public static function invalidParameters(): array
    {
        $valid = ['click_id' => 'c', 'event' => 'sale', 'amount' => '1'];
        $cases = [[], ['click_id' => 'c'], ['event' => 'sale', 'amount' => '1']];
        foreach (['click_id', 'event', 'amount'] as $field) {
            foreach (['', '   ', ['nested'], str_repeat('9', 1000)] as $value) {
                $cases[] = array_replace($valid, [$field => $value]);
            }
        }
        foreach (['-1', '-+0', '-'.str_repeat('0', 128), 'NaN', 'INF', 'hello', '1.2.3', '1e99999', '1000000000000000000', '0.000000001'] as $amount) {
            $cases[] = array_replace($valid, ['amount' => $amount]);
        }
        $cases[] = array_replace($valid, ['click_id' => "bad\nvalue"]);

        return array_map(fn ($case) => [$case], $cases);
    }

    #[DataProvider('validAmounts')]
    public function test_valid_numeric_amounts(string $input, string $canonical): void
    {
        $this->postback(['amount' => $input])->assertOk()->assertJsonPath('status', 'accepted');
        $this->assertDatabaseHas('postbacks', ['amount' => $canonical]);
    }

    public static function validAmounts(): array
    {
        return [['.5', '0.5'], ['+.5', '0.5'], ['-0', '0'], ['-0.000', '0'], ['0', '0'], ['0.00', '0'], ['49.90', '49.9'], ['00049.9000', '49.9'], ['4.99e1', '49.9'], ['0.00000001', '0.00000001']];
    }

    public function test_get_body_cannot_override_query_identity_or_amount(): void
    {
        $this->json('GET', '/postback?click_id=query-id&event=query-event&amount=1', [
            'click_id' => 'body-id', 'event' => 'body-event', 'amount' => '2',
        ])->assertJsonPath('status', 'accepted');
        $this->assertDatabaseHas('postbacks', ['click_id' => 'query-id', 'event' => 'query-event', 'amount' => '1']);
    }

    public function test_get_body_cannot_supply_missing_query_parameters(): void
    {
        $this->json('GET', '/postback', ['click_id' => 'body-id', 'event' => 'sale', 'amount' => '1'])
            ->assertStatus(422)->assertExactJson(['status' => 'error', 'message' => 'Invalid parameters']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('postbacks', 0);
    }

    public function test_get_body_cannot_replace_malformed_query_parameter(): void
    {
        $this->json('GET', '/postback?click_id=query-id&event=sale&amount[]=bad', ['amount' => '1'])
            ->assertStatus(422);
        Http::assertNothingSent();
        $this->assertDatabaseCount('postbacks', 0);
    }

    public function test_head_request_cannot_accept_or_deliver_an_event(): void
    {
        $this->call('HEAD', '/postback', ['click_id' => 'head-probe', 'event' => 'purchase', 'amount' => '1'])
            ->assertStatus(405);
        $this->assertDatabaseCount('postbacks', 0);
        Http::assertNothingSent();
    }

    public function test_identity_is_case_sensitive_and_tuple_boundaries_are_unambiguous(): void
    {
        foreach ([['click_id' => 'abc', 'event' => 'sale'], ['click_id' => 'ABC', 'event' => 'sale'],
            ['click_id' => 'abc', 'event' => 'SALE'], ['click_id' => 'a|b', 'event' => 'c'],
            ['click_id' => 'a', 'event' => 'b|c']] as $identity) {
            $this->postback($identity)->assertJsonPath('status', 'accepted');
        }
        $this->assertDatabaseCount('postbacks', 5);
        Http::assertSentCount(5);
    }

    public function test_cache_hit_obeys_exact_expiry_without_a_sliding_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00.750000'));
        $this->postback()->assertJsonPath('status', 'accepted');
        $this->travelTo(CarbonImmutable::parse('2026-01-02 12:00:00.749999'));
        $this->postback()->assertJsonPath('status', 'duplicate');
        $this->travelTo(CarbonImmutable::parse('2026-01-02 12:00:00.750000'));
        $this->postback()->assertJsonPath('status', 'accepted');
        $this->assertDatabaseCount('postbacks', 2);
        Http::assertSentCount(2);
    }

    public function test_invalid_utf8_identity_is_a_validation_error(): void
    {
        $this->getJson('/postback?click_id=%FF&event=sale&amount=1')->assertStatus(422)
            ->assertExactJson(['status' => 'error', 'message' => 'Invalid parameters']);
        Http::assertNothingSent();
    }

    public function test_dedup_window_does_not_expire_before_full_twenty_four_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00.750000'));
        $this->postback()->assertJsonPath('status', 'accepted');
        $this->travelTo(CarbonImmutable::parse('2026-01-02 12:00:00.500000'));
        Cache::store('array')->flush();
        $this->postback()->assertJsonPath('status', 'duplicate');
        $this->travelTo(CarbonImmutable::parse('2026-01-02 12:00:00.750000'));
        $this->postback()->assertJsonPath('status', 'accepted');
        $this->assertDatabaseCount('postbacks', 2);
        Http::assertSentCount(2);
    }

    public function test_numeric_equivalents_are_duplicates_without_further_delivery(): void
    {
        $this->postback()->assertJsonPath('status', 'accepted');
        foreach (['49.9', '049.9000', '4.99e1'] as $amount) {
            $this->postback(['amount' => $amount])->assertOk()->assertExactJson([
                'status' => 'duplicate', 'message' => 'Event already received',
            ]);
        }
        $this->assertDatabaseCount('postbacks', 1);
        Http::assertSentCount(1);
    }

    public function test_each_identity_component_distinguishes_events(): void
    {
        $this->postback();
        foreach ([['click_id' => 'another'], ['event' => 'refund'], ['amount' => '49.91']] as $change) {
            $this->postback($change)->assertJsonPath('status', 'accepted');
        }
        $this->assertDatabaseCount('postbacks', 4);
        Http::assertSentCount(4);
    }

    public function test_duplicate_does_not_extend_twenty_four_hour_window(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->postback()->assertJsonPath('status', 'accepted');
        $this->travel(23)->hours();
        $this->postback()->assertJsonPath('status', 'duplicate');
        $this->travel(61)->minutes();
        $this->postback()->assertJsonPath('status', 'accepted');
        $this->assertDatabaseCount('postbacks', 2);
        Http::assertSentCount(2);
    }

    public function test_missing_redis_marker_is_reconciled_from_database(): void
    {
        $this->postback()->assertJsonPath('status', 'accepted');
        Cache::store('array')->flush();
        $this->postback()->assertJsonPath('status', 'duplicate');
        $this->assertDatabaseCount('postbacks', 1);
        Http::assertSentCount(1);
    }

    #[DataProvider('deliveryStatuses')]
    public function test_delivery_status_retry_policy(int $httpStatus, int $attempts, string $status): void
    {
        $this->fakePartner(['*' => Http::response([], $httpStatus)]);
        $this->postback()->assertJsonPath('status', 'accepted');
        Http::assertSentCount($attempts);
        $this->assertDatabaseHas('postbacks', [
            'status' => $status, 'attempts' => $attempts, 'last_http_status' => $httpStatus,
        ]);
        $this->assertDatabaseCount('postbacks', 1);
        $this->postback()->assertJsonPath('status', 'duplicate');
        Http::assertSentCount($attempts);
    }

    public static function deliveryStatuses(): array
    {
        $cases = [];
        for ($status = 200; $status <= 599; $status++) {
            $success = $status < 300;
            $attempts = $status === 429 || $status >= 500 ? 3 : 1;
            $cases['HTTP '.$status] = [$status, $attempts, $success ? 'succeeded' : 'failed'];
        }

        return $cases;
    }

    #[DataProvider('responseBearingExceptions')]
    public function test_response_bearing_transport_errors_follow_http_retry_policy(int $status, int $attempts): void
    {
        $calls = 0;
        $this->fakePartner(function () use (&$calls, $status) {
            $calls++;
            throw new RequestException(new Response(new \GuzzleHttp\Psr7\Response($status, [], 'truncated response')));
        });
        $this->postback()->assertJsonPath('status', 'accepted');
        self::assertSame($attempts, $calls);
        $this->assertDatabaseHas('postbacks', ['status' => 'failed', 'attempts' => $attempts, 'last_http_status' => $status]);
        $this->assertDatabaseCount('postbacks', 1);
        $this->postback()->assertJsonPath('status', 'duplicate');
        self::assertSame($attempts, $calls);
    }

    public static function responseBearingExceptions(): array
    {
        return [[429, 3], [503, 3], [400, 1], [422, 1]];
    }

    public function test_recovers_on_third_attempt_and_clears_previous_error(): void
    {
        $this->fakePartner(['*' => Http::sequence()->push([], 503)->push([], 429)->push([], 204)]);
        $this->postback()->assertJsonPath('status', 'accepted');
        Http::assertSentCount(3);
        $this->assertDatabaseHas('postbacks', ['status' => 'succeeded', 'attempts' => 3, 'last_http_status' => 204, 'last_error' => null]);
        $this->assertDatabaseCount('postbacks', 1);
    }

    public function test_network_failures_stop_after_three_attempts(): void
    {
        $calls = 0;
        $this->fakePartner(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Simulated connection timeout with secret URL');
        });
        $this->postback()->assertJsonPath('status', 'accepted');
        self::assertSame(3, $calls);
        $row = DB::table('postbacks')->first();
        self::assertSame('failed', $row->status);
        self::assertSame(3, $row->attempts);
        self::assertNull($row->last_http_status);
        self::assertStringNotContainsString('secret URL', $row->last_error);
    }

    public function test_failed_insert_rolls_back_identity_and_does_not_consume_dedup_key(): void
    {
        DB::unprepared("CREATE TRIGGER reject_postback BEFORE INSERT ON postbacks BEGIN SELECT RAISE(ABORT, 'simulated insert failure'); END");
        $this->postback()->assertStatus(503)->assertJsonPath('status', 'error');
        $this->assertDatabaseCount('postbacks', 0);
        $this->assertDatabaseCount('postback_identities', 0);
        Http::assertNothingSent();
        DB::unprepared('DROP TRIGGER reject_postback');
        $this->postback()->assertOk()->assertJsonPath('status', 'accepted');
        $this->assertDatabaseCount('postbacks', 1);
        Http::assertSentCount(1);
    }

    public function test_database_gate_preserves_deduplication_during_cache_outage(): void
    {
        Cache::shouldReceive('store')->andThrow(new \RuntimeException('Redis unavailable'));
        $this->postback()->assertJsonPath('status', 'accepted');
        $this->postback()->assertJsonPath('status', 'duplicate');
        $this->assertDatabaseCount('postbacks', 1);
        Http::assertSentCount(1);
    }

    public function test_network_failure_can_recover_without_new_event_record(): void
    {
        $calls = 0;
        $this->fakePartner(function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('Simulated DNS failure');
            }

            return Http::response([], 201);
        });
        $this->postback()->assertJsonPath('status', 'accepted');
        self::assertSame(2, $calls);
        $this->assertDatabaseHas('postbacks', ['status' => 'succeeded', 'attempts' => 2, 'last_error' => null]);
        $this->assertDatabaseCount('postbacks', 1);
    }

    public function test_outbound_json_preserves_decimal_precision(): void
    {
        $amount = '9007199254740993.12345678';
        $this->postback(['amount' => $amount])->assertJsonPath('status', 'accepted');
        Http::assertSent(fn ($request) => str_contains($request->body(), '"amount":'.$amount));
    }

    public function test_invalid_partner_configuration_is_observable_without_http_attempt(): void
    {
        config()->set('partner.url', 'file:///etc/passwd');
        $this->postback()->assertJsonPath('status', 'accepted');
        Http::assertNothingSent();
        $this->assertDatabaseHas('postbacks', [
            'status' => 'failed', 'attempts' => 0, 'last_error' => 'invalid_partner_configuration',
        ]);
    }

    #[DataProvider('configuredPartnerUrls')]
    public function test_partner_url_configuration_supports_only_valid_http_destinations(mixed $url, bool $valid): void
    {
        config()->set('partner.url', $url);
        $this->postback()->assertJsonPath('status', 'accepted');
        if ($valid) {
            Http::assertSentCount(1);
            $this->assertDatabaseHas('postbacks', ['status' => 'succeeded', 'attempts' => 1]);
        } else {
            Http::assertNothingSent();
            $this->assertDatabaseHas('postbacks', ['status' => 'failed', 'attempts' => 0, 'last_error' => 'invalid_partner_configuration']);
        }
    }

    public static function configuredPartnerUrls(): array
    {
        return [
            'https' => ['https://partner.test/events?source=postback', true],
            'internal mock' => ['http://partner-mock:8080/events', true],
            'localhost' => ['http://localhost:8080/events', true],
            'IPv4' => ['http://127.0.0.1:8080/events', true],
            'IPv6' => ['http://[::1]:8080/events', true],
            'uppercase scheme' => ['HTTP://partner.test/events', true],
            'file scheme' => ['file:///etc/passwd', false],
            'ftp scheme' => ['ftp://partner.test/events', false],
            'missing scheme' => ['partner.test/events', false],
            'missing host' => ['https:///events', false],
            'invalid port' => ['http://partner.test:99999/events', false],
            'embedded space' => ['https://partner.test/invalid path', false],
            'empty' => ['', false],
            'null' => [null, false],
            'array' => [['https://partner.test/events'], false],
        ];
    }

    #[DataProvider('inspectionCredentials')]
    public function test_inspection_authentication_preserves_edge_token_behavior(?string $configuredToken, ?string $providedToken, int $status): void
    {
        config()->set('partner.inspection_token', $configuredToken);
        if ($providedToken !== null) {
            $this->withToken($providedToken);
        }
        $response = $this->getJson('/events')->assertStatus($status);
        if ($status === 401) {
            $response->assertExactJson(['message' => 'Unauthorized']);
        }
    }

    public static function inspectionCredentials(): array
    {
        return [
            'null disables auth' => [null, null, 200],
            'empty disables auth' => ['', null, 200],
            'empty config ignores supplied token' => ['', 'anything', 200],
            'zero requires auth' => ['0', null, 401],
            'zero mismatched' => ['0', 'wrong', 401],
            'zero matches' => ['0', '0', 200],
            'whitespace requires auth' => [' ', null, 401],
            'whitespace mismatched' => [' ', 'wrong', 401],
            'whitespace matches exactly' => [' ', ' ', 200],
            'token missing' => ['secret-token', null, 401],
            'token mismatched' => ['secret-token', 'wrong', 401],
            'token matches' => ['secret-token', 'secret-token', 200],
        ];
    }

    public function test_explicit_model_fillable_preserves_all_processing_attributes(): void
    {
        $attributes = [
            'identity_hash' => str_repeat('a', 64),
            'click_id' => 'persisted-click',
            'event' => 'purchase',
            'original_amount' => '049.900',
            'amount' => '49.9',
            'received_at' => '2026-01-01 12:00:00.123456',
            'status' => 'failed',
            'attempts' => 3,
            'last_http_status' => 503,
            'last_error' => 'http_503',
        ];
        $postback = Postback::create([...$attributes, 'id' => 99999, 'unexpected_attribute' => 'discard me'])->fresh();
        $this->assertDatabaseHas('postbacks', $attributes);
        self::assertNotSame(99999, $postback->id);
        self::assertArrayNotHasKey('unexpected_attribute', $postback->getAttributes());
        self::assertInstanceOf(CarbonImmutable::class, $postback->received_at);
        self::assertSame(3, $postback->attempts);
        self::assertSame(503, $postback->last_http_status);
    }

    public function test_inspection_exposes_persisted_processing_results(): void
    {
        $this->postback();
        $this->getJson('/events')->assertOk()->assertJsonPath('data.0.click_id', 'clk_123')
            ->assertJsonPath('data.0.status', 'succeeded')->assertJsonPath('data.0.attempts', 1);
    }

    public function test_inspection_token_is_required_when_configured(): void
    {
        config()->set('partner.inspection_token', 'test-inspection-token');
        $this->getJson('/events')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/events')->assertUnauthorized();
        $this->withToken('test-inspection-token')->getJson('/events')->assertOk();
    }
}
