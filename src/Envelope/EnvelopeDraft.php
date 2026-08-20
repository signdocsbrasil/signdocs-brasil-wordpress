<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Envelope;

defined( 'ABSPATH' ) || exit;

/**
 * The signers and settings an envelope will be sent with, validated.
 *
 * Kept apart from the sending itself so the rules that decide whether a draft
 * is sendable can be tested without a WordPress request or an API client.
 * Everything here is a pure function of its input.
 */
final class EnvelopeDraft {

	public const MODE_SEQUENTIAL = 'SEQUENTIAL';
	public const MODE_PARALLEL   = 'PARALLEL';

	/** The API rejects an envelope with fewer than two signers — that is a plain session. */
	public const MIN_SIGNERS = 2;

	/** Mirrors MAX_SIGNERS_PER_ENVELOPE on the API. */
	public const MAX_SIGNERS = 100;

	/**
	 * @param list<array{name: string, email: string, cpf: string, cnpj: string}> $signers
	 * @param list<string>                                                        $errors
	 */
	private function __construct(
		public readonly array $signers,
		public readonly string $mode,
		public readonly array $errors,
	) {
	}

	/**
	 * Build a draft from raw admin input.
	 *
	 * Never throws: an unsendable draft is a normal state for a half-filled
	 * form, so the problems are collected and returned for display rather than
	 * raised. Call isSendable() before handing it to the sender.
	 *
	 * @param array<int|string, array<string, mixed>> $rawSigners
	 */
	public static function fromInput( array $rawSigners, string $rawMode ): self {
		$signers = array();
		$errors  = array();

		foreach ( array_values( $rawSigners ) as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name  = trim( (string) ( $row['name'] ?? '' ) );
			$email = trim( (string) ( $row['email'] ?? '' ) );
			$cpf   = self::digits( (string) ( $row['cpf'] ?? '' ) );
			$cnpj  = self::digits( (string) ( $row['cnpj'] ?? '' ) );

			// A row where nothing was typed is the empty template the repeater
			// renders, not a signer somebody forgot to finish. Dropping it
			// silently is what lets the form ship with a spare blank row.
			if ( $name === '' && $email === '' && $cpf === '' && $cnpj === '' ) {
				continue;
			}

			$position = $i + 1;

			if ( $name === '' ) {
				$errors[] = sprintf(
					/* translators: %d: signer position in the list. */
					__( 'Signatário %d: informe o nome.', 'signdocs-brasil' ),
					$position
				);
			}
			if ( $email === '' ) {
				$errors[] = sprintf(
					/* translators: %d: signer position in the list. */
					__( 'Signatário %d: informe o e-mail.', 'signdocs-brasil' ),
					$position
				);
			}
			if ( $cpf !== '' && strlen( $cpf ) !== 11 ) {
				$errors[] = sprintf(
					/* translators: %d: signer position in the list. */
					__( 'Signatário %d: CPF precisa ter 11 dígitos.', 'signdocs-brasil' ),
					$position
				);
			}
			if ( $cnpj !== '' && strlen( $cnpj ) !== 14 ) {
				$errors[] = sprintf(
					/* translators: %d: signer position in the list. */
					__( 'Signatário %d: CNPJ precisa ter 14 dígitos.', 'signdocs-brasil' ),
					$position
				);
			}
			if ( $cpf !== '' && $cnpj !== '' ) {
				$errors[] = sprintf(
					/* translators: %d: signer position in the list. */
					__( 'Signatário %d: informe CPF ou CNPJ, não os dois.', 'signdocs-brasil' ),
					$position
				);
			}

			$signers[] = array(
				'name'  => $name,
				'email' => $email,
				'cpf'   => $cpf,
				'cnpj'  => $cnpj,
			);
		}

		// The same address twice means the same person is signer 1 and signer 3.
		// The API assigns one session per signerIndex and would accept it, so
		// the duplicate has to be caught here or one person receives two
		// invitations and the audit trail records them as two parties.
		$seen = array();
		foreach ( $signers as $i => $signer ) {
			$key = strtolower( $signer['email'] );
			if ( $key === '' ) {
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = sprintf(
					/* translators: 1: signer position, 2: email address. */
					__( 'Signatário %1$d: o e-mail %2$s já foi informado.', 'signdocs-brasil' ),
					$i + 1,
					$signer['email']
				);
				continue;
			}
			$seen[ $key ] = true;
		}

		$count = count( $signers );
		if ( $count < self::MIN_SIGNERS ) {
			$errors[] = sprintf(
				/* translators: %d: minimum number of signers. */
				__( 'Um envelope precisa de pelo menos %d signatários. Para um signatário use o shortcode ou o bloco.', 'signdocs-brasil' ),
				self::MIN_SIGNERS
			);
		}
		if ( $count > self::MAX_SIGNERS ) {
			$errors[] = sprintf(
				/* translators: %d: maximum number of signers. */
				__( 'Um envelope aceita no máximo %d signatários.', 'signdocs-brasil' ),
				self::MAX_SIGNERS
			);
		}

		return new self( $signers, self::normalizeMode( $rawMode ), $errors );
	}

	public function isSendable(): bool {
		return $this->errors === array();
	}

	public function count(): int {
		return count( $this->signers );
	}

	/** Anything that is not the sequential opt-in is parallel. */
	public static function normalizeMode( string $mode ): string {
		return strtoupper( trim( $mode ) ) === self::MODE_SEQUENTIAL
			? self::MODE_SEQUENTIAL
			: self::MODE_PARALLEL;
	}

	private static function digits( string $value ): string {
		return (string) preg_replace( '/\D+/', '', $value );
	}
}
