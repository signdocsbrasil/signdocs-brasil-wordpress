<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Cpt;

defined( 'ABSPATH' ) || exit;

use SignDocsBrasil\WordPress\Auth\Capabilities;

/**
 * Parent CPT for multi-signer envelopes. Each envelope has N
 * `signdocs_signing` children (one per signer), linked via
 * `post_parent`. The envelope row aggregates:
 *   - signing_mode  (SEQUENTIAL | PARALLEL)
 *   - total_signers
 *   - envelope_id (from the API)
 *   - completed_signers (updated as STEP.* events arrive)
 *   - consolidated_download_url (set when last signer finishes)
 */
final class EnvelopeCpt {

	public const POST_TYPE = 'signdocs_envelope';

	public function register(): void {
		\add_action( 'init', array( $this, 'registerPostType' ) );
	}

	public function registerPostType(): void {
		\register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'SignDocs Envelopes', 'signdocs-brasil' ),
				'labels'          => array(
					'name'          => __( 'Envelopes', 'signdocs-brasil' ),
					'singular_name' => __( 'Envelope', 'signdocs-brasil' ),
					'menu_name'     => __( 'Envelopes', 'signdocs-brasil' ),
					'add_new_item'  => __( 'Criar envelope', 'signdocs-brasil' ),
					'edit_item'     => __( 'Editar envelope', 'signdocs-brasil' ),
					'view_item'     => __( 'Ver envelope', 'signdocs-brasil' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=signdocs_signing',
				'supports'        => array( 'title', 'custom-fields' ),
				// Use custom capability type. The plugin's `Capabilities::mapMetaCap`
				// filter translates the generated `edit_signdocs_envelope`-style
				// caps to our signdocs_* primitive caps. We do NOT remap the CPT's
				// primitive caps directly here, because mapping `read_post` →
				// `signdocs_verify` registers signdocs_verify as a *meta* cap and
				// causes core's map_meta_cap to short-circuit it to `do_not_allow`
				// when called without a post argument.
				'capability_type' => array( 'signdocs_envelope', 'signdocs_envelopes' ),
				'map_meta_cap'    => true,
				'show_in_rest'    => false,
			)
		);
	}
}
