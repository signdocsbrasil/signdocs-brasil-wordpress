<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

use SignDocsBrasil\WordPress\Cpt\EnvelopeCpt;
use SignDocsBrasil\WordPress\Envelope\EnvelopeDraft;
use SignDocsBrasil\WordPress\Envelope\EnvelopeSender;
use SignDocsBrasil\WordPress\Envelope\EnvelopeService;
use SignDocsBrasil\WordPress\Support\Logger;

/**
 * Persists the envelope compose form, and sends when asked to.
 *
 * Saving and sending are the same request on purpose: an admin who edits the
 * signer list and presses "Enviar" expects the edit to be what goes out, and a
 * save-then-send pair would let those two diverge.
 */
final class EnvelopeSaveHandler {

	private const NOTICE_META = '_signdocs_envelope_notice';

	public function register(): void {
		\add_action( 'save_post_' . EnvelopeCpt::POST_TYPE, array( $this, 'onSave' ), 10, 2 );
		\add_action( 'admin_notices', array( $this, 'renderNotice' ) );
	}

	public function onSave( int $postId, \WP_Post $post ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( $post->post_status === 'auto-draft' ) {
			return;
		}
		if ( ! isset( $_POST[ EnvelopeMetaBox::NONCE_FIELD ] ) ) {
			return;
		}
		$nonce = \sanitize_text_field( \wp_unslash( (string) $_POST[ EnvelopeMetaBox::NONCE_FIELD ] ) );
		if ( ! \wp_verify_nonce( $nonce, EnvelopeMetaBox::NONCE_ACTION ) ) {
			return;
		}
		if ( ! \current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		// Already sent: the signer list is fixed. Only the resend button, which
		// carries no signer input, is still allowed through.
		$alreadySent = (string) \get_post_meta( $postId, EnvelopeSender::META_ENVELOPE_ID, true ) !== '';

		$rawSigners = array();
		if ( ! $alreadySent && isset( $_POST['signdocs_envelope_signers'] ) && is_array( $_POST['signdocs_envelope_signers'] ) ) {
			// Sanitised field by field in EnvelopeDraft::fromInput, which trims
			// and strips non-digits; unslashed here because WordPress adds
			// slashes to the whole superglobal.
			$rawSigners = \map_deep( \wp_unslash( $_POST['signdocs_envelope_signers'] ), 'sanitize_text_field' );
		}

		$mode   = isset( $_POST['signdocs_envelope_mode'] )
			? \sanitize_text_field( \wp_unslash( (string) $_POST['signdocs_envelope_mode'] ) )
			: '';
		$policy = isset( $_POST['signdocs_envelope_policy'] )
			? \sanitize_text_field( \wp_unslash( (string) $_POST['signdocs_envelope_policy'] ) )
			: '';
		$docId  = isset( $_POST['signdocs_envelope_document'] )
			? \absint( \wp_unslash( $_POST['signdocs_envelope_document'] ) )
			: 0;

		$policy = $this->allowedPolicy( $policy );

		if ( ! $alreadySent ) {
			\update_post_meta( $postId, EnvelopeSender::META_SIGNERS, $rawSigners );
			\update_post_meta( $postId, EnvelopeSender::META_MODE, EnvelopeDraft::normalizeMode( $mode ) );
			\update_post_meta( $postId, EnvelopeSender::META_POLICY, $policy );
			\update_post_meta( $postId, EnvelopeSender::META_DOCUMENT, $docId );
		}

		$action = isset( $_POST['signdocs_envelope_action'] )
			? \sanitize_text_field( \wp_unslash( (string) $_POST['signdocs_envelope_action'] ) )
			: '';
		switch ( $action ) {
			case 'send':
				$this->doSend( $postId, $alreadySent, $rawSigners, $mode, $policy, $docId );
				return;
			case 'cancel':
				$this->doCancel( $postId );
				return;
			case 'combined_stamp':
				$this->doCombinedStamp( $postId );
				return;
		}
	}

	private function doCancel( int $postId ): void {
		$reason = isset( $_POST['signdocs_envelope_cancel_reason'] )
			? \sanitize_text_field( \wp_unslash( (string) $_POST['signdocs_envelope_cancel_reason'] ) )
			: '';
		if ( $reason === '' ) {
			$reason = 'cancelled_via_wordpress';
		}

		$sender = $this->sender();
		if ( $sender === null ) {
			$this->notice( $postId, 'error', __( 'Credenciais do SignDocs não configuradas.', 'signdocs-brasil' ) );
			return;
		}

		try {
			$result = $sender->cancel( $postId, $reason );

			$this->notice(
				$postId,
				'success',
				$result['alreadyCancelled']
					? __( 'Este envelope já estava cancelado.', 'signdocs-brasil' )
					: sprintf(
						/* translators: 1: sessions cancelled, 2: signatures preserved. */
						__( 'Envelope cancelado. %1$d sessões encerradas, %2$d assinaturas preservadas.', 'signdocs-brasil' ),
						$result['cancelled'],
						$result['preserved']
					)
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'envelope.cancel', 'Envelope cancel failed', array( 'postId' => $postId, 'error' => $e->getMessage() ) );
			$this->notice( $postId, 'error', $e->getMessage() );
		}
	}

	private function doCombinedStamp( int $postId ): void {
		$sender = $this->sender();
		if ( $sender === null ) {
			$this->notice( $postId, 'error', __( 'Credenciais do SignDocs não configuradas.', 'signdocs-brasil' ) );
			return;
		}

		try {
			$result = $sender->refreshCombinedStamp( $postId );
			$this->notice(
				$postId,
				'success',
				sprintf(
					/* translators: %d: number of signers in the combined document. */
					__( 'PDF combinado gerado com %d signatários. O link está abaixo.', 'signdocs-brasil' ),
					$result['signerCount']
				)
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'envelope.combined_stamp', 'Combined stamp failed', array( 'postId' => $postId, 'error' => $e->getMessage() ) );
			$this->notice( $postId, 'error', $e->getMessage() );
		}
	}

	private function sender(): ?EnvelopeSender {
		$client = \Signdocs_Client_Factory::get_client();
		if ( $client === null ) {
			return null;
		}
		return new EnvelopeSender( new EnvelopeService( $client ) );
	}

	/**
	 * @param array<int|string, array<string, mixed>> $rawSigners
	 */
	private function doSend( int $postId, bool $alreadySent, array $rawSigners, string $mode, string $policy, int $docId ): void {
		if ( $alreadySent ) {
			// Resend: the stored list is the one that was sent, and it is the
			// only list that keeps the per-signer indexes stable. Re-reading the
			// form here would let a tampered POST re-point a signer index at a
			// different person.
			$stored     = \get_post_meta( $postId, EnvelopeSender::META_SIGNERS, true );
			$rawSigners = is_array( $stored ) ? $stored : array();
			$mode       = (string) \get_post_meta( $postId, EnvelopeSender::META_MODE, true );
			$policy     = (string) \get_post_meta( $postId, EnvelopeSender::META_POLICY, true );
			$docId      = (int) \get_post_meta( $postId, EnvelopeSender::META_DOCUMENT, true );
		}

		$draft = EnvelopeDraft::fromInput( $rawSigners, $mode );

		if ( $docId === 0 ) {
			$this->notice( $postId, 'error', __( 'Escolha o documento antes de enviar.', 'signdocs-brasil' ) );
			return;
		}
		if ( ! $draft->isSendable() ) {
			$this->notice( $postId, 'error', implode( ' ', $draft->errors ) );
			return;
		}

		$sender = $this->sender();
		if ( $sender === null ) {
			$this->notice( $postId, 'error', __( 'Credenciais do SignDocs não configuradas.', 'signdocs-brasil' ) );
			return;
		}

		try {
			$result = $sender->send( $postId, $draft, $docId, $policy );

			$this->notice(
				$postId,
				'success',
				sprintf(
					/* translators: 1: sessions created now, 2: sessions that already existed. */
					__( 'Envelope enviado. %1$d signatários criados, %2$d já existiam.', 'signdocs-brasil' ),
					$result['created'],
					$result['replayed']
				)
			);
		} catch ( \Throwable $e ) {
			Logger::error(
				'envelope.send',
				'Envelope send failed',
				array(
					'postId' => $postId,
					'error'  => $e->getMessage(),
				)
			);
			$this->notice( $postId, 'error', $e->getMessage() );
		}
	}

	/** Never trust a select: the value is echoed back into an API call. */
	private function allowedPolicy( string $policy ): string {
		$allowed = array_map( 'strval', array_keys( \Signdocs_Settings::get_policy_options() ) );
		if ( in_array( $policy, $allowed, true ) ) {
			return $policy;
		}
		return (string) \get_option( 'signdocs_default_policy', 'CLICK_ONLY' );
	}

	private function notice( int $postId, string $type, string $message ): void {
		\update_post_meta( $postId, self::NOTICE_META, array( 'type' => $type, 'message' => $message ) );
	}

	public function renderNotice(): void {
		$screen = \get_current_screen();
		if ( $screen === null || $screen->post_type !== EnvelopeCpt::POST_TYPE ) {
			return;
		}
		$postId = isset( $_GET['post'] ) ? \absint( \wp_unslash( $_GET['post'] ) ) : 0;
		if ( $postId === 0 ) {
			return;
		}
		$notice = \get_post_meta( $postId, self::NOTICE_META, true );
		if ( ! is_array( $notice ) || ( $notice['message'] ?? '' ) === '' ) {
			return;
		}
		\delete_post_meta( $postId, self::NOTICE_META );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			\esc_attr( ( $notice['type'] ?? '' ) === 'success' ? 'success' : 'error' ),
			\esc_html( (string) $notice['message'] )
		);
	}
}
