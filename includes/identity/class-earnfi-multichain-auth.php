<?php
/**
 * Challenge + verify for Base (EIP-191) and NEAR (NEP-413) human sign-in / wallet link.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Multichain_Auth {

    /** @var GROWL_Database */
    private $database;

    /** @var GROWL_Security */
    private $security;

    public function __construct(?GROWL_Database $database = null, ?GROWL_Security $security = null) {
        $this->database = $database ?: new GROWL_Database();
        $this->security = $security ?: new GROWL_Security();
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function issue_challenge(string $chain_caip2, string $purpose = 'login', string $requested_wallet = '') {
        $chain_caip2 = trim($chain_caip2);
        if (!in_array($purpose, ['login', 'link_wallet', 'link_payment'], true)) {
            $purpose = 'login';
        }
        if (!$this->is_supported_auth_chain($chain_caip2)) {
            return new WP_Error('unsupported_chain', 'This chain is not enabled for sign-in.', ['status' => 400]);
        }

        $requested_wallet = $this->sanitize_wallet_for_chain($chain_caip2, $requested_wallet);
        if ($requested_wallet === false) {
            return new WP_Error('invalid_wallet_address', 'Invalid wallet address for this network.', ['status' => 400]);
        }

        $settings = get_option('growl_settings', []);
        $uri = is_array($settings) && trim((string) ($settings['app_url'] ?? '')) !== ''
            ? untrailingslashit(esc_url_raw(trim($settings['app_url'])))
            : untrailingslashit(home_url('/'));
        $host = strtolower((string) wp_parse_url($uri, PHP_URL_HOST));
        if ($host === '' || $host === 'earnfi.fun' || !preg_match('/(^|\.)earnfi\.fun$/i', $host)) {
            $uri = 'https://app.earnfi.fun';
            $host = 'app.earnfi.fun';
        }

        $challenge_id = wp_generate_uuid4();
        $nonce = bin2hex(random_bytes(16));
        $nonce_bytes = random_bytes(32);
        $issued_timestamp = time();
        $expires_timestamp = $issued_timestamp + 180;
        $statement = 'Sign in to EarnFi.';

        $table = $this->database->table_prefix . 'wallet_auth_challenges';
        $inserted = $this->database->wpdb->insert($table, [
            'challenge_id'     => $challenge_id,
            'nonce_hash'       => hash('sha256', $nonce),
            'requested_wallet' => $requested_wallet !== '' ? $requested_wallet : null,
            'consumed_wallet'  => null,
            'network'          => $this->legacy_network_label($chain_caip2),
            'chain_caip2'      => $chain_caip2,
            'purpose'          => $purpose,
            'domain'           => $host,
            'uri'              => $uri,
            'statement'        => $statement,
            'issued_at'        => gmdate('Y-m-d H:i:s', $issued_timestamp),
            'expires_at'       => gmdate('Y-m-d H:i:s', $expires_timestamp),
            'ip_hash'          => hash_hmac('sha256', $this->client_ip(), wp_salt('auth')),
        ]);
        if ($inserted !== 1) {
            return new WP_Error('challenge_unavailable', 'Sign-in is temporarily unavailable.', ['status' => 503]);
        }

        $message = $this->build_plain_message($host, $chain_caip2, $requested_wallet, $statement, $nonce, $expires_timestamp);

        $payload = [
            'id'              => $challenge_id,
            'domain'          => $host,
            'uri'             => $uri,
            'statement'       => $statement,
            'nonce'           => $nonce,
            'chain_caip2'     => $chain_caip2,
            'issued_at'       => gmdate('c', $issued_timestamp),
            'expiration_time' => gmdate('c', $expires_timestamp),
            'purpose'         => $purpose,
            'message'         => $message,
            'signature_scheme'=> strpos($chain_caip2, 'near:') === 0 ? 'nep413' : 'eip191',
        ];

        if (strpos($chain_caip2, 'near:') === 0) {
            $payload['nep413'] = [
                'message'    => $message,
                'recipient'  => $host,
                'nonce_hex'  => bin2hex($nonce_bytes),
                'callback_url' => $uri,
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|WP_Error validated challenge row + wallet
     */
    public function verify_request(array $params) {
        $chain_caip2 = trim((string) ($params['chain_caip2'] ?? ''));
        $challenge_id = sanitize_text_field((string) ($params['challenge_id'] ?? ''));
        $wallet_raw = trim((string) ($params['wallet_address'] ?? ''));
        $message = (string) ($params['message'] ?? '');

        $wallet = $this->sanitize_wallet_for_chain($chain_caip2, $wallet_raw);
        if ($wallet === false || $wallet === '') {
            return new WP_Error('invalid_wallet_address', 'Invalid wallet address.', ['status' => 400]);
        }
        if ($challenge_id === '') {
            return new WP_Error('invalid_challenge', 'challenge_id is required.', ['status' => 400]);
        }

        $table = $this->database->table_prefix . 'wallet_auth_challenges';
        $row = $this->database->wpdb->get_row(
            $this->database->wpdb->prepare(
                "SELECT * FROM {$table} WHERE challenge_id = %s LIMIT 1",
                $challenge_id
            )
        );
        if (!$row || !empty($row->consumed_at) || strtotime($row->expires_at . ' UTC') < time()) {
            return new WP_Error('expired_challenge', 'This sign-in request expired.', ['status' => 401]);
        }
        $row_chain = trim((string) ($row->chain_caip2 ?? ''));
        if ($row_chain !== '' && !hash_equals($row_chain, $chain_caip2)) {
            return new WP_Error('challenge_mismatch', 'Chain does not match challenge.', ['status' => 400]);
        }

        if (!empty($row->requested_wallet) && !hash_equals((string) $row->requested_wallet, $wallet)) {
            return new WP_Error('challenge_mismatch', 'Wallet does not match challenge.', ['status' => 400]);
        }

        if (strpos($chain_caip2, 'eip155:') === 0) {
            $sig = $params['signature'] ?? '';
            if (!Earnfi_Evm_Personal_Sign::verify($message, is_string($sig) || is_array($sig) ? $sig : '', $wallet)) {
                return new WP_Error('invalid_signature', 'EVM signature verification failed.', ['status' => 401]);
            }
        } elseif (strpos($chain_caip2, 'near:') === 0) {
            $nep = is_array($params['nep413'] ?? null) ? $params['nep413'] : $params;
            $nonce_hex = (string) ($nep['nonce_hex'] ?? $params['nonce_hex'] ?? '');
            $nonce_raw = strlen($nonce_hex) === 64 ? hex2bin($nonce_hex) : false;
            if ($nonce_raw === false || strlen($nonce_raw) !== 32) {
                return new WP_Error('invalid_nonce', 'Invalid NEAR nonce.', ['status' => 400]);
            }
            $recipient = (string) ($nep['recipient'] ?? $row->domain ?? '');
            $msg = (string) ($nep['message'] ?? $message);
            $account_id = (string) ($nep['account_id'] ?? $params['account_id'] ?? $wallet);
            $public_key = (string) ($nep['public_key'] ?? $params['public_key'] ?? '');
            $signature = (string) ($nep['signature'] ?? $params['signature'] ?? '');
            if (!Earnfi_Nep413_Verify::verify($msg, $recipient, $nonce_raw, $account_id, $public_key, $signature, (string) ($row->uri ?? ''))) {
                return new WP_Error('invalid_signature', 'NEAR message verification failed.', ['status' => 401]);
            }
            if (!hash_equals(strtolower($account_id), strtolower($wallet))) {
                return new WP_Error('challenge_mismatch', 'NEAR account id mismatch.', ['status' => 400]);
            }
        } else {
            return new WP_Error('unsupported_chain', 'Unsupported chain.', ['status' => 400]);
        }

        $updated = $this->database->wpdb->query(
            $this->database->wpdb->prepare(
                "UPDATE {$table}
                 SET consumed_at = UTC_TIMESTAMP(), consumed_wallet = %s
                 WHERE challenge_id = %s AND consumed_at IS NULL AND expires_at >= UTC_TIMESTAMP()",
                $wallet,
                $challenge_id
            )
        );
        if ($updated !== 1) {
            return new WP_Error('challenge_used', 'Challenge already used.', ['status' => 409]);
        }

        return [
            'row'    => $row,
            'wallet' => $wallet,
            'wallet_type' => Earnfi_Wallet_Address_Validator::infer_wallet_type($wallet, ['network' => $chain_caip2]),
            'chain_caip2' => $chain_caip2,
        ];
    }

    public function is_supported_auth_chain(string $chain_caip2): bool {
        if (strpos($chain_caip2, 'eip155:8453') === 0) {
            return Earnfi_Chain_Network_Catalog::base_connect_enabled();
        }
        if (strpos($chain_caip2, 'near:') === 0) {
            return Earnfi_Chain_Network_Catalog::near_connect_enabled();
        }
        return false;
    }

    /**
     * @return string|false normalized wallet or false
     */
    private function sanitize_wallet_for_chain(string $chain_caip2, string $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (strpos($chain_caip2, 'eip155:') === 0) {
            $w = Earnfi_Evm_Personal_Sign::normalize_address($raw);
            return $w !== '' ? $w : false;
        }
        if (strpos($chain_caip2, 'near:') === 0) {
            return Earnfi_Wallet_Address_Validator::is_near_account_id($raw) ? strtolower($raw) : false;
        }
        return false;
    }

    private function legacy_network_label(string $chain_caip2): string {
        if (strpos($chain_caip2, 'near:') === 0) {
            return 'near-mainnet';
        }
        if (strpos($chain_caip2, 'eip155:8453') === 0) {
            return 'base-mainnet';
        }
        return 'multichain';
    }

    private function build_plain_message(
        string $domain,
        string $chain_caip2,
        string $wallet,
        string $statement,
        string $nonce,
        int $expires_ts
    ): string {
        $wallet_line = $wallet !== '' ? $wallet : '(your wallet)';
        return "EarnFi — sign in\nDomain: {$domain}\nChain: {$chain_caip2}\nWallet: {$wallet_line}\n\n{$statement}\n\nNonce: {$nonce}\nExpires (Unix): {$expires_ts}";
    }

    private function client_ip(): string {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}
