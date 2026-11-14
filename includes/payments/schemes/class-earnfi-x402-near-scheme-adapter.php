<?php
/**
 * NEAR x402 exact scheme (NEP-366) — Gate B; disabled unless admin flags + A9 treasury.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Near_Scheme_Adapter {

    public static function is_live_enabled(): bool {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            return false;
        }
        return !empty($settings['near_x402_enabled'])
            && !empty($settings['near_x402_gate_b_ok'])
            && self::is_configured_for_settlement($settings);
    }

    /**
     * Treasury + API key for the active NEAR x402 facilitator (admin dropdown).
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
        if (trim((string) ($settings['near_treasury_account'] ?? '')) === '') {
            return false;
        }
        return class_exists('Earnfi_Near_X402_Facilitator', false) && Earnfi_Near_X402_Facilitator::has_active_api_key();
    }

    /**
     * NEAR accept in multi-accept quotes (public rollout).
     */
    public static function should_include_in_multi_accept(): bool {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            return false;
        }
        return self::is_live_enabled() && !empty($settings['near_rail_enabled']);
    }

    public static function rollout_max_usd(): float {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings) || !isset($settings['near_x402_max_usd'])) {
            return 0.0;
        }
        $max = (float) $settings['near_x402_max_usd'];
        return $max > 0 ? $max : 0.0;
    }

    /**
     * Filter accepts[] to NEAR mainnet only (near/v1 alias).
     *
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>
     */
    public static function filter_accepts_near_only(array $accepts): array {
        return array_values(array_filter($accepts, static function ($a) {
            $n = (string) ($a['network'] ?? '');
            return strpos($n, 'near:') === 0;
        }));
    }

    /**
     * Merge FastNEAR-supported accept into descriptor list when enabled.
     *
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>
     */
    public static function merge_when_enabled(array $accepts, string $resource_url, ?string $amount = null): array {
        if (!self::should_include_in_multi_accept()) {
            return $accepts;
        }
        return self::append_near_accept($accepts, $resource_url, $amount);
    }

    /**
     * Gate B / ai-agent/near/v1 — quotes without public Live + sign-off switches.
     *
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>
     */
    public static function merge_for_gate_b_test(array $accepts, string $resource_url, ?string $amount = null): array {
        if (!self::is_configured_for_settlement()) {
            return $accepts;
        }
        return self::append_near_accept($accepts, $resource_url, $amount);
    }

    /**
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>
     */
    private static function append_near_accept(array $accepts, string $resource_url, ?string $amount = null): array {
        if (!class_exists('Earnfi_X402_Facilitator_Client', false)) {
            return $accepts;
        }
        $facilitator_id = class_exists('Earnfi_Near_X402_Facilitator', false)
            ? Earnfi_Near_X402_Facilitator::active_id()
            : 'fastnear';
        $supported = Earnfi_X402_Facilitator_Client::get_supported($facilitator_id);
        if (is_wp_error($supported) || empty($supported['kinds']) || !is_array($supported['kinds'])) {
            return $accepts;
        }
        $settings = get_option('growl_settings', []);
        $pay_to = trim((string) ($settings['near_treasury_account'] ?? ''));
        if ($pay_to === '') {
            return $accepts;
        }
        $atomic = class_exists('Earnfi_X402_Rail_Limits', false)
            ? Earnfi_X402_Rail_Limits::resolve_merge_amount_atomic($amount, $accepts)
            : (is_string($amount) && preg_match('/^[1-9]\d*$/', trim($amount)) ? trim($amount) : null);
        if ($atomic === null) {
            return $accepts;
        }
        if (class_exists('Earnfi_X402_Rail_Limits', false)) {
            $cap = Earnfi_X402_Rail_Limits::assert_atomic_within_cap('near:mainnet', $atomic);
            if (is_wp_error($cap)) {
                return $accepts;
            }
        }
        foreach ($supported['kinds'] as $kind) {
            if (!is_array($kind) || ($kind['network'] ?? '') !== 'near:mainnet') {
                continue;
            }
            $asset = trim((string) ($kind['asset'] ?? ''));
            if ($asset === '') {
                $asset = Earnfi_Near_X402_Facilitator::USDC_MAINNET;
            }
            $accepts[] = [
                'scheme'              => (string) ($kind['scheme'] ?? 'exact'),
                'network'             => 'near:mainnet',
                'asset'               => $asset,
                'payTo'               => $pay_to,
                'amount'              => $atomic,
                'maxAmountRequired'   => $atomic,
                'maxTimeoutSeconds'   => 60,
                'extra'               => [
                    'facilitator' => $facilitator_id,
                    'resourceUrl' => $resource_url,
                ],
            ];
            break;
        }
        return $accepts;
    }

    /**
     * Client/server signing hook — implement NEP-366 per PHASE-0-NEAR-SIGNING-SPEC before Gate B txs.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function build_settlement_payload(array $requirements) {
        if (!self::is_live_enabled()) {
            return new WP_Error('near_disabled', 'NEAR x402 is not enabled on this environment.', ['status' => 503]);
        }
        return new WP_Error(
            'near_signing_client',
            'NEAR x402 signing must be performed in the SPA (near-x402-signer.js) per NEP-366.',
            ['status' => 501]
        );
    }
}
