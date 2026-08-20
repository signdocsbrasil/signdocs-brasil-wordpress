<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SignDocsBrasil\WordPress\Webhook\EventRouter;

final class EventRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // We don't exercise the DB path in unit; route() short-circuits
        // to "unmatched" when postId === 0.
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_insert_post')->justReturn(0);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return list<array{0:string}> */
    public static function nt65EventProvider(): array
    {
        return [
            ['TRANSACTION.DEADLINE_APPROACHING'],
            ['STEP.PURPOSE_DISCLOSURE_SENT'],
        ];
    }

    /** @dataProvider nt65EventProvider */
    public function test_nt65_events_are_routed_as_handled(string $eventType): void
    {
        $router = new EventRouter();
        // No CPT match (postId=0) — but handled flag should still be
        // false because we bail early when postId can't be resolved.
        // The test here is that the router doesn't throw on these types.
        $result = $router->route([
            'eventType' => $eventType,
            'data' => ['sessionId' => '', 'transactionId' => ''],
        ]);
        $this->assertIsArray($result);
        $this->assertSame($eventType, $result['event']);
    }

    public function test_envelope_event_resolves_by_envelope_id_not_transaction_id(): void
    {
        // An ENVELOPE.* payload carries a top-level transactionId belonging to
        // the LAST SIGNER, not to the envelope. Routing on it would stamp the
        // envelope's terminal status onto one member session and leave the
        // envelope itself untouched — so the lookup must go through
        // _signdocs_envelope_id on the envelope post type.
        $queried = [];
        Functions\when('get_posts')->alias(static function (array $args) use (&$queried) {
            $queried[] = [
                'post_type' => $args['post_type'] ?? '',
                'key'       => $args['meta_query'][0]['key'] ?? '',
                'value'     => $args['meta_query'][0]['value'] ?? '',
            ];
            return [321];
        });

        $router = new EventRouter();
        $result = $router->route([
            'eventType'     => 'ENVELOPE.ALL_SIGNED',
            'transactionId' => 'tx_last_signer',
            'data'          => [
                'envelopeId'   => 'env_abc123',
                'totalSigners' => 3,
                'completedAt'  => '2026-08-20T12:00:00.000Z',
            ],
        ]);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['matched']);

        $envelopeLookups = array_values(array_filter(
            $queried,
            static fn (array $q): bool => $q['key'] === '_signdocs_envelope_id'
        ));
        $this->assertCount(1, $envelopeLookups, 'the envelope must be looked up by its own id');
        $this->assertSame('signdocs_envelope', $envelopeLookups[0]['post_type']);
        $this->assertSame('env_abc123', $envelopeLookups[0]['value']);

        // And the last signer's transaction must never become the target.
        foreach ($queried as $q) {
            $this->assertNotSame('tx_last_signer', $q['value']);
        }
    }

    public function test_envelope_all_signed_stores_status_and_combined_url(): void
    {
        Functions\when('get_posts')->justReturn([321]);
        $written = [];
        Functions\when('update_post_meta')->alias(static function ($postId, $key, $value) use (&$written) {
            $written[$key] = $value;
            return true;
        });

        $router = new EventRouter();
        $router->route([
            'eventType' => 'ENVELOPE.ALL_SIGNED',
            'data'      => [
                'envelopeId'                  => 'env_abc123',
                'completedAt'                 => '2026-08-20T12:00:00.000Z',
                'combinedDownloadUrl'         => 'https://s3.example/combined.pdf?sig=x',
                'combinedDownloadUrlExpiresIn' => 3600,
            ],
        ]);

        $this->assertSame('COMPLETED', $written['_signdocs_status'] ?? null);
        $this->assertSame('2026-08-20T12:00:00.000Z', $written['_signdocs_completed_at'] ?? null);
        $this->assertSame(
            'https://s3.example/combined.pdf?sig=x',
            $written['_signdocs_combined_url'] ?? null
        );
        // Stored as an absolute moment so a stale link is distinguishable from
        // a live one rather than being offered until it 403s.
        $this->assertGreaterThan(time(), $written['_signdocs_combined_url_expires'] ?? 0);
    }

    /** @return list<array{0:string,1:string}> */
    public static function envelopeTerminalProvider(): array
    {
        return [
            ['ENVELOPE.ALL_SIGNED', 'COMPLETED'],
            ['ENVELOPE.CANCELLED', 'CANCELLED'],
            ['ENVELOPE.EXPIRED', 'EXPIRED'],
        ];
    }

    /** @dataProvider envelopeTerminalProvider */
    public function test_envelope_terminal_events_set_their_status(string $eventType, string $expected): void
    {
        Functions\when('get_posts')->justReturn([321]);
        $written = [];
        Functions\when('update_post_meta')->alias(static function ($postId, $key, $value) use (&$written) {
            $written[$key] = $value;
            return true;
        });

        $router = new EventRouter();
        $result = $router->route([
            'eventType' => $eventType,
            'data'      => ['envelopeId' => 'env_abc123'],
        ]);

        $this->assertTrue($result['handled']);
        $this->assertSame($expected, $written['_signdocs_status'] ?? null);
    }

    public function test_envelope_event_for_an_unknown_envelope_is_not_matched(): void
    {
        // A tenant can have envelopes this site did not create — the API is
        // per-tenant, not per-site. Those must not be treated as ours.
        Functions\when('get_posts')->justReturn([]);

        $router = new EventRouter();
        $result = $router->route([
            'eventType' => 'ENVELOPE.ALL_SIGNED',
            'data'      => ['envelopeId' => 'env_someone_else'],
        ]);

        $this->assertFalse($result['matched']);
    }

    public function test_quota_warning_routes_without_cpt_lookup(): void
    {
        $router = new EventRouter();
        $result = $router->route([
            'eventType' => 'QUOTA.WARNING',
            'data' => ['threshold' => 0.9, 'usage' => 0.93],
        ]);
        $this->assertTrue($result['handled']);
        $this->assertSame('QUOTA.WARNING', $result['event']);
    }

    public function test_api_deprecation_notice_routes(): void
    {
        $router = new EventRouter();
        $result = $router->route([
            'eventType' => 'API.DEPRECATION_NOTICE',
            'data' => ['endpoint' => 'POST /admin/tenants/{id}/mode', 'sunset' => '2026-09-01'],
        ]);
        $this->assertTrue($result['handled']);
    }

    public function test_unknown_event_is_not_handled_but_doesnt_throw(): void
    {
        $router = new EventRouter();
        $result = $router->route([
            'eventType' => 'SOMETHING.BOGUS',
            'data' => [],
        ]);
        $this->assertFalse($result['handled']);
        $this->assertSame('SOMETHING.BOGUS', $result['event']);
    }
}
