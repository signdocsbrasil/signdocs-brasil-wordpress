<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Envelope;

use SignDocsBrasil\Api\Models\AddEnvelopeSessionRequest;
use SignDocsBrasil\Api\Models\CreateEnvelopeRequest;
use SignDocsBrasil\Api\Models\Envelope;
use SignDocsBrasil\Api\Models\EnvelopeDetail;
use SignDocsBrasil\Api\Models\EnvelopeSession;
use SignDocsBrasil\Api\Models\Owner;
use SignDocsBrasil\Api\Models\Policy;
use SignDocsBrasil\Api\Models\Signer;
use SignDocsBrasil\Api\SignDocsBrasilClient;
use SignDocsBrasil\WordPress\Support\IdempotencyKey;

/**
 * Thin WordPress-facing wrapper around the SDK's EnvelopesResource.
 * Adds deterministic idempotency keys so WP admin retries don't
 * duplicate-create envelopes.
 */
final class EnvelopeService {

	public function __construct(
		private readonly SignDocsBrasilClient $client,
	) {
	}

	/**
	 * Create a new multi-signer envelope.
	 *
	 * @param "SEQUENTIAL"|"PARALLEL"        $signingMode
	 * @param array<string,string>|null      $metadata
	 */
	public function create(
		string $signingMode,
		int $totalSigners,
		string $documentContent,
		?string $documentFilename = null,
		?string $returnUrl = null,
		?string $cancelUrl = null,
		?array $metadata = null,
		?string $locale = null,
		?int $expiresInMinutes = null,
		?Owner $owner = null,
	): Envelope {
		$document = array( 'content' => $documentContent );
		if ( $documentFilename !== null ) {
			$document['filename'] = $documentFilename;
		}

		$request = new CreateEnvelopeRequest(
			signingMode: $signingMode,
			totalSigners: $totalSigners,
			document: $document,
			metadata: $metadata,
			locale: $locale,
			returnUrl: $returnUrl,
			cancelUrl: $cancelUrl,
			expiresInMinutes: $expiresInMinutes,
			owner: $owner,
		);

		$idempotencyKey = IdempotencyKey::forAction(
			'envelope.create',
			array(
				'signingMode'  => $signingMode,
				'totalSigners' => $totalSigners,
				'filename'     => $documentFilename ?? '',
				// Hash the document content for key stability without bloating the key.
				'doc'          => substr( hash( 'sha256', $documentContent ), 0, 16 ),
			)
		);

		return $this->client->envelopes->create( $request, $idempotencyKey );
	}

	public function get( string $envelopeId ): EnvelopeDetail {
		return $this->client->envelopes->get( $envelopeId );
	}

	/**
	 * Add a signing session to an envelope.
	 */
	public function addSession(
		string $envelopeId,
		int $signerIndex,
		string $signerName,
		string $signerEmail,
		?string $signerCpf = null,
		?string $policyProfile = null,
	): EnvelopeSession {
		$request = new AddEnvelopeSessionRequest(
			signer: new Signer(
				name: $signerName,
				userExternalId: 'wp_' . md5( $signerEmail ),
				email: $signerEmail,
				cpf: $signerCpf,
			),
			policy: new Policy( profile: $policyProfile ?? 'CLICK_ONLY' ),
			signerIndex: $signerIndex,
		);
		return $this->client->envelopes->addSession( $envelopeId, $request );
	}

	public function combinedStamp( string $envelopeId ): mixed {
		return $this->client->envelopes->combinedStamp( $envelopeId );
	}
}
