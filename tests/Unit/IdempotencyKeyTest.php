<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SignDocsBrasil\WordPress\Support\IdempotencyKey;

final class IdempotencyKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_current_user_id')->justReturn(42);
        Functions\when('get_site_url')->justReturn('https://example.org');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_for_resource_ignores_who_is_acting(): void
    {
        // The whole point of forResource. An order-completed transition is
        // reached by an administrator, a gateway callback, WP-Cron or a REST
        // request depending on the shop, and each of those is a different
        // current user — or none. If that changed the key, every route to the
        // same order would create its own signing session, which is the
        // duplicate this exists to prevent.
        $asAdmin = IdempotencyKey::forResource('wc.order.signing', ['order' => 7, 'document' => 3]);

        Functions\when('get_current_user_id')->justReturn(0);
        $asCron = IdempotencyKey::forResource('wc.order.signing', ['order' => 7, 'document' => 3]);

        Functions\when('get_current_user_id')->justReturn(999);
        $asOtherAdmin = IdempotencyKey::forResource('wc.order.signing', ['order' => 7, 'document' => 3]);

        $this->assertSame($asAdmin, $asCron);
        $this->assertSame($asAdmin, $asOtherAdmin);
    }

    public function test_for_action_still_separates_users(): void
    {
        // The counterpart property: a per-user action must stay per-user, so
        // two administrators clicking the same button do not read back one
        // another's cached session.
        $asUser42 = IdempotencyKey::forAction('session.create', ['document' => 3]);

        Functions\when('get_current_user_id')->justReturn(43);
        $asUser43 = IdempotencyKey::forAction('session.create', ['document' => 3]);

        $this->assertNotSame($asUser42, $asUser43);
    }

    public function test_for_resource_separates_documents_on_one_order(): void
    {
        // An order can carry more than one signable product; each needs its
        // own session, so the document has to be part of the key.
        $docA = IdempotencyKey::forResource('wc.order.signing', ['order' => 7, 'document' => 3]);
        $docB = IdempotencyKey::forResource('wc.order.signing', ['order' => 7, 'document' => 4]);

        $this->assertNotSame($docA, $docB);
    }

    public function test_for_resource_and_for_action_do_not_collide(): void
    {
        $resource = IdempotencyKey::forResource('same.action', ['a' => 1]);
        $action = IdempotencyKey::forAction('same.action', ['a' => 1]);

        $this->assertNotSame($resource, $action);
    }

    public function test_same_inputs_yield_same_key(): void
    {
        $a = IdempotencyKey::forAction('create_session', ['document' => 'doc_1', 'signer' => 'a@b.com']);
        $b = IdempotencyKey::forAction('create_session', ['document' => 'doc_1', 'signer' => 'a@b.com']);
        $this->assertSame($a, $b);
    }

    public function test_order_insensitive_canonicalization(): void
    {
        $a = IdempotencyKey::forAction('create_session', ['document' => 'doc_1', 'signer' => 'a@b.com']);
        $b = IdempotencyKey::forAction('create_session', ['signer' => 'a@b.com', 'document' => 'doc_1']);
        $this->assertSame($a, $b);
    }

    public function test_different_inputs_yield_different_keys(): void
    {
        $a = IdempotencyKey::forAction('create_session', ['document' => 'doc_1']);
        $b = IdempotencyKey::forAction('create_session', ['document' => 'doc_2']);
        $this->assertNotSame($a, $b);
    }

    public function test_user_id_affects_key(): void
    {
        $a = IdempotencyKey::forAction('create_session', ['x' => 'y']);
        Functions\when('get_current_user_id')->justReturn(99);
        $b = IdempotencyKey::forAction('create_session', ['x' => 'y']);
        $this->assertNotSame($a, $b);
    }

    public function test_key_has_expected_shape(): void
    {
        $key = IdempotencyKey::forAction('x', []);
        $this->assertStringStartsWith('sdb-wp-', $key);
        // 7 char prefix + 32 hex chars
        $this->assertSame(7 + 32, strlen($key));
        // No PII / no raw input visible in output
        $this->assertStringNotContainsString('example.org', $key);
        $this->assertStringNotContainsString('42', substr($key, 7));
    }
}
