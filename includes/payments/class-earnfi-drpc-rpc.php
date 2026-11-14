<?php
/**
 * dRPC load-balanced JSON-RPC URLs (Solana, Base, NEAR).
 * API keys stay server-side; browsers use authenticated REST proxies (/growl/v1/rpc/*).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Drpc_Rpc {

    public const LB_ORIGIN = 'https://lb.drpc.live';

    /**
     * Resolve dRPC API key from encrypted setting, env, or Solana custom_rpc_url path segment.
     */
    public static function resolve_api_key(?array $settings = null): string {
        if (!is_array($settings)) {
            $settings = get_option('growl_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
        }

        if (defined('EARNFI_DRPC_API_KEY')) {
            $env = trim((string) constant('EARNFI_DRPC_API_KEY'));
            if ($env !== '') {
                return $env;
            }
        }

        if (class_exists('GROWL_Secret_Settings', false)) {
            $from_opt = trim(GROWL_Secret_Settings::decrypt('drpc_api_key'));
            if ($from_opt !== '') {
                return $from_opt;
            }
        }

        $sol = trim((string) ($settings['custom_rpc_url'] ?? ''));
        if ($sol !== '') {
            $parsed = self::parse_key_from_url($sol);
            if ($parsed !== '') {
                return $parsed;
            }
        }

        return '';
    }

    /**
     * @return string Empty when not a dRPC lb URL.
     */
    public static function parse_key_from_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://lb\.drpc\.(?:live|org)/(?:[a-z0-9_-]+/)?([A-Za-z0-9_-]{20,})#i', $url, $m)) {
            return (string) $m[1];
        }
        return '';
    }

    public static function lb_url_for(string $network_slug, ?string $api_key = null): string {
        $network_slug = sanitize_key($network_slug);
        if ($network_slug === '') {
            return '';
        }
        $api_key = $api_key ?? self::resolve_api_key();
        $api_key = trim($api_key);
        if ($api_key === '') {
            return '';
        }
        return untrailingslashit(self::LB_ORIGIN . '/' . $network_slug . '/' . $api_key);
    }

    /**
     * Upstream Solana JSON-RPC URL (server-side only).
     */
    public static function solana_upstream(?array $settings = null): string {
        if (!is_array($settings)) {
            $settings = get_option('growl_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
        }
        $network = (string) ($settings['solana_network'] ?? 'mainnet-beta');
        if ($network === 'mainnet') {
            $network = 'mainnet-beta';
        }
        $public = [
            'mainnet-beta' => 'https://api.mainnet-beta.solana.com',
            'devnet'       => 'https://api.devnet.solana.com',
            'testnet'      => 'https://api.testnet.solana.com',
        ];

        $custom = trim((string) ($settings['custom_rpc_url'] ?? ''));
        if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_URL)) {
            return $custom;
        }

        $drpc_net = $network === 'devnet' ? 'solana-devnet' : ($network === 'testnet' ? 'solana-testnet' : 'solana');
        $built = self::lb_url_for($drpc_net);
        if ($built !== '') {
            return $built;
        }

        return $public[$network] ?? $public['mainnet-beta'];
    }

    /**
     * Upstream Base (EVM) JSON-RPC URL (server-side only).
     */
    public static function base_upstream(?array $settings = null): string {
        if (!is_array($settings)) {
            $settings = get_option('growl_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
        }
        $custom = trim((string) ($settings['base_rpc_url'] ?? ''));
        if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_URL)) {
            return $custom;
        }
        $built = self::lb_url_for('base');
        if ($built !== '') {
            return $built;
        }
        return 'https://mainnet.base.org';
    }

    /**
     * Upstream NEAR JSON-RPC URL (server-side only).
     */
    public static function near_upstream(?array $settings = null): string {
        if (!is_array($settings)) {
            $settings = get_option('growl_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
        }
        $custom = trim((string) ($settings['fastnear_rpc_url'] ?? ''));
        if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_URL)) {
            return untrailingslashit($custom);
        }
        $built = self::lb_url_for('near');
        if ($built !== '') {
            return $built;
        }
        return Earnfi_Fastnear_Rpc::DEFAULT_MAINNET;
    }

    public static function uses_drpc_url(string $url): bool {
        return (bool) preg_match('#^https?://lb\.drpc\.(?:live|org)/#i', trim($url));
    }

    /**
     * One-time backfill: point Base/NEAR RPC settings at dRPC when key is known (Solana URL or drpc_api_key).
     */
    public static function maybe_backfill_rpc_urls(): void {
        if (get_option('growl_migr_drpc_multichain_rpc_v1')) {
            return;
        }
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            update_option('growl_migr_drpc_multichain_rpc_v1', 1);
            return;
        }
        $key = self::resolve_api_key($settings);
        if ($key === '') {
            update_option('growl_migr_drpc_multichain_rpc_v1', 1);
            return;
        }
        $changed = false;

        if (trim((string) ($settings['base_rpc_url'] ?? '')) === '') {
            $settings['base_rpc_url'] = self::lb_url_for('base', $key);
            $changed = true;
        }

        $near = trim((string) ($settings['fastnear_rpc_url'] ?? ''));
        if ($near === '' || strpos($near, 'fastnear.com') !== false) {
            $settings['fastnear_rpc_url'] = self::lb_url_for('near', $key);
            $changed = true;
        }

        if ($changed) {
            update_option('growl_settings', $settings);
        }
        update_option('growl_migr_drpc_multichain_rpc_v1', 1);
    }
}
