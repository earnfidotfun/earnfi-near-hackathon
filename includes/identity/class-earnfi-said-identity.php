<?php
/**
 * SAID Protocol identity lookup with transient cache (off-chain REST API).
 */

if (!defined('ABSPATH')) {
    exit;
}

class EarnFi_Said_Identity {

    private static $instance = null;
    private const API_BASE = 'https://api.saidprotocol.com';
    private const CACHE_TTL = 900;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Read transient/agent cache only — never hits SAID API (safe for list endpoints).
     *
     * @param string $wallet
     * @param object|null $agent_row
     * @return array|null
     */
    public function lookup_transient_only($wallet, $agent_row = null) {
        $wallet = trim((string) $wallet);
        if ($wallet === '') {
            return null;
        }

        if ($agent_row && isset($agent_row->said_verified) && (int) $agent_row->said_verified === 1) {
            return [
                'registered' => !empty($agent_row->said_registered),
                'verified'   => true,
                'wallet'     => $wallet,
            ];
        }

        if ($agent_row && !empty($agent_row->capabilities)) {
            $caps = json_decode((string) $agent_row->capabilities, true);
            if (is_array($caps) && !empty($caps['said']) && is_array($caps['said'])) {
                $said = $caps['said'];
                if (!empty($said['fetched_at']) && (time() - (int) $said['fetched_at']) < self::CACHE_TTL) {
                    return $said;
                }
            }
        }

        $tkey = 'earnfi_said_' . md5($wallet);
        $cached = get_transient($tkey);
        return is_array($cached) ? $cached : null;
    }

    /**
     * @param string[] $wallets
     * @param array<string, object> $agent_rows_by_wallet optional agent DB rows keyed by wallet
     * @return array<string, bool> wallet => verified
     */
    public function batch_verified_flags(array $wallets, array $agent_rows_by_wallet = []) {
        $out = [];
        foreach ($wallets as $wallet) {
            $wallet = trim((string) $wallet);
            if ($wallet === '') {
                continue;
            }
            $row = $agent_rows_by_wallet[$wallet]
                ?? $agent_rows_by_wallet[strtolower($wallet)]
                ?? null;
            $data = $this->lookup_transient_only($wallet, $row);
            $verified = is_array($data) && !empty($data['verified']);
            $out[$wallet] = $verified;
        }
        return $out;
    }

    /**
     * Read SAID verified flag from persisted agent capabilities (no TTL gate).
     *
     * @param object|null $agent_row
     * @return bool
     */
    public function verified_from_agent_row($agent_row) {
        if (!$agent_row) {
            return false;
        }
        if (isset($agent_row->said_verified)) {
            return (int) $agent_row->said_verified === 1;
        }
        $wallet = trim((string) ($agent_row->wallet_address ?? ''));
        $data = $this->lookup_transient_only($wallet, $agent_row);
        return is_array($data) && !empty($data['verified']);
    }

    /**
     * Whether SAID lists this wallet on /api/agents/:wallet (public profile exists).
     *
     * @param string $wallet
     * @return bool
     */
    public function agent_public_profile_exists($wallet) {
        $wallet = trim((string) $wallet);
        if ($wallet === '') {
            return false;
        }
        return $this->fetch_agent($wallet) !== null;
    }

    /**
     * Public SAID agent profile URL on saidprotocol.com.
     *
     * @param string $wallet
     * @return string
     */
    public static function public_profile_url($wallet) {
        $wallet = trim((string) $wallet);
        if ($wallet === '') {
            return '';
        }
        return 'https://www.saidprotocol.com/agents/' . rawurlencode($wallet);
    }

    /**
     * Agent website for public display — never the SAID identity profile URL.
     *
     * @param string $url
     * @return string
     */
    public static function sanitize_agent_website($url) {
        $url = esc_url_raw(trim((string) $url));
        if ($url === '') {
            return '';
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === 'saidprotocol.com' || $host === 'www.saidprotocol.com') {
            return '';
        }
        if (stripos($url, 'saidprotocol.com/agents/') !== false) {
            return '';
        }
        return $url;
    }

    /**
     * @param string $wallet
     * @param object|null $agent_row
     * @return array|null
     */
    public function lookup_cached($wallet, $agent_row = null) {
        $wallet = trim((string) $wallet);
        if ($wallet === '') {
            return null;
        }

        if ($agent_row && !empty($agent_row->capabilities)) {
            $caps = json_decode((string) $agent_row->capabilities, true);
            if (is_array($caps) && !empty($caps['said']) && is_array($caps['said'])) {
                $said = $caps['said'];
                if (!empty($said['fetched_at']) && (time() - (int) $said['fetched_at']) < self::CACHE_TTL) {
                    return $said;
                }
            }
        }

        $tkey = 'earnfi_said_' . md5($wallet);
        $cached = get_transient($tkey);
        if (is_array($cached)) {
            return $cached;
        }

        $fresh = $this->fetch_verify($wallet);
        if ($fresh) {
            $fresh['fetched_at'] = time();
            set_transient($tkey, $fresh, self::CACHE_TTL);
        }
        return $fresh;
    }

    /**
     * Force refresh from SAID API (verify + agent + passport).
     *
     * @param string $wallet
     * @return array|null
     */
    public function refresh_from_api($wallet) {
        $wallet = trim((string) $wallet);
        if ($wallet === '') {
            return null;
        }

        $data = $this->fetch_verify($wallet);
        if (!$data) {
            return null;
        }

        $agent = $this->fetch_agent($wallet);
        if ($agent) {
            $data = array_merge($data, $agent);
        }

        $passport = $this->fetch_passport($wallet);
        if ($passport) {
            $data = array_merge($data, $passport);
        }

        $data['fetched_at'] = time();
        set_transient('earnfi_said_' . md5($wallet), $data, self::CACHE_TTL);
        return $data;
    }

    /**
     * Normalize SAID reputation to basis points (0–10000).
     *
     * @param mixed $raw
     * @return int
     */
    public function normalize_reputation_score($raw) {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (is_array($raw) && isset($raw['score'])) {
            $raw = $raw['score'];
        }
        if (!is_numeric($raw)) {
            return 0;
        }
        $val = (float) $raw;
        if ($val <= 0) {
            return 0;
        }
        if ($val <= 100) {
            return (int) round($val * 100);
        }
        return (int) round($val);
    }

    /**
     * @param string $wallet
     * @return array|null
     */
    private function fetch_verify($wallet) {
        $url = self::API_BASE . '/api/verify/' . rawurlencode($wallet);
        $body = $this->api_get_json($url);
        if (!$body) {
            return null;
        }

        $rep = $this->normalize_reputation_score(
            $body['reputation']['score'] ?? $body['reputation_score'] ?? $body['reputationScore'] ?? $body['score'] ?? 0
        );

        return [
            'registered'        => !empty($body['registered']),
            'verified'          => !empty($body['verified']) || !empty($body['isVerified']),
            'name'              => (string) ($body['name'] ?? ''),
            'bio'               => (string) ($body['bio'] ?? $body['description'] ?? ''),
            'avatar_url'        => (string) ($body['avatar'] ?? $body['avatar_url'] ?? $body['image'] ?? ''),
            'reputation_score'  => $rep,
            'passport_mint'     => (string) ($body['passportMint'] ?? $body['passport_mint'] ?? ''),
        ];
    }

    /**
     * @param string $wallet
     * @return array|null
     */
    private function fetch_agent($wallet) {
        $url = self::API_BASE . '/api/agents/' . rawurlencode($wallet);
        $body = $this->api_get_json($url);
        if (!$body) {
            return null;
        }

        $agent = is_array($body['agent'] ?? null) ? $body['agent'] : $body;
        if (!is_array($agent)) {
            return null;
        }

        $rep = $this->normalize_reputation_score(
            $agent['reputationScore'] ?? $agent['reputation_score'] ?? $agent['reputation']['score'] ?? $agent['score'] ?? null
        );

        $out = [];
        if ($rep > 0) {
            $out['reputation_score'] = $rep;
        }
        if (!empty($agent['name'])) {
            $out['name'] = (string) $agent['name'];
        }
        if (!empty($agent['description']) || !empty($agent['bio'])) {
            $out['bio'] = (string) ($agent['bio'] ?? $agent['description']);
        }
        if (!empty($agent['avatar']) || !empty($agent['avatar_url']) || !empty($agent['image'])) {
            $out['avatar_url'] = (string) ($agent['avatar'] ?? $agent['avatar_url'] ?? $agent['image']);
        }
        if (!empty($agent['isVerified']) || !empty($agent['verified'])) {
            $out['verified'] = true;
        }
        if (!empty($agent['registered'])) {
            $out['registered'] = true;
        }
        if (!empty($agent['passportMint']) || !empty($agent['passport_mint'])) {
            $out['passport_mint'] = (string) ($agent['passportMint'] ?? $agent['passport_mint']);
        }

        return $out ?: null;
    }

    /**
     * @param string $wallet
     * @return array|null
     */
    private function fetch_passport($wallet) {
        $url = self::API_BASE . '/api/agents/' . rawurlencode($wallet) . '/passport';
        $body = $this->api_get_json($url);
        if (!$body) {
            return null;
        }

        $passport = is_array($body['passport'] ?? null) ? $body['passport'] : $body;
        if (!is_array($passport)) {
            return null;
        }

        $out = [
            'has_passport' => !empty($passport['hasPassport']) || !empty($passport['mint']),
        ];
        if (!empty($passport['mint'])) {
            $out['passport_mint'] = (string) $passport['mint'];
        }
        if (!empty($passport['image'])) {
            $out['passport_image'] = esc_url_raw((string) $passport['image']) ?: '';
        }
        if (!empty($passport['mintedAt'])) {
            $out['passport_minted_at'] = (string) $passport['mintedAt'];
        }
        if (!empty($passport['txHash'])) {
            $out['passport_tx_hash'] = (string) $passport['txHash'];
        }

        return $out;
    }

    /**
     * @param string $url
     * @return array|null
     */
    private function api_get_json($url) {
        $res = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($res)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($body) ? $body : null;
    }

    /**
     * Import SAID identity fields into an agent row (register / import-identity).
     *
     * @param string $wallet
     * @param string $agent_id
     * @return array|null
     */
    public function import_into_agent_profile($wallet, $agent_id) {
        $wallet = trim((string) $wallet);
        $agent_id = sanitize_text_field((string) $agent_id);
        if ($wallet === '' || $agent_id === '') {
            return null;
        }

        $data = $this->refresh_from_api($wallet);
        if (!$data || empty($data['registered'])) {
            return null;
        }

        if (!class_exists('GROWL_Database')) {
            return $data;
        }

        $db = new GROWL_Database();
        $p = $db->table_prefix;
        $row = $db->get_row(
            'SELECT ' . GROWL_Database::SQL_AI_AGENT_ID_WALLET . " FROM {$p}ai_agents WHERE agent_id = %s LIMIT 1",
            [$agent_id]
        );
        if (!$row) {
            return $data;
        }

        $upd = ['profile_updated_at' => current_time('mysql')];
        if (!empty($data['name']) && trim((string) ($row->agent_name ?? '')) === '') {
            $upd['agent_name'] = mb_substr(sanitize_text_field($data['name']), 0, 120);
        }
        if (!empty($data['bio']) && trim((string) ($row->bio ?? '')) === '') {
            $upd['bio'] = mb_substr(sanitize_textarea_field($data['bio']), 0, 500);
        }
        if (!empty($data['avatar_url']) || !empty($data['passport_image'])) {
            require_once GROWL_PLUGIN_PATH . 'includes/class-avatar-service.php';
            $avatars = GROWL_Avatar_Service::get_instance();
            if (!$avatars->agent_has_local_avatar($agent_id)) {
                $remote = trim((string) ($data['avatar_url'] ?? ''));
                if ($remote === '') {
                    $remote = trim((string) ($data['passport_image'] ?? ''));
                }
                if ($remote !== '') {
                    $saved = $avatars->save_agent_avatar_from_url($agent_id, $remote);
                    if (!is_wp_error($saved) && is_string($saved) && $saved !== '') {
                        $upd['avatar_url'] = $saved;
                    }
                }
            }
        }

        $caps = [];
        if (!empty($row->capabilities)) {
            $decoded = json_decode((string) $row->capabilities, true);
            if (is_array($decoded)) {
                $caps = $decoded;
            }
        }
        $caps['said'] = $data;
        $upd['capabilities'] = wp_json_encode($caps);
        $upd['said_verified'] = !empty($data['verified']) ? 1 : 0;
        $upd['said_registered'] = !empty($data['registered']) ? 1 : 0;
        if (!empty($data['verified']) || !empty($data['registered'])) {
            $upd['identity_provider'] = 'said';
            $upd['identity_reference'] = $wallet;
        }

        $db->update('ai_agents', $upd, ['agent_id' => $agent_id]);
        set_transient('earnfi_said_' . md5($wallet), $data, self::CACHE_TTL);

        if (class_exists('GROWL_Public_Profile')) {
            GROWL_Public_Profile::get_instance()->bust_public_cache_for_identifier($agent_id);
        }

        return $data;
    }

    /**
     * Build normalized SAID summary from cached row data.
     *
     * @param array|null $data
     * @return array|null
     */
    public function format_trust_payload($data) {
        if (!$data || empty($data['registered'])) {
            return null;
        }
        $score = isset($data['reputation_score']) ? (int) $data['reputation_score'] : 0;
        return [
            'registered'         => !empty($data['registered']),
            'verified'           => !empty($data['verified']),
            'reputation_score'   => $score,
            'display_score'      => $score > 0 ? (int) round($score / 100) : null,
            'name'               => (string) ($data['name'] ?? ''),
            'passport_mint'      => (string) ($data['passport_mint'] ?? ''),
            'has_passport'       => !empty($data['has_passport']) || !empty($data['passport_mint']),
            'passport_image'     => (string) ($data['passport_image'] ?? ''),
            'last_synced'        => !empty($data['fetched_at']) ? (int) $data['fetched_at'] : null,
            'profile_url'        => (!empty($data['verified']) && !empty($data['wallet']))
                ? self::public_profile_url((string) $data['wallet'])
                : '',
            'provider'           => 'said',
            'provider_label'     => 'SAID Protocol',
        ];
    }

    /**
     * Solana explorer URL for a passport / token mint.
     *
     * @param string $mint
     * @return string
     */
    public static function solscan_token_url($mint) {
        $mint = trim((string) $mint);
        if ($mint === '') {
            return '';
        }
        return 'https://solscan.io/token/' . rawurlencode($mint);
    }

    /**
     * Submit work outcome feedback to SAID (agent workers only).
     *
     * SAID docs: POST /api/agents/:wallet/feedback requires a wallet signature from
     * the party submitting feedback (the rater — typically the job creator), not an API key.
     * See https://www.saidprotocol.com/docs (Reputation + API Reference).
     *
     * @param string $worker_wallet Agent wallet being rated.
     * @param string $task_id
     * @param int    $completion_id
     * @param string $outcome positive|negative
     * @param array  $auth signer_wallet, message, signature (required to call SAID API)
     * @return array{ok:bool,skipped?:bool,reason?:string,status?:int,body?:mixed}
     */
    public function submit_job_feedback($worker_wallet, $task_id, $completion_id, $outcome = 'positive', array $auth = []) {
        $worker_wallet = trim((string) $worker_wallet);
        if ($worker_wallet === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_worker_wallet'];
        }

        $signer_wallet = trim((string) ($auth['signer_wallet'] ?? ''));
        $message = trim((string) ($auth['message'] ?? ''));
        $signature = $auth['signature'] ?? null;
        if ($signer_wallet === '' || $message === '' || $signature === null || $signature === '') {
            return [
                'ok'      => false,
                'skipped' => true,
                'reason'  => 'wallet_signature_required',
                'status'  => 0,
            ];
        }

        if (is_string($signature)) {
            $signature = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', trim($signature)))));
        }
        if (!is_array($signature) || empty($signature)) {
            return [
                'ok'      => false,
                'skipped' => true,
                'reason'  => 'invalid_signature',
                'status'  => 0,
            ];
        }

        $positive = $outcome !== 'negative';
        $url = self::API_BASE . '/api/agents/' . rawurlencode($worker_wallet) . '/feedback';
        $payload = [
            'positive'      => $positive,
            'rating'        => $positive ? 5 : 1,
            'context'       => $positive
                ? 'Task completed successfully on EarnFi (task ' . (string) $task_id . ')'
                : 'Task completion rejected on EarnFi (task ' . (string) $task_id . ')',
            'source'        => 'earnfi',
            'task_id'       => (string) $task_id,
            'completion_id' => (int) $completion_id,
            'outcome'       => $outcome,
            'wallet'        => $signer_wallet,
            'signer_wallet' => $signer_wallet,
            'message'       => $message,
            'signature'     => $signature,
        ];

        $res = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
        ]);

        $status = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $body = is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_body($res);
        $ok = !is_wp_error($res) && $status >= 200 && $status < 300;

        do_action('earnfi_said_feedback_submitted', [
            'worker_wallet' => $worker_wallet,
            'signer_wallet' => $signer_wallet,
            'task_id'       => $task_id,
            'completion_id' => $completion_id,
            'outcome'       => $outcome,
            'ok'            => $ok,
            'status'        => $status,
            'response'      => $body,
        ]);

        return ['ok' => $ok, 'status' => $status, 'body' => $body];
    }
}
