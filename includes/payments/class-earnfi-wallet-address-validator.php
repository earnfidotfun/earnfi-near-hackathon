<?php
/**
 * Per-network wallet validation and wallet_type inference (Phase 2b).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Wallet_Address_Validator {

    public static function infer_wallet_type(string $address, array $context = []): string {
        $address = trim($address);
        $network = strtolower(trim((string) ($context['network'] ?? $context['rail_network'] ?? '')));
        $provider = sanitize_key((string) ($context['provider'] ?? ''));

        if ($network !== '' && strpos($network, 'eip155:') === 0) {
            return 'evm';
        }
        if ($network !== '' && strpos($network, 'near:') === 0) {
            return 'near';
        }
        if ($provider === 'okx_xlayer' || $provider === 'okx') {
            return 'evm';
        }
        if (preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return 'evm';
        }
        if (self::is_near_account_id($address)) {
            return 'near';
        }
        return 'solana';
    }

    public static function is_solana_pubkey(string $address): bool {
        $address = trim($address);
        if ($address === '' || strlen($address) < 32 || strlen($address) > 44) {
            return false;
        }
        return (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address);
    }

    public static function is_evm_address(string $address): bool {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', trim($address));
    }

    public static function is_near_account_id(string $account): bool {
        $account = trim($account);
        if ($account === '' || strlen($account) > 64) {
            return false;
        }
        if (!preg_match('/^[a-z0-9._-]+$/i', $account)) {
            return false;
        }
        if (preg_match('/\.(near|testnet|mainnet)$/i', $account)) {
            return true;
        }
        return strlen($account) >= 2 && strlen($account) <= 64 && !self::is_solana_pubkey($account) && !self::is_evm_address($account);
    }

    /**
     * @return true|WP_Error
     */
    public static function validate_for_network(string $address, string $network_caip2) {
        $address = trim($address);
        $network_caip2 = trim($network_caip2);
        if ($address === '') {
            return new WP_Error('invalid_wallet', 'Wallet address is required.', ['status' => 400]);
        }
        if (strpos($network_caip2, 'solana:') === 0) {
            return self::is_solana_pubkey($address)
                ? true
                : new WP_Error('invalid_wallet', 'Invalid Solana wallet address.', ['status' => 400]);
        }
        if (strpos($network_caip2, 'eip155:') === 0) {
            return self::is_evm_address($address)
                ? true
                : new WP_Error('invalid_wallet', 'Invalid EVM wallet address.', ['status' => 400]);
        }
        if (strpos($network_caip2, 'near:') === 0) {
            return self::is_near_account_id($address)
                ? true
                : new WP_Error('invalid_wallet', 'Invalid NEAR account id.', ['status' => 400]);
        }
        return true;
    }
}
