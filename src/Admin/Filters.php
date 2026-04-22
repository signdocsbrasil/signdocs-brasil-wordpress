<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

/**
 * Immutable, validated representation of the audit-log list filters.
 *
 * Every public field is either `null` (filter not applied) or a value
 * that has already passed an allow-list or regex check at construction
 * time. By the time a consumer has a `Filters` instance in hand, there
 * is no path for raw user input to still be lurking. This makes the
 * downstream SQL layer ({@see AuditQuery}) trivial to audit: it only
 * has to trust that the constructor ran.
 *
 *   level       ∈ {debug,info,warning,error} ∪ null
 *   eventType   matches /^[A-Za-z0-9._-]{1,64}$/ ∪ null
 *   from, to    match /^\d{4}-\d{2}-\d{2}$/ ∪ null
 *   orderBy     matches a caller-provided allow-list OR falls back to
 *               'created_at' at query time
 *   order       ∈ {asc,desc} (normalized to ASC/DESC at query time)
 *
 * The constructor never throws — bad input silently produces a null
 * filter, which the query layer translates to "filter not applied".
 * This mirrors how WP_Query and WP_List_Table behave and keeps the
 * admin UX forgiving.
 */
final class Filters {

	public const ALLOWED_LEVELS = array( 'debug', 'info', 'warning', 'error' );

	private const EVENT_TYPE_REGEX = '/^[A-Za-z0-9._-]{1,64}$/';
	private const DATE_REGEX       = '/^\d{4}-\d{2}-\d{2}$/';

	/**
	 * @param string|null $level     debug|info|warning|error | null
	 * @param string|null $eventType Canonical event identifier | null
	 * @param string|null $from      YYYY-MM-DD | null
	 * @param string|null $to        YYYY-MM-DD | null
	 * @param string|null $orderBy   Raw caller-supplied column name; re-validated at query time
	 * @param string|null $order     'asc'|'desc' (case-insensitive) | null
	 */
	public function __construct(
		public readonly ?string $level = null,
		public readonly ?string $eventType = null,
		public readonly ?string $from = null,
		public readonly ?string $to = null,
		private readonly ?string $orderBy = null,
		private readonly ?string $order = null,
	) {
	}

	/**
	 * Construct a {@see Filters} from a `$_REQUEST`-shaped array.
	 * Bad/missing/malicious values silently become null — the intent is
	 * "read what's valid, ignore what isn't" rather than hard-fail.
	 *
	 * @param array<string,mixed>|null $request Defaults to $_REQUEST.
	 */
	public static function fromRequest( ?array $request = null ): self {
		if ( $request === null ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this is the validation layer; caller is responsible for CSRF on state-changing operations
			$request = $_REQUEST;
		}

		$level = null;
		if ( isset( $request['level'] ) ) {
			$raw = self::sanitizeString( (string) $request['level'] );
			if ( in_array( $raw, self::ALLOWED_LEVELS, true ) ) {
				$level = $raw;
			}
		}

		$eventType = null;
		if ( isset( $request['event_type'] ) ) {
			$raw = self::sanitizeString( (string) $request['event_type'] );
			if ( $raw !== '' && preg_match( self::EVENT_TYPE_REGEX, $raw ) ) {
				$eventType = $raw;
			}
		}

		$from = self::validatedDate( $request['from'] ?? null );
		$to   = self::validatedDate( $request['to'] ?? null );

		$orderBy = null;
		if ( isset( $request['orderby'] ) ) {
			$orderBy = self::sanitizeString( (string) $request['orderby'] );
		}

		$order = null;
		if ( isset( $request['order'] ) ) {
			$candidate = strtolower( self::sanitizeString( (string) $request['order'] ) );
			if ( $candidate === 'asc' || $candidate === 'desc' ) {
				$order = $candidate;
			}
		}

		return new self( $level, $eventType, $from, $to, $orderBy, $order );
	}

	/**
	 * Return the validated ORDER BY column, or the first entry in the
	 * caller's allow-list as the fallback. Guarantees the returned
	 * string is a member of $allowed.
	 *
	 * @param list<string> $allowed
	 */
	public function validatedOrderBy( array $allowed ): string {
		if ( $this->orderBy !== null && in_array( $this->orderBy, $allowed, true ) ) {
			return $this->orderBy;
		}
		return $allowed[0] ?? 'created_at';
	}

	/**
	 * Return ASC or DESC (uppercase) — guaranteed one of the two.
	 */
	public function validatedOrder(): string {
		return $this->order === 'asc' ? 'ASC' : 'DESC';
	}

	private static function validatedDate( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$raw = self::sanitizeString( $value );
		if ( $raw === '' || ! preg_match( self::DATE_REGEX, $raw ) ) {
			return null;
		}
		return $raw;
	}

	private static function sanitizeString( string $value ): string {
		// Undo magic-quotes escaping (harmless when not active) then
		// run WP's conservative sanitizer. Output is ASCII-ish, length-
		// bounded (WP caps at 255), and stripped of tags / encoded
		// newlines / tabs / NUL.
		if ( function_exists( '\\wp_unslash' ) && function_exists( '\\sanitize_text_field' ) ) {
			return (string) \sanitize_text_field( \wp_unslash( $value ) );
		}
		// Fallback used only in non-WP unit tests.
		$stripped = strip_tags( $value );
		$stripped = (string) preg_replace( '/[\r\n\t\0]+/', '', $stripped );
		return trim( $stripped );
	}
}
