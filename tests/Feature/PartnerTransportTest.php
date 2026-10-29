<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerTransportTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('transportFailures')]
    public function test_real_socket_failures_persist_outcomes_and_apply_retry_policy(int $status, int $attempts, ?int $persistedStatus, bool $timeout): void
    {
        [$url, $pid, $resultFile] = $this->startPartnerFixture($status, $attempts, $timeout);
        Http::swap(new Factory);
        Http::globalOptions(['proxy' => '']);
        config()->set([
            'partner.url' => $url,
            'partner.timeout' => $timeout ? 0.1 : 1,
            'partner.connect_timeout' => 0.1,
            'partner.retry_delay_ms' => 0,
            'partner.dedup_store' => 'array',
        ]);
        try {
            $this->getJson('/postback?click_id=transport-fixture&event=purchase&amount=49.90')
                ->assertOk()->assertJsonPath('status', 'accepted');
            $this->assertDatabaseHas('postbacks', [
                'status' => 'failed', 'attempts' => $attempts,
                'last_http_status' => $persistedStatus,
                'last_error' => $persistedStatus === null ? 'network_error' : 'http_'.$persistedStatus,
            ]);
            $this->getJson('/postback?click_id=transport-fixture&event=purchase&amount=49.9')
                ->assertJsonPath('status', 'duplicate');
            $this->assertDatabaseCount('postbacks', 1);
        } finally {
            pcntl_waitpid($pid, $childStatus);
            $received = json_decode(File::get($resultFile), true);
            File::delete($resultFile);
        }
        self::assertSame(0, pcntl_wexitstatus($childStatus));
        self::assertCount($attempts, $received);
        foreach ($received as $payload) {
            self::assertSame(['click_id' => 'transport-fixture', 'event' => 'purchase', 'amount' => 49.9], $payload);
        }
    }

    /** @return array{0: string, 1: int, 2: string} */
    private function startPartnerFixture(int $status, int $attempts, bool $timeout): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the local socket fixture.');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($server, $errorMessage);
        $address = stream_socket_get_name($server, false);
        $resultFile = tempnam(sys_get_temp_dir(), 'postback-transport-');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $received = $this->servePartnerResponses($server, $status, $attempts, $timeout);
            File::put($resultFile, json_encode($received));
            fclose($server);
            exit(0);
        }
        fclose($server);

        return ['http://'.$address.'/events', $pid, $resultFile];
    }

    /** @param resource $server */
    private function servePartnerResponses($server, int $status, int $attempts, bool $timeout): array
    {
        $received = [];
        for ($index = 0; $index < $attempts; $index++) {
            $connection = @stream_socket_accept($server, 2);
            if ($connection === false) {
                break;
            }
            stream_set_timeout($connection, 1);
            $received[] = json_decode($this->readRequestBody($connection), true);
            if ($timeout) {
                Sleep::for(150)->milliseconds();
            } else {
                // Deliberately close before the advertised body is complete.
                fwrite($connection, "HTTP/1.1 {$status} Fixture\r\nContent-Type: application/json\r\nContent-Length: 100\r\nConnection: close\r\n\r\n{}");
            }
            fclose($connection);
        }

        return $received;
    }

    /** @param resource $connection */
    private function readRequestBody($connection): string
    {
        $headers = '';
        while (($line = fgets($connection)) !== false && $line !== "\r\n") {
            $headers .= $line;
        }
        preg_match('/Content-Length:\s*(\d+)/i', $headers, $matches);
        $remaining = (int) ($matches[1] ?? 0);
        $body = '';
        while ($remaining > 0 && ! feof($connection)) {
            $chunk = fread($connection, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $body;
    }

    public static function transportFailures(): array
    {
        return [
            'truncated 503' => [503, 3, 503, false],
            'truncated 429' => [429, 3, 429, false],
            'truncated 400' => [400, 1, 400, false],
            'truncated 422' => [422, 1, 422, false],
            'truncated 200 is a transport failure' => [200, 3, null, false],
            'response timeout' => [200, 3, null, true],
        ];
    }
}
