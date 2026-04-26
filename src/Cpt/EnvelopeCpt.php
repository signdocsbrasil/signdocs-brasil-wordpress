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
				'capability_type' => 'signdocs_envelope',
				'map_meta_cap'    => true,
				'capabilities'    => array(
					'edit_post'          => Capabilities::SEND,
					'read_post'          => Capabilities::VERIFY,
					'delete_post'        => Capabilities::MANAGE,
					'edit_posts'         => Capabilities::SEND,
					'edit_others_posts'  => Capabilities::MANAGE,
					'publish_posts'      => Capabilities::SEND,
					'read_private_posts' => Capabilities::VERIFY,
				),
				'show_in_rest'    => false,
			)
		);
	}
}
