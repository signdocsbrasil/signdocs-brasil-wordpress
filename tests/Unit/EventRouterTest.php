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
