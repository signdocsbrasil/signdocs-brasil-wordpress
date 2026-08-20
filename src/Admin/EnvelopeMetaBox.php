<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

use SignDocsBrasil\WordPress\Cpt\EnvelopeCpt;
use SignDocsBrasil\WordPress\Envelope\EnvelopeDraft;
use SignDocsBrasil\WordPress\Envelope\EnvelopeSender;

/**
 * The envelope edit screen: compose before sending, status after.
 *
 * One meta box with two faces rather than two boxes, because the two are
 * mutually exclusive states of the same record and showing an editable signer
 * list beside a sent envelope invites someone to change it and expect the
 * change to travel.
 */
final class EnvelopeMetaBox {

	public const NONCE_ACTION = 'signdocs_envelope_send';
	public const NONCE_FIELD  = 'signdocs_envelope_nonce';

	public function register(): void {
		\add_action( 'add_meta_boxes', array( $this, 'addMetaBox' ) );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function addMetaBox(): void {
		\add_meta_box(
			'signdocs_envelope_compose',
			__( 'Envelope', 'signdocs-brasil' ),
			array( $this, 'render' ),
			EnvelopeCpt::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = \get_current_screen();
		if ( $screen === null || $screen->post_type !== EnvelopeCpt::POST_TYPE ) {
			return;
		}

		\wp_enqueue_media();
		\wp_enqueue_script(
			'signdocs-envelope',
			SIGNDOCS_PLUGIN_URL . 'assets/js/signdocs-envelope.js',
			array(),
			SIGNDOCS_VERSION,
			true
		);
		\wp_localize_script(
			'signdocs-envelope',
			'signdocsEnvelope',
			array(
				'i18n' => array(
					'chooseDocument' => __( 'Escolher documento', 'signdocs-brasil' ),
					'useDocument'    => __( 'Usar este documento', 'signdocs-brasil' ),
					'remove'         => __( 'Remover', 'signdocs-brasil' ),
				),
			)
		);
	}

	public function render( \WP_Post $post ): void {
		$envelopeId = (string) \get_post_meta( $post->ID, EnvelopeSender::META_ENVELOPE_ID, true );

		if ( $envelopeId !== '' ) {
			$this->renderStatus( $post, $envelopeId );
			return;
		}
		$this->renderCompose( $post );
	}

	private function renderCompose( \WP_Post $post ): void {
		$signers = \get_post_meta( $post->ID, EnvelopeSender::META_SIGNERS, true );
		$signers = is_array( $signers ) && $signers !== array() ? $signers : array( array(), array() );
		$mode    = (string) \get_post_meta( $post->ID, EnvelopeSender::META_MODE, true );
		$policy  = (string) \get_post_meta( $post->ID, EnvelopeSender::META_POLICY, true );
		$docId   = (int) \get_post_meta( $post->ID, EnvelopeSender::META_DOCUMENT, true );

		if ( $policy === '' ) {
			$policy = (string) \get_option( 'signdocs_default_policy', 'CLICK_ONLY' );
		}

		\wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="signdocs-envelope-document">'
			. \esc_html__( 'Documento', 'signdocs-brasil' ) . '</label></th><td>';
		printf(
			'<input type="hidden" id="signdocs-envelope-document" name="signdocs_envelope_document" value="%d" />',
			$docId
		);
		printf(
			'<button type="button" class="button" id="signdocs-envelope-pick">%s</button> <span id="signdocs-envelope-document-name">%s</span>',
			\esc_html__( 'Escolher documento', 'signdocs-brasil' ),
			\esc_html( $docId > 0 ? (string) \get_the_title( $docId ) : '' )
		);
		echo '<p class="description">'
			. \esc_html__( 'PDF da biblioteca de mídia. Todos os signatários assinam este mesmo arquivo.', 'signdocs-brasil' )
			. '</p></td></tr>';

		echo '<tr><th scope="row"><label for="signdocs-envelope-mode">'
			. \esc_html__( 'Ordem', 'signdocs-brasil' ) . '</label></th><td>';
		echo '<select id="signdocs-envelope-mode" name="signdocs_envelope_mode">';
		foreach (
			array(
				EnvelopeDraft::MODE_PARALLEL   => __( 'Paralela — todos assinam ao mesmo tempo', 'signdocs-brasil' ),
				EnvelopeDraft::MODE_SEQUENTIAL => __( 'Sequencial — cada um espera o anterior', 'signdocs-brasil' ),
			) as $value => $label
		) {
			printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( $value ),
				\selected( EnvelopeDraft::normalizeMode( $mode ), $value, false ),
				\esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="signdocs-envelope-policy">'
			. \esc_html__( 'Perfil de assinatura', 'signdocs-brasil' ) . '</label></th><td>';
		echo '<select id="signdocs-envelope-policy" name="signdocs_envelope_policy">';
		foreach ( \Signdocs_Settings::get_policy_options() as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( (string) $value ),
				\selected( $policy, (string) $value, false ),
				\esc_html( (string) $label )
			);
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';

		echo '<h4>' . \esc_html__( 'Signatários', 'signdocs-brasil' ) . '</h4>';
		echo '<table class="widefat striped" id="signdocs-envelope-signers"><thead><tr>';
		echo '<th>' . \esc_html__( 'Nome', 'signdocs-brasil' ) . '</th>';
		echo '<th>' . \esc_html__( 'E-mail', 'signdocs-brasil' ) . '</th>';
		echo '<th>' . \esc_html__( 'CPF', 'signdocs-brasil' ) . '</th>';
		echo '<th>' . \esc_html__( 'CNPJ', 'signdocs-brasil' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		foreach ( array_values( $signers ) as $i => $signer ) {
			$this->renderSignerRow( $i, is_array( $signer ) ? $signer : array() );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="signdocs-envelope-add-signer">'
			. \esc_html__( 'Adicionar signatário', 'signdocs-brasil' ) . '</button></p>';

		echo '<p class="description">'
			. \esc_html__( 'Ordem sequencial usa a ordem desta lista. Um envelope precisa de pelo menos dois signatários.', 'signdocs-brasil' )
			. '</p>';

		echo '<p><button type="submit" name="signdocs_envelope_action" value="send" class="button button-primary">'
			. \esc_html__( 'Enviar para assinatura', 'signdocs-brasil' ) . '</button></p>';
		echo '<p class="description">'
			. \esc_html__( 'O envio consome cota e dispara os convites por e-mail. Depois de enviado, a lista de signatários não pode ser alterada.', 'signdocs-brasil' )
			. '</p>';
	}

	/**
	 * The combined PDF, and cancellation.
	 *
	 * Both are shown from the same place because which one applies is decided
	 * by the same status: an envelope that everyone has signed offers the
	 * document, and one that is still running offers to stop it.
	 */
	private function renderCompletedActions( \WP_Post $post, string $status ): void {
		\wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( $status === 'COMPLETED' ) {
			$url     = (string) \get_post_meta( $post->ID, EnvelopeSender::META_COMBINED_URL, true );
			$expires = (int) \get_post_meta( $post->ID, EnvelopeSender::META_COMBINED_EXPIRES, true );

			// A presigned URL that has run out is worse than none: it looks
			// like a working download and answers 403. Offer to mint a fresh
			// one instead of rendering the dead link.
			$live = $url !== '' && $expires > time();

			echo '<h4>' . \esc_html__( 'Documento assinado', 'signdocs-brasil' ) . '</h4>';

			if ( $live ) {
				printf(
					'<p><a class="button button-primary" href="%s" target="_blank" rel="noopener">%s</a></p>',
					\esc_url( $url ),
					\esc_html__( 'Baixar PDF combinado', 'signdocs-brasil' )
				);
				printf(
					'<p class="description">%s</p>',
					\esc_html(
						sprintf(
							/* translators: %s: human-readable time difference, e.g. "45 minutos". */
							__( 'O link expira em %s. Depois disso, gere um novo.', 'signdocs-brasil' ),
							\human_time_diff( time(), $expires )
						)
					)
				);
			} else {
				echo '<p class="description">'
					. \esc_html__( 'O link de download expira depois de algumas horas. Gere um novo quando precisar.', 'signdocs-brasil' )
					. '</p>';
			}

			printf(
				'<p><button type="submit" name="signdocs_envelope_action" value="combined_stamp" class="button">%s</button></p>',
				\esc_html(
					$live
						? __( 'Gerar novo link', 'signdocs-brasil' )
						: __( 'Gerar PDF combinado', 'signdocs-brasil' )
				)
			);
			return;
		}

		if ( in_array( $status, EnvelopeSender::TERMINAL_STATUSES, true ) ) {
			return;
		}

		echo '<h4>' . \esc_html__( 'Cancelar', 'signdocs-brasil' ) . '</h4>';
		echo '<p class="description">'
			. \esc_html__( 'Cancela o envelope inteiro e derruba os links pendentes. Assinaturas já coletadas são preservadas.', 'signdocs-brasil' )
			. '</p>';
		printf(
			'<p><label for="signdocs-envelope-cancel-reason">%s</label><br /><input type="text" class="regular-text" id="signdocs-envelope-cancel-reason" name="signdocs_envelope_cancel_reason" value="" /></p>',
			\esc_html__( 'Motivo (registrado na trilha de auditoria)', 'signdocs-brasil' )
		);
		printf(
			'<p><button type="submit" name="signdocs_envelope_action" value="cancel" class="button button-link-delete">%s</button></p>',
			\esc_html__( 'Cancelar envelope', 'signdocs-brasil' )
		);
	}

	/**
	 * @param array<string, mixed> $signer
	 */
	private function renderSignerRow( int $index, array $signer ): void {
		printf( '<tr class="signdocs-signer-row">' );
		foreach ( array( 'name', 'email', 'cpf', 'cnpj' ) as $field ) {
			printf(
				'<td><input type="text" class="regular-text" name="signdocs_envelope_signers[%1$d][%2$s]" value="%3$s" /></td>',
				$index,
				\esc_attr( $field ),
				\esc_attr( (string) ( $signer[ $field ] ?? '' ) )
			);
		}
		printf(
			'<td><button type="button" class="button-link signdocs-remove-signer">%s</button></td></tr>',
			\esc_html__( 'Remover', 'signdocs-brasil' )
		);
	}

	private function renderStatus( \WP_Post $post, string $envelopeId ): void {
		$status = (string) \get_post_meta( $post->ID, EnvelopeSender::META_STATUS, true );
		$mode   = (string) \get_post_meta( $post->ID, EnvelopeSender::META_MODE, true );
		$total  = (int) \get_post_meta( $post->ID, EnvelopeSender::META_TOTAL, true );

		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th scope="row">%s</th><td><code>%s</code></td></tr>',
			\esc_html__( 'Envelope ID', 'signdocs-brasil' ),
			\esc_html( $envelopeId )
		);
		printf(
			'<tr><th scope="row">%s</th><td><code>%s</code></td></tr>',
			\esc_html__( 'Status', 'signdocs-brasil' ),
			\esc_html( $status !== '' ? $status : 'ACTIVE' )
		);
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			\esc_html__( 'Ordem', 'signdocs-brasil' ),
			\esc_html(
				EnvelopeDraft::normalizeMode( $mode ) === EnvelopeDraft::MODE_SEQUENTIAL
					? __( 'Sequencial', 'signdocs-brasil' )
					: __( 'Paralela', 'signdocs-brasil' )
			)
		);
		echo '</tbody></table>';

		$children = \get_children(
			array(
				'post_parent' => $post->ID,
				'post_type'   => \Signdocs_CPT::POST_TYPE,
				'numberposts' => EnvelopeDraft::MAX_SIGNERS,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);

		echo '<h4>' . \esc_html__( 'Signatários', 'signdocs-brasil' ) . '</h4>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>#</th>';
		echo '<th>' . \esc_html__( 'Signatário', 'signdocs-brasil' ) . '</th>';
		echo '<th>' . \esc_html__( 'Status', 'signdocs-brasil' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $children as $child ) {
			$childId = (int) $child->ID;
			printf(
				'<tr><td>%s</td><td>%s<br /><span class="description">%s</span></td><td><code>%s</code></td><td>%s</td></tr>',
				\esc_html( (string) \get_post_meta( $childId, '_signdocs_signer_index', true ) ),
				\esc_html( (string) \get_post_meta( $childId, '_signdocs_signer_name', true ) ),
				\esc_html( (string) \get_post_meta( $childId, '_signdocs_signer_email', true ) ),
				\esc_html( (string) \get_post_meta( $childId, '_signdocs_status', true ) ),
				sprintf(
					'<a href="%s">%s</a>',
					\esc_url( \get_edit_post_link( $childId ) ?? '' ),
					\esc_html__( 'Ver', 'signdocs-brasil' )
				)
			);
		}
		echo '</tbody></table>';

		$this->renderCompletedActions( $post, $status );

		if ( count( $children ) < $total ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: 1: sessions created, 2: signers expected. */
				\esc_html__( 'Apenas %1$d de %2$d signatários foram criados. Reenviar cria somente os que faltam — os já criados não são cobrados de novo.', 'signdocs-brasil' ),
				count( $children ),
				$total
			);
			echo '</p></div>';
			\wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			echo '<p><button type="submit" name="signdocs_envelope_action" value="send" class="button">'
				. \esc_html__( 'Reenviar signatários faltantes', 'signdocs-brasil' ) . '</button></p>';
		}
	}
}
