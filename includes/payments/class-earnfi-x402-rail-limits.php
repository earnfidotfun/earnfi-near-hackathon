<?php
/**
 * Per-rail max USD caps from growl_settings (NEAR / Base multi-accept).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Rail_Limits {

    public const USDC_DECIMALS = 6;

    /**
     * @return array{max_usd: float, decimals: int}|null
     */
    public static function cap_for_network(string $network_caip2): ?array {
        $network_caip2 = trim($network_caip2);
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        if (strpos($network_caip2, 'near:') === 0) {
            if (!isset($settings['near_x402_max_usd'])) {
                return null;
            }
            $max = (float) $settings['near_x402_max_usd'];
            return $max > 0 ? ['max_usd' => $max, 'decimals' => self::USDC_DECIMALS] : null;
        }
        if ($network_caip2 === 'eip155:8453' || $network_caip2 === 'eip155:84532') {
            if (!isset($settings['base_x402_max_usd'])) {
                return null;
            }
            $max = (float) $settings['base_x402_max_usd'];
            return $max > 0 ? ['max_usd' => $max, 'decimals' => self::USDC_DECIMALS] : null;
        }
        return null;
    }

    public static function usd_from_atomic(string $amount_atomic, int $decimals = self::USDC_DECIMALS): float {
        $amount_atomic = preg_replace('/\D/', '', $amount_atomic) ?? '';
        if ($amount_atomic === '' || $amount_atomic === '0') {
            return 0.0;
        }
        if (function_exists('bcdiv')) {
            $div = bcpow('10', (string) $decimals, 0);
            return (float) bcdiv($amount_atomic, $div, 6);
        }
        return ((float) $amount_atomic) / (10 ** $decimals);
    }

    /**
     * @return true|WP_Error
     */
    public static function assert_atomic_within_cap(string $network_caip2, string $amount_atomic) {
        $cap = self::cap_for_network($network_caip2);
        if ($cap === null) {
            return true;
        }
        if (!preg_match('/^[1-9]\d*$/', $amount_atomic)) {
            return new WP_Error('invalid_amount', 'The payment amount is invalid.', ['status' => 400]);
        }
        $usd = self::usd_from_atomic($amount_atomic, (int) $cap['decimals']);
        if ($usd > (float) $cap['max_usd'] + 1e-9) {
            return new WP_Error(
                'amount_exceeds_rail_cap',
                sprintf(
                    'This payment exceeds the configured limit for this network (max $%s USD).',
                    number_format((float) $cap['max_usd'], 2, '.', '')
                ),
                ['status' => 400, 'max_usd' => $cap['max_usd'], 'network' => $network_caip2]
            );
        }
        return true;
    }

    /**
     * Resolve atomic amount for multichain accept merge (never invent $1 default).
     *
     * @param array<int, array<string, mixed>> $accepts
     */
    public static function resolve_merge_amount_atomic(?string $amount, array $accepts): ?string {
        $amount = is_string($amount) ? trim($amount) : '';
        if ($amount !== '' && preg_match('/^[1-9]\d*$/', $amount)) {
            return $amount;
        }
        foreach ($accepts as $accept) {
            if (!is_array($accept)) {
                continue;
            }
            $candidate = (string) ($accept['amount'] ?? $accept['maxAmountRequired'] ?? '');
            if (preg_match('/^[1-9]\d*$/', $candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
