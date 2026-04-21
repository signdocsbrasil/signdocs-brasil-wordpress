<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SignDocsBrasil\WordPress\Auth\Capabilities;

final class CapabilitiesTest extends TestCase
{
    public function test_all_caps_are_listed(): void
    {
        $caps = Capabilities::all();
        $this->assertContains(Capabilities::MANAGE, $caps);
        $this->assertContains(Capabilities::SEND, $caps);
        $this->assertContains(Capabilities::VERIFY, $caps);
        $this->assertContains(Capabilities::VIEW_LOGS, $caps);
        $this->assertCount(4, $caps);
    }

    public function test_map_meta_cap_edit_requires_send(): void
    {
        $out = Capabilities::mapMetaCap([], 'edit_signdocs_signing', 1, [1]);
        $this->assertSame([Capabilities::SEND], $out);
    }

    public function test_map_meta_cap_read_requires_verify(): void
    {
        $out = Capabilities::mapMetaCap([], 'read_signdocs_signing', 1, [1]);
        $this->assertSame([Capabilities::VERIFY], $out);
    }

    public function test_map_meta_cap_delete_requires_manage(): void
    {
        $out = Capabilities::mapMetaCap([], 'delete_signdocs_signing', 1, [1]);
        $this->assertSame([Capabilities::MANAGE], $out);
    }

    public function test_map_meta_cap_passthrough_for_unrelated(): void
    {
        $in = ['manage_options'];
        $out = Capabilities::mapMetaCap($in, 'manage_options', 1, []);
        $this->assertSame($in, $out);
    }
}
