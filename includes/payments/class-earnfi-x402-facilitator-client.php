<?php
/**
 * Thin HTTP client for facilitator /supported, /verify, /settle (adapter-specific bodies).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Facilitator_Client {

    /**
     * @return array<string, mixed>|WP_Error
     */
    public static function get_supported(string $facilitator_id) {
        $facilitator_id = sanitize_key($facilitator_id);
        $known = Earnfi_X402_Facilitator_Registry::known_facilitators();
        if (!isset($known[$facilitator_id])) {
            return new WP_Error('unknown_facilitator', 'Unknown facilitator.', ['status' => 400]);
        }
        $base = untrailingslashit((string) $known[$facilitator_id]['base_url']);
        $cache_key = 'earnfi_x402_supported_' . md5($facilitator_id);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $res = wp_remote_get($base . '/supported', ['timeout' => 12]);
        if (is_wp_error($res)) {
            return $res;
        }
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new WP_Error('supported_failed', 'Facilitator /supported unavailable.', ['status' => 502]);
        }
        set_transient($cache_key, $body, 15 * MINUTE_IN_SECONDS);
        return $body;
    }
}
