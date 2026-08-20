<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SignDocsBrasil\Api\Models\Webhook;

/**
 * Which registration the settings page replaces when the API refuses a
 * duplicate URL.
 *
 * The API returns the conflicting id inside a human-readable message. Reading
 * it back out of that string would make a reworded message into a delete of
 * the wrong registration, so the match is done against the listed URLs
 * instead — and a tenant can hold webhooks for other sites, which must never
 * be touched.
 */
final class WebhookUrlMatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('untrailingslashit')->alias(static fn(string $s): string => rtrim($s, '/'));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private static function webhook(string $id, string $url): Webhook
    {
        return new Webhook(
            webhookId: $id,
            url: $url,
            events: ['TRANSACTION.COMPLETED'],
            status: 'ACTIVE',
            createdAt: '2026-08-20T00:00:00Z',
        );
    }

    public function test_matches_this_site_endpoint(): void
    {
        $id = \Signdocs_Settings::find_webhook_id_for_url(
            [
                self::webhook('wh_other', 'https://other-site.example/wp-json/signdocs/v1/webhook'),
                self::webhook('wh_ours', 'https://ours.example/wp-json/signdocs/v1/webhook'),
            ],
            'https://ours.example/wp-json/signdocs/v1/webhook'
        );

        $this->assertSame('wh_ours', $id);
    }

    public function test_ignores_a_trailing_slash_difference(): void
    {
        // rest_url() may or may not carry one depending on permalink settings;
        // a mismatch there would leave the admin stuck with no way to
        // re-register.
        $id = \Signdocs_Settings::find_webhook_id_for_url(
            [self::webhook('wh_ours', 'https://ours.example/wp-json/signdocs/v1/webhook/')],
            'https://ours.example/wp-json/signdocs/v1/webhook'
        );

        $this->assertSame('wh_ours', $id);
    }

    public function test_returns_null_when_no_registration_is_ours(): void
    {
        // Then the 409 was about something else and nothing may be deleted.
        $id = \Signdocs_Settings::find_webhook_id_for_url(
            [self::webhook('wh_other', 'https://other-site.example/wp-json/signdocs/v1/webhook')],
            'https://ours.example/wp-json/signdocs/v1/webhook'
        );

        $this->assertNull($id);
    }

    public function test_requires_an_exact_match_not_a_prefix(): void
    {
        // A registration whose URL merely starts with ours is a different
        // endpoint. Matching loosely here would delete it and silently stop
        // whatever depends on it — and the failure would surface as missing
        // deliveries somewhere else entirely, with nothing pointing back here.
        $id = \Signdocs_Settings::find_webhook_id_for_url(
            [self::webhook('wh_other', 'https://ours.example/wp-json/signdocs/v1/webhook-backup')],
            'https://ours.example/wp-json/signdocs/v1/webhook'
        );

        $this->assertNull($id);
    }

    public function test_does_not_match_a_different_site_on_the_same_host(): void
    {
        // Multisite, or two installs under one domain. Deleting the other
        // install's registration would silently stop its deliveries.
        $id = \Signdocs_Settings::find_webhook_id_for_url(
            [self::webhook('wh_sub', 'https://ours.example/blog2/wp-json/signdocs/v1/webhook')],
            'https://ours.example/wp-json/signdocs/v1/webhook'
        );

        $this->assertNull($id);
    }
}
