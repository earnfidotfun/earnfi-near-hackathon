<?php
/**
 * Shared EarnFi Agent API registration core (challenge + token mint).
 *
 * Facades:
 *   - Solana (/ai-agent/v1): base58 + Ed25519
 *   - OKX EVM (/ai-agent/okx/v1): 0x + EIP-191 personal_sign
 *
 * Solana public contract is unchanged; OKX adds an accurate EVM path without proxying
 * into Solana address/signature validators.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Agent_Register {

    public const SCHEME_SOLANA = 'solana_ed25519';
    public const SCHEME_EVM    = 'eip191';
    public const SCHEME_NEAR   = 'nep413';

    public const WALLET_SOLANA = 'solana';
    public const WALLET_EVM    = 'evm';
    public const WALLET_NEAR   = 'near';

    public const SOLANA_SIGNATURE_HINT = 'Send signature as a JSON array of 64 integers (0–255), or as a base58, hex (128 chars), or standard base64 string encoding exactly 64 raw Ed25519 signature bytes. The message bytes must match what was signed exactly.';

    /** @var GROWL_Database */
    private $database;

    /** @var GROWL_Security */
    private $security;

    public function __construct(?GROWL_Database $database = null, ?GROWL_Security $security = null) {
        $this->database = $database ?: new GROWL_Database();
        $this->security = $security ?: new GROWL_Security();
    }

    public static function detect_wallet_type(string $wallet): string {
        $w = trim($wallet);
        if (class_exists('Earnfi_Wallet_Address_Validator', false)) {
            if (Earnfi_Wallet_Address_Validator::is_near_account_id($w)) {
                return self::WALLET_NEAR;
            }
            if (Earnfi_Wallet_Address_Validator::is_evm_address($w)) {
                return self::WALLET_EVM;
            }
        }
        if (class_exists('Earnfi_Okx_Xlayer_Rail') && Earnfi_Okx_Xlayer_Rail::is_valid_evm_address($w)) {
            return self::WALLET_EVM;
        }
        $sec = new GROWL_Security();
        $s = $sec->sanitize_wallet_address($w);
        if (is_string($s) && $s !== '') {
            return self::WALLET_SOLANA;
        }
        return '';
    }

    public static function is_evm_wallet(string $wallet): bool {
        return self::detect_wallet_type($wallet) === self::WALLET_EVM;
    }

    /**
     * Sanitize + classify address for a required scheme.
     *
     * @return array{wallet:string,wallet_type:string}|WP_Error
     */
    public function sanitize_for_scheme(string $raw, string $scheme) {
        $raw = trim($raw);
        if ($scheme === self::SCHEME_SOLANA) {
            $wallet = $this->security->sanitize_wallet_address($raw);
            if ($wallet === false || $wallet === '') {
                return new WP_Error(
                    'invalid_params',
                    'wallet_address must be a Solana base58 pubkey',
                    [
                        'status' => 400,
                        'hint'   => 'Example: GET .../register/challenge?wallet_address=YOUR_PUBKEY&agent_name=my-agent',
                    ]
                );
            }
            return ['wallet' => $wallet, 'wallet_type' => self::WALLET_SOLANA];
        }
        if ($scheme === self::SCHEME_EVM) {
            $wallet = Earnfi_Evm_Personal_Sign::normalize_address($raw);
            if ($wallet === '') {
                return new WP_Error(
                    'invalid_params',
                    'wallet_address must be an EVM 0x address (X Layer / Agentic Wallet Ethereum address)',
                    [
                        'status' => 400,
                        'hint'   => 'Use a 0x-prefixed 40-hex address. Not a Solana pubkey. Example: GET .../okx/v1/register/challenge?wallet_address=0x…&agent_name=my-agent',
                    ]
                );
            }
            return ['wallet' => $wallet, 'wallet_type' => self::WALLET_EVM];
        }
        if ($scheme === self::SCHEME_NEAR) {
            $wallet = strtolower(trim($raw));
            if (!class_exists('Earnfi_Wallet_Address_Validator', false)
                || !Earnfi_Wallet_Address_Validator::is_near_account_id($wallet)) {
                return new WP_Error(
                    'invalid_params',
                    'wallet_address must be a NEAR account id',
                    ['status' => 400]
                );
            }
            return ['wallet' => $wallet, 'wallet_type' => self::WALLET_NEAR];
        }
        return new WP_Error('invalid_scheme', 'Unsupported registration scheme', ['status' => 400]);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function issue_challenge(string $wallet_raw, string $agent_name, string $scheme) {
        $agent_name = sanitize_text_field($agent_name);
        if ($agent_name === '') {
            return new WP_Error(
                'invalid_params',
                'wallet_address and agent_name are required',
                ['status' => 400]
            );
        }
        $name_len = function_exists('mb_strlen') ? mb_strlen($agent_name) : strlen($agent_name);
        if ($name_len > 120) {
            return new WP_Error('invalid_params', 'agent_name is too long (max 120 characters)', ['status' => 400]);
        }

        $san = $this->sanitize_for_scheme($wallet_raw, $scheme);
        if (is_wp_error($san)) {
            return $san;
        }
        $wallet = $san['wallet'];
        $wallet_type = $san['wallet_type'];

        $can = EarnFi_Wallet_Identity::assert_can_register_agent($wallet);
        if (is_wp_error($can)) {
            return $can;
        }

        $nonce   = bin2hex(random_bytes(16));
        $nep413_nonce_hex = bin2hex(random_bytes(32));
        $exp_ts  = time() + 600;
        $exp_iso = gmdate('Y-m-d\TH:i:s\Z', $exp_ts);
        // ASCII-only challenge body (hyphen, not Unicode em dash). EIP-191 personal_sign
        // prefixes with UTF-8 byte length; JS string.length on em dashes breaks agent signers.
        $message = "EarnFi Agent API - register agent\nWallet: {$wallet}\nAgent name: {$agent_name}\nNonce: {$nonce}\nExpires (Unix): {$exp_ts}";
        $message_utf8_bytes = strlen($message);
        $transient = 'earnfi_reg_ch_v1_' . $nonce;
        set_transient(
            $transient,
            [
                'wallet'      => $wallet,
                'agent_name'  => $agent_name,
                'message'     => $message,
                'exp'         => $exp_ts,
                'scheme'      => $scheme,
                'wallet_type' => $wallet_type,
                'nep413_nonce_hex' => $nep413_nonce_hex,
            ],
            600
        );

        $payload = [
            'success'           => true,
            'wallet_address'    => $wallet,
            'wallet_type'       => $wallet_type,
            'signature_scheme'  => $scheme,
            'agent_name'        => $agent_name,
            'nonce'             => $nonce,
            'message'           => $message,
            'message_utf8_bytes'=> $message_utf8_bytes,
            'expires_at'        => $exp_iso,
            'expires_at_unix'   => $exp_ts,
            'sign_hint'         => $scheme === self::SCHEME_EVM
                ? Earnfi_Evm_Personal_Sign::HINT
                : ($scheme === self::SCHEME_NEAR
                    ? 'Sign with NEP-413 signMessage using nep413 fields from this response (near-api-js / wallet selector). Do not use EIP-191 or Solana Ed25519.'
                    : self::SOLANA_SIGNATURE_HINT),
            'hint'              => 'Sign the message exactly as returned, then POST /register with the same fields plus signature and nonce.',
            'next_step'         => 'POST /register with wallet_address, agent_name, message, nonce, and signature.',
        ];
        if ($scheme === self::SCHEME_NEAR) {
            $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            $payload['nep413'] = [
                'message'   => $message,
                'recipient' => is_string($host) ? $host : 'app.earnfi.fun',
                'nonce_hex' => $nep413_nonce_hex,
            ];
        }
        return rest_ensure_response($payload);
    }

    /**
     * Default display name when auto-binding from payment without agent_name.
     */
    public static function default_agent_name_for_wallet(string $wallet): string {
        $w = preg_replace('/[^a-zA-Z0-9]/', '', trim($wallet));
        if (!is_string($w) || $w === '') {
            return 'agent';
        }
        return 'agent_' . strtolower(substr($w, 0, 8));
    }

    /**
     * Sanitize optional agent_name from a paid-create request (empty = caller should use default).
     */
    public static function sanitize_optional_agent_name($raw): string {
        $name = sanitize_text_field((string) $raw);
        if ($name === '') {
            return '';
        }
        $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($len > 120) {
            $name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
        }
        return $name;
    }

    /**
     * Upsert agent + mint a new agent_token for a wallet without a message signature.
     * Used after x402 settlement when the client did not pre-register.
     *
     * @param array<string,mixed> $body
     * @return array{agent_id:string,agent_token:string,wallet:string,wallet_type:string,agent_name:string,updated:bool,partner_id:int|null,token_row:object}|WP_Error
     */
    public function provision_for_wallet(
        string $wallet,
        string $wallet_type,
        string $agent_name,
        array $body = [],
        ?WP_REST_Request $request = null
    ) {
        $wallet = trim($wallet);
        if ($wallet === '') {
            return new WP_Error(
                'invalid_wallet',
                'A paying wallet address is required to bind agent identity.',
                [
                    'status'    => 400,
                    'hint'      => 'Complete payment so the paying wallet can be bound, or register first and send Agent-Token.',
                    'next_step' => 'POST /register/challenge then POST /register, or retry the paid create with PAYMENT-SIGNATURE.',
                ]
            );
        }

        $agent_name = sanitize_text_field($agent_name);
        if ($agent_name === '') {
            $agent_name = self::default_agent_name_for_wallet($wallet);
        }
        $name_len = function_exists('mb_strlen') ? mb_strlen($agent_name) : strlen($agent_name);
        if ($name_len > 120) {
            $agent_name = function_exists('mb_substr') ? mb_substr($agent_name, 0, 120) : substr($agent_name, 0, 120);
        }

        $can = EarnFi_Wallet_Identity::assert_can_register_agent($wallet);
        if (is_wp_error($can)) {
            return $can;
        }

        $existing_agent = $this->database->get_ai_agent_by_wallet($wallet);
        $is_update = (bool) $existing_agent;

        $partner = null;
        if ($request instanceof WP_REST_Request) {
            $partner = EarnFi_Partner_Context::resolve_from_request($request);
        }
        if (!$partner && !empty($body['partner_source'])) {
            $partner = EarnFi_Partner_Context::get_by_slug(sanitize_key((string) $body['partner_source']));
        }
        if (!$partner && $request instanceof WP_REST_Request) {
            $ps = sanitize_key((string) ($request->get_param('partner_source') ?? ''));
            if ($ps !== '') {
                $partner = EarnFi_Partner_Context::get_by_slug($ps);
            }
        }
        $meta_raw = $body['partner_metadata'] ?? ($request instanceof WP_REST_Request ? $request->get_param('partner_metadata') : null);
        $meta = EarnFi_Partner_Context::normalize_partner_metadata($meta_raw);

        $default_provider = 'wallet';
        if ($wallet_type === self::WALLET_EVM) {
            $default_provider = 'evm_wallet';
        } elseif ($wallet_type === self::WALLET_NEAR) {
            $default_provider = 'near_wallet';
        }
        if (empty($body['identity_provider']) && !empty($body['provision_source'])) {
            $default_provider = sanitize_key((string) $body['provision_source']);
        }
        $agent_payload = [
            'wallet_address'     => $wallet,
            'agent_name'         => $agent_name,
            'identity_provider'  => sanitize_text_field($body['identity_provider'] ?? $default_provider),
            'identity_reference' => isset($body['identity_reference']) ? sanitize_text_field($body['identity_reference']) : null,
            'capabilities'       => isset($body['capabilities']) && is_array($body['capabilities']) ? $body['capabilities'] : [],
        ];
        $agent_payload['capabilities']['wallet_type'] = $wallet_type;
        if ($wallet_type === self::WALLET_EVM) {
            $agent_payload['capabilities']['register_rail'] = 'base-usdc-x402';
            $agent_payload['capabilities']['register_caip2'] = Earnfi_Chain_Network_Catalog::BASE_CAIP2;
        } elseif ($wallet_type === self::WALLET_NEAR) {
            $agent_payload['capabilities']['register_rail'] = 'near-usdc-x402';
            $agent_payload['capabilities']['register_caip2'] = Earnfi_Chain_Network_Catalog::NEAR_CAIP2;
        } else {
            $agent_payload['capabilities']['register_rail'] = Earnfi_Payment_Rails::RAIL_SOLANA_USDC;
        }
        if (!empty($body['provision_source'])) {
            $agent_payload['capabilities']['provision_source'] = sanitize_key((string) $body['provision_source']);
        }

        if ($partner) {
            $agent_payload = EarnFi_Partner_Context::attach_to_agent_registration($agent_payload, $partner, $meta);
        } elseif ($meta !== []) {
            $agent_payload['capabilities']['partner_metadata'] = $meta;
        }

        $agent_id = $this->database->upsert_ai_agent($agent_payload);
        if (!$agent_id) {
            return new WP_Error('db_error', 'Failed to save agent profile', ['status' => 500]);
        }

        if (class_exists('GROWL_Public_Profile')) {
            $profile_patch = [];
            if (array_key_exists('x_username', $body)) {
                $profile_patch['x_username'] = $body['x_username'];
            }
            if (array_key_exists('x_user_id', $body)) {
                $profile_patch['x_user_id'] = $body['x_user_id'];
            }
            if (array_key_exists('avatar_url', $body)) {
                $profile_patch['avatar_url'] = $body['avatar_url'];
            }
            if (array_key_exists('bio', $body)) {
                $profile_patch['bio'] = $body['bio'];
            }
            if (array_key_exists('models', $body) && is_array($body['models'])) {
                $profile_patch['models'] = $body['models'];
            }
            if ($profile_patch !== []) {
                GROWL_Public_Profile::get_instance()->update_agent_profile($agent_id, $profile_patch);
            }
        }

        $provider = strtolower((string) ($agent_payload['identity_provider'] ?? ''));
        if (($provider === 'said' || !empty($body['import_said'])) && class_exists('EarnFi_Said_Identity') && $wallet_type === self::WALLET_SOLANA) {
            EarnFi_Said_Identity::get_instance()->import_into_agent_profile($wallet, $agent_id);
        }

        $token_plain = $this->base64url_encode(random_bytes(32));
        $token_hash  = hash('sha256', $token_plain);
        $token_insert_id = $this->database->insert('ai_agent_tokens', [
            'agent_id'       => $agent_id,
            'wallet_address' => $wallet,
            'token_hash'     => $token_hash,
            'status'         => 'active',
            'token_version'  => 1,
            'created_at'     => current_time('mysql'),
            'last_used_at'   => null,
            'revoked_at'     => null,
        ]);
        if ($token_insert_id === false) {
            return new WP_Error('db_error', 'Failed to issue agent_token', ['status' => 500]);
        }

        if ($partner && !empty($partner->partner_id)) {
            EarnFi_Partner_Report_Cache::bust_partner((int) $partner->partner_id);
        }

        $linked_partner_id = null;
        if ($partner && !empty($partner->partner_id)) {
            $linked_partner_id = (int) $partner->partner_id;
        } elseif ($existing_agent && !empty($existing_agent->partner_id)) {
            $linked_partner_id = (int) $existing_agent->partner_id;
        }

        $token_row = (object) [
            'id'             => (int) $token_insert_id,
            'agent_id'       => $agent_id,
            'wallet_address' => $wallet,
            'token_hash'     => $token_hash,
            'status'         => 'active',
            'agent_name'     => $agent_name,
        ];

        return [
            'agent_id'     => $agent_id,
            'agent_token'  => $token_plain,
            'wallet'       => $wallet,
            'wallet_type'  => $wallet_type,
            'agent_name'   => $agent_name,
            'updated'      => $is_update,
            'partner_id'   => $linked_partner_id,
            'token_row'    => $token_row,
        ];
    }

    /**
     * Complete registration after signature verify (caller verifies first).
     *
     * @param array<string,mixed> $body
     * @return WP_REST_Response|WP_Error
     */
    public function complete_after_verified(
        string $wallet,
        string $wallet_type,
        string $agent_name,
        array $body,
        WP_REST_Request $request
    ) {
        $provisioned = $this->provision_for_wallet($wallet, $wallet_type, $agent_name, $body, $request);
        if (is_wp_error($provisioned)) {
            return $provisioned;
        }

        return rest_ensure_response([
            'success'      => true,
            'agent_id'     => $provisioned['agent_id'],
            'wallet'       => $provisioned['wallet'],
            'wallet_type'  => $provisioned['wallet_type'],
            'agent_name'   => $provisioned['agent_name'],
            'agent_token'  => $provisioned['agent_token'],
            'updated'      => $provisioned['updated'],
            'partner_id'   => $provisioned['partner_id'],
            'hint'         => 'Store agent_token securely. It authorizes paid creates and creator controls.',
            'next_step'    => 'GET /catalog to list actions, then call a paid create such as /actions/ask or /jobs/social. The first create response is a payment quote (HTTP 402); retry with PAYMENT-SIGNATURE.',
        ]);
    }

    /**
     * Validate challenge nonce binding without consuming it.
     * Call {@see invalidate_challenge()} only after signature verification succeeds.
     *
     * @return array{message:string,wallet:string,agent_name:string,scheme:string,nonce:string}|WP_Error|null null = no nonce (legacy solana path)
     */
    public function consume_challenge_if_present(string $nonce_in, string $wallet, string $agent_name, string $message, string $expected_scheme) {
        $nonce_in = preg_replace('/[^a-f0-9]/i', '', $nonce_in);
        if ($nonce_in === '') {
            return null;
        }
        if (strlen($nonce_in) !== 32) {
            return new WP_Error('invalid_nonce', 'nonce must be the 32-character hex value from GET /register/challenge', ['status' => 400]);
        }
        $tk = 'earnfi_reg_ch_v1_' . $nonce_in;
        $row = get_transient($tk);
        if (!is_array($row)) {
            return new WP_Error(
                'invalid_nonce',
                'Unknown or expired registration challenge. Request a new challenge from GET /register/challenge.',
                ['status' => 400, 'hint' => 'Challenges expire after 10 minutes and are single-use after successful registration.']
            );
        }
        if (time() > (int) ($row['exp'] ?? 0)) {
            delete_transient($tk);
            return new WP_Error('challenge_expired', 'Registration challenge expired. Request a new challenge.', ['status' => 400]);
        }
        $row_scheme = (string) ($row['scheme'] ?? self::SCHEME_SOLANA);
        if ($row_scheme !== $expected_scheme) {
            // Do not burn — client may retry on the correct API base with the same nonce.
            return new WP_Error(
                'challenge_mismatch',
                'Registration challenge was issued for a different signature scheme. Request a new challenge on this API base.',
                ['status' => 400]
            );
        }
        if ((string) ($row['wallet'] ?? '') !== $wallet || (string) ($row['agent_name'] ?? '') !== $agent_name) {
            return new WP_Error(
                'challenge_mismatch',
                'wallet_address and agent_name must match the values used when the challenge was issued.',
                ['status' => 400]
            );
        }
        if ($message !== (string) ($row['message'] ?? '')) {
            return new WP_Error(
                'message_mismatch',
                'message must match the challenge response exactly (same line breaks and text).',
                ['status' => 400, 'hint' => 'Re-fetch GET /register/challenge and sign the message field without edits.']
            );
        }
        return [
            'message'    => (string) $row['message'],
            'wallet'     => (string) $row['wallet'],
            'agent_name' => (string) $row['agent_name'],
            'scheme'     => $row_scheme,
            'nonce'      => $nonce_in,
        ];
    }

    /** Invalidate a challenge after successful signature verification (single-use). */
    public function invalidate_challenge(string $nonce_in): void {
        $nonce_in = preg_replace('/[^a-f0-9]/i', '', $nonce_in);
        if ($nonce_in === '' || strlen($nonce_in) !== 32) {
            return;
        }
        delete_transient('earnfi_reg_ch_v1_' . $nonce_in);
    }

    private function base64url_encode($bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Rotate: revoke current token, mint a new one. Plaintext shown once.
     *
     * @param object $token_row Active token row from resolve_agent_from_token
     * @return array<string,mixed>|WP_Error
     */
    public function rotate_token($token_row) {
        $agent_id = (string) $token_row->agent_id;
        $wallet   = (string) $token_row->wallet_address;
        $this->revoke_token_row((int) $token_row->id);
        $version = isset($token_row->token_version) ? ((int) $token_row->token_version + 1) : 2;
        $token_plain = $this->base64url_encode(random_bytes(32));
        $token_hash  = hash('sha256', $token_plain);
        $id = $this->database->insert('ai_agent_tokens', [
            'agent_id'       => $agent_id,
            'wallet_address' => $wallet,
            'token_hash'     => $token_hash,
            'status'         => 'active',
            'token_version'  => $version,
            'created_at'     => current_time('mysql'),
            'last_used_at'   => null,
            'revoked_at'     => null,
        ]);
        if ($id === false) {
            return new WP_Error('db_error', 'Could not rotate token.', ['status' => 500]);
        }
        return [
            'agent_id'      => $agent_id,
            'agent_token'   => $token_plain,
            'token_version' => $version,
        ];
    }

    /**
     * @return true|WP_Error
     */
    public function revoke_token_row($token_id) {
        $ok = $this->database->update('ai_agent_tokens', [
            'status'     => 'revoked',
            'revoked_at' => current_time('mysql'),
        ], ['id' => (int) $token_id]);
        if ($ok === false) {
            return new WP_Error('db_error', 'Could not revoke token.', ['status' => 500]);
        }
        return true;
    }
}
