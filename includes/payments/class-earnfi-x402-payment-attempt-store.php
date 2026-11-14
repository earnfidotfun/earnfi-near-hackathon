<?php
/**
 * Internal payment attempts (not x402 wire fields). Transient-backed until schema approved.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Payment_Attempt_Store {

    private const PREFIX = 'earnfi_x402_attempt_';
    private const TTL = 3600;

    /**
     * @param array<string, mixed> $data quote_id/intent_id, payment_method_id, accept_index, idempotency_key, state
     * @return string attempt_id UUID
     */
    public static function create(array $data): string {
        $attempt_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : self::fallback_uuid();
        $payload = array_merge($data, [
            'attempt_id' => $attempt_id,
            'created_at' => time(),
            'state'      => sanitize_key((string) ($data['state'] ?? Earnfi_X402_Settlement_State::PAYMENT_SUBMITTED)),
        ]);
        set_transient(self::PREFIX . md5($attempt_id), $payload, self::TTL);
        $quote_id = sanitize_text_field((string) ($data['quote_id'] ?? $data['intent_id'] ?? ''));
        if ($quote_id !== '') {
            set_transient(self::PREFIX . 'quote:' . md5($quote_id), $payload, self::TTL);
        }
        return $attempt_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_by_attempt_id(string $attempt_id): ?array {
        $attempt_id = sanitize_text_field($attempt_id);
        if ($attempt_id === '') {
            return null;
        }
        $row = get_transient(self::PREFIX . md5($attempt_id));
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_latest_for_quote(string $quote_id): ?array {
        $quote_id = sanitize_text_field($quote_id);
        if ($quote_id === '') {
            return null;
        }
        $row = get_transient(self::PREFIX . 'quote:' . md5($quote_id));
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public static function update(string $attempt_id, array $patch): void {
        $existing = self::get_by_attempt_id($attempt_id);
        if ($existing === null) {
            return;
        }
        $merged = array_merge($existing, $patch);
        set_transient(self::PREFIX . md5($attempt_id), $merged, self::TTL);
        $quote_id = sanitize_text_field((string) ($merged['quote_id'] ?? $merged['intent_id'] ?? ''));
        if ($quote_id !== '') {
            set_transient(self::PREFIX . 'quote:' . md5($quote_id), $merged, self::TTL);
        }
    }

    private static function fallback_uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
