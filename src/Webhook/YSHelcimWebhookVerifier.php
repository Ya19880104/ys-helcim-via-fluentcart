<?php
/**
 * YS Helcim Webhook signature verifier.
 *
 * Helcim webhooks use Svix-style signatures:
 *   signed content = "{webhook-id}.{webhook-timestamp}.{raw body}"
 *   key            = base64_decode(verifier token)
 *   signature      = base64_encode(HMAC-SHA256(signed content, key))
 *
 * The `webhook-signature` header may contain several signatures (space
 * separated), each of which may carry a version prefix (such as "v1,{base64}").
 * Each candidate is compared with hash_equals, and a match on any of them
 * passes. The whole process is fail-closed: any missing field, malformed
 * value, or expired timestamp is treated as a verification failure.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Webhook;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class YSHelcimWebhookVerifier
 *
 * A stateless, static verification utility shared by the handleIPN() methods of
 * both the ys_helcim and ys_helcim_js gateways.
 */
class YSHelcimWebhookVerifier
{
    /**
     * Timestamp tolerance in seconds: +/- 5 minutes, to guard against replay attacks.
     */
    private const TIMESTAMP_TOLERANCE = 300;

    /**
     * Verify a webhook signature.
     *
     * @param array  $headers               Request headers (key lookup is case-insensitive).
     * @param string $raw_body              The raw request body (untouched by any processing).
     * @param string $verifier_token_base64 The verifier token from the Helcim dashboard (a base64 string).
     * @return bool True if the signature verifies; false on any anomaly (fail-closed).
     */
    public static function verify(array $headers, string $raw_body, string $verifier_token_base64): bool
    {
        // Reject when no verifier token is set (fail-closed; callers should have returned 501 before this).
        $verifier_token_base64 = trim($verifier_token_base64);
        if ($verifier_token_base64 === '') {
            return false;
        }

        // Header lookups are case-insensitive.
        $normalized = self::normalizeHeaders($headers);

        $webhook_id        = trim((string) ($normalized['webhook-id'] ?? ''));
        $webhook_timestamp = trim((string) ($normalized['webhook-timestamp'] ?? ''));
        $webhook_signature = trim((string) ($normalized['webhook-signature'] ?? ''));

        // All three required headers must be present.
        if ($webhook_id === '' || $webhook_timestamp === '' || $webhook_signature === '') {
            return false;
        }

        // The timestamp must be digits only (unix seconds) and within tolerance (replay guard).
        if (!preg_match('/^\d+$/', $webhook_timestamp)) {
            return false;
        }

        if (abs(time() - (int) $webhook_timestamp) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        // The verifier token must be valid base64 (strict mode); decoded, it is the HMAC key.
        $key = base64_decode($verifier_token_base64, true);
        if ($key === false || $key === '') {
            return false;
        }

        // Build the signed content and compute the expected signature.
        $signed_content = $webhook_id . '.' . $webhook_timestamp . '.' . $raw_body;
        $expected       = base64_encode(hash_hmac('sha256', $signed_content, $key, true));

        // The signature header may hold several values (space separated), each either "v1,{base64}" or plain base64.
        $candidates = preg_split('/\s+/', $webhook_signature, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($candidates)) {
            return false;
        }

        foreach ($candidates as $candidate) {
            // Strip the version prefix: for "v1,{base64}" take the part after the comma; with no comma, treat the whole thing as the signature.
            $comma_pos = strpos($candidate, ',');
            if ($comma_pos !== false) {
                $candidate = substr($candidate, $comma_pos + 1);
            }

            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            // Constant-time comparison; a match on any candidate passes.
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize header keys to lowercase.
     *
     * For duplicate keys the later occurrence wins; if a value is an array (as on
     * some server environments) the first element is used.
     *
     * @param array $headers The original headers.
     * @return array Headers with all-lowercase keys.
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            if (!is_scalar($value)) {
                continue;
            }

            // WP_REST_Request canonicalizes header names by replacing hyphens
            // with underscores. Normalize both representations back to the
            // wire-name form before reading the required Helcim headers.
            $key = str_replace('_', '-', strtolower($name));
            $value = (string) $value;
            if (isset($normalized[$key]) && !hash_equals($normalized[$key], $value)) {
                // Conflicting aliases are ambiguous and must fail closed.
                $normalized[$key] = '';
                continue;
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
