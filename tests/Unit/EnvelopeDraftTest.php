<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SignDocsBrasil\WordPress\Envelope\EnvelopeDraft;

final class EnvelopeDraftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function signer(string $name, string $email, string $cpf = '', string $cnpj = ''): array
    {
        return ['name' => $name, 'email' => $email, 'cpf' => $cpf, 'cnpj' => $cnpj];
    }

    public function test_two_valid_signers_are_sendable(): void
    {
        $draft = EnvelopeDraft::fromInput(
            [
                self::signer('Maria Silva', 'maria@example.com', '52998224725'),
                self::signer('Joao Souza', 'joao@example.com'),
            ],
            'PARALLEL'
        );

        $this->assertTrue($draft->isSendable(), implode(' | ', $draft->errors));
        $this->assertSame(2, $draft->count());
    }

    public function test_blank_rows_are_dropped_not_reported(): void
    {
        // The repeater always renders one spare empty row. If that counted as
        // an unfinished signer the form could never be submitted.
        $draft = EnvelopeDraft::fromInput(
            [
                self::signer('Maria Silva', 'maria@example.com'),
                self::signer('Joao Souza', 'joao@example.com'),
                self::signer('', ''),
            ],
            'PARALLEL'
        );

        $this->assertTrue($draft->isSendable(), implode(' | ', $draft->errors));
        $this->assertSame(2, $draft->count());
    }

    public function test_one_signer_is_rejected(): void
    {
        // An envelope with one signer is a plain signing session; the API
        // rejects it and the admin should be told which tool to use instead.
        $draft = EnvelopeDraft::fromInput([self::signer('Maria', 'maria@example.com')], 'PARALLEL');

        $this->assertFalse($draft->isSendable());
    }

    public function test_duplicate_email_is_rejected(): void
    {
        // The API would accept this — the signers differ by index — and one
        // person would receive two invitations while the audit trail recorded
        // them as two parties.
        $draft = EnvelopeDraft::fromInput(
            [
                self::signer('Maria Silva', 'maria@example.com'),
                self::signer('Maria S.', 'MARIA@EXAMPLE.COM'),
            ],
            'PARALLEL'
        );

        $this->assertFalse($draft->isSendable());
    }

    public function test_incomplete_row_is_reported_rather_than_dropped(): void
    {
        // Distinct from a blank row: something was typed, so it is a signer
        // somebody did not finish, and dropping it would silently send the
        // envelope to fewer people than the admin listed.
        $draft = EnvelopeDraft::fromInput(
            [
                self::signer('Maria Silva', 'maria@example.com'),
                self::signer('Joao Souza', 'joao@example.com'),
                self::signer('Sem Email', ''),
            ],
            'PARALLEL'
        );

        $this->assertFalse($draft->isSendable());
        $this->assertSame(3, $draft->count());
    }

    public function test_malformed_documents_are_rejected(): void
    {
        $short = EnvelopeDraft::fromInput(
            [self::signer('A', 'a@example.com', '123'), self::signer('B', 'b@example.com')],
            'PARALLEL'
        );
        $this->assertFalse($short->isSendable());

        $both = EnvelopeDraft::fromInput(
            [
                self::signer('A', 'a@example.com', '52998224725', '12345678000195'),
                self::signer('B', 'b@example.com'),
            ],
            'PARALLEL'
        );
        $this->assertFalse($both->isSendable());
    }

    public function test_punctuation_is_stripped_from_documents(): void
    {
        $draft = EnvelopeDraft::fromInput(
            [
                self::signer('A', 'a@example.com', '529.982.247-25'),
                self::signer('B', 'b@example.com', '', '12.345.678/0001-95'),
            ],
            'PARALLEL'
        );

        $this->assertTrue($draft->isSendable(), implode(' | ', $draft->errors));
        $this->assertSame('52998224725', $draft->signers[0]['cpf']);
        $this->assertSame('12345678000195', $draft->signers[1]['cnpj']);
    }

    public function test_mode_defaults_to_parallel(): void
    {
        $this->assertSame('PARALLEL', EnvelopeDraft::normalizeMode(''));
        $this->assertSame('PARALLEL', EnvelopeDraft::normalizeMode('nonsense'));
        $this->assertSame('SEQUENTIAL', EnvelopeDraft::normalizeMode('sequential'));
        $this->assertSame('SEQUENTIAL', EnvelopeDraft::normalizeMode('  SEQUENTIAL '));
    }
}
