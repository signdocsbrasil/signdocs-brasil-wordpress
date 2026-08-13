<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SignDocsBrasil\WordPress\Support\SigningUrl;

/**
 * Regression cover for the v1.3.8 fix: the WooCommerce order email and the
 * CPT admin meta stored the bare session `url`, which the hosted signing
 * page rejects with HTTP 400 because the embed token was missing.
 */
final class SigningUrlTest extends TestCase
{
    public function test_appends_secret_as_cs_query_param(): void
    {
        $this->assertSame(
            'https://sign.signdocs.com.br/s/sess_123?cs=secret_abc',
            SigningUrl::build('https://sign.signdocs.com.br/s/sess_123', 'secret_abc'),
        );
    }

    public function test_uses_ampersand_when_url_already_has_a_query(): void
    {
        $this->assertSame(
            'https://sign.signdocs.com.br/s/sess_123?lang=pt-BR&cs=secret_abc',
            SigningUrl::build('https://sign.signdocs.com.br/s/sess_123?lang=pt-BR', 'secret_abc'),
        );
    }

    public function test_secret_is_url_encoded(): void
    {
        // Base64-ish tokens can carry +, / and = — all of which change meaning
        // inside a query string if passed through raw.
        $this->assertSame(
            'https://sign.signdocs.com.br/s/sess_123?cs=a%2Bb%2Fc%3D',
            SigningUrl::build('https://sign.signdocs.com.br/s/sess_123', 'a+b/c='),
        );
    }

    public function test_empty_secret_leaves_url_untouched(): void
    {
        $this->assertSame(
            'https://sign.signdocs.com.br/s/sess_123',
            SigningUrl::build('https://sign.signdocs.com.br/s/sess_123', ''),
        );
    }

    public function test_empty_url_yields_empty_string(): void
    {
        $this->assertSame('', SigningUrl::build('', 'secret_abc'));
    }

    public function test_from_session_reads_url_and_secret_properties(): void
    {
        $session = new class {
            public string $url = 'https://sign.signdocs.com.br/s/sess_777';
            public string $clientSecret = 'tok_999';
        };

        $this->assertSame(
            'https://sign.signdocs.com.br/s/sess_777?cs=tok_999',
            SigningUrl::fromSession($session),
        );
    }

    public function test_from_session_tolerates_null(): void
    {
        $this->assertSame('', SigningUrl::fromSession(null));
    }
}
