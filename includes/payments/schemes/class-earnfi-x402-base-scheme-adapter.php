<?php
/**
 * Base mainnet x402 exact (EIP-3009 / EVM) — PayAI facilitator; gated by admin flags.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Base_Scheme_Adapter {

    public const NETWORK = 'eip155:8453';

    public static function is_live_enabled(): bool {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            return false;
        }
        return !empty($settings['base_x402_enabled'])
            && !empty($settings['base_x402_gate_b_ok'])
            && self::is_configured_for_settlement($settings);
    }

    /**
     * Treasury + PayAI facilitator (no user-facing switches).
     *
     * @param array<string, mixed>|null $settings
     */
    public static function is_configured_for_settlement($settings = null): bool {
        if ($settings === null) {
            $settings = get_option('growl_settings', []);
        }
        if (!is_array($settings)) {
            return false;
        }
        return self::is_valid_evm_pay_to((string) ($settings['base_x402_pay_to'] ?? ''));
    }

    public static function should_include_in_multi_accept(): bool {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            return false;
        }
        return self::is_live_enabled() && !empty($settings['base_rail_enabled']);
    }

    public static function is_valid_evm_pay_to(string $pay_to): bool {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', trim($pay_to));
    }

    public static function rollout_max_usd(): float {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings) || !isset($settings['base_x402_max_usd'])) {
            return 0.0;
        }
        $max = (float) $settings['base_x402_max_usd'];
        return $max > 0 ? $max : 0.0;
    }

    /**
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>
     */
    public static function merge_when_enabled(array $accepts, string $resource_url, ?string $amount = null): array {
        if (!self::should_include_in_multi_accept()) {
            return $accepts;
        }
        return self::append_base_accept($accepts, $resource_url, $amount);
    }

    /**
     * Gate B / ai-agent/base/v1 — quotes without public Live + sign-off switches.
     *
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>
     */
    public static function merge_for_gate_b_test(array $accepts, string $resource_url, ?string $amount = null): array {
        if (!self::is_configured_for_settlement()) {
            return $accepts;
        }
        return self::append_base_accept($accepts, $resource_url, $amount);
    }

    /**
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>
     */
    private static function append_base_accept(array $accepts, string $resource_url, ?string $amount = null): array {
        $settings = get_option('growl_settings', []);
        $pay_to = trim((string) ($settings['base_x402_pay_to'] ?? ''));
        $network = trim((string) ($settings['base_x402_network'] ?? self::NETWORK));
        if ($network === '' || !self::is_valid_evm_pay_to($pay_to)) {
            return $accepts;
        }
        $asset = trim((string) ($settings['base_usdc_contract'] ?? '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'));
        if (!self::is_valid_evm_pay_to($asset)) {
            return $accepts;
        }
        if (!class_exists('Earnfi_X402_Facilitator_Client', false)) {
            return $accepts;
        }
        $supported = Earnfi_X402_Facilitator_Client::get_supported('payai');
        if (is_wp_error($supported) || empty($supported['kinds']) || !is_array($supported['kinds'])) {
            return $accepts;
        }
        foreach ($supported['kinds'] as $kind) {
            if (!is_array($kind) || ($kind['network'] ?? '') !== $network) {
                continue;
            }
            if (($kind['scheme'] ?? '') !== 'exact') {
                continue;
            }
            $atomic = class_exists('Earnfi_X402_Rail_Limits', false)
                ? Earnfi_X402_Rail_Limits::resolve_merge_amount_atomic($amount, $accepts)
                : (is_string($amount) && preg_match('/^[1-9]\d*$/', trim($amount)) ? trim($amount) : null);
            if ($atomic === null) {
                break;
            }
            if (class_exists('Earnfi_X402_Rail_Limits', false)) {
                $cap = Earnfi_X402_Rail_Limits::assert_atomic_within_cap($network, $atomic);
                if (is_wp_error($cap)) {
                    break;
                }
            }
            $accepts[] = [
                'scheme'              => 'exact',
                'network'             => $network,
                'asset'               => $asset,
                'payTo'               => $pay_to,
                'amount'              => $atomic,
                'maxAmountRequired'   => $atomic,
                'maxTimeoutSeconds'   => 60,
                'extra'               => [
                    'facilitator'          => 'payai',
                    'resourceUrl'          => $resource_url,
                    'tokenDecimals'        => 6,
                    'tokenSymbol'          => 'USDC',
                    'name'                 => 'USD Coin',
                    'version'              => '2',
                    'assetTransferMethod'  => 'eip3009',
                ],
            ];
            break;
        }
        return $accepts;
    }
}
