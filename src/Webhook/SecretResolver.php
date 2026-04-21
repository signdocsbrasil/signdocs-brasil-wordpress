<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Webhook;

/**
 * Returns the set of webhook HMAC secrets that are currently valid.
 *
 * During a rotation, BOTH the primary (new) and the previous (old)
 * secrets are returned for a grace window; once the window expires a
 * scheduled event clears the old secret. This lets operators rotate
 * the SignDocs tenant's webhook secret without a zero-window where
 * in-flight deliveries signed with the old secret would be rejected.
 *
 *   signdocs_webhook_secret_enc         → current secret (always used)
 *   signdocs_webhook_secret_prev_enc    → previous secret (during grace)
 *   signdocs_webhook_secret_rotated_at  → unix ts of rotation start
 *
 * Grace window: 7 days. If `rotated_at` is older, the previous secret
 * is ignored even if still in wp_options (defence against expiry-
 * cleanup cron failing).
 */
final class SecretResolver
{
    public const GRACE_WINDOW_SECONDS = 604800; // 7 days
    public const EXPIRE_CRON_HOOK = 'signdocs_expire_prev_secret';

    /** @var callable(string):string */
    private $decrypt;

    /**
     * @param callable(string):string $decrypt
     */
    public function __construct(callable $decrypt)
    {
        $this->decrypt = $decrypt;
    }

    /**
     * @return list<string> Up to two secrets, primary first. Empty if
     *                      no primary is configured.
     */
    public function all(): array
    {
        $primary = ($this->decrypt)((string) \get_option('signdocs_webhook_secret_enc', ''));
        if ($primary === '') {
            return [];
        }

        $secrets = [$primary];

        $prevEnc = (string) \get_option('signdocs_webhook_secret_prev_enc', '');
        $rotatedAt = (int) \get_option('signdocs_webhook_secret_rotated_at', 0);

        if ($prevEnc !== '' && $rotatedAt > 0 && (time() - $rotatedAt) <= self::GRACE_WINDOW_SECONDS) {
            $prev = ($this->decrypt)($prevEnc);
            if ($prev !== '' && $prev !== $primary) {
                $secrets[] = $prev;
            }
        }

        return $secrets;
    }

    /**
     * True while the grace window is active (both secrets still valid).
     */
    public function rotationActive(): bool
    {
        $rotatedAt = (int) \get_option('signdocs_webhook_secret_rotated_at', 0);
        if ($rotatedAt === 0) {
            return false;
        }
        return (time() - $rotatedAt) <= self::GRACE_WINDOW_SECONDS;
    }

    /**
     * Seconds remaining in the grace window, or 0 if inactive.
     */
    public function rotationRemainingSeconds(): int
    {
        $rotatedAt = (int) \get_option('signdocs_webhook_secret_rotated_at', 0);
        if ($rotatedAt === 0) {
            return 0;
        }
        $elapsed = time() - $rotatedAt;
        $remaining = self::GRACE_WINDOW_SECONDS - $elapsed;
        return max(0, $remaining);
    }

    /**
     * Daily cron callback — purges the previous secret once grace has
     * expired. Registered in the plugin bootstrap via:
     *   add_action(SecretResolver::EXPIRE_CRON_HOOK, [SecretResolver::class, 'expireIfDue']);
     */
    public static function expireIfDue(): void
    {
        $rotatedAt = (int) \get_option('signdocs_webhook_secret_rotated_at', 0);
        if ($rotatedAt === 0) {
            return;
        }
        if ((time() - $rotatedAt) > self::GRACE_WINDOW_SECONDS) {
            \delete_option('signdocs_webhook_secret_prev_enc');
            \delete_option('signdocs_webhook_secret_rotated_at');
        }
    }
}
