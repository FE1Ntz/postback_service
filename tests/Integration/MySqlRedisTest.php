<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Uses a disposable database; never runs unless explicitly enabled. */
class MySqlRedisTest extends TestCase
{
    private string $clickId;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_INFRA_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_INFRA_TESTS=1 and TEST_DB_DATABASE to a disposable MySQL database.');
        }
        $database = getenv('TEST_DB_DATABASE');
        if (! $database || ! str_ends_with($database, '_test')) {
            self::fail('TEST_DB_DATABASE must explicitly name a disposable database ending in _test.');
        }
        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
            'database.connections.mysql.url' => null,
            'partner.dedup_store' => 'redis',
            'partner.url' => 'https://partner.test/events',
            'partner.retry_delay_ms' => 0,
            'cache.prefix' => 'postback-test-'.Str::random(16).':',
        ]);
        DB::purge('mysql');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->clickId = 'infra-'.Str::random(24);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 204)]);
    }

    private function receive(): array
    {
        $kernel = $this->app->make(Kernel::class);
        $request = Request::create('/postback?click_id='.$this->clickId.'&event=purchase&amount=49.90', 'GET');
        $request->headers->set('Accept', 'application/json');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return ['http' => $response->getStatusCode(), ...json_decode($response->getContent(), true)];
    }

    public function test_real_database_and_redis_deduplicate_and_reconcile_cache_loss(): void
    {
        self::assertSame('accepted', $this->receive()['status']);
        self::assertSame('duplicate', $this->receive()['status']);
        self::assertSame(1, DB::table('postbacks')->where('click_id', $this->clickId)->count());
        $this->assertDatabaseHas('postbacks', ['click_id' => $this->clickId, 'attempts' => 1, 'status' => 'succeeded']);
        $store = Cache::store('redis')->getStore();
        $connection = Redis::connection('cache');
        $keys = $connection->keys($store->getPrefix().'*');
        self::assertNotEmpty($keys, 'Acceptance should publish a real Redis marker.');
        foreach ($keys as $key) {
            $prefix = config('database.redis.options.prefix', '');
            $logicalKey = str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
            $ttl = $connection->ttl($logicalKey);
            self::assertGreaterThan(86390, $ttl);
            self::assertLessThanOrEqual(86400, $ttl);
            $connection->del($logicalKey);
        }
        self::assertSame('duplicate', $this->receive()['status']);
        self::assertSame(1, DB::table('postbacks')->where('click_id', $this->clickId)->count());
    }

    public function test_real_redis_connection_failure_falls_back_and_recovers_without_duplicates(): void
    {
        $original = config('database.redis.cache');
        config()->set('database.redis.cache', array_replace($original, [
            'port' => 1, 'url' => null, 'timeout' => 0.1, 'read_timeout' => 0.1, 'max_retries' => 0,
        ]));
        Redis::purge('cache');
        try {
            self::assertSame('accepted', $this->receive()['status']);
            self::assertSame('duplicate', $this->receive()['status']);
        } finally {
            config()->set('database.redis.cache', $original);
            Redis::purge('cache');
        }
        self::assertSame('duplicate', $this->receive()['status']);
        self::assertSame(1, DB::table('postbacks')->where('click_id', $this->clickId)->count());
        Http::assertSentCount(1);
    }

    public function test_real_database_connection_failure_does_not_consume_acceptance(): void
    {
        $originalPort = config('database.connections.mysql.port');
        config()->set('database.connections.mysql.port', 1);
        DB::purge('mysql');
        try {
            self::assertSame(['http' => 503, 'status' => 'error', 'message' => 'Service unavailable'], $this->receive());
            Http::assertNothingSent();
        } finally {
            config()->set('database.connections.mysql.port', $originalPort);
            DB::purge('mysql');
        }
        self::assertSame('accepted', $this->receive()['status']);
        self::assertSame('duplicate', $this->receive()['status']);
        self::assertSame(1, DB::table('postbacks')->where('click_id', $this->clickId)->count());
        Http::assertSentCount(1);
    }

    public function test_concurrent_identical_requests_create_and_deliver_one_event(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for concurrent integration testing.');
        }
        $directory = sys_get_temp_dir().'/postback-concurrency-'.Str::random(12);
        File::makeDirectory($directory, 0700);
        $children = [];
        DB::disconnect('mysql');
        Redis::connection('cache')->disconnect();
        try {
            for ($index = 0; $index < 6; $index++) {
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        while (! File::exists($directory.'/start')) {
                            Sleep::for(1)->milliseconds();
                        }
                        $result = $this->receive();
                        File::put($directory.'/'.$index.'.json', json_encode($result));
                        exit(0);
                    } catch (\Throwable $exception) {
                        File::put($directory.'/'.$index.'.json', json_encode(['error' => $exception->getMessage()]));
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            touch($directory.'/start');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertSame(0, pcntl_wexitstatus($status), implode('', array_map(fn ($file) => File::get($file), File::glob($directory.'/*.json'))));
            }
            $statuses = [];
            for ($index = 0; $index < 6; $index++) {
                $result = json_decode(File::get($directory.'/'.$index.'.json'), true);
                self::assertSame(200, $result['http'], json_encode($result));
                $statuses[] = $result['status'];
            }
            self::assertSame(1, count(array_filter($statuses, fn ($status) => $status === 'accepted')));
            self::assertSame(5, count(array_filter($statuses, fn ($status) => $status === 'duplicate')));
            self::assertSame(1, DB::table('postbacks')->where('click_id', $this->clickId)->count());
            $this->assertDatabaseHas('postbacks', ['click_id' => $this->clickId, 'status' => 'succeeded', 'attempts' => 1]);
        } finally {
            foreach (File::glob($directory.'/*') as $file) {
                File::delete($file);
            }
            rmdir($directory);
        }
    }
}
