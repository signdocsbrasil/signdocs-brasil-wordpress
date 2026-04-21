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
