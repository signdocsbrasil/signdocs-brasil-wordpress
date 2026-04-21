<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SignDocsBrasil\WordPress\Support\ClientIp;

final class ClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_remote_addr_when_no_trusted_proxies(): void
    {
        Functions\when('get_option')->justReturn('');
        $ip = ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.5']);
        $this->assertSame('203.0.113.5', $ip);
    }

    public function test_ignores_xff_when_remote_is_not_trusted(): void
    {
        Functions\when('get_option')->justReturn('10.0.0.0/8');
        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 10.0.0.7',
        ]);
        $this->assertSame('203.0.113.5', $ip);
    }

    public function test_walks_xff_right_to_left_when_remote_is_trusted(): void
    {
        Functions\when('get_option')->justReturn('10.0.0.0/8');
        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => '10.0.0.7',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 10.0.0.6',
        ]);
        // Rightmost (10.0.0.6) is also trusted → skip; next is 198.51.100.1.
        $this->assertSame('198.51.100.1', $ip);
    }

    public function test_falls_back_to_remote_on_malformed_xff(): void
    {
        Functions\when('get_option')->justReturn('10.0.0.0/8');
        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip, still-garbage',
        ]);
        $this->assertSame('10.0.0.1', $ip);
    }

    public function test_supports_multiple_trusted_ranges(): void
    {
        Functions\when('get_option')->justReturn('10.0.0.0/8, 192.168.0.0/16');
        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => '192.168.1.50',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
        ]);
        $this->assertSame('203.0.113.1', $ip);
    }

    public function test_ipv6_range(): void
    {
        Functions\when('get_option')->justReturn('fd00::/8');
        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => 'fd00:1234::1',
            'HTTP_X_FORWARDED_FOR' => '2001:db8::1',
        ]);
        $this->assertSame('2001:db8::1', $ip);
    }
}
