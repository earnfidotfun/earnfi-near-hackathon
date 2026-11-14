<?php
/**
 * Shared register/challenge handlers for Base and NEAR agent API aliases.
 */
if (!defined('ABSPATH')) {
    exit;
}

trait Earnfi_Agent_Rail_Register_Mixin {

    /** @var Earnfi_Agent_Register */
    private $agent_register;

    private function init_agent_register(): void {
        if (!isset($this->agent_register)) {
            $this->agent_register = new Earnfi_Agent_Register(new GROWL_Database(), new GROWL_Security());
        }
    }

    private function merge_json_body_into_request(WP_REST_Request $request): void {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return;
        }
        foreach ($body as $key => $value) {
            if (is_string($key) && $key !== '' && $request->get_param($key) === null) {
                $request->set_param($key, $value);
            }
        }
    }

    public function get_register_challenge(WP_REST_Request $request) {
        $this->init_agent_register();
        $scheme = $this->register_scheme();
        $wallet = trim((string) $request->get_param('wallet_address'));
        $agent_name = (string) $request->get_param('agent_name');
        $resp = $this->agent_register->issue_challenge($wallet, $agent_name, $scheme);
        if ($resp instanceof WP_REST_Response) {
            $resp->header('X-EarnFi-Register-Scheme', $scheme);
        }
        return $resp;
    }

    public function register_agent(WP_REST_Request $request) {
        $this->init_agent_register();
        $this->merge_json_body_into_request($request);
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }
        $scheme = $this->register_scheme();
        $wallet_raw = trim((string) ($body['wallet_address'] ?? $request->get_param('wallet_address') ?? ''));
        $agent_name = sanitize_text_field((string) ($body['agent_name'] ?? $request->get_param('agent_name') ?? ''));
        $message = (string) ($body['message'] ?? $request->get_param('message') ?? '');
        $nonce_in = (string) ($body['nonce'] ?? $request->get_param('nonce') ?? '');
        $signature_raw = $body['signature'] ?? $request->get_param('signature');

        $san = $this->agent_register->sanitize_for_scheme($wallet_raw, $scheme);
        if (is_wp_error($san)) {
            return $san;
        }
        $wallet = $san['wallet'];
        $wallet_type = $san['wallet_type'];

        if ($agent_name === '' || $message === '' || $signature_raw === null || $signature_raw === '') {
            return new WP_Error('invalid_params', 'wallet_address, agent_name, message, nonce, and signature are required', ['status' => 400]);
        }
        if (trim($nonce_in) === '') {
            return new WP_Error('invalid_params', 'nonce is required — call GET /register/challenge first', ['status' => 400]);
        }

        $chal = $this->agent_register->consume_challenge_if_present($nonce_in, $wallet, $agent_name, $message, $scheme);
        if ($chal === null || is_wp_error($chal)) {
            return is_wp_error($chal) ? $chal : new WP_Error('invalid_nonce', 'Invalid registration challenge', ['status' => 400]);
        }

        if ($scheme === Earnfi_Agent_Register::SCHEME_EVM) {
            if (!Earnfi_Evm_Personal_Sign::verify($message, is_string($signature_raw) || is_array($signature_raw) ? $signature_raw : '', $wallet)) {
                return new WP_Error(
                    'invalid_signature',
                    'EVM personal_sign verification failed',
                    [
                        'status' => 401,
                        'hint'   => 'Use ethers Wallet.signMessage(challenge.message) or viem personalSign. If hand-rolling EIP-191, prefix with UTF-8 byte length (message_utf8_bytes / Buffer.byteLength), not JavaScript string.length. Sign the exact challenge message; fetch a fresh challenge after each failed attempt.',
                    ]
                );
            }
        } elseif ($scheme === Earnfi_Agent_Register::SCHEME_NEAR) {
            $nep = is_array($body['nep413'] ?? null) ? $body['nep413'] : $body;
            $nonce_hex = (string) ($nep['nonce_hex'] ?? '');
            if ($nonce_hex === '' && is_array($chal) && !empty($chal['nonce'])) {
                $row = get_transient('earnfi_reg_ch_v1_' . preg_replace('/[^a-f0-9]/i', '', (string) $chal['nonce']));
                if (is_array($row) && !empty($row['nep413_nonce_hex'])) {
                    $nonce_hex = (string) $row['nep413_nonce_hex'];
                }
            }
            $nonce_raw = strlen($nonce_hex) === 64 ? hex2bin($nonce_hex) : false;
            if ($nonce_raw === false) {
                return new WP_Error('invalid_nonce', 'nep413 nonce_hex (64 hex chars) required', ['status' => 400]);
            }
            $recipient = (string) ($nep['recipient'] ?? parse_url(home_url(), PHP_URL_HOST));
            $account_id = (string) ($nep['account_id'] ?? $wallet);
            $public_key = (string) ($nep['public_key'] ?? '');
            $signature = is_string($signature_raw) ? $signature_raw : (string) ($nep['signature'] ?? '');
            if (!Earnfi_Nep413_Verify::verify($message, $recipient, $nonce_raw, $account_id, $public_key, $signature, home_url('/'))) {
                return new WP_Error('invalid_signature', 'NEP-413 verification failed', ['status' => 401]);
            }
        }

        $this->agent_register->invalidate_challenge($nonce_in);
        $body['identity_provider'] = $body['identity_provider'] ?? ($scheme === Earnfi_Agent_Register::SCHEME_NEAR ? 'near_wallet' : 'base_wallet');
        return $this->agent_register->complete_after_verified($wallet, $wallet_type, $agent_name, $body, $request);
    }

    /** @return string Earnfi_Agent_Register::SCHEME_* */
    abstract protected function register_scheme(): string;
}
