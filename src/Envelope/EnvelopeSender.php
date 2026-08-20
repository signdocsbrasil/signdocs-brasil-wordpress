<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Envelope;

defined( 'ABSPATH' ) || exit;

use SignDocsBrasil\WordPress\Support\SigningUrl;

/**
 * Sends an envelope: one create, then one session per signer.
 *
 * Each signer becomes a `signdocs_signing` child post of the envelope, linked
 * by post_parent. That is not bookkeeping for its own sake — the envelope's
 * member sessions emit ordinary `TRANSACTION.*` webhooks, so once the children
 * exist the router already in place keeps every signer's status current with
 * no envelope-specific handling.
 *
 * Partial sends are expected rather than exceptional. The envelope and the
 * sessions are separate API calls, so a failure part-way leaves an envelope
 * with fewer sessions than signers. That state is recorded and resumable: the
 * per-signer idempotency keys are derived from the envelope id and the signer
 * index, so re-sending replays the sessions that already exist instead of
 * duplicating them, and creates only the ones that are missing.
 */
final class EnvelopeSender {

	public const META_ENVELOPE_ID  = '_signdocs_envelope_id';
	public const META_STATUS       = '_signdocs_status';
	public const META_MODE         = '_signdocs_signing_mode';
	public const META_TOTAL        = '_signdocs_total_signers';
	public const META_DOCUMENT     = '_signdocs_document_attachment_id';
	public const META_POLICY       = '_signdocs_policy';
	public const META_SIGNERS      = '_signdocs_signers';
	public const META_SENT_INDEXES = '_signdocs_sent_signer_indexes';

	/** Presigned and short-lived; always read together. */
	public const META_COMBINED_URL     = '_signdocs_combined_url';
	public const META_COMBINED_EXPIRES = '_signdocs_combined_url_expires';

	/** Statuses past which an envelope can no longer be cancelled. */
	public const TERMINAL_STATUSES = array( 'COMPLETED', 'CANCELLED', 'EXPIRED' );

	public function __construct(
		private readonly EnvelopeService $service,
	) {
	}

	/**
	 * @return array{envelopeId: string, created: int, replayed: int}
	 * @throws \RuntimeException When the document is unreadable or the API rejects the send.
	 */
	public function send( int $envelopePostId, EnvelopeDraft $draft, int $attachmentId, string $policy ): array {
		if ( ! $draft->isSendable() ) {
			throw new \RuntimeException( implode( ' ', $draft->errors ) );
		}

		$filePath = \get_attached_file( $attachmentId );
		if ( ! is_string( $filePath ) || $filePath === '' || ! file_exists( $filePath ) ) {
			throw new \RuntimeException( __( 'Documento não encontrado na biblioteca de mídia.', 'signdocs-brasil' ) );
		}
		$contents = file_get_contents( $filePath );
		if ( $contents === false ) {
			throw new \RuntimeException( __( 'Não foi possível ler o documento.', 'signdocs-brasil' ) );
		}

		// Reuse the envelope this post already has. Without this a retry after
		// a part-way failure would create a second envelope and orphan the
		// sessions written against the first.
		$envelopeId = (string) \get_post_meta( $envelopePostId, self::META_ENVELOPE_ID, true );
		if ( $envelopeId === '' ) {
			$envelope   = $this->service->create(
				signingMode: $draft->mode,
				totalSigners: $draft->count(),
				documentContent: $contents,
				documentFilename: basename( $filePath ),
				metadata: array(
					'wp_source'       => 'envelope',
					'wp_envelope_post' => (string) $envelopePostId,
					'wp_site_url'     => \home_url(),
				),
			);
			$envelopeId = (string) $envelope->envelopeId;
			\update_post_meta( $envelopePostId, self::META_ENVELOPE_ID, $envelopeId );
		}

		\update_post_meta( $envelopePostId, self::META_MODE, $draft->mode );
		\update_post_meta( $envelopePostId, self::META_TOTAL, $draft->count() );
		\update_post_meta( $envelopePostId, self::META_DOCUMENT, $attachmentId );
		\update_post_meta( $envelopePostId, self::META_POLICY, $policy );

		$sent    = $this->sentIndexes( $envelopePostId );
		$created = 0;
		$replayed = 0;

		foreach ( $draft->signers as $i => $signer ) {
			$signerIndex = $i + 1;

			if ( isset( $sent[ (string) $signerIndex ] ) ) {
				++$replayed;
				continue;
			}

			$session = $this->service->addSession(
				envelopeId: $envelopeId,
				signerIndex: $signerIndex,
				signerName: $signer['name'],
				signerEmail: $signer['email'],
				signerCpf: $signer['cpf'] !== '' ? $signer['cpf'] : null,
				policyProfile: $policy,
			);

			$childId = $this->createChild( $envelopePostId, $signerIndex, $signer, $policy, $session );

			$sent[ (string) $signerIndex ] = array(
				'sessionId' => (string) ( $session->sessionId ?? '' ),
				'postId'    => $childId,
			);
			\update_post_meta( $envelopePostId, self::META_SENT_INDEXES, $sent );
			++$created;
		}

		\update_post_meta( $envelopePostId, self::META_STATUS, 'ACTIVE' );

		return array(
			'envelopeId' => $envelopeId,
			'created'    => $created,
			'replayed'   => $replayed,
		);
	}

	/**
	 * Cancel the whole envelope through its own endpoint.
	 *
	 * Not the same as cancelling each member session: that leaves the
	 * envelope's own status ACTIVE, costs a call per signer, and records N
	 * separate events instead of one auditable cancellation. Signatures
	 * already collected are preserved upstream and reported back.
	 *
	 * @return array{cancelled: int, preserved: int, alreadyCancelled: bool}
	 */
	public function cancel( int $envelopePostId, string $reason ): array {
		$envelopeId = (string) \get_post_meta( $envelopePostId, self::META_ENVELOPE_ID, true );
		if ( $envelopeId === '' ) {
			throw new \RuntimeException( __( 'Este envelope ainda não foi enviado.', 'signdocs-brasil' ) );
		}

		$result = $this->service->cancel( $envelopeId, $reason );

		\update_post_meta( $envelopePostId, self::META_STATUS, 'CANCELLED' );

		// The children mirror sessions that are now dead upstream. The webhook
		// will say so too, but only for the ones that were still pending —
		// writing it here means the screen is right immediately rather than
		// after the deliveries land.
		foreach ( $this->children( $envelopePostId ) as $childId ) {
			$childStatus = (string) \get_post_meta( $childId, '_signdocs_status', true );
			if ( in_array( $childStatus, array( 'COMPLETED', 'CANCELLED', 'EXPIRED' ), true ) ) {
				continue;
			}
			\update_post_meta( $childId, '_signdocs_status', 'CANCELLED' );
		}

		return array(
			'cancelled'        => (int) ( $result->cancelledCount ?? 0 ),
			'preserved'        => (int) ( $result->preservedSignedCount ?? 0 ),
			'alreadyCancelled' => (bool) ( $result->alreadyCancelled ?? false ),
		);
	}

	/**
	 * Mint a combined stamped PDF and store the link.
	 *
	 * The URL is presigned and short-lived, so the moment it stops working is
	 * stored with it. Without that the screen would keep offering a link that
	 * has silently become a 403.
	 *
	 * @return array{url: string, signerCount: int}
	 */
	public function refreshCombinedStamp( int $envelopePostId ): array {
		$envelopeId = (string) \get_post_meta( $envelopePostId, self::META_ENVELOPE_ID, true );
		if ( $envelopeId === '' ) {
			throw new \RuntimeException( __( 'Este envelope ainda não foi enviado.', 'signdocs-brasil' ) );
		}

		$stamp = $this->service->combinedStamp( $envelopeId );

		$url = (string) ( $stamp->downloadUrl ?? '' );
		if ( $url === '' ) {
			throw new \RuntimeException( __( 'A API não retornou um link para o PDF combinado.', 'signdocs-brasil' ) );
		}

		\update_post_meta( $envelopePostId, self::META_COMBINED_URL, $url );
		\update_post_meta(
			$envelopePostId,
			self::META_COMBINED_EXPIRES,
			time() + (int) ( $stamp->expiresIn ?? 3600 )
		);

		return array(
			'url'         => $url,
			'signerCount' => (int) ( $stamp->signerCount ?? 0 ),
		);
	}

	/** @return list<int> */
	private function children( int $envelopePostId ): array {
		$children = \get_children(
			array(
				'post_parent' => $envelopePostId,
				'post_type'   => \Signdocs_CPT::POST_TYPE,
				'numberposts' => EnvelopeDraft::MAX_SIGNERS,
				'fields'      => 'ids',
			)
		);
		return is_array( $children ) ? array_map( 'intval', array_values( $children ) ) : array();
	}

	/**
	 * @return array<string, array{sessionId: string, postId: int}>
	 */
	private function sentIndexes( int $envelopePostId ): array {
		$raw = \get_post_meta( $envelopePostId, self::META_SENT_INDEXES, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array{name: string, email: string, cpf: string, cnpj: string} $signer
	 */
	private function createChild(
		int $envelopePostId,
		int $signerIndex,
		array $signer,
		string $policy,
		object $session,
	): int {
		$childId = \wp_insert_post(
			array(
				'post_type'   => \Signdocs_CPT::POST_TYPE,
				'post_title'  => sprintf(
					/* translators: 1: signer position, 2: signer name. */
					__( 'Signatário %1$d — %2$s', 'signdocs-brasil' ),
					$signerIndex,
					$signer['name']
				),
				'post_status' => 'publish',
				'post_parent' => $envelopePostId,
			),
			// Ask for the error object. Without this second argument
			// wp_insert_post returns 0 on failure and an is_wp_error() check
			// against it is dead code — the failure would pass silently and the
			// signer would have a session upstream with no record here.
			true
		);

		if ( \is_wp_error( $childId ) ) {
			// The session exists upstream regardless, and its idempotency key
			// is derived from the envelope and signer index, so re-sending
			// replays it rather than charging again. Surfacing the reason
			// matters more than the failure itself.
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: WordPress error message. */
					__( 'Sessão criada, mas o registro local falhou: %s', 'signdocs-brasil' ),
					$childId->get_error_message()
				)
			);
		}

		$childId = (int) $childId;
		\update_post_meta( $childId, '_signdocs_session_id', (string) ( $session->sessionId ?? '' ) );
		\update_post_meta( $childId, '_signdocs_transaction_id', (string) ( $session->transactionId ?? '' ) );
		\update_post_meta( $childId, '_signdocs_status', 'ACTIVE' );
		\update_post_meta( $childId, '_signdocs_signer_name', $signer['name'] );
		\update_post_meta( $childId, '_signdocs_signer_email', $signer['email'] );
		\update_post_meta( $childId, '_signdocs_policy', $policy );
		\update_post_meta( $childId, '_signdocs_signer_index', $signerIndex );
		\update_post_meta( $childId, '_signdocs_source', 'envelope' );

		// Assembled, never bare: `url` alone is rejected with HTTP 400 because
		// the signing page needs the embed token as `?cs=`. See SigningUrl.
		if ( ! empty( $session->url ) && ! empty( $session->clientSecret ) ) {
			\update_post_meta( $childId, '_signdocs_session_url', SigningUrl::fromSession( $session ) );
		}

		return $childId;
	}
}
