<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SignDocsBrasil\Api\HttpClient;
use SignDocsBrasil\Api\Resources\EnvelopesResource;
use SignDocsBrasil\Api\SignDocsBrasilClient;
use SignDocsBrasil\WordPress\Envelope\EnvelopeDraft;
use SignDocsBrasil\WordPress\Envelope\EnvelopeSender;
use SignDocsBrasil\WordPress\Envelope\EnvelopeService;

/**
 * Sending an envelope: one create, then one session per signer.
 *
 * Drives a real EnvelopesResource over a mocked HttpClient rather than mocking
 * the resource, so the request the SDK actually shapes — path, body and
 * idempotency key — is what gets asserted. The SDK resources are final and
 * cannot be mocked anyway.
 */
final class EnvelopeSenderTest extends TestCase
{
    private const ENVELOPE_POST = 500;

    /** @var list<array{method: string, path: string, body: mixed, key: ?string}> */
    private array $calls = [];

    /** @var array<string, mixed> keyed by "postId:metaKey" */
    private array $meta = [];

    /** @var list<array<string, mixed>> */
    private array $inserted = [];

    private string $pdfPath = '';
    private int $nextPostId = 900;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->pdfPath = (string) tempnam(sys_get_temp_dir(), 'sdb');
        file_put_contents($this->pdfPath, "%PDF-1.4\ntest\n");

        Functions\when('__')->returnArg();
        Functions\when('home_url')->justReturn('https://example.org');
        Functions\when('get_site_url')->justReturn('https://example.org');
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_attached_file')->justReturn($this->pdfPath);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('get_children')->justReturn([]);

        Functions\when('get_post_meta')->alias(function ($postId, $key, $single = false) {
            return $this->meta[$postId . ':' . $key] ?? '';
        });
        Functions\when('update_post_meta')->alias(function ($postId, $key, $value) {
            $this->meta[$postId . ':' . $key] = $value;
            return true;
        });
        Functions\when('wp_insert_post')->alias(function (array $postarr, bool $wpError = false) {
            $this->inserted[] = $postarr + ['__wp_error_requested' => $wpError];
            return ++$this->nextPostId;
        });
    }

    protected function tearDown(): void
    {
        if ($this->pdfPath !== '' && file_exists($this->pdfPath)) {
            unlink($this->pdfPath);
        }
        Monkey\tearDown();
        parent::tearDown();
    }

    private function sender(): EnvelopeSender
    {
        $http = $this->createMock(HttpClient::class);

        $http->method('request')->willReturnCallback(
            function (string $method, string $path, $body = null) {
                $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'key' => null];
                return $this->responseFor($path);
            }
        );
        $http->method('requestWithIdempotency')->willReturnCallback(
            function (string $method, string $path, $body = null, ?string $key = null) {
                $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'key' => $key];
                return $this->responseFor($path);
            }
        );

        $ref = new ReflectionClass(SignDocsBrasilClient::class);
        $client = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('envelopes');
        $prop->setAccessible(true);
        $prop->setValue($client, new EnvelopesResource($http));

        return new EnvelopeSender(new EnvelopeService($client));
    }

    /** @return array<string, mixed> */
    private function responseFor(string $path): array
    {
        if (str_ends_with($path, '/sessions')) {
            $n = count(array_filter($this->calls, static fn(array $c): bool => str_ends_with($c['path'], '/sessions')));
            return [
                'sessionId'     => 'ss_' . $n,
                'transactionId' => 'tx_' . $n,
                'signerIndex'   => $n,
                'status'        => 'ACTIVE',
                'url'           => 'https://sign.example/s/ss_' . $n,
                'clientSecret'  => 'ss_secret_' . $n,
                'expiresAt'     => '2026-09-01T00:00:00.000Z',
            ];
        }
        return [
            'envelopeId'   => 'env_1',
            'status'       => 'CREATED',
            'signingMode'  => 'PARALLEL',
            'totalSigners' => 2,
            'documentHash' => 'abc',
            'createdAt'    => '2026-08-20T00:00:00.000Z',
            'expiresAt'    => '2026-09-01T00:00:00.000Z',
        ];
    }

    private function draft(int $signers = 2): EnvelopeDraft
    {
        $rows = [];
        for ($i = 1; $i <= $signers; $i++) {
            $rows[] = ['name' => 'Signer ' . $i, 'email' => 's' . $i . '@example.com', 'cpf' => '', 'cnpj' => ''];
        }
        return EnvelopeDraft::fromInput($rows, 'PARALLEL');
    }

    /** @return list<array{method: string, path: string, body: mixed, key: ?string}> */
    private function sessionCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn(array $c): bool => str_ends_with($c['path'], '/sessions')
        ));
    }

    public function test_creates_the_envelope_then_one_session_per_signer(): void
    {
        $result = $this->sender()->send(self::ENVELOPE_POST, $this->draft(3), 42, 'CLICK_ONLY');

        $this->assertSame('env_1', $result['envelopeId']);
        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['replayed']);

        $this->assertSame('/v1/envelopes', $this->calls[0]['path']);
        $this->assertCount(3, $this->sessionCalls());
        foreach ($this->sessionCalls() as $call) {
            $this->assertSame('/v1/envelopes/env_1/sessions', $call['path']);
        }
    }

    public function test_every_signer_gets_a_distinct_idempotency_key(): void
    {
        // The one that fails silently. The API scopes its cache by key and
        // resolved path, and every signer shares that path — one key across the
        // envelope returns signer 1's session, client secret included, for all
        // of them.
        $this->sender()->send(self::ENVELOPE_POST, $this->draft(3), 42, 'CLICK_ONLY');

        $keys = array_map(static fn(array $c): ?string => $c['key'], $this->sessionCalls());

        $this->assertCount(3, $keys);
        $this->assertNotContains(null, $keys, 'add-session must always carry a key');
        $this->assertSame(count($keys), count(array_unique($keys)));

        // And distinct from the envelope's own create key.
        $this->assertNotContains($this->calls[0]['key'], $keys);
    }

    public function test_signers_are_numbered_from_one(): void
    {
        // signerIndex is 1-based on the API. Sending a 0 would be rejected, and
        // an off-by-one would silently shift who signs in which position under
        // sequential order.
        $this->sender()->send(self::ENVELOPE_POST, $this->draft(2), 42, 'CLICK_ONLY');

        $indexes = array_map(static fn(array $c) => $c['body']['signerIndex'] ?? null, $this->sessionCalls());
        $this->assertSame([1, 2], $indexes);
    }

    public function test_each_signer_becomes_a_child_of_the_envelope(): void
    {
        // The link that lets the existing TRANSACTION.* handlers keep every
        // signer current without any envelope-specific webhook code.
        $this->sender()->send(self::ENVELOPE_POST, $this->draft(2), 42, 'CLICK_ONLY');

        $this->assertCount(2, $this->inserted);
        foreach ($this->inserted as $post) {
            $this->assertSame(self::ENVELOPE_POST, $post['post_parent']);
            $this->assertSame('signdocs_signing', $post['post_type']);
            // Without the second argument wp_insert_post returns 0 on failure
            // and the error check downstream is dead code.
            $this->assertTrue($post['__wp_error_requested']);
        }
    }

    public function test_child_stores_the_assembled_signing_url(): void
    {
        // `url` alone is rejected with HTTP 400 — the signing page needs the
        // embed token as `?cs=`.
        $this->sender()->send(self::ENVELOPE_POST, $this->draft(2), 42, 'CLICK_ONLY');

        $stored = $this->meta['901:_signdocs_session_url'] ?? '';
        $this->assertStringContainsString('cs=', $stored);
        $this->assertStringStartsWith('https://sign.example/s/ss_1', $stored);
        $this->assertSame('ss_1', $this->meta['901:_signdocs_session_id'] ?? null);
        $this->assertSame('tx_1', $this->meta['901:_signdocs_transaction_id'] ?? null);
    }

    public function test_resending_replays_instead_of_duplicating(): void
    {
        $sender = $this->sender();
        $sender->send(self::ENVELOPE_POST, $this->draft(2), 42, 'CLICK_ONLY');

        $this->calls = [];
        $this->inserted = [];
        $result = $sender->send(self::ENVELOPE_POST, $this->draft(2), 42, 'CLICK_ONLY');

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['replayed']);
        $this->assertSame([], $this->sessionCalls(), 'no signer may be re-sent');
        $this->assertSame([], $this->inserted, 'no duplicate child records');
    }

    public function test_resending_reuses_the_envelope_rather_than_creating_another(): void
    {
        $sender = $this->sender();
        $sender->send(self::ENVELOPE_POST, $this->draft(2), 42, 'CLICK_ONLY');

        $this->calls = [];
        $sender->send(self::ENVELOPE_POST, $this->draft(2), 42, 'CLICK_ONLY');

        $creates = array_filter($this->calls, static fn(array $c): bool => $c['path'] === '/v1/envelopes');
        $this->assertSame([], $creates, 'a second envelope would orphan the sessions on the first');
    }

    public function test_a_partial_send_resumes_at_the_missing_signer(): void
    {
        // The envelope and its sessions are separate calls, so a failure at
        // signer three leaves an envelope with two. Resuming must create only
        // the third — the first two are already charged for.
        $this->meta[self::ENVELOPE_POST . ':' . EnvelopeSender::META_ENVELOPE_ID] = 'env_1';
        $this->meta[self::ENVELOPE_POST . ':' . EnvelopeSender::META_SENT_INDEXES] = [
            '1' => ['sessionId' => 'ss_1', 'postId' => 901],
            '2' => ['sessionId' => 'ss_2', 'postId' => 902],
        ];

        $result = $this->sender()->send(self::ENVELOPE_POST, $this->draft(3), 42, 'CLICK_ONLY');

        $this->assertSame(1, $result['created']);
        $this->assertSame(2, $result['replayed']);
        $this->assertCount(1, $this->sessionCalls());
        $this->assertSame(3, $this->sessionCalls()[0]['body']['signerIndex'] ?? null);
    }

    public function test_an_unsendable_draft_never_reaches_the_api(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->sender()->send(self::ENVELOPE_POST, $this->draft(1), 42, 'CLICK_ONLY');
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    public function test_a_missing_document_never_reaches_the_api(): void
    {
        Functions\when('get_attached_file')->justReturn('/nonexistent/path.pdf');

        $this->expectException(\RuntimeException::class);
        try {
            $this->sender()->send(self::ENVELOPE_POST, $this->draft(2), 42, 'CLICK_ONLY');
        } finally {
            $this->assertSame([], $this->calls);
        }
    }
}
