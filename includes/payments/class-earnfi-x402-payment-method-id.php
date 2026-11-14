<?php
/**
 * Internal payment method identity (plan §4). Not a client-trusted x402 wire field.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Payment_Method_Id {

    /**
     * @param array<string, mixed> $accept One accepts[] entry (may include internal facilitator_id in extra).
     */
    public static function from_accept(array $accept, string $facilitator_id = 'payai'): string {
        $facilitator_id = sanitize_key($facilitator_id);
        if ($facilitator_id === '' && !empty($accept['extra']['facilitator'])) {
            $facilitator_id = sanitize_key((string) $accept['extra']['facilitator']);
        }
        if ($facilitator_id === '') {
            $facilitator_id = self::infer_facilitator_from_network((string) ($accept['network'] ?? ''));
        }
        $parts = [
            '2',
            sanitize_key((string) ($accept['scheme'] ?? 'exact')),
            sanitize_text_field((string) ($accept['network'] ?? '')),
            sanitize_text_field((string) ($accept['asset'] ?? '')),
            sanitize_text_field((string) ($accept['payTo'] ?? '')),
            $facilitator_id,
        ];
        return hash('sha256', implode('|', $parts));
    }

    public static function infer_facilitator_from_network(string $network_caip2): string {
        $network_caip2 = trim($network_caip2);
        if (strpos($network_caip2, 'solana:') === 0) {
            return 'payai';
        }
        if ($network_caip2 === 'eip155:8453' || $network_caip2 === 'eip155:84532') {
            return 'payai';
        }
        if ($network_caip2 === 'eip155:196' || $network_caip2 === 'eip155:1952') {
            return 'okx_xlayer';
        }
        if (strpos($network_caip2, 'near:') === 0) {
            if (class_exists('Earnfi_Near_X402_Facilitator', false)) {
                return Earnfi_Near_X402_Facilitator::active_id();
            }
            return 'fastnear';
        }
        return 'unknown';
    }
}
