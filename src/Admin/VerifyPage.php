<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

use SignDocsBrasil\Api\Models\EnvelopeVerificationResponse;
use SignDocsBrasil\Api\Models\VerificationResponse;
use SignDocsBrasil\WordPress\Auth\Capabilities;

/**
 * "Verify Document" admin submenu — exposes the verification endpoint
 * so a support/ops user can paste an evidence ID or envelope ID and
 * see the signer identities, timeline, and downloadable artifacts.
 *
 * Gated by `signdocs_verify`. Wires `GET /v1/verify/{evidenceId}` and
 * `GET /v1/verify/envelope/{envelopeId}` (SDK 1.2.0+).
 */
final class VerifyPage {

	public const MENU_SLUG     = 'signdocs-verify';
	private const NONCE_ACTION = 'signdocs_verify';

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'registerMenu' ) );
	}

	public function registerMenu(): void {
		\add_submenu_page(
			'edit.php?post_type=signdocs_signing',
			__( 'Verificar Documento', 'signdocs-brasil' ),
			__( 'Verificar', 'signdocs-brasil' ),
			Capabilities::VERIFY,
			self::MENU_SLUG,
			array( $this, 'render' ),
		);
	}

	public function render(): void {
		if ( ! \current_user_can( Capabilities::VERIFY ) ) {
			\wp_die( \esc_html__( 'Sem permissão.', 'signdocs-brasil' ) );
		}

		$result = null;
		$error  = null;
		$id     = '';
		$kind   = 'evidence';

		if ( isset( $_POST['signdocs_verify_nonce'] )
			&& \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['signdocs_verify_nonce'] ) ), self::NONCE_ACTION )
		) {
			$id   = isset( $_POST['id'] ) ? \sanitize_text_field( \wp_unslash( $_POST['id'] ) ) : '';
			$kind = isset( $_POST['kind'] ) && $_POST['kind'] === 'envelope' ? 'envelope' : 'evidence';

			$client = null;
			if ( class_exists( 'Signdocs_Client_Factory' ) ) {
				$client = \Signdocs_Client_Factory::get_client();
			}
			if ( $client === null ) {
				$error = __( 'Credenciais não configuradas.', 'signdocs-brasil' );
			} elseif ( $id === '' ) {
				$error = __( 'Informe um ID.', 'signdocs-brasil' );
			} else {
				try {
					$result = $kind === 'envelope'
						? $client->verification->verifyEnvelope( $id )
						: $client->verification->verify( $id );
				} catch ( \Throwable $e ) {
					$error = $e->getMessage();
				}
			}
		}

		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__( 'Verificar Documento', 'signdocs-brasil' ) . '</h1>';
		echo '<form method="post" style="margin-bottom:2em;">';
		\wp_nonce_field( self::NONCE_ACTION, 'signdocs_verify_nonce' );
		echo '<table class="form-table">';
		echo '<tr><th><label for="kind">' . \esc_html__( 'Tipo', 'signdocs-brasil' ) . '</label></th><td>';
		echo '<select name="kind" id="kind">';
		echo '<option value="evidence"' . \selected( $kind, 'evidence', false ) . '>' . \esc_html__( 'Evidence ID', 'signdocs-brasil' ) . '</option>';
		echo '<option value="envelope"' . \selected( $kind, 'envelope', false ) . '>' . \esc_html__( 'Envelope ID', 'signdocs-brasil' ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th><label for="id">' . \esc_html__( 'ID', 'signdocs-brasil' ) . '</label></th><td>';
		echo '<input type="text" name="id" id="id" class="regular-text" value="' . \esc_attr( $id ) . '" />';
		echo '</td></tr>';
		echo '</table>';
		\submit_button( __( 'Verificar', 'signdocs-brasil' ) );
		echo '</form>';

		if ( $error !== null ) {
			echo '<div class="notice notice-error"><p>' . \esc_html( $error ) . '</p></div>';
		}

		if ( $result instanceof EnvelopeVerificationResponse ) {
			$this->renderEnvelopeResult( $result );
		} elseif ( $result instanceof VerificationResponse ) {
			$this->renderEvidenceResult( $result );
		}

		echo '</div>';
	}

	private function renderEvidenceResult( VerificationResponse $r ): void {
		echo '<h2>' . \esc_html__( 'Resultado — Evidence', 'signdocs-brasil' ) . '</h2>';
		echo '<table class="widefat striped">';
		$rows = array(
			array( 'Evidence ID', (string) ( $r->evidenceId ?? '' ) ),
			array( 'Transaction ID', (string) ( $r->transactionId ?? '' ) ),
			array( 'Tenant CNPJ', (string) ( $r->tenantCnpj ?? '' ) ),
			array( 'Signer name', (string) ( $r->signer['name'] ?? '' ) ),
			array( 'Signer CPF/CNPJ', (string) ( $r->signer['cpfCnpj'] ?? '' ) ),
			array( 'Completed at', (string) ( $r->completedAt ?? '' ) ),
			array( 'Policy', (string) ( $r->policy['profile'] ?? '' ) ),
		);
		foreach ( $rows as [$label, $value] ) {
			echo '<tr><th style="width:20%;">' . \esc_html( $label ) . '</th><td><code>' . \esc_html( $value ) . '</code></td></tr>';
		}
		echo '</table>';
	}

	private function renderEnvelopeResult( EnvelopeVerificationResponse $r ): void {
		echo '<h2>' . \esc_html__( 'Resultado — Envelope', 'signdocs-brasil' ) . '</h2>';
		echo '<p><strong>' . \esc_html__( 'Status', 'signdocs-brasil' ) . ':</strong> <code>' . \esc_html( (string) ( $r->status ?? '' ) ) . '</code></p>';
		$signers = is_array( $r->signers ?? null ) ? $r->signers : array();
		if ( $signers !== array() ) {
			echo '<h3>' . \esc_html__( 'Signatários', 'signdocs-brasil' ) . '</h3>';
			echo '<table class="widefat striped">';
			echo '<thead><tr><th>#</th><th>Name</th><th>CPF/CNPJ</th><th>Evidence ID</th><th>Completed</th></tr></thead><tbody>';
			foreach ( $signers as $i => $signer ) {
				if ( ! is_array( $signer ) ) {
					continue;
				}
				echo '<tr>';
				echo '<td>' . (int) ( $signer['signerIndex'] ?? $i + 1 ) . '</td>';
				echo '<td>' . \esc_html( (string) ( $signer['name'] ?? '' ) ) . '</td>';
				echo '<td><code>' . \esc_html( (string) ( $signer['cpfCnpj'] ?? '' ) ) . '</code></td>';
				echo '<td><code>' . \esc_html( (string) ( $signer['evidenceId'] ?? '' ) ) . '</code></td>';
				echo '<td>' . \esc_html( (string) ( $signer['completedAt'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$downloads = is_array( $r->downloads ?? null ) ? $r->downloads : array();
		if ( $downloads !== array() ) {
			echo '<h3>' . \esc_html__( 'Downloads', 'signdocs-brasil' ) . '</h3><ul>';
			foreach ( $downloads as $label => $url ) {
				if ( ! is_string( $url ) || $url === '' ) {
					continue;
				}
				echo '<li><a href="' . \esc_url( $url ) . '" target="_blank" rel="noopener">' . \esc_html( (string) $label ) . '</a></li>';
			}
			echo '</ul>';
		}
	}
}
