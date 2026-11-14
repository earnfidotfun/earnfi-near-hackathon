<?php
/**
 * Single source of truth for which chains appear in public network lists vs live x402 quotes.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Chain_Network_Catalog {

    public const SOLANA_CAIP2 = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';
    public const BASE_CAIP2   = 'eip155:8453';
    public const NEAR_CAIP2   = 'near:mainnet';

    /**
     * Wallet connect / GET /growl/v1/networks — show NEAR when live flags are on and treasury is set.
     * Does NOT require FastNEAR API key (that gates settle only). Matches SPA tiles.
     *
     * @param array<string, mixed>|null $settings
     */
    public static function near_connect_enabled($settings = null): bool {
        if ($settings === null) {
            $settings = get_option('growl_settings', []);
        }
        if (!is_array($settings)) {
            return false;
        }
        // Admin off wins: no live / gate / rail switch → hide from wallet connect + Connections.
        if (empty($settings['near_x402_enabled'])
            && empty($settings['near_x402_gate_b_ok'])
            && empty($settings['near_rail_enabled'])) {
            return false;
        }
        $treasury = trim((string) ($settings['near_treasury_account'] ?? ''));
        return $treasury !== '';
    }

    /**
     * @param array<string, mixed>|null $settings
     */
    public static function base_connect_enabled($settings = null): bool {
        if ($settings === null) {
            $settings = get_option('growl_settings', []);
        }
        if (!is_array($settings)) {
            return false;
        }
        if (empty($settings['base_x402_enabled'])
            && empty($settings['base_x402_gate_b_ok'])
            && empty($settings['base_rail_enabled'])) {
            return false;
        }
        $pay_to = trim((string) ($settings['base_x402_pay_to'] ?? ''));
        return $pay_to !== '' && (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $pay_to);
    }

    /**
     * Human/agent multichain sign-in (Base + NEAR) when rail is configured.
     *
     * @param array<string, mixed>|null $settings
     */
    public static function multichain_auth_enabled($settings = null): bool {
        return self::base_connect_enabled($settings) || self::near_connect_enabled($settings);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function public_networks_list(): array {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $networks = [
            [
                'id'           => 'solana',
                'caip2'        => self::SOLANA_CAIP2,
                'label'        => 'Solana',
                'description'  => '',
                'default'      => true,
                'enabled'      => true,
                'connect_kind' => 'solana_wallet',
                'auth_enabled' => true,
                'withdrawals'  => true,
            ],
        ];

        if (!empty($settings['okx_x402_pay_to'])) {
            $networks[] = [
                'id'           => 'evm_okx',
                'caip2'        => sanitize_text_field((string) ($settings['okx_x402_network'] ?? 'eip155:196')),
                'label'        => 'OKX X Layer',
                'description'  => '',
                'default'      => false,
                'enabled'      => true,
                'connect_kind' => 'evm_agent_rail',
                'auth_enabled' => false,
                'withdrawals'  => false,
            ];
        }

        if (self::near_connect_enabled($settings)) {
            $networks[] = [
                'id'           => 'near',
                'caip2'        => self::NEAR_CAIP2,
                'label'        => 'NEAR',
                'description'  => '',
                'default'      => false,
                'enabled'      => true,
                'connect_kind' => 'near_wallet',
                'auth_enabled' => true,
                'withdrawals'  => false,
            ];
        }

        if (self::base_connect_enabled($settings)) {
            $networks[] = [
                'id'           => 'base',
                'caip2'        => (string) ($settings['base_x402_network'] ?? self::BASE_CAIP2),
                'label'        => 'Base',
                'description'  => '',
                'default'      => false,
                'enabled'      => true,
                'connect_kind' => 'evm_base_wallet',
                'auth_enabled' => true,
                'withdrawals'  => false,
            ];
        }

        return $networks;
    }
}
