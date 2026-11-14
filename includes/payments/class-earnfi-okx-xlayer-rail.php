<?php
/**
 * OKX X Layer USDT0 rail — quote shape + config (paid settlement via Earnfi_Okx_Facilitator).
 *
 * Network: eip155:196 (X Layer mainnet) or eip155:1952 (testnet).
 * Asset:   USD₮0 0x779Ded0c9e1022225f8E0630b35a9b54bE713736
 *
 * Config source of truth: WordPress `growl_settings` keys:
 *   okx_x402_api_key, okx_x402_secret_key, okx_x402_passphrase (encrypted),
 *   okx_x402_pay_to, okx_x402_network, okx_x402_base_url
 *
 * Optional override (only if setting empty): env / PHP constants
 *   EARNFI_OKX_API_KEY, EARNFI_OKX_SECRET_KEY, EARNFI_OKX_PASSPHRASE, EARNFI_OKX_PAY_TO,
 *   EARNFI_OKX_NETWORK, EARNFI_OKX_BASE_URL
 *
 * MCP .env does NOT feed this rail — configure in growl_settings / default_settings.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Okx_Xlayer_Rail {

    public const NETWORK         = 'eip155:196';
    public const NETWORK_TESTNET = 'eip155:1952';
    public const ASSET_USDT0     = '0x779Ded0c9e1022225f8E0630b35a9b54bE713736';
    public const DECIMALS        = 6;

    /**
     * @return array{api_key:string,secret_key:string,passphrase:string,pay_to:string,network:string,base_url:string}
     */
    public static function credentials(): array {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $api_key    = self::setting_secret($settings, 'okx_x402_api_key', 'EARNFI_OKX_API_KEY');
        $secret_key = self::setting_secret($settings, 'okx_x402_secret_key', 'EARNFI_OKX_SECRET_KEY');
        $passphrase = self::setting_secret($settings, 'okx_x402_passphrase', 'EARNFI_OKX_PASSPHRASE');
        $pay_to     = self::setting_plain($settings, 'okx_x402_pay_to', 'EARNFI_OKX_PAY_TO');
        $network    = self::setting_plain($settings, 'okx_x402_network', 'EARNFI_OKX_NETWORK') ?: self::NETWORK;
        $base_url   = self::setting_plain($settings, 'okx_x402_base_url', 'EARNFI_OKX_BASE_URL') ?: 'https://web3.okx.com';

        return [
            'api_key'    => $api_key,
            'secret_key' => $secret_key,
            'passphrase' => $passphrase,
            'pay_to'     => $pay_to,
            'network'    => $network,
            'base_url'   => rtrim($base_url, '/'),
        ];
    }

    public static function is_paid_ready(): bool {
        $c = self::credentials();
        return $c['api_key'] !== ''
            && $c['secret_key'] !== ''
            && $c['passphrase'] !== ''
            && self::is_valid_evm_address($c['pay_to']);
    }

    /**
     * Public status without leaking secrets or setup internals.
     * Prefer marketplace_health() for ASP /okx/v1/health.
     *
     * @return array<string,mixed>
     */
    public static function public_config_status(): array {
        $c = self::credentials();
        $network = $c['network'] === self::NETWORK_TESTNET ? self::NETWORK_TESTNET : self::NETWORK;
        return [
            'paid_ready'   => self::is_paid_ready(),
            'network'      => $network,
            'asset_symbol' => 'USDT0',
            'asset'        => self::ASSET_USDT0,
        ];
    }

    /**
     * Minimal public health (no credentials, facilitator dump, or admin setup hints).
     *
     * @return array<string,mixed>
     */
    public static function marketplace_health(): array {
        return [
            'paid_ready' => self::is_paid_ready(),
            'network'    => self::NETWORK,
            'asset'      => 'USDT0',
        ];
    }

    /**
     * Build x402 v2 accepts[0] for X Layer USDT0.
     *
     * @param string $resource_url
     * @param string $atomic_amount Integer string (6 dp USDT0)
     * @param string $description
     * @return array{x402Version:int,accepts:array,resource:array}|WP_Error
     */
    public static function build_challenge(string $resource_url, string $atomic_amount, string $description = '') {
        $c = self::credentials();
        if (!self::is_valid_evm_address($c['pay_to'])) {
            return new WP_Error(
                'okx_rail_not_configured',
                'OKX X Layer pay_to address is not configured (growl_settings okx_x402_pay_to). Free discovery endpoints remain available.',
                ['status' => 503, 'config' => self::public_config_status()]
            );
        }

        $amount = preg_replace('/\D+/', '', (string) $atomic_amount);
        if ($amount === '' || $amount === '0') {
            return new WP_Error('invalid_amount', 'atomic amount must be a positive integer string', ['status' => 400]);
        }

        $network = ($c['network'] === self::NETWORK_TESTNET) ? self::NETWORK_TESTNET : self::NETWORK;
        $desc = $description !== ''
            ? $description
            : 'EarnFi Human Execution — pay with USDT0 on X Layer (OKX x402), then retry with PAYMENT-SIGNATURE.';

        $accept = [
            'scheme'            => 'exact',
            'network'           => $network,
            'asset'             => self::ASSET_USDT0,
            'amount'            => $amount,
            'maxAmountRequired' => $amount,
            'payTo'             => $c['pay_to'],
            'maxTimeoutSeconds' => 300,
            'extra'             => [
                'name'    => 'USD₮0',
                'version' => '1',
            ],
        ];

        return [
            'x402Version' => 2,
            'accepts'     => [ $accept ],
            'resource'    => [
                'url'         => $resource_url,
                'description' => $desc,
                'mimeType'    => 'application/json',
            ],
        ];
    }

    /**
     * USD decimal string → atomic USDT0 (6 dp).
     */
    public static function usd_to_atomic(string $total_cost_usd): string {
        $n = number_format((float) $total_cost_usd, 6, '.', '');
        return bcmul($n, bcpow('10', (string) self::DECIMALS, 0), 0);
    }

    public static function is_valid_evm_address(string $addr): bool {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', trim($addr));
    }

    /**
     * Prefer growl_settings (decrypt if encrypted); fall back to env/constant.
     */
    private static function setting_secret(array $settings, string $option_key, string $env_key): string {
        $raw = isset($settings[$option_key]) ? trim((string) $settings[$option_key]) : '';
        if ($raw !== '') {
            $plain = self::decrypt_setting($raw);
            if ($plain !== '') {
                return $plain;
            }
        }
        return self::env_cfg($env_key);
    }

    private static function setting_plain(array $settings, string $option_key, string $env_key): string {
        $raw = isset($settings[$option_key]) ? trim((string) $settings[$option_key]) : '';
        if ($raw !== '') {
            return $raw;
        }
        return self::env_cfg($env_key);
    }

    private static function decrypt_setting(string $raw): string {
        if ($raw === '') {
            return '';
        }
        try {
            if (!class_exists('GROWL_Security')) {
                return $raw;
            }
            $sec = new GROWL_Security();
            $maybe = $sec->decrypt($raw);
            if (is_string($maybe) && $maybe !== '') {
                return trim($maybe);
            }
        } catch (Throwable $e) {
            // Stored as plaintext (or wrong key) — use raw.
        }
        return $raw;
    }

    private static function env_cfg(string $env_key): string {
        if (defined($env_key)) {
            $v = constant($env_key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        $env = getenv($env_key);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        return '';
    }
}
