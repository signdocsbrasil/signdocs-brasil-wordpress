<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignDocsBrasil\WordPress\Admin\AuditQuery;
use SignDocsBrasil\WordPress\Admin\Filters;

/**
 * SQL-injection fuzz harness for the audit query layer.
 *
 * For every payload in {@see payloads()}, we construct a Filters by
 * putting the payload in every slot (level / event_type / from / to /
 * orderby / order), build the WHERE clause, and assert two invariants:
 *
 *   1. Either the filter dropped the value (producing null), OR the
 *      value ends up as an element of $params — NEVER in the SQL
 *      literal. That's what keeps $wpdb->prepare() honest.
 *
 *   2. The generated SQL string contains no payload-derived substring
 *      longer than what the whitelist would legitimately allow.
 *      Concretely: the SQL contains only fixed keywords, our column
 *      names, and %s/%d placeholders. We assert that explicitly.
 *
 * Also verifies orderBy/order validation: malicious values are replaced
 * by safe defaults (created_at / DESC) rather than passing through.
 */
final class AuditQueryFuzzTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Each case: [shortLabel, payload].
	 *
	 * @return list<array{0:string,1:string}>
	 */
	public static function payloads(): array {
		return array(
			array( 'classic-or', "' OR 1=1 --" ),
			array( 'classic-or-hash', "' OR '1'='1" ),
			array( 'drop-table', "'; DROP TABLE wp_signdocs_log; --" ),
			array( 'union-select', "' UNION SELECT user_login, user_pass FROM wp_users --" ),
			array( 'comment-out', "/* */ OR 1=1 /*" ),
			array( 'stacked', "1; DELETE FROM wp_users" ),
			array( 'backtick-break', '`; SHOW DATABASES; --' ),
			array( 'quote-escape', "\\'; --" ),
			array( 'time-blind', "'; WAITFOR DELAY '0:0:10' --" ),
			array( 'bool-blind', "' AND SLEEP(5) --" ),
			array( 'hex-encoded', '0x27204f522031203d2031' ),
			array( 'nullbyte', "info\x00' OR 1=1 --" ),
			array( 'newline-break', "info\n' OR 1=1 --" ),
			array( 'crlf-break', "info\r\n' OR 1=1 --" ),
			array( 'tab-break', "info\t' OR 1=1 --" ),
			array( 'double-encoded', "%2527%20OR%25201%253D1" ),
			array( 'unicode-homoglyph', "\u{FF07} OR 1=1 --" ),
			array( 'long-overflow', str_repeat( 'A', 2000 ) . "' OR 1=1" ),
			array( 'tag-injection', '<script>alert(1)</script>' ),
			array( 'order-by-injection', 'id; DROP TABLE x' ),
			array( 'order-injection', 'ASC; SELECT sleep(1)' ),
			array( 'polyglot', "'\"><script>/**/</script><!-- UNION SELECT" ),
			array( 'sql-keyword', 'SELECT' ),
			array( 'mixed-case', "' oR 1=1 --" ),
		);
	}

	#[DataProvider( 'payloads' )]
	public function test_payload_does_not_leak_into_sql_or_bypass_validation( string $label, string $payload ): void {
		$filters = Filters::fromRequest(
			array(
				'level'      => $payload,
				'event_type' => $payload,
				'from'       => $payload,
				'to'         => $payload,
				'orderby'    => $payload,
				'order'      => $payload,
			)
		);

		// ── Invariant 1: each filter field is either null OR a string
		//    that passed the whitelist/regex at construction time. ──
		$this->assertTrue(
			$filters->level === null || in_array( $filters->level, Filters::ALLOWED_LEVELS, true ),
			"[{$label}] level survived validation but isn't in the allow-list",
		);
		$this->assertTrue(
			$filters->eventType === null || (bool) preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $filters->eventType ),
			"[{$label}] eventType survived validation but doesn't match the regex",
		);
		$this->assertTrue(
			$filters->from === null || (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters->from ),
			"[{$label}] from date survived but isn't YYYY-MM-DD",
		);
		$this->assertTrue(
			$filters->to === null || (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters->to ),
			"[{$label}] to date survived but isn't YYYY-MM-DD",
		);

		// ── Invariant 2: orderBy always falls back to 'created_at' when
		//    the candidate isn't in the allow-list we pass. ──
		$orderBy = $filters->validatedOrderBy( array( 'created_at', 'level', 'event_type' ) );
		$this->assertContains(
			$orderBy,
			array( 'created_at', 'level', 'event_type' ),
			"[{$label}] orderBy escaped the allow-list",
		);

		// ── Invariant 3: order is always ASC or DESC. ──
		$order = $filters->validatedOrder();
		$this->assertContains( $order, array( 'ASC', 'DESC' ), "[{$label}] order escaped" );

		// ── Invariant 4: WHERE SQL contains only fixed tokens + %s
		//    placeholders. No payload substring leaks into the SQL. ──
		[ $whereSql, $params ] = AuditQuery::buildWhere( $filters );

		// Allowed tokens: keywords, whitespace, our column names, %s.
		$allowedPattern = '/^(1=1|level = %s|event_type = %s|created_at (>=|<=) %s)( AND (level = %s|event_type = %s|created_at (>=|<=) %s))*$/';
		$this->assertMatchesRegularExpression(
			$allowedPattern,
			$whereSql,
			"[{$label}] WHERE SQL contains a fragment outside the known shape: {$whereSql}",
		);

		// Regardless of payload content, if it DID survive validation,
		// it must be in $params (positional, for %s), not in $whereSql.
		foreach ( array( $filters->level, $filters->eventType, $filters->from, $filters->to ) as $survivor ) {
			if ( $survivor === null ) {
				continue;
			}
			// The survivor value itself may legitimately appear in
			// $params — but the raw payload (which failed validation)
			// must never appear in the SQL string, even as a substring.
			$this->assertStringNotContainsString( $survivor, $whereSql, "[{$label}] validated value leaked into SQL literal" );
		}

		// The raw malicious payload must never appear in $whereSql.
		// We check a few signature substrings common to SQLi payloads.
		foreach ( array( "'", '--', 'DROP', 'UNION', 'SELECT', 'SLEEP', 'DELETE', 'INSERT', 'UPDATE', '/*', '*/', ';', "\x00" ) as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$whereSql,
				"[{$label}] suspicious token {$needle} appears in SQL literal: {$whereSql}",
			);
		}

		// ── Invariant 5: every %s placeholder has exactly one param. ──
		$placeholderCount = substr_count( $whereSql, '%s' );
		$this->assertSame( $placeholderCount, count( $params ), "[{$label}] placeholder/param count mismatch" );
	}

	public function test_valid_inputs_still_flow_through(): void {
		// Positive control: ensure the validator doesn't over-reject.
		$filters = Filters::fromRequest(
			array(
				'level'      => 'warning',
				'event_type' => 'TRANSACTION.COMPLETED',
				'from'       => '2026-04-01',
				'to'         => '2026-04-30',
				'orderby'    => 'level',
				'order'      => 'asc',
			)
		);

		$this->assertSame( 'warning', $filters->level );
		$this->assertSame( 'TRANSACTION.COMPLETED', $filters->eventType );
		$this->assertSame( '2026-04-01', $filters->from );
		$this->assertSame( '2026-04-30', $filters->to );
		$this->assertSame( 'level', $filters->validatedOrderBy( array( 'created_at', 'level', 'event_type' ) ) );
		$this->assertSame( 'ASC', $filters->validatedOrder() );

		[ $sql, $params ] = AuditQuery::buildWhere( $filters );
		$this->assertSame( '1=1 AND level = %s AND event_type = %s AND created_at >= %s AND created_at <= %s', $sql );
		$this->assertSame(
			array( 'warning', 'TRANSACTION.COMPLETED', '2026-04-01 00:00:00', '2026-04-30 23:59:59' ),
			$params,
		);
	}

	public function test_empty_request_yields_no_filters(): void {
		$filters = Filters::fromRequest( array() );
		$this->assertNull( $filters->level );
		$this->assertNull( $filters->eventType );
		$this->assertNull( $filters->from );
		$this->assertNull( $filters->to );

		[ $sql, $params ] = AuditQuery::buildWhere( $filters );
		$this->assertSame( '1=1', $sql );
		$this->assertSame( array(), $params );
	}
}
