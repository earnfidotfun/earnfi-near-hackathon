<?php
/**
 * FastNEAR JSON-RPC for ops reconciliation (separate from x402 facilitator key).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Fastnear_Rpc {

    public const DEFAULT_MAINNET = 'https://rpc.mainnet.fastnear.com';
    public const DEFAULT_ARCHIVAL = 'https://archival-rpc.mainnet.fastnear.com';

    /**
     * @return array{url: string, api_key: string}
     */
    public static function credentials(): array {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        if (defined('EARNFI_FASTNEAR_RPC_URL')) {
            $env_url = trim((string) constant('EARNFI_FASTNEAR_RPC_URL'));
            if ($env_url !== '') {
                return [
                    'url'     => untrailingslashit($env_url),
                    'api_key' => self::setting_secret($settings, 'fastnear_rpc_api_key', 'EARNFI_FASTNEAR_RPC_API_KEY'),
                ];
            }
        }
        if (class_exists('Earnfi_Drpc_Rpc', false)) {
            $url = Earnfi_Drpc_Rpc::near_upstream($settings);
        } else {
            $url = trim((string) ($settings['fastnear_rpc_url'] ?? ''));
            if ($url === '') {
                $url = self::DEFAULT_MAINNET;
            }
        }
        $api_key = '';
        if (!class_exists('Earnfi_Drpc_Rpc', false) || !Earnfi_Drpc_Rpc::uses_drpc_url($url)) {
            $api_key = self::setting_secret($settings, 'fastnear_rpc_api_key', 'EARNFI_FASTNEAR_RPC_API_KEY');
        }
        return ['url' => untrailingslashit($url), 'api_key' => $api_key];
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $params
     * @return array<string, mixed>|WP_Error
     */
    public static function call(string $method, $params = []) {
        $c = self::credentials();
        $headers = ['Content-Type' => 'application/json'];
        if ($c['api_key'] !== '') {
            $headers['Authorization'] = 'Bearer ' . $c['api_key'];
        }
        $body = [
            'jsonrpc' => '2.0',
            'id'      => 'earnfi-' . wp_generate_password(8, false, false),
            'method'  => $method,
            'params'  => $params,
        ];
        $res = wp_remote_post($c['url'], [
            'headers' => $headers,
            'body'    => wp_json_encode($body, JSON_UNESCAPED_SLASHES),
            'timeout' => 25,
        ]);
        if (is_wp_error($res)) {
            return $res;
        }
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            return new WP_Error('fastnear_rpc_http', 'NEAR RPC request failed.', ['status' => 502]);
        }
        if (isset($data['error']) && is_array($data['error'])) {
            $msg = (string) ($data['error']['message'] ?? 'NEAR RPC error');
            return new WP_Error('fastnear_rpc_error', $msg, ['status' => 502, 'rpc' => $data['error']]);
        }
        return is_array($data['result'] ?? null) ? $data['result'] : ['result' => $data['result'] ?? null];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public static function view_account(string $account_id) {
        $account_id = trim($account_id);
        if ($account_id === '') {
            return new WP_Error('invalid_account', 'NEAR account id is required.', ['status' => 400]);
        }
        return self::call('query', [
            'request_type' => 'view_account',
            'finality'     => 'final',
            'account_id'   => $account_id,
        ]);
    }

    /**
     * Circle USDC storage registration check.
     *
     * @return array{registered: bool, balance: ?array}|WP_Error
     */
    public static function usdc_storage_balance_of(string $account_id, ?string $contract_id = null) {
        $contract_id = $contract_id ?: Earnfi_Fastnear_Facilitator::USDC_MAINNET;
        $args = wp_json_encode(['account_id' => trim($account_id)]);
        $result = self::call('query', [
            'request_type' => 'call_function',
            'finality'     => 'final',
            'account_id'   => $contract_id,
            'method_name'  => 'storage_balance_of',
            'args_base64'  => base64_encode($args),
        ]);
        if (is_wp_error($result)) {
            return $result;
        }
        $raw = $result['result'] ?? null;
        if (!is_array($raw) || !isset($raw['result'])) {
            return ['registered' => false, 'balance' => null];
        }
        $decoded = json_decode(base64_decode((string) $raw['result'], true) ?: '', true);
        return [
            'registered' => is_array($decoded) && $decoded !== [],
            'balance'    => is_array($decoded) ? $decoded : null,
        ];
    }

    private static function setting_secret(array $settings, string $option_key, string $env_key): string {
        $raw = isset($settings[$option_key]) ? trim((string) $settings[$option_key]) : '';
        if ($raw !== '') {
            try {
                if (class_exists('GROWL_Security')) {
                    $sec = new GROWL_Security();
                    $maybe = $sec->decrypt($raw);
                    if (is_string($maybe) && $maybe !== '') {
                        return trim($maybe);
                    }
                }
            } catch (Throwable $e) {
                // plaintext
            }
            return $raw;
        }
        if (defined($env_key)) {
            $v = constant($env_key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        $env = getenv($env_key);
        return is_string($env) ? trim($env) : '';
    }
}
