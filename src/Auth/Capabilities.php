<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Auth;

/**
 * Custom capability model for fine-grained authorization beyond
 * WordPress's default `manage_options` / `edit_posts`.
 *
 *   signdocs_manage     → configure credentials, webhook, branding
 *   signdocs_send       → create signing sessions from WP admin
 *   signdocs_verify     → use the verify UI / CLI to inspect evidence
 *   signdocs_view_logs  → read the audit log table
 *
 * Default grants:
 *   administrator → all 4
 *   editor        → signdocs_send + signdocs_verify
 *   author        → signdocs_send
 *   contributor   → (none)
 *
 * Grants are applied in `install()` and removed in `uninstall()`; the
 * plugin's `activation` hook calls install(), but deactivate does NOT
 * remove caps (deactivation isn't uninstall — leaving caps means the
 * operator can reactivate without reconfiguring roles).
 */
final class Capabilities {

	public const MANAGE    = 'signdocs_manage';
	public const SEND      = 'signdocs_send';
	public const VERIFY    = 'signdocs_verify';
	public const VIEW_LOGS = 'signdocs_view_logs';

	/** @return list<string> */
	public static function all(): array {
		return array( self::MANAGE, self::SEND, self::VERIFY, self::VIEW_LOGS );
	}

	public static function install(): void {
		$roleGrants = array(
			'administrator' => self::all(),
			'editor'        => array( self::SEND, self::VERIFY ),
			'author'        => array( self::SEND ),
		);

		foreach ( $roleGrants as $roleName => $caps ) {
			$role = \get_role( $roleName );
			if ( $role === null ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function uninstall(): void {
		global $wp_roles;
		if ( ! isset( $wp_roles ) || ! is_object( $wp_roles ) ) {
			return;
		}

		foreach ( $wp_roles->role_names as $roleName => $_label ) {
			$role = \get_role( $roleName );
			if ( $role === null ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Map CPT-specific meta caps (edit_signdocs_signing, etc.) to the
	 * custom caps defined here. Register via:
	 *   add_filter('map_meta_cap', [Capabilities::class, 'mapMetaCap'], 10, 4);
	 *
	 * @param list<string> $caps
	 * @param string       $cap
	 * @param int          $userId
	 * @param array<int,mixed> $args
	 * @return list<string>
	 */
	public static function mapMetaCap( array $caps, string $cap, int $userId, array $args ): array {
		switch ( $cap ) {
			case 'edit_signdocs_signing':
			case 'edit_signdocs_signings':
			case 'edit_others_signdocs_signings':
			case 'publish_signdocs_signings':
			case 'edit_signdocs_envelope':
			case 'edit_signdocs_envelopes':
			case 'edit_others_signdocs_envelopes':
			case 'publish_signdocs_envelopes':
				return array( self::SEND );
			case 'read_signdocs_signing':
			case 'read_private_signdocs_signings':
			case 'read_signdocs_envelope':
			case 'read_private_signdocs_envelopes':
				return array( self::VERIFY );
			case 'delete_signdocs_signing':
			case 'delete_signdocs_signings':
			case 'delete_others_signdocs_signings':
			case 'delete_signdocs_envelope':
			case 'delete_signdocs_envelopes':
			case 'delete_others_signdocs_envelopes':
			case 'delete_published_signdocs_envelopes':
				return array( self::MANAGE );
			default:
				return $caps;
		}
	}
}
