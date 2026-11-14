<?php
// /wp-content/plugins/growl-fun/includes/ai-agent/class-agent-rest-api.php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Earnfi_Agent_Register', false)) {
    require_once dirname(__FILE__) . '/class-earnfi-agent-register.php';
}

/**
 * EarnFi AI Agent API — namespace `ai-agent/v1` (canonical multichain x402 v2 surface).
 *
 * Solana-first `accepts[]` may include Base (`eip155:8453`) and NEAR (`near:mainnet`) when admin rails are live.
 * `ai-agent/v2` is a compatibility alias that delegates here. OKX X Layer remains `ai-agent/okx/v1`.
 *
 * Agent-first: standard x402 (402 → PAYMENT-SIGNATURE → 200) with no Work API keys.
 * Registration issues a long-lived agent_token (hashed at rest). Jobs return a per-job secret for stateless polling.
 *
 * **Human Interrupt** (`/interrupt`) is a distinct `job_type` / `action_type` (`interrupt`), not a social quick-task slug;
 * it uses the same pricing estimator as a like-job for quoting but creates `interrupt` rows + task on payment.
 *
 * Optional **`GET|POST /x402`** returns 402 JSON as probing `/jobs/social` (discovery convenience;
 *
 * Paid creates and token-gated quotes require a valid agent_token from POST /register; eligibility for enabling
 * token_gate is checked against the wallet stored on that agent registration (not the payment-signing wallet).
 */
class Earnfi_Agent_REST_API {
    private $namespace = 'ai-agent/v1';

    /** Shipped on registration errors as REST `data.hint`. */
    private const REGISTRATION_SIGNATURE_HINT = 'Send signature as a JSON array of 64 integers (0-255), or as a base58, hex (128 chars), or standard base64 string encoding exactly 64 raw Ed25519 signature bytes. The message bytes must match what was signed exactly.';

    private const RE_TASK_ID = '/^[a-zA-Z0-9]{1,12}$/';
    private const RE_INTERRUPT_ID = '/^EFI[a-fA-F0-9]{6}$/';
    private const RE_X_PROFILE_URL = '#(?:twitter\.com|x\.com)/([A-Za-z0-9_]{1,15})(?:/|$)#i';
    private const RE_HEX_128 = '/^[0-9a-fA-F]{128}$/';
    private const RE_HUMAN_ACTIONS_PROBE = '#/(human-actions|actions)/?$#';

    /** @var GROWL_Database */
    private $database;

    /** @var GROWL_Security */
    private $security;

    /** @var Earnfi_Agent_Register */
    private $agent_register;

    public function __construct() {
        $this->database = new GROWL_Database();
        $this->security = new GROWL_Security();
        $this->agent_register = new Earnfi_Agent_Register($this->database, $this->security);
    }

    public function register_routes() {
        register_rest_route($this->namespace, '/x402', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'get_x402_descriptor'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/x402/capabilities', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_x402_capabilities'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/info', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_api_info'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/catalog', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'get_agent_catalog'],
            'permission_callback' => '__return_true',
        ]);

        // Multi-rail discovery (Solana default + OKX X Layer). Additive; does not change paid create behavior.
        register_rest_route($this->namespace, '/rails', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'get_payment_rails'],
            'permission_callback' => '__return_true',
        ]);

        // Register/rotate: returns agent_id + agent_token (token shown once).
        register_rest_route($this->namespace, '/register', [
            'methods'             => 'POST',
            'callback'            => [$this, 'register_agent_v2'],
            'permission_callback' => '__return_true',
        ]);

        // Canonical UTF-8 message to sign (recommended for autonomous clients).
        register_rest_route($this->namespace, '/register/challenge', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'get_register_challenge'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/token/rotate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rotate_agent_token'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/token/revoke', [
            'methods'             => 'POST',
            'callback'            => [$this, 'revoke_agent_token'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/jobs/(?P<id>[A-Za-z0-9]{6,12})/claim', [
            'methods'             => 'POST',
            'callback'            => [$this, 'claim_job_as_agent'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/jobs/(?P<id>[A-Za-z0-9]{6,12})/submit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'submit_job_as_agent'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/providers/services', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'provider_upsert_service'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'provider_list_services'],
                'permission_callback' => '__return_true',
            ],
        ]);
        register_rest_route($this->namespace, '/providers/services/(?P<id>\d+)/status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'provider_set_service_status'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/agents/orders', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_create_order'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+|\{id\}|999999999)/deliver', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_deliver_order'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+|\{id\}|999999999)/fund', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_fund_order'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+|\{id\}|999999999)/release', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_release_order'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+|\{id\}|999999999)/revision', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_revise_order'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+|\{id\}|999999999)/dispute', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_dispute_order'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/deals', [
            ['methods' => 'GET', 'callback' => [$this, 'agent_list_deals'], 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [$this, 'agent_create_deal'], 'permission_callback' => '__return_true'],
        ]);
        register_rest_route($this->namespace, '/agents/deals/(?P<slug>[a-z0-9]{8,16})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'agent_get_deal'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/deals/(?P<id>\d+|\{id\}|999999999)/accept', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_accept_deal'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/deals/(?P<id>\d+|\{id\}|999999999)/fund', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_fund_deal'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/deals/(?P<id>\d+|\{id\}|999999999)/deliver', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_deliver_deal'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/deals/(?P<id>\d+|\{id\}|999999999)/release', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_release_deal'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents', [
            'methods'             => 'GET',
            'callback'            => [$this, 'agent_services_catalog'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/capabilities', [
            'methods'             => 'GET',
            'callback'            => [$this, 'agent_list_capabilities'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/agents/catalog', [
            'methods'             => 'GET',
            'callback'            => [$this, 'agent_services_catalog'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/profile', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_agent_profile'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => ['PATCH', 'POST'],
                'callback'            => [$this, 'patch_agent_profile'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route($this->namespace, '/profile/import-identity', [
            'methods'             => 'POST',
            'callback'            => [$this, 'import_agent_identity'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/profile/sync-said', [
            'methods'             => 'POST',
            'callback'            => [$this, 'import_agent_identity'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/x-verification/generate-code', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_generate_x_verification_code'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/x-verification/verify', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_verify_x_post'],
            'permission_callback' => '__return_true',
        ]);

        // Paid social job create (x402) - GET (query) or POST (JSON) with identical semantics.
        register_rest_route($this->namespace, '/jobs/social', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_create_social_job'],
            'permission_callback' => '__return_true',
        ]);

        // Paid manual/custom job create (x402) - GET or POST.
        register_rest_route($this->namespace, '/jobs/manual', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_create_manual_job'],
            'permission_callback' => '__return_true',
        ]);

        // Paid contest create (x402) - GET or POST.
        register_rest_route($this->namespace, '/jobs/contest', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_create_contest_job'],
            'permission_callback' => '__return_true',
        ]);

        // Paid Human Interrupt (x402) - GET or POST.
        register_rest_route($this->namespace, '/interrupt', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_create_interrupt'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/interrupt/(?P<id>EFI[a-fA-F0-9]{6})', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_get_interrupt'],
            'permission_callback' => '__return_true',
        ]);

        // Human Actions are the agent-first contract for requesting human judgment or work.
        // Existing interrupt and manual handlers remain the execution adapters.
        foreach (['actions', 'human-actions'] as $action_base) {
            register_rest_route($this->namespace, '/' . $action_base, [
                'methods'             => ['GET', 'POST'],
                'callback'            => [$this, 'agent_v2_create_human_action'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route($this->namespace, '/' . $action_base . '/(?P<action_type>ask|review|vote|test|research|verify|moderate|feedback|\{action_type\})', [
                'methods'             => ['GET', 'POST'],
                'callback'            => [$this, 'agent_v2_create_human_action'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route($this->namespace, '/' . $action_base . '/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/result', [
                'methods'             => ['GET', 'POST'],
                'callback'            => [$this, 'agent_v2_get_human_action_result'],
                'permission_callback' => '__return_true',
            ]);
        }

        // Polling routes: GET or POST (POST body may carry secret/agent_token for scanners and JSON clients).
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_get_job'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/submissions', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_list_job_submissions'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/completions', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_list_job_completions'],
            'permission_callback' => '__return_true',
        ]);

        // Toggle pause (creator control) - requires agent_token (not secret).
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/pause', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_pause_job'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/close', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_close_job'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/metadata', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'agent_v2_update_job_metadata'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/hire-listings', [
            'methods'             => 'POST',
            'callback'            => [$this, 'agent_v2_create_hire_listing'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/hire-listings/(?P<id>\d+|\{id\}|999999999)', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'agent_v2_update_hire_listing'],
            'permission_callback' => '__return_true',
        ]);

        // Manual verifications (creator control) - requires agent_token. GET or POST.
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/verifications', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_list_job_verifications'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/verifications/(?P<id>\\d+)/approve', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_approve_verification'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/verifications/(?P<id>\\d+)/reject', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_reject_verification'],
            'permission_callback' => '__return_true',
        ]);

        // Contest creator views/actions - requires agent_token. GET or POST.
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/contest/submissions', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_list_contest_submissions'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/contest/mark-winner', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_mark_contest_winner'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/contest/leaderboard', [
            'methods'             => 'GET',
            'callback'            => [$this, 'agent_v2_contest_leaderboard'],
            'permission_callback' => '__return_true',
        ]);

        // Creator job detail / users / payments - requires agent_token. GET or POST.
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/detail', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_get_creator_job_detail'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/users', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_get_creator_job_users'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/jobs/(?P<id>[a-zA-Z0-9]{1,12}|\{id\})/payments', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_get_creator_job_payments'],
            'permission_callback' => '__return_true',
        ]);

        // Agent-scoped reporting (agent_token).
        register_rest_route($this->namespace, '/agents/(?P<agent_id>[A-Za-z0-9_-]+)/jobs', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_list_agent_jobs'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/agents/(?P<agent_id>[A-Za-z0-9_-]+)/usage', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'agent_v2_agent_usage'],
            'permission_callback' => '__return_true',
        ]);

        // Partner reporting (X-EarnFi-Partner-Key).
        register_rest_route($this->namespace, '/partner/usage', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'partner_report_usage'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/partner/agents', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'partner_report_agents'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/partner/agents/lookup/usage', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'partner_report_lookup_usage'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/partner/agents/lookup/jobs', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'partner_report_lookup_jobs'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/partner/agents/link', [
            'methods'             => ['POST'],
            'callback'            => [$this, 'partner_link_agent'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/partner/agents/(?P<agent_id>[A-Za-z0-9_-]+)/usage', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'partner_report_agent_usage'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/partner/agents/(?P<agent_id>[A-Za-z0-9_-]+)/jobs', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'partner_report_agent_jobs'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function get_x402_descriptor(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $resource_url = untrailingslashit(rest_url($this->namespace . '/jobs/social'));
        $rd =
            'Preview how paid social and quick jobs are priced on EarnFi. This discovery entry uses the same payment terms as the main â€œcreate social jobâ€ URL for this host. ' .
            'Use it in registries to find job types and quotes; when you are ready to fund a campaign, open the same create URL with a completed on-chain payment.';
        $settings = get_option('growl_settings', []);
        $decimals = intval($settings['usdc_decimals'] ?? 6);
        $example_atomic = (string) bcpow('10', (string) $decimals, 0);
        $filter = class_exists('Earnfi_X402_Capabilities', false)
            ? Earnfi_X402_Capabilities::accepts_filter_from_request($request)
            : '';
        $accepts = Earnfi_X402_Priced_Quote::build_accepts(
            $resource_url,
            $example_atomic,
            $rd,
            $filter !== '' ? $filter : null
        );
        if (is_wp_error($accepts)) {
            return $accepts;
        }
        $w          = new Earnfi_Work_REST_API();
        $resource   = Earnfi_X402_Priced_Quote::resource_meta($resource_url, $rd);
        $extensions = Earnfi_X402_Bazaar::extensions(
            $request->get_method(),
            $w->get_social_x402_extensions_schema(),
            ['note' => 'Use …/jobs/social for the live quote and payment.']
        );
        $x402 = Earnfi_X402_Bazaar::payment_required_body($resource, $accepts, $extensions);
        $this->agent_send_402_quote($x402, null);
        return new WP_REST_Response($this->agent_attach_quote_guidance([
            'payment_required' => true,
            'x402Version'      => 2,
            'accepts'          => $x402['accepts'],
            'resource'         => $x402['resource'],
            'extensions'       => $x402['extensions'],
            'x402'             => $x402,
        ]), 402);
    }

    public function get_agent_catalog(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $work = new Earnfi_Work_REST_API();
        $catalog = $work->get_work_catalog($request);
        if (is_wp_error($catalog)) {
            return $catalog;
        }
        $data = $catalog instanceof WP_REST_Response ? $catalog->get_data() : $catalog;
        if (!is_array($data)) {
            $data = [];
        }
        // Additive discovery only - existing clients ignore unknown keys.
        if (class_exists('Earnfi_Payment_Rails')) {
            $home = untrailingslashit(home_url('/'));
            $data['payment_rail'] = Earnfi_Payment_Rails::RAIL_SOLANA_USDC;
            $data['payment_rails_url'] = untrailingslashit(rest_url($this->namespace)) . '/rails';
            $data['okx_rail_api'] = $home . '/api/ai-agent/okx/v1';
        }
        $data['hint'] = Earnfi_Agent_Integrator_Copy::catalog_discovery_hint();
        $data['next_step'] = Earnfi_Agent_Integrator_Copy::catalog_discovery_next_step()
            . ' Try POST /actions/ask, /interrupt, /jobs/social, /jobs/manual, or /jobs/contest.';
        if (class_exists('GROWL_Equity_Guard_Agent_API', false)) {
            $home = untrailingslashit(home_url('/'));
            $data['equity_guard'] = [
                'name' => 'Equity Guard',
                'description' => 'Tokenized equity safety — verify, simulate, ALLOW/WARN/BLOCK before sign.',
                'api_base' => $home . '/api/ai-agent/v1/equity',
                'growl_base' => $home . '/api/growl/v1/equity-guard',
                'ui' => $home . '/equity-guard',
                'endpoints' => [
                    'GET /equity/glossary',
                    'POST /equity/trades/check',
                    'GET /equity/portfolio?wallet=',
                    'GET /equity/assets/search',
                    'GET /equity/corporate-actions',
                ],
                'glossary' => $home . '/api/ai-agent/v1/equity/glossary',
            ];
        }
        return rest_ensure_response($data);
    }

    /**
     * Payment rail registry (Solana USDC default + OKX X Layer USDT0).
     */
    public function get_payment_rails(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        if (!class_exists('Earnfi_Payment_Rails')) {
            return new WP_Error('rails_unavailable', 'Payment rails registry is not loaded', ['status' => 500]);
        }
        $resp = rest_ensure_response(Earnfi_Payment_Rails::catalog());
        $resp->header('Cache-Control', 'public, max-age=60');
        $resp->header('X-EarnFi-Payment-Rail', Earnfi_Payment_Rails::RAIL_SOLANA_USDC);
        return $resp;
    }

    private function base64url_encode($bytes) {
        $b64 = base64_encode($bytes);
        return rtrim(strtr($b64, '+/', '-_'), '=');
    }

    private function now_mysql() {
        return current_time('mysql');
    }

    /**
     * Merge JSON (or raw JSON body) into request params for POST/PUT/PATCH so paid routes match GET query behavior
     * (x402 scanners and clients that POST the same fields as query).
     */
    private function merge_json_body_into_request(WP_REST_Request $request) {
        $m = $request->get_method();
        if (!in_array($m, ['POST', 'PUT', 'PATCH'], true)) {
            return;
        }
        $json = $request->get_json_params();
        if (!is_array($json)) {
            $raw = $request->get_body();
            if (is_string($raw) && $raw !== '') {
                $dec = json_decode($raw, true);
                $json = is_array($dec) ? $dec : null;
            }
        }
        if (!is_array($json)) {
            return;
        }
        foreach ($json as $key => $value) {
            if ($request->get_param($key) === null) {
                $request->set_param($key, $value);
            }
        }
    }

    /**
     * Optional merge for read routes so POST with JSON body { "secret": "..." } works like ?secret=.
     */
    private function merge_json_body_into_request_for_reads(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
    }

    /**
     * Paid create routes: match GET (query) and POST (JSON) parameter names.
     */
    private function merge_json_and_get_for_paid_creates(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        // Always fold query params in so GET quotes and POST settles with ?partner_source=…
        // (or other attribution fields) resolve the same way.
        $q = $request->get_query_params();
        if (!is_array($q)) {
            return;
        }
        $skip = [ 'rest_route' => 1, '_embed' => 1, 'context' => 1 ];
        foreach ($q as $key => $value) {
            if (isset($skip[$key]) || (is_string($key) && $key !== '' && $key[0] === '_')) {
                continue;
            }
            if ($request->get_param($key) === null) {
                $request->set_param($key, $value);
            }
        }
    }

    /**
     * @return string|WP_Error
     */
    private function agent_execution_mode_from_request(WP_REST_Request $request, $default = 'human') {
        $raw = $request->get_param('execution_mode');
        if ($raw === null || $raw === '') {
            return $default;
        }
        $ex = earnfi_normalize_execution_mode_for_create((string) $raw);
        if (empty($ex['ok'])) {
            return new WP_Error(
                isset($ex['error']) ? (string) $ex['error'] : 'invalid_execution_mode',
                isset($ex['message']) ? (string) $ex['message'] : 'Use human, agent, or hybrid.',
                ['status' => isset($ex['status']) ? (int) $ex['status'] : 400]
            );
        }
        return (string) $ex['mode'];
    }

    private function ensure_user_id_for_wallet($wallet_address) {
        $wallet = is_string($wallet_address) ? trim($wallet_address) : '';
        if ($wallet === '') {
            return new WP_Error('invalid_wallet', 'wallet address is required', ['status' => 400]);
        }
        $u = $this->database->get_user_by_wallet($wallet, false);
        if ($u && !empty($u->user_id)) {
            return (int) $u->user_id;
        }
        $wallet_type = Earnfi_Agent_Register::is_evm_wallet($wallet)
            ? Earnfi_Agent_Register::WALLET_EVM
            : Earnfi_Agent_Register::WALLET_SOLANA;
        $ins = $this->database->insert('users', [
            'wallet_address'      => $wallet,
            'wallet_type'         => $wallet_type,
            'user_rank'           => 'basic',
            'verification_status' => 'approved',
            'usd_balance'         => 0,
            'locked_usd_balance'  => 0,
            'dispute_usd_balance' => 0,
            'negative_balance'    => 0,
            'reputation_score'    => 100,
            'created_at'          => $this->now_mysql(),
            'updated_at'          => $this->now_mysql(),
        ]);
        if (!$ins) {
            $u2 = $this->database->get_user_by_wallet($wallet, false);
            if ($u2 && !empty($u2->user_id)) {
                return (int) $u2->user_id;
            }
            return new WP_Error('db_error', 'Failed to create wallet identity', ['status' => 500]);
        }
        return (int) $ins;
    }

    private function wallet_belongs_to_human_user($wallet_address) {
        return EarnFi_Wallet_Identity::is_human_wallet($wallet_address);
    }

    /**
     * Public wrapper for partner reporting token resolution.
     *
     * @return object|WP_Error
     */
    public function resolve_agent_token_public($agent_token_raw) {
        return $this->resolve_agent_from_token($agent_token_raw);
    }

    private function resolve_agent_from_token($agent_token_raw) {
        $token = is_string($agent_token_raw) ? trim($agent_token_raw) : '';
        if ($token === '') {
            return new WP_Error(
                'missing_agent_token',
                'agent_token is required',
                [
                    'status'    => 401,
                    'hint'      => 'Send Agent-Token (or agent_token), register first, or omit the token and complete x402 payment so identity binds from the paying wallet.',
                    'next_step' => 'GET /register/challenge then POST /register, or retry the paid create without Agent-Token and with PAYMENT-SIGNATURE.',
                ]
            );
        }
        // Hash at rest. Join ai_agents so partner_id / identity from registration are available
        // for paid-create attribution (partner_source optional when agent is already linked).
        $hash = hash('sha256', $token);
        $p = $this->database->table_prefix;
        $row = $this->database->get_row(
            "SELECT t.*, a.partner_id AS partner_id, a.agent_name AS agent_name,
                    a.identity_provider AS identity_provider, a.identity_reference AS identity_reference
             FROM {$p}ai_agent_tokens t
             LEFT JOIN {$p}ai_agents a ON a.agent_id = t.agent_id
             WHERE t.token_hash = %s AND t.status = 'active'
             LIMIT 1",
            [$hash]
        );
        if (!$row) {
            return new WP_Error(
                'invalid_agent_token',
                'Invalid agent_token',
                [
                    'status'    => 401,
                    'hint'      => 'Use a valid Agent-Token from POST /register, or omit Agent-Token and complete x402 payment to bind a new token to the paying wallet.',
                    'next_step' => 'POST /register/challenge then POST /register, or omit Agent-Token on the paid create.',
                ]
            );
        }
        // Touch last_used_at best-effort (no hard failure).
        $this->database->update('ai_agent_tokens', ['last_used_at' => $this->now_mysql()], ['id' => (int) $row->id]);
        return $row;
    }

    /**
     * Resolve agent_token from JSON body (POST), query, or Agent-Token / X-Agent-Token header.
     */
    private function extract_agent_token(WP_REST_Request $request): string {
        $token = trim((string) ($request->get_param('agent_token') ?? ''));
        if ($token !== '') {
            return $token;
        }
        foreach (['agent-token', 'x-agent-token'] as $header) {
            $from_header = trim((string) ($request->get_header($header) ?? ''));
            if ($from_header !== '') {
                return $from_header;
            }
        }
        return '';
    }

    /**
     * Optional token for paid creates: empty → null (quote/settle may bind from payer);
     * invalid non-empty → WP_Error 401.
     *
     * @return object|null|WP_Error
     */
    private function resolve_agent_token_row_optional(WP_REST_Request $request) {
        $raw = $this->extract_agent_token($request);
        if ($raw === '') {
            return null;
        }
        return $this->resolve_agent_from_token($raw);
    }

    /** Optional display name for payment-time agent bind. */
    private function extract_optional_agent_name(WP_REST_Request $request): string {
        return Earnfi_Agent_Register::sanitize_optional_agent_name($request->get_param('agent_name') ?? '');
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function agent_guidance_error(string $code, string $message, int $status, string $hint, string $next_step, array $extra = []): WP_Error {
        return new WP_Error($code, $message, array_merge([
            'status'    => $status,
            'hint'      => $hint,
            'next_step' => $next_step,
        ], $extra));
    }

    private function paid_create_quote_hint(): string {
        return Earnfi_Agent_Integrator_Copy::paid_create_quote_hint();
    }

    private function paid_create_quote_next_step(): string {
        return Earnfi_Agent_Integrator_Copy::paid_create_quote_next_step();
    }

    private function paid_create_success_next_step(): string {
        return 'Store secret and agent_token securely. Poll status_url (or submissions) until work completes. GET /catalog for more action types.';
    }

    /**
     * After payment without a prior token: provision agent from payer wallet.
     *
     * @return array{token_row:object,agent_token:string,agent_name:string,agent_bound:bool}|WP_Error
     */
    private function ensure_agent_after_payment(WP_REST_Request $request, string $payer_wallet) {
        $payer_wallet = trim($payer_wallet);
        if ($payer_wallet === '') {
            return $this->agent_guidance_error(
                'payer_unavailable',
                'Payment settled but the paying wallet could not be determined.',
                400,
                'Send a valid Agent-Token on the paid create, or register first.',
                'POST /register/challenge then POST /register, then retry with Agent-Token and PAYMENT-SIGNATURE.'
            );
        }
        $name = $this->extract_optional_agent_name($request);
        if ($name === '') {
            $name = Earnfi_Agent_Register::default_agent_name_for_wallet($payer_wallet);
        }
        $body = [];
        if ($request->get_param('partner_source') !== null) {
            $body['partner_source'] = $request->get_param('partner_source');
        }
        if ($request->get_param('partner_metadata') !== null) {
            $body['partner_metadata'] = $request->get_param('partner_metadata');
        }
        $body['provision_source'] = 'x402_payment_bind';
        $provisioned = $this->agent_register->provision_for_wallet(
            $payer_wallet,
            Earnfi_Agent_Register::WALLET_SOLANA,
            $name,
            $body,
            $request
        );
        if (is_wp_error($provisioned)) {
            return $provisioned;
        }
        return [
            'token_row'    => $provisioned['token_row'],
            'agent_token'  => $provisioned['agent_token'],
            'agent_name'   => $provisioned['agent_name'],
            'agent_bound'  => true,
        ];
    }

    /**
     * @param WP_REST_Response|WP_Error|array $settle_resp
     * @return array{token_row:object,agent_token:string,agent_name:string,agent_bound:bool,fd:array}|WP_Error
     */
    private function resolve_token_row_after_settle(WP_REST_Request $request, $token_row, $settle_resp, string $agent_token_in) {
        if (is_wp_error($settle_resp)) {
            return $settle_resp;
        }
        $fd = $settle_resp instanceof WP_REST_Response ? $settle_resp->get_data() : (is_array($settle_resp) ? $settle_resp : []);
        if (!is_array($fd)) {
            $fd = [];
        }
        if ($token_row && is_object($token_row)) {
            return [
                'token_row'   => $token_row,
                'agent_token' => $agent_token_in,
                'agent_name'  => (string) ($token_row->agent_name ?? ''),
                'agent_bound' => false,
                'fd'          => $fd,
            ];
        }
        // Prefer token minted during settle auto-bind (avoids minting a second token).
        $settled_token = isset($fd['agent_token']) ? trim((string) $fd['agent_token']) : '';
        if ($settled_token !== '') {
            $row = $this->resolve_agent_from_token($settled_token);
            if (!is_wp_error($row)) {
                return [
                    'token_row'   => $row,
                    'agent_token' => $settled_token,
                    'agent_name'  => (string) ($fd['agent_name'] ?? $row->agent_name ?? ''),
                    'agent_bound' => true,
                    'fd'          => $fd,
                ];
            }
        }
        $payer = '';
        if (!empty($fd['from_wallet']) && is_string($fd['from_wallet'])) {
            $payer = trim($fd['from_wallet']);
        } elseif (!empty($fd['_discovered_payer']) && is_string($fd['_discovered_payer'])) {
            $payer = trim($fd['_discovered_payer']);
        }
        $bound = $this->ensure_agent_after_payment($request, $payer);
        if (is_wp_error($bound)) {
            return $bound;
        }
        $bound['fd'] = $fd;
        return $bound;
    }

    /**
     * Attach auto-bind flags when the client omitted agent_token.
     *
     * @param array<string,mixed> $job_data
     * @return array<string,mixed>
     */
    private function agent_job_data_for_optional_bind(WP_REST_Request $request, array $job_data, $token_row) {
        if ($token_row && is_object($token_row)) {
            return $job_data;
        }
        $job_data['_auto_bind_agent'] = true;
        $job_data['creator_type'] = 'agent';
        unset($job_data['creator_agent_id']);
        $name = $this->extract_optional_agent_name($request);
        if ($name !== '') {
            $job_data['_agent_name'] = $name;
        }
        $ps = sanitize_key((string) ($request->get_param('partner_source') ?? ''));
        if ($ps !== '') {
            $job_data['_partner_source'] = $ps;
        }
        if ($request->get_param('partner_metadata') !== null) {
            $job_data['_partner_metadata'] = $request->get_param('partner_metadata');
        }
        return $job_data;
    }

    /**
     * Settle PayAI paid create; discover payer + bind agent when token was omitted.
     *
     * @param object|null $token_row
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $job_data
     * @return array{token_row:object,agent_token:string,agent_name:string,agent_bound:bool,fd:array}|WP_Error
     */
    private function agent_settle_payai_paid_create(WP_REST_Request $request, $token_row, string $agent_token, array $parsed, array $job_data) {
        $wallet = '';
        if ($token_row && is_object($token_row) && !empty($token_row->wallet_address)) {
            $wallet = (string) $token_row->wallet_address;
        }
        $job_data = $this->agent_job_data_for_optional_bind($request, $job_data, $token_row);
        $growl = new GROWL_REST_API();
        $payload_v2 = isset($parsed['payment_payload_v2']) && is_array($parsed['payment_payload_v2'])
            ? $parsed['payment_payload_v2']
            : null;
        $fin = Earnfi_Rail_Job_Funding::payai_settle_and_create_for_wallet(
            $growl,
            $wallet,
            $parsed['signed_tx'],
            $parsed['requirements'],
            $job_data,
            $payload_v2
        );
        return $this->resolve_token_row_after_settle($request, $token_row, $fin, $agent_token);
    }

    /**
     * Persist x402 quote for PayAI Bazaar echo and send PAYMENT-REQUIRED headers.
     *
     * @param array<string, mixed>      $x402_payload
     * @param array<string, mixed>|null $accept
     */
    private function agent_send_402_quote(array $x402_payload, ?array $accept = null): void {
        PayAI_X402_Quote_Store::persist_402_response($x402_payload, $accept);
        PayAI_X402_Http::send_payment_required_headers($x402_payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function agent_attach_quote_guidance(array $payload): array {
        return Earnfi_Agent_Integrator_Copy::merge_agent_402_quote_fields($payload);
    }

    private function require_agent_token(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $tok = $this->resolve_agent_from_token($this->extract_agent_token($request));
        if (is_wp_error($tok)) {
            return $tok;
        }
        return $tok;
    }

    /**
     * Used by Growl_Agent_Public_Work_REST for the same token check.
     */
    public function public_require_agent(WP_REST_Request $request) {
        return $this->require_agent_token($request);
    }

    public function rotate_agent_token(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        $reg = new Earnfi_Agent_Register();
        $out = $reg->rotate_token($row);
        if (is_wp_error($out)) {
            return $out;
        }
        return rest_ensure_response($out);
    }

    public function revoke_agent_token(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        $reg = new Earnfi_Agent_Register();
        $ok = $reg->revoke_token_row((int) $row->id);
        if (is_wp_error($ok)) {
            return $ok;
        }
        return rest_ensure_response(['revoked' => true, 'agent_id' => (string) $row->agent_id]);
    }

    public function claim_job_as_agent(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        $job_id = sanitize_text_field((string) $request['id']);
        $task = $this->database->get_row(
            "SELECT task_id, execution_mode, user_count, completed_count, status
             FROM {$this->database->table_prefix}tasks WHERE task_id = %s LIMIT 1",
            [$job_id]
        );
        if (!$task) {
            return new WP_Error('job_not_found', 'Job not found.', ['status' => 404]);
        }
        $mode = (string) ($task->execution_mode ?? 'human');
        if ($mode === 'human') {
            return new WP_Error('wrong_mode', 'This job is for humans only.', ['status' => 403]);
        }
        if (!in_array($mode, ['agent', 'hybrid'], true)) {
            return new WP_Error('wrong_mode', 'This job cannot be claimed by an agent.', ['status' => 403]);
        }
        $x_ok = $this->require_agent_x_for_task((string) $row->agent_id, $task);
        if (is_wp_error($x_ok)) {
            return $x_ok;
        }
        $agent_id = (string) $row->agent_id;
        $p = $this->database->table_prefix;
        $dup = $this->database->get_var(
            "SELECT completion_id FROM {$p}completions WHERE task_id = %s AND worker_agent_id = %s LIMIT 1",
            [$job_id, $agent_id]
        );
        if ($dup) {
            return new WP_Error('already_claimed', 'You already claimed this job.', ['status' => 400]);
        }
        $updated = $this->database->wpdb->query($this->database->wpdb->prepare(
            "UPDATE {$p}tasks SET completed_count = completed_count + 1
             WHERE task_id = %s AND completed_count < user_count AND status IN ('active','open')",
            $job_id
        ));
        if (!$updated) {
            return new WP_Error('slots_full', 'No slots remaining.', ['status' => 409]);
        }
        $cid = $this->database->insert('completions', [
            'task_id'            => $job_id,
            'user_id'            => 0,
            'reward_amount'      => 0,
            'rank_at_completion' => 'basic',
            'status'             => 'pending',
            'verification_type'  => 'manual',
            'locked_until'       => gmdate('Y-m-d H:i:s'),
            'worker_type'        => 'agent',
            'worker_agent_id'    => $agent_id,
            'created_at'         => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$cid) {
            $this->database->query(
                "UPDATE {$p}tasks SET completed_count = GREATEST(completed_count - 1, 0) WHERE task_id = %s",
                [$job_id]
            );
            return new WP_Error('claim_failed', 'Could not claim this job.', ['status' => 500]);
        }
        return rest_ensure_response(['claimed' => true, 'completion_id' => (int) $cid, 'job_id' => $job_id]);
    }

    public function submit_job_as_agent(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        $job_id = sanitize_text_field((string) $request['id']);
        $p = $this->database->table_prefix;
        $x_ok = $this->require_agent_x_for_task((string) $row->agent_id, null);
        if (is_wp_error($x_ok)) {
            return $x_ok;
        }
        $completion = $this->database->get_row(
            "SELECT completion_id, status, verification_type FROM {$p}completions
             WHERE task_id = %s AND worker_agent_id = %s ORDER BY completion_id DESC LIMIT 1",
            [$job_id, (string) $row->agent_id]
        );
        if (!$completion) {
            return new WP_Error('not_claimed', 'Claim this job before submitting.', ['status' => 400]);
        }
        $result = $request->get_param('result');
        $note   = sanitize_textarea_field((string) ($request->get_param('note') ?? ''));
        $agent_x = $this->database->get_row(
            "SELECT x_username, agent_name FROM {$p}ai_agents WHERE agent_id = %s LIMIT 1",
            [(string) $row->agent_id]
        );
        $this->database->insert('manual_verifications', [
            'completion_id'  => (int) $completion->completion_id,
            'task_id'        => $job_id,
            'user_id'        => 0,
            'submitted_data' => wp_json_encode([
                'note' => $note,
                'result' => $result,
                'worker_type' => 'agent',
                'agent_id' => (string) $row->agent_id,
                'agent_name' => (string) ($agent_x->agent_name ?? ''),
                'x_username' => (string) ($agent_x->x_username ?? ''),
            ]),
            'status'         => 'pending',
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        return rest_ensure_response(['submitted' => true, 'completion_id' => (int) $completion->completion_id]);
    }

    /**
     * When a task requires X for humans, agents must have x_username on ai_agents.
     *
     * @param object $task
     * @return true|WP_Error
     */
    private function require_agent_x_for_task($agent_id, $task = null) {
        if (!$this->task_requires_x_identity($task)) {
            return true;
        }
        $xu = $this->database->get_row(
            "SELECT x_username, x_user_id FROM {$this->database->table_prefix}ai_agents WHERE agent_id = %s LIMIT 1",
            [sanitize_text_field((string) $agent_id)]
        );
        $username = trim((string) ($xu->x_username ?? ''));
        $xid = trim((string) ($xu->x_user_id ?? ''));
        if ($username === '' || $xid === '') {
            return new WP_Error(
                'x_required',
                'This job requires a verified X account. Connect X on your agent profile with the post verification flow.',
                ['status' => 403]
            );
        }
        return true;
    }

    /**
     * @param object $task
     */
    private function task_requires_x_identity($task): bool {
        // Platform default: X is mandatory for agent participation in Tasks/Contests.
        return true;
    }

    public function provider_upsert_service(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        $svc = (new Growl_Agent_Order_Service())->upsert_service((string) $row->agent_id, $request->get_json_params() ?: []);
        return is_wp_error($svc) ? $svc : rest_ensure_response(['service' => $svc]);
    }

    public function provider_list_services(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        return rest_ensure_response([
            'services' => (new Growl_Agent_Order_Service())->list_services_for_agent((string) $row->agent_id),
        ]);
    }

    public function provider_set_service_status(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        $svc = (new Growl_Agent_Order_Service())->set_service_status(
            (string) $row->agent_id,
            (int) $request['id'],
            (string) ($request->get_param('status') ?? '')
        );
        return is_wp_error($svc) ? $svc : rest_ensure_response(['service' => $svc]);
    }

    public function agent_create_order(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $order = (new Growl_Agent_Order_Service())->create_order(
            'agent',
            (string) $row->agent_id,
            (int) $request->get_param('service_id'),
            is_array($request->get_param('input')) ? $request->get_param('input') : []
        );
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'agent', (string) $row->agent_id);
    }

    public function agent_deliver_order(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $order = (new Growl_Agent_Order_Service())->deliver(
            (int) $request['id'],
            (string) $row->agent_id,
            is_array($request->get_param('output')) ? $request->get_param('output') : []
        );
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'agent', (string) $row->agent_id);
    }

    public function agent_fund_order(WP_REST_Request $request) {
        $payment_sig = trim((string) $request->get_header('payment-signature'));
        if ($payment_sig === '') {
            $token_row = $this->resolve_agent_token_row_optional($request);
            if (is_wp_error($token_row) || $token_row === null) {
                if (class_exists('Earnfi_X402_Paid_Probe', false)) {
                    $def = Earnfi_X402_Paid_Resources::probe_definitions()['marketplace/order/fund'] ?? null;
                    if (is_array($def)) {
                        return Earnfi_X402_Paid_Probe::respond($request, 'marketplace/order/fund', $def);
                    }
                }
            }
        }
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $sid = $request->get_param('settlement_id') ? (int) $request->get_param('settlement_id') : 0;
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $order = (new Growl_Agent_Order_Service())->fund_order_as_agent(
            (int) $request['id'],
            (string) $row->agent_id,
            $sid,
            $idem
        );
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'agent', (string) $row->agent_id);
    }

    public function agent_release_order(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $order = (new Growl_Agent_Order_Service())->release((int) $request['id'], (string) $row->agent_id, $idem);
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'agent', (string) $row->agent_id);
    }

    public function agent_revise_order(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $order = (new Growl_Agent_Order_Service())->request_revision_by_buyer(
            (int) $request['id'],
            'agent',
            (string) $row->agent_id,
            (string) ($request->get_param('note') ?? '')
        );
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'agent', (string) $row->agent_id);
    }

    public function agent_dispute_order(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $order = (new Growl_Agent_Order_Service())->open_dispute(
            (int) $request['id'],
            'agent',
            (string) $row->agent_id,
            (string) ($request->get_param('reason') ?? '')
        );
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'agent', (string) $row->agent_id);
    }

    public function agent_create_deal(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $deal = (new Growl_Agent_Deal_Service())->create_draft(
            'agent',
            (string) $row->agent_id,
            $request->get_json_params() ?: []
        );
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'agent', (string) $row->agent_id);
    }

    public function agent_list_deals(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        return rest_ensure_response([
            'deals' => (new Growl_Agent_Deal_Service())->list_for_party(
                'agent',
                (string) $row->agent_id,
                $per,
                ($page - 1) * $per
            ),
        ]);
    }

    public function agent_get_deal(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        $deal = (new Growl_Agent_Deal_Service())->get_by_slug(
            (string) $request['slug'],
            'agent',
            (string) $row->agent_id
        );
        if (!$deal) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        return rest_ensure_response(['deal' => $deal]);
    }

    public function agent_accept_deal(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        $params = $request->get_json_params() ?: [];
        $deal = (new Growl_Agent_Deal_Service())->accept(
            (int) $request['id'],
            'agent',
            (string) $row->agent_id,
            (string) ($params['role'] ?? 'buyer'),
            (string) ($params['invite_token'] ?? '')
        );
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'agent', (string) $row->agent_id);
    }

    public function agent_fund_deal(WP_REST_Request $request) {
        $payment_sig = trim((string) $request->get_header('payment-signature'));
        if ($payment_sig === '') {
            $token_row = $this->resolve_agent_token_row_optional($request);
            if (is_wp_error($token_row) || $token_row === null) {
                if (class_exists('Earnfi_X402_Paid_Probe', false)) {
                    $def = Earnfi_X402_Paid_Resources::probe_definitions()['marketplace/deal/fund'] ?? null;
                    if (is_array($def)) {
                        return Earnfi_X402_Paid_Probe::respond($request, 'marketplace/deal/fund', $def);
                    }
                }
            }
        }
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $sid = $request->get_param('settlement_id') ? (int) $request->get_param('settlement_id') : 0;
        $deal = (new Growl_Agent_Deal_Service())->fund(
            (int) $request['id'],
            'agent',
            (string) $row->agent_id,
            $sid ? 'wallet' : 'wallet',
            $sid ?: null,
            $idem
        );
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'agent', (string) $row->agent_id);
    }

    public function agent_deliver_deal(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        $params = $request->get_json_params() ?: [];
        $deal = (new Growl_Agent_Deal_Service())->deliver(
            (int) $request['id'],
            'agent',
            (string) $row->agent_id,
            (string) ($params['note'] ?? ''),
            (string) ($params['payload'] ?? $params['output'] ?? '')
        );
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'agent', (string) $row->agent_id);
    }

    public function agent_release_deal(WP_REST_Request $request) {
        $row = $this->require_agent_token($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $deal = (new Growl_Agent_Deal_Service())->release(
            (int) $request['id'],
            'agent',
            (string) $row->agent_id,
            $idem
        );
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'agent', (string) $row->agent_id);
    }

    public function agent_list_capabilities() {
        $db = $this->database;
        $rows = $db->get_results("SELECT slug, title, description, provider, catalog_path FROM {$db->table_prefix}capabilities ORDER BY slug ASC");
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[] = [
                'slug' => (string) $r->slug,
                'title' => (string) $r->title,
                'description' => (string) $r->description,
                'provider' => (string) $r->provider,
            ];
        }
        return rest_ensure_response(['capabilities' => $out]);
    }

    public function agent_services_catalog(WP_REST_Request $request) {
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        $cap = sanitize_key((string) ($request->get_param('capability') ?? ''));
        return rest_ensure_response([
            'services' => (new Growl_Agent_Order_Service())->catalog($cap, 20, 0),
        ]);
    }

    private function assert_agent_owns_job($job_id, $token_row) {
        $tasks = $this->database->table_prefix . 'tasks';
        $task = $this->database->get_row("SELECT creator_type, creator_agent_id FROM {$tasks} WHERE task_id = %s LIMIT 1", [$job_id]);
        if (!$task) {
            return new WP_Error('job_not_found', 'Job not found', ['status' => 404]);
        }
        if ((string) ($task->creator_type ?? '') !== 'agent' || (string) ($task->creator_agent_id ?? '') !== (string) $token_row->agent_id) {
            return new WP_Error('forbidden', 'Not authorized for this job', ['status' => 403]);
        }
        return true;
    }

    /**
     * @param array<string, mixed> $job_data
     * @return array<string, mixed>|WP_Error
     */
    private function agent_merge_partner_job_context(WP_REST_Request $request, array $job_data, $token_row) {
        $partner = EarnFi_Partner_Context::resolve_from_request($request);
        if (!$partner && $token_row) {
            $pid = isset($token_row->partner_id) ? (int) $token_row->partner_id : 0;
            if ($pid <= 0 && !empty($token_row->agent_id)) {
                $agent = $this->database->get_row(
                    "SELECT partner_id FROM {$this->database->table_prefix}ai_agents WHERE agent_id = %s LIMIT 1",
                    [(string) $token_row->agent_id]
                );
                $pid = $agent && isset($agent->partner_id) ? (int) $agent->partner_id : 0;
            }
            if ($pid > 0) {
                $partner = EarnFi_Partner_Context::get_by_id($pid);
            }
        }
        if ($partner && ($partner->status ?? '') === 'paused') {
            return new WP_Error('partner_paused', 'Partner integration is paused', ['status' => 403]);
        }
        $meta = EarnFi_Partner_Context::normalize_partner_metadata($request->get_param('partner_metadata'));
        return EarnFi_Partner_Context::attach_to_job_data($job_data, $partner, $meta);
    }

    private function agent_idempotency_key(WP_REST_Request $request): string {
        return EarnFi_Idempotency::resolve_key($request);
    }

    /**
     * @return WP_REST_Response|null
     */
    private function agent_check_idempotency(WP_REST_Request $request, string $resource, bool $probe, bool $has_payment_sig) {
        if ($probe || $has_payment_sig) {
            return null;
        }
        $key = $this->agent_idempotency_key($request);
        if ($key === '') {
            return null;
        }
        $cached = EarnFi_Idempotency::get($key, $resource);
        if ($cached === null) {
            return null;
        }
        $status = !empty($cached['payment_required']) ? 402 : 200;
        return new WP_REST_Response($cached, $status);
    }

    /**
     * @param array<string, mixed>|WP_REST_Response $response
     */
    private function agent_store_idempotency(WP_REST_Request $request, string $resource, $response, bool $probe): void {
        if ($probe) {
            return;
        }
        $key = $this->agent_idempotency_key($request);
        if ($key === '') {
            return;
        }
        $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
        if (!is_array($data)) {
            return;
        }
        EarnFi_Idempotency::put($key, $resource, $data);
    }

    private function agent_poll_credential(WP_REST_Request $request): string {
        $secret = trim((string) ($request->get_param('secret') ?? ''));
        if ($secret !== '') {
            return $secret;
        }
        return $this->extract_agent_token($request);
    }

    /**
     * @param callable(): WP_REST_Response|WP_Error $inner
     * @return WP_REST_Response|WP_Error
     */
    private function agent_poll_cached(WP_REST_Request $request, string $route, string $entity_id, callable $inner) {
        $cred = $this->agent_poll_credential($request);
        $cache_key = EarnFi_Partner_Report_Cache::poll_key($route, $entity_id, $cred);
        $cached = EarnFi_Partner_Report_Cache::get_poll($cache_key);
        if (is_array($cached)) {
            EarnFi_Partner_Report_Cache::poll_cache_headers(true);
            return rest_ensure_response($cached);
        }
        $resp = $inner();
        if ($resp instanceof WP_REST_Response && $resp->get_status() === 200) {
            $data = $resp->get_data();
            if (is_array($data)) {
                EarnFi_Partner_Report_Cache::set_poll($cache_key, $data, 60);
            }
            EarnFi_Partner_Report_Cache::poll_cache_headers(false);
        }
        return $resp;
    }

    private function get_platform_fee_percent() {
        $settings = get_option('growl_settings', []);
        return floatval($settings['platform_fee_percent'] ?? 10);
    }

    private function quote_atomic_from_total_cost_usd_string($total_cost) {
        $settings = get_option('growl_settings', []);
        $decimals = intval($settings['usdc_decimals'] ?? 6);
        $multiplier = bcpow('10', (string) $decimals, 0);
        return bcmul((string) $total_cost, $multiplier, 0);
    }

    /**
     * @param string      $resource_url
     * @param string      $atomic_amount
     * @param string|null $resource_description Per-endpoint `resource.description`.
     * @return array{resource: array, accepts: array}|WP_Error
     */
    public function get_x402_capabilities(WP_REST_Request $request) {
        if (class_exists('Earnfi_X402_Capabilities', false)) {
            $payload = Earnfi_X402_Capabilities::agent_capabilities_payload();
            if (class_exists('Earnfi_X402_Facilitator_Client', false)) {
                $supported = Earnfi_X402_Facilitator_Client::get_supported('payai');
                if (!is_wp_error($supported)) {
                    $payload['payai_supported'] = $supported;
                }
                $near_fac = class_exists('Earnfi_Near_X402_Facilitator', false)
                    ? Earnfi_Near_X402_Facilitator::active_id()
                    : 'fastnear';
                $near_supported = Earnfi_X402_Facilitator_Client::get_supported($near_fac);
                if (!is_wp_error($near_supported)) {
                    $payload['near_supported'] = $near_supported;
                    $payload['near_x402_facilitator'] = $near_fac;
                    if ($near_fac === 'fastnear') {
                        $payload['fastnear_supported'] = $near_supported;
                    } elseif ($near_fac === 'solvador') {
                        $payload['solvador_supported'] = $near_supported;
                    }
                }
            }
            $payload['canonical_api'] = $this->namespace;
            return rest_ensure_response($payload);
        }
        return rest_ensure_response(['x402Version' => 2, 'canonical_api' => $this->namespace]);
    }

    public function get_api_info(WP_REST_Request $request) {
        return rest_ensure_response([
            'name'           => 'EarnFi Agent API',
            'api_version'    => 'v1',
            'canonical_base' => rest_url($this->namespace),
            'description'    => 'Solana-first multichain x402 agent surface (jobs, human actions, marketplace, escrow)',
            'x402_version'   => 2,
            'docs'           => home_url('/skill.md'),
            'inventory'      => rest_url('growl/v1/x402/inventory'),
            'networks'       => rest_url('growl/v1/networks'),
        ]);
    }

    private function x402_accepts_for_atomic($resource_url, $atomic_amount, $resource_description = null, $request = null) {
        if (!class_exists('Earnfi_X402_Priced_Quote', false)) {
            return new WP_Error('x402_unavailable', 'x402 quotes are not loaded.', ['status' => 503]);
        }
        $filter = class_exists('Earnfi_X402_Capabilities', false)
            ? Earnfi_X402_Capabilities::accepts_filter_from_request($request)
            : '';
        $accepts = Earnfi_X402_Priced_Quote::build_accepts(
            $resource_url,
            (string) $atomic_amount,
            $resource_description,
            $filter !== '' ? $filter : null
        );
        if (is_wp_error($accepts)) {
            return $accepts;
        }
        $desc = is_string($resource_description) && $resource_description !== ''
            ? $resource_description
            : 'EarnFi paid create.';
        return [
            'resource' => Earnfi_X402_Priced_Quote::resource_meta($resource_url, $desc),
            'accepts'  => $accepts,
        ];
    }

    /**
     * @param array<string, mixed> $parsed
     * @return WP_Error|null
     */
    private function agent_validate_priced_payment(array $parsed, string $expected_atomic, ?string $quote_fingerprint) {
        if (!class_exists('Earnfi_X402_Priced_Quote', false)) {
            return null;
        }
        $check = Earnfi_X402_Priced_Quote::validate_paid_retry($parsed, $expected_atomic, $quote_fingerprint);
        return is_wp_error($check) ? $check : null;
    }

    /**
     * @param array<string, mixed> $estimate_data
     * @return array<string, mixed> extensions object (bazaar)
     */
    private function build_agent_social_bazaar(WP_REST_Request $request, $estimate_data, $task_type, $slots, $reward_per_user) {
        $w = new Earnfi_Work_REST_API();
        return [
            'bazaar' => [
                'info' => [
                    'input' => [
                        'type'     => 'http',
                        'method'   => $request->get_method(),
                        'bodyType' => 'application/json',
                        'queryParams' => 'GET: pass fields as query. POST: same keys in JSON.',
                    ],
                    'output' => [
                        'type'    => 'json',
                        'example' => [
                            'ok'              => true,
                            'paid'            => true,
                            'total_cost'      => isset($estimate_data['total_cost']) ? $estimate_data['total_cost'] : '0',
                            'task_type'       => $task_type,
                            'slots'           => $slots,
                            'reward_per_user' => (string) $reward_per_user,
                        ],
                    ],
                ],
                'schema' => $w->get_social_x402_extensions_schema(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function agent_manual_x402_extensions_schema() {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type'    => 'object',
            'properties' => [
                'input' => [
                    'type'     => 'object',
                    'properties' => [
                        'type'   => [ 'type' => 'string', 'const' => 'http' ],
                        'method' => [ 'type' => 'string', 'enum' => [ 'GET', 'POST' ] ],
                        'queryParams' => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'title, instructions, reward_per_user, slots, verification_method, agent_token' ],
                        'body'   => [ 'type' => 'object', 'additionalProperties' => true ],
                    ],
                    'required' => [ 'type', 'method' ],
                ],
                'output' => [
                    'type' => 'object',
                    'properties' => [
                        'type'    => [ 'type' => 'string' ],
                        'example' => [ 'type' => 'object' ],
                    ],
                    'required' => [ 'type' ],
                ],
            ],
            'required' => [ 'input' ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function agent_contest_x402_extensions_schema() {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type'    => 'object',
            'properties' => [
                'input' => [
                    'type'     => 'object',
                    'properties' => [
                        'type'   => [ 'type' => 'string', 'const' => 'http' ],
                        'method' => [ 'type' => 'string', 'enum' => [ 'GET', 'POST' ] ],
                        'queryParams' => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'title, instructions, total_prize_pool, contest_mode_card (creator_choice|community_assisted|community_choice|lucky_draw), voting_hours, voting_visibility, vote_gate_* or vote_gate JSON, deadline, winner_count, prizes, agent_token' ],
                        'body'   => [ 'type' => 'object', 'additionalProperties' => true ],
                    ],
                    'required' => [ 'type', 'method' ],
                ],
                'output' => [
                    'type' => 'object',
                    'properties' => [
                        'type'    => [ 'type' => 'string' ],
                        'example' => [ 'type' => 'object' ],
                    ],
                    'required' => [ 'type' ],
                ],
            ],
            'required' => [ 'input' ],
        ];
    }

    /**
     * Issue a short-lived registration message so clients sign an exact server-known string.
     *
     * GET or POST: wallet_address, agent_name (query or JSON body for POST).
     */
    public function get_register_challenge(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $wallet = trim((string) $request->get_param('wallet_address'));
        $agent_name = (string) $request->get_param('agent_name');
        if ($wallet === '' && $agent_name === '') {
            $wallet = '11111111111111111111111111111111';
            $agent_name = 'discovery-probe';
        }
        return $this->agent_register->issue_challenge($wallet, $agent_name, Earnfi_Agent_Register::SCHEME_SOLANA);
    }

    public function register_agent_v2(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }
        $wallet_raw = isset($body['wallet_address']) ? trim((string) $body['wallet_address']) : trim((string) ($request->get_param('wallet_address') ?? ''));
        $agent_name = isset($body['agent_name']) ? sanitize_text_field($body['agent_name']) : sanitize_text_field((string) ($request->get_param('agent_name') ?? ''));
        $message = isset($body['message']) ? (string) $body['message'] : (string) ($request->get_param('message') ?? '');
        $nonce_in = isset($body['nonce']) ? (string) $body['nonce'] : (string) ($request->get_param('nonce') ?? '');
        $signature = $this->normalize_signature_bytes($body['signature'] ?? $request->get_param('signature'));

        $san = $this->agent_register->sanitize_for_scheme($wallet_raw, Earnfi_Agent_Register::SCHEME_SOLANA);
        if (is_wp_error($san)) {
            if ($wallet_raw === '' || $agent_name === '' || $message === '' || !$signature) {
                return new WP_Error(
                    'invalid_params',
                    'wallet_address, agent_name, message, and signature are required',
                    [
                        'status' => 400,
                        'hint'   => $signature ? 'message must be non-empty' : self::REGISTRATION_SIGNATURE_HINT,
                    ]
                );
            }
            return $san;
        }
        $wallet = $san['wallet'];

        if ($agent_name === '' || $message === '' || !$signature) {
            return new WP_Error(
                'invalid_params',
                'wallet_address, agent_name, message, and signature are required',
                [
                    'status' => 400,
                    'hint'   => $signature ? 'message must be non-empty' : self::REGISTRATION_SIGNATURE_HINT,
                ]
            );
        }

        $chal = $this->agent_register->consume_challenge_if_present(
            $nonce_in,
            $wallet,
            $agent_name,
            $message,
            Earnfi_Agent_Register::SCHEME_SOLANA
        );
        if (is_wp_error($chal)) {
            return $chal;
        }

        if (!$this->security->validate_solana_signature($message, $signature, $wallet)) {
            return new WP_Error(
                'invalid_signature',
                'Signature verification failed',
                [
                    'status' => 401,
                    'hint'   => self::REGISTRATION_SIGNATURE_HINT,
                ]
            );
        }

        if (is_array($chal) && !empty($chal['nonce'])) {
            $this->agent_register->invalidate_challenge((string) $chal['nonce']);
        }

        return $this->agent_register->complete_after_verified(
            $wallet,
            Earnfi_Agent_Register::WALLET_SOLANA,
            $agent_name,
            $body,
            $request
        );
    }

    /**
     * Get agent public profile (requires agent_token).
     */
    public function get_agent_profile(WP_REST_Request $request) {
        $token_row = $this->require_agent_token($request);
        if (is_wp_error($token_row)) {
            return $token_row;
        }
        $service = GROWL_Public_Profile::get_instance();
        $profile = $service->get_public_profile((string) $token_row->agent_id);
        if (!$profile) {
            return new WP_Error('agent_not_found', 'Agent not found', ['status' => 404]);
        }
        return rest_ensure_response(['success' => true, 'profile' => $profile]);
    }

    /**
     * Update agent public profile (requires agent_token).
     */
    public function patch_agent_profile(WP_REST_Request $request) {
        $token_row = $this->require_agent_token($request);
        if (is_wp_error($token_row)) {
            return $token_row;
        }
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }
        $service = GROWL_Public_Profile::get_instance();
        $result = $service->update_agent_profile((string) $token_row->agent_id, $body);
        if (is_wp_error($result)) {
            return $result;
        }
        $service->bust_public_cache_for_identifier((string) $token_row->agent_id);
        return rest_ensure_response(['success' => true, 'profile' => $result]);
    }

    /**
     * Generate X verification code for agent (post + verify flow, same as humans).
     */
    public function agent_generate_x_verification_code(WP_REST_Request $request) {
        $token_row = $this->require_agent_token($request);
        if (is_wp_error($token_row)) {
            return $token_row;
        }
        $agent_id = sanitize_text_field((string) $token_row->agent_id);
        try {
            $random_bytes = random_bytes(5);
            $code = strtoupper(substr(bin2hex($random_bytes), 0, 8));
        } catch (Exception $e) {
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        }
        $verification_code = 'EarnFi' . $code;
        set_transient('growl_agent_verification_code_' . $agent_id, $verification_code, 10 * MINUTE_IN_SECONDS);
        return rest_ensure_response([
            'success'           => true,
            'verification_code' => $verification_code,
            'expires_in'        => 600,
            'expires_at'        => time() + 600,
        ]);
    }

    /**
     * Verify agent X post and persist x_username on ai_agents.
     */
    public function agent_verify_x_post(WP_REST_Request $request) {
        $token_row = $this->require_agent_token($request);
        if (is_wp_error($token_row)) {
            return $token_row;
        }
        $agent_id = sanitize_text_field((string) $token_row->agent_id);
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }
        $verification_code = sanitize_text_field((string) ($params['verification_code'] ?? ''));
        $x_post_url = esc_url_raw((string) ($params['x_post_url'] ?? ''));
        if ($verification_code === '' || $x_post_url === '') {
            return new WP_Error('invalid_params', 'Missing verification code or X post URL.', ['status' => 400]);
        }
        $settings = get_option('growl_settings', []);
        $security = new GROWL_Security();
        $api_key = $security->decrypt($settings['x_bearer_token'] ?? '');
        if (!$api_key || empty(trim($api_key))) {
            return new WP_Error('api_not_configured', 'X verification is not properly configured.', ['status' => 503]);
        }
        $stored_code = get_transient('growl_agent_verification_code_' . $agent_id);
        if ($stored_code !== $verification_code) {
            return new WP_Error('invalid_code', 'Invalid verification code. Generate a new one.', ['status' => 400]);
        }
        $x_username = $this->extract_x_username_from_post_url($x_post_url);
        if ($x_username === '') {
            return new WP_Error('invalid_url', 'Invalid X post URL format.', ['status' => 400]);
        }
        if ($this->database->is_identifier_banned('x_username', $x_username)) {
            return new WP_Error('x_account_banned', 'This X account cannot be connected.', ['status' => 403]);
        }
        $p = $this->database->table_prefix;
        $existing_user = $this->database->get_row(
            "SELECT user_id FROM {$p}users WHERE x_username = %s LIMIT 1",
            [$x_username]
        );
        if ($existing_user) {
            return new WP_Error('account_already_bound', 'This X account is already connected to a human profile.', ['status' => 400]);
        }
        $existing_agent = $this->database->get_row(
            "SELECT agent_id FROM {$p}ai_agents WHERE x_username = %s AND agent_id <> %s LIMIT 1",
            [$x_username, $agent_id]
        );
        if ($existing_agent) {
            return new WP_Error('account_already_bound', 'This X account is already connected to another agent.', ['status' => 400]);
        }
        $x_api = new GROWL_X_API();
        $verified = $x_api->verify_post($x_username, $verification_code, $x_post_url);
        if (!$verified) {
            return new WP_Error(
                'verification_failed',
                'Verification failed. Ensure the post is public, contains the code, and the account meets requirements.',
                ['status' => 400]
            );
        }
        $service = GROWL_Public_Profile::get_instance();
        $result = $service->update_agent_profile($agent_id, [
            'x_username' => $x_username,
            'x_user_id'  => 'verified_agent_' . $agent_id,
        ]);
        if (is_wp_error($result)) {
            return $result;
        }
        delete_transient('growl_agent_verification_code_' . $agent_id);
        $service->bust_public_cache_for_identifier($agent_id);
        return rest_ensure_response([
            'success'     => true,
            'x_username'  => $x_username,
            'profile'     => $result,
        ]);
    }

    private function extract_x_username_from_post_url($url) {
        $url = (string) $url;
        if (preg_match(self::RE_X_PROFILE_URL, $url, $m)) {
            $u = $m[1];
            if (!in_array(strtolower($u), ['home', 'i', 'intent', 'search', 'hashtag', 'share'], true)) {
                return $u;
            }
        }
        return '';
    }

    /**
     * Import external identity (SAID) into agent public profile.
     */
    public function import_agent_identity(WP_REST_Request $request) {
        $token_row = $this->require_agent_token($request);
        if (is_wp_error($token_row)) {
            return $token_row;
        }
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }
        $provider = strtolower(sanitize_text_field((string) ($body['identity_provider'] ?? 'said')));
        if ($provider !== 'said') {
            return new WP_Error('unsupported_provider', 'Only identity_provider=said is supported', ['status' => 400]);
        }
        if (!class_exists('EarnFi_Said_Identity')) {
            return new WP_Error('said_unavailable', 'SAID identity service unavailable', ['status' => 503]);
        }

        $wallet = (string) ($token_row->wallet_address ?? '');
        $agent_id = (string) ($token_row->agent_id ?? '');
        $imported = EarnFi_Said_Identity::get_instance()->import_into_agent_profile($wallet, $agent_id);
        if (!$imported) {
            return new WP_Error('said_not_found', 'No SAID identity found for this wallet', ['status' => 404]);
        }

        $service = GROWL_Public_Profile::get_instance();
        $service->bust_public_cache_for_identifier($agent_id);
        $profile = $service->get_public_profile($agent_id);
        $last_synced = !empty($imported['fetched_at']) ? (int) $imported['fetched_at'] : null;
        return rest_ensure_response([
            'success'     => true,
            'imported'    => $imported,
            'last_synced' => $last_synced,
            'profile'     => $profile,
        ]);
    }

    private function normalize_signature_bytes($raw_signature) {
        if (is_array($raw_signature)) {
            $out = [];
            foreach ($raw_signature as $b) {
                if (is_int($b)) {
                    $out[] = $b & 255;
                } elseif (is_float($b)) {
                    $out[] = ((int) $b) & 255;
                } elseif (is_string($b) && $b !== '' && ctype_digit($b)) {
                    $v = (int) $b;
                    if ($v >= 0 && $v <= 255) {
                        $out[] = $v;
                    } else {
                        return null;
                    }
                } else {
                    return null;
                }
            }
            return count($out) === 64 ? $out : null;
        }
        if (!is_string($raw_signature)) {
            return null;
        }
        $s = trim($raw_signature);
        if ($s === '') {
            return null;
        }
        if (function_exists('base58_decode')) {
            $decoded = @base58_decode($s);
            if (is_string($decoded) && strlen($decoded) === 64) {
                return array_values(unpack('C*', $decoded));
            }
        }
        if (preg_match(self::RE_HEX_128, $s)) {
            $bin = @hex2bin($s);
            if (is_string($bin) && strlen($bin) === 64) {
                return array_values(unpack('C*', $bin));
            }
        }
        $b64 = preg_replace('/\s+/', '', $s);
        $raw = base64_decode($b64, true);
        if (is_string($raw) && strlen($raw) === 64) {
            return array_values(unpack('C*', $raw));
        }
        return null;
    }

    /**
     * @param array<string, mixed> $job_data
     * @return array<string, mixed>|WP_Error
     */
    private function agent_apply_token_gate_to_paid_job(WP_REST_Request $request, array $job_data, $wallet_address) {
        $tg  = new GROWL_Token_Gate_Service();
        $aux = GROWL_Token_Gate_Service::merge_request_sources_for_token_gate($request);
        if (is_wp_error($aux)) {
            return $aux;
        }
        if (class_exists('EarnFi_X_Verified')) {
            $job_data = EarnFi_X_Verified::merge_x_requirement_from_body($aux, $job_data);
        }
        $w = is_string($wallet_address) ? trim($wallet_address) : '';
        // Defer creator EARNFI eligibility until settle when identity binds from the paying wallet.
        $merged = $tg->merge_api_token_gate_into_job_data($aux, $job_data, $w, $w !== '');
        if (is_wp_error($merged)) {
            return $merged;
        }
        return $this->agent_apply_audience_from_request($request, $merged);
    }

    /**
     * Apply Seeker / Instant / delivery targeting from Agent request params onto job data.
     * Mirrors Work API targeting_legacy_fields so require_sgt_holder is enforced on create.
     *
     * @param array<string, mixed> $job_data
     * @return array<string, mixed>
     */
    private function agent_apply_audience_from_request(WP_REST_Request $request, array $job_data) {
        $seeker_raw = $request->get_param('require_sgt_holder');
        if ($seeker_raw === null || $seeker_raw === '') {
            $seeker_raw = $request->get_param('seeker_only');
        }
        if ($seeker_raw === null || $seeker_raw === '') {
            $seeker_raw = $request->get_param('require_sgt');
        }
        $want_seeker = !empty($seeker_raw)
            || !empty($job_data['require_sgt_holder'])
            || !empty($job_data['seeker_only'])
            || !empty($job_data['require_sgt']);

        $target_clients = $request->get_param('target_clients');
        if ($target_clients === null || $target_clients === '') {
            $target_clients = $job_data['target_clients'] ?? [];
        }
        if (is_string($target_clients)) {
            $decoded = json_decode($target_clients, true);
            if (is_array($decoded)) {
                $target_clients = $decoded;
            } else {
                $target_clients = array_filter(array_map('trim', explode(',', $target_clients)));
            }
        }
        if (!is_array($target_clients)) {
            $target_clients = [];
        }
        $clients = [];
        foreach ($target_clients as $ch) {
            $key = sanitize_key((string) $ch);
            if ($key !== '') {
                $clients[] = $key;
            }
        }
        $clients = array_values(array_unique($clients));

        // solana_seeker in target_clients implies Seeker SGT gate (matches in-app Seeker Only).
        if (in_array('solana_seeker', $clients, true)) {
            $want_seeker = true;
        }

        if ($want_seeker) {
            $job_data['require_sgt_holder'] = true;
            $job_data['seeker_only'] = true;
            if (!in_array('solana_seeker', $clients, true)) {
                $clients[] = 'solana_seeker';
            }
        }

        if (!empty($clients)) {
            $job_data['target_clients'] = $clients;
        }

        $policy = $request->get_param('targeting_policy');
        if (is_string($policy) && $policy !== '') {
            $decoded = json_decode($policy, true);
            $policy = is_array($decoded) ? $decoded : null;
        }
        if (is_array($policy)) {
            $job_data['targeting_policy'] = $policy;
        } elseif ($want_seeker && empty($job_data['targeting_policy']) && class_exists('GROWL_Targeting_Service')) {
            $job_data['targeting_policy'] = (new GROWL_Targeting_Service())->normalize([], [
                'require_sgt_holder' => true,
                'target_clients' => $clients,
                'min_rank' => $job_data['min_rank'] ?? 'basic',
                'is_quick' => !empty($job_data['quick']) || !empty($job_data['is_quick']),
            ]);
        }

        $effort = $request->get_param('effort_bucket');
        if (is_string($effort) && trim($effort) !== '') {
            $job_data['effort_bucket'] = sanitize_text_field($effort);
            $job_data['quick'] = true;
            $job_data['is_quick'] = 1;
        }
        $quick = $request->get_param('quick');
        if ($quick !== null && $quick !== '' && !isset($job_data['effort_bucket'])) {
            $job_data['quick'] = !empty($quick);
            $job_data['is_quick'] = !empty($quick) ? 1 : 0;
        }

        $min_rank = $request->get_param('min_rank');
        if (is_string($min_rank) && $min_rank !== '') {
            $job_data['min_rank'] = sanitize_key($min_rank);
        }

        return $job_data;
    }

    /**
     * Before returning HTTP 402: reject invalid token_gate JSON, bad shape, or insufficient EARNFI when gate is requested.
     *
     * @param object|null $token_row Row from resolve_agent_from_token (not WP_Error)
     * @return WP_Error|null
     */
    private function agent_quote_token_gate_guard(WP_REST_Request $request, $token_row, $probe) {
        if ($probe) {
            return null;
        }
        $aux = GROWL_Token_Gate_Service::merge_request_sources_for_token_gate($request);
        if (is_wp_error($aux)) {
            return $aux;
        }
        $tg = new GROWL_Token_Gate_Service();
        if (!$tg->gate_enabled_in_request_body($aux)) {
            return null;
        }
        $wallet = ($token_row && is_object($token_row) && !empty($token_row->wallet_address))
            ? (string) $token_row->wallet_address
            : '';
        return $tg->enforce_token_gate_api_quote_rules($aux, $wallet !== '' ? $wallet : null, false);
    }

    /**
     * Run shared create preflight; returns WP_Error on failure, null when ok or probe skipped.
     *
     * @param array<string, mixed> $job_data
     * @return WP_Error|null
     */
    private function agent_preflight_paid_create(array $job_data, $probe) {
        if ($probe) {
            return null;
        }
        $growl = new GROWL_REST_API();
        $preflight = $growl->validate_paid_job_data_before_settlement($job_data);
        return is_wp_error($preflight) ? $preflight : null;
    }

    /**
     * Apply Agent API contest defaults so paid creates pass full contest validation.
     *
     * @param array<string, mixed> $job_data
     * @return array<string, mixed>
     */
    private function agent_enrich_contest_job_data(array $job_data) {
        return GROWL_Vote_Service::enrich_contest_job_defaults($job_data);
    }

    /**
     * @return array<string, mixed>
     */
    private function agent_contest_setup_params_from_request(WP_REST_Request $request) {
        $keys = [
            'title', 'instructions', 'description', 'total_prize_pool', 'deadline',
            'contest_mode_card', 'decision_mode', 'voting_enabled', 'voting_binding_strict',
            'voting_hours', 'voting_visibility', 'weighting_enabled',
            'vote_gate_enabled', 'vote_token_mint', 'vote_min_token_amount', 'vote_min_token_usd', 'vote_gate_use_usd',
            'winner_count', 'winner_selection_mode', 'winner_selection',
            'participants_type', 'max_participants', 'verification_method', 'display_submissions', 'prizes', 'vote_gate',
        ];
        $source = [];
        foreach ($keys as $key) {
            $val = $request->get_param($key);
            if ($val !== null) {
                $source[$key] = $val;
            }
        }
        return GROWL_Vote_Service::merge_contest_create_fields_from_source([], $source);
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function agent_build_contest_job_data(WP_REST_Request $request, array $base) {
        $merged = GROWL_Vote_Service::merge_contest_create_fields_from_source($base, $this->agent_contest_setup_params_from_request($request));
        return $this->agent_enrich_contest_job_data($merged);
    }

    /**
     * Build suggested_job_data for Agent social creates (matches Work API shape).
     *
     * @param object|null $token_row
     * @return array<string, mixed>
     */
    private function agent_build_social_suggested_job_data(
        string $task_type,
        int $slots,
        string $reward_per_user,
        string $execution_mode,
        $quick,
        string $content_url,
        string $title,
        $token_row = null,
        $effort_bucket = null,
        $target_clients = null
    ) {
        $job_types       = new GROWL_Job_Types();
        $job_type_config = $job_types->get_job_type($task_type);
        $lock_hours      = 24;
        if ($job_type_config && isset($job_type_config['lock_duration'])) {
            $ld = $job_type_config['lock_duration'];
            $lock_hours = isset($ld['default_hours']) ? intval($ld['default_hours']) : 24;
        }
        $req_defaults = [];
        $req_src      = ($job_type_config && ! empty($job_type_config['requirements']) && is_array($job_type_config['requirements']))
            ? $job_type_config['requirements']
            : [];
        foreach ($req_src as $rk => $rv) {
            if (is_array($rv) && array_key_exists('default', $rv)) {
                $req_defaults[ $rk ] = $rv['default'];
            }
        }
        $effort = is_string($effort_bucket) ? sanitize_text_field($effort_bucket) : '';
        $is_quick = (bool) $quick || ($effort !== '');
        $data = [
            'job_type'            => $task_type,
            'action_type'         => $task_type,
            'base_reward'         => floatval($reward_per_user),
            'user_count'          => $slots,
            'lock_duration_hours' => $lock_hours,
            'min_rank'            => 'basic',
            'requirements'        => $req_defaults,
            'title'               => $title,
            'execution_mode'      => $execution_mode,
            'execution_tier'      => 'best_effort',
            'creator_type'        => 'agent',
            'quick'               => $is_quick,
            'is_quick'            => $is_quick ? 1 : 0,
            'effort_bucket'       => $effort !== '' ? $effort : null,
            'target_clients'      => $target_clients,
        ];
        if ($token_row && is_object($token_row)) {
            $data['creator_agent_id'] = substr((string) $token_row->agent_id, 0, 32);
        }
        if ($content_url !== '') {
            $data['content_url'] = $content_url;
            if ($task_type === 'video') {
                $data['youtube_url'] = $content_url;
            } else {
                $data['x_post_id'] = $content_url;
            }
        }
        return $data;
    }

    /**
     * Build standard paid-create JSON after PayAI settlement.
     *
     * @param array<string, mixed> $fd
     * @return WP_REST_Response|WP_Error
     */
    private function agent_paid_create_response(array $fd, $token_row, string $agent_token, string $total_cost, array $extra = []) {
        $job_id = isset($fd['job_id']) ? (string) $fd['job_id'] : '';
        if ($job_id === '') {
            return new WP_Error('job_creation_failed', 'Payment settled but job_id was missing', ['status' => 500]);
        }
        $secret_plain = $this->base64url_encode(random_bytes(24));
        $this->database->insert('ai_job_access', [
            'job_id'      => $job_id,
            'agent_id'    => substr((string) $token_row->agent_id, 0, 32),
            'secret_hash' => hash('sha256', $secret_plain),
            'created_at'  => $this->now_mysql(),
        ]);
        $base = untrailingslashit(rest_url($this->namespace));
        $payload = array_merge([
            'success'          => true,
            'paid'             => true,
            'job_id'           => $job_id,
            'transaction_hash' => $fd['transaction_hash'] ?? null,
            'total_cost'       => $total_cost,
            'secret'           => $secret_plain,
            'status_url'       => $base . '/jobs/' . rawurlencode($job_id) . '?secret=' . rawurlencode($secret_plain),
            'hint'             => 'Store secret and agent_token securely. Polling with secret does not require another payment.',
            'next_step'        => $this->paid_create_success_next_step(),
        ], $extra);
        if ($agent_token !== '') {
            $payload['agent_token'] = $agent_token;
        }
        if (isset($fd['settlement'])) {
            $payload['settlement'] = $fd['settlement'];
        }
        $resp = rest_ensure_response($payload);
        $tx = isset($fd['transaction_hash']) ? (string) $fd['transaction_hash'] : '';
        if ($tx !== '' && class_exists('PayAI_X402_Http', false)) {
            PayAI_X402_Http::attach_payment_response($resp, ['transaction' => $tx, 'success' => true]);
        }
        return $resp;
    }

    public function agent_v2_create_manual_job(WP_REST_Request $request) {
        $this->merge_json_and_get_for_paid_creates($request);
        $agent_token = $this->extract_agent_token($request);
        $token_row = $this->resolve_agent_token_row_optional($request);
        if (is_wp_error($token_row)) {
            return $token_row;
        }

        $title = sanitize_text_field($request->get_param('title') ?? '');
        $instructions = (string) ($request->get_param('instructions') ?? '');
        $reward_per_user = (string) ($request->get_param('reward_per_user') ?? '');
        $slots = intval($request->get_param('slots') ?? 0);
        $verification_method = sanitize_text_field($request->get_param('verification_method') ?? 'manual');
        if (!in_array($verification_method, ['manual','auto'], true)) $verification_method = 'manual';
        $execution_mode = $this->agent_execution_mode_from_request($request, 'human');
        if (is_wp_error($execution_mode)) {
            return $execution_mode;
        }

        $payment_sig_header = trim((string) ($request->get_header('payment-signature') ?? ''));
        $has_payment_sig = ($payment_sig_header !== '');

        $probe = ($title === '' || $slots <= 0 || $reward_per_user === '');
        $job_types = GROWL_Job_Types::get_instance();
        $min_reward_usd = number_format((float) $job_types->calculate_minimum_reward('custom_job', []), 6, '.', '');
        if ($probe) {
            $title = 'Manual job';
            $slots = 10;
            $reward_per_user = $min_reward_usd;
            if ($instructions === '') $instructions = 'Describe what workers must do and what proof is required.';
        }

        // Quote using existing pricing estimator (custom_job).
        $work_api = new Earnfi_Work_REST_API();
        $fake = new WP_REST_Request('GET', '/work/v1/pricing/estimate');
        $fake->set_param('task_type', 'custom_job');
        $fake->set_param('slots', $slots);
        $fake->set_param('reward_per_user', $reward_per_user);
        $estimate = $work_api->get_pricing_estimate($fake);
        if (is_wp_error($estimate)) return $estimate;
        $estimate_data = $estimate->get_data();
        $total_cost = (string) ($estimate_data['total_cost'] ?? '0');

        $resource_url = untrailingslashit(rest_url($this->namespace . '/jobs/manual'));
        $rd =
            'Fund a custom job you write yourself: title, full instructions, how you will verify work, and how many people you need. ' .
            'Use it for surveys, creative briefs, reviews, moderation, labeling, or any task that does not match a one-click social action. ' .
            'After payment you review and approve work in the creator flow. Best for clear briefs and easy-to-check proof; avoid vague or expert-only tasks unless you spell out steps and examples.';
        $atomic = $this->quote_atomic_from_total_cost_usd_string($total_cost);
        $xa = $this->x402_accepts_for_atomic($resource_url, $atomic, $rd, $request);
        if (is_wp_error($xa)) {
            return $xa;
        }
        $quote_fingerprint = Earnfi_X402_Priced_Quote::fingerprint([
            'route'           => 'jobs/manual',
            'title'           => substr($title, 0, 100),
            'slots'           => (string) $slots,
            'reward_per_user' => (string) $reward_per_user,
            'atomic'          => $atomic,
        ]);

        $idem = $this->agent_check_idempotency($request, 'jobs/manual', $probe, $has_payment_sig);
        if ($idem) {
            return $idem;
        }

        $effort_bucket = sanitize_text_field((string) ($request->get_param('effort_bucket') ?? ''));
        $target_clients = $request->get_param('target_clients');
        $payment_method = sanitize_key((string) ($request->get_param('payment_method') ?? 'connected_wallet'));
        if (!in_array($payment_method, ['connected_wallet', 'creator_wallet'], true)) {
            $payment_method = 'connected_wallet';
        }

        if ($payment_method === 'creator_wallet' && !$probe) {
            if (!$token_row || is_wp_error($token_row)) {
                return new WP_Error('missing_agent_token', 'agent_token is required for Creator Wallet create', ['status' => 401]);
            }
            $user_id = $this->ensure_user_id_for_wallet((string) $token_row->wallet_address);
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            $suggested_job_data = [
                'job_type'            => 'custom_job',
                'action_type'         => 'custom_job',
                'base_reward'         => floatval($reward_per_user),
                'user_count'          => $slots,
                'lock_duration_hours' => 24,
                'min_rank'            => 'basic',
                'requirements'        => [],
                'title'               => substr($title, 0, 100),
                'custom_instructions' => $instructions,
                'verification_method' => $verification_method,
                'execution_mode'      => $execution_mode,
                'creator_type'        => 'agent',
                'creator_agent_id'    => substr((string) $token_row->agent_id, 0, 32),
                'quick'               => ($effort_bucket !== ''),
                'is_quick'            => $effort_bucket !== '' ? 1 : 0,
                'effort_bucket'       => $effort_bucket !== '' ? $effort_bucket : null,
                'target_clients'      => $target_clients,
                'payment_method'      => 'creator_wallet',
            ];
            $merged_ctx = $this->agent_merge_partner_job_context($request, $suggested_job_data, $token_row);
            if (is_wp_error($merged_ctx)) {
                return $merged_ctx;
            }
            $suggested_job_data = $merged_ctx;
            $gate_merged = $this->agent_apply_token_gate_to_paid_job($request, $suggested_job_data, (string) $token_row->wallet_address);
            if (is_wp_error($gate_merged)) {
                return $gate_merged;
            }
            $suggested_job_data = $gate_merged;
            $growl_api = new GROWL_REST_API();
            $fin = $growl_api->creator_create_job_with_wallet((int) $user_id, $suggested_job_data, 'custom_job');
            if (is_wp_error($fin)) {
                return $fin;
            }
            $base = untrailingslashit(rest_url($this->namespace));
            $resp = $this->agent_paid_create_response($fin->get_data(), $token_row, $agent_token, $total_cost);
            if (is_wp_error($resp)) {
                return $resp;
            }
            $d = $resp->get_data();
            $d['payment_method'] = 'creator_wallet';
            $d['submissions_url']   = $base . '/jobs/' . rawurlencode($d['job_id']) . '/submissions?secret=' . rawurlencode($d['secret']);
            $d['verifications_url'] = $base . '/jobs/' . rawurlencode($d['job_id']) . '/verifications?agent_token=' . rawurlencode($agent_token);
            $final = rest_ensure_response($d);
            $this->agent_store_idempotency($request, 'jobs/manual', $final, false);
            return $final;
        }

        if (!$has_payment_sig) {
            $qg = $this->agent_quote_token_gate_guard($request, $token_row, $probe);
            if (is_wp_error($qg)) {
                return $qg;
            }

            if (!$probe) {
                $preflight_job = [
                    'job_type'            => 'custom_job',
                    'action_type'         => 'custom_job',
                    'base_reward'         => floatval($reward_per_user),
                    'user_count'          => $slots,
                    'lock_duration_hours' => 24,
                    'requirements'        => [],
                    'title'               => substr($title, 0, 100),
                    'custom_instructions' => $instructions,
                    'verification_method' => $verification_method,
                ];
                $preflight_err = $this->agent_preflight_paid_create($preflight_job, false);
                if ($preflight_err) {
                    return $preflight_err;
                }
            }

            $extensions = [
                'bazaar' => [
                    'info' => [
                        'input' => [
                            'type'   => 'http',
                            'method' => $request->get_method(),
                            'queryParams' => (object) [],
                        ],
                        'output' => [
                            'type'    => 'json',
                            'example' => [
                                'ok'         => true,
                                'paid'       => true,
                                'job_id'     => 'EFâ€¦',
                                'total_cost' => $total_cost,
                            ],
                        ],
                    ],
                    'schema' => $this->agent_manual_x402_extensions_schema(),
                ],
            ];
            $x402_payload = [
                'x402Version' => 2,
                'accepts'     => $xa['accepts'],
                'resource'    => $xa['resource'],
                'extensions'  => $extensions,
            ];
            Earnfi_X402_Priced_Quote::attach_fingerprint($x402_payload, $quote_fingerprint);
            $this->agent_send_402_quote($x402_payload, null);
            $resp402 = new WP_REST_Response($this->agent_attach_quote_guidance([
                'payment_required' => true,
                'x402Version'      => 2,
                'accepts'          => $x402_payload['accepts'],
                'resource'         => $x402_payload['resource'],
                'extensions'       => $x402_payload['extensions'],
                'x402'             => $x402_payload,
                'total_cost'       => $total_cost,
                'min_reward_usd'   => $min_reward_usd,
                'descriptor_probe' => $probe ? true : false,
            ]), 402);
            $this->agent_store_idempotency($request, 'jobs/manual', $resp402, $probe);
            return $resp402;
        }

        if ($probe) return new WP_Error('invalid_params', 'Provide title, slots, and reward_per_user before paying', ['status' => 400]);

        $suggested_job_data = [
            'job_type'            => 'custom_job',
            'action_type'         => 'custom_job',
            'base_reward'         => floatval($reward_per_user),
            'user_count'          => $slots,
            'lock_duration_hours' => 24,
            'min_rank'            => 'basic',
            'requirements'        => [],
            'title'               => substr($title, 0, 100),
            'custom_instructions' => $instructions,
            'verification_method' => $verification_method,
            'execution_mode'      => $execution_mode,
            'creator_type'        => 'agent',
        ];
        if ($token_row && is_object($token_row)) {
            $suggested_job_data['creator_agent_id'] = substr((string) $token_row->agent_id, 0, 32);
        }

        $merged_ctx = $this->agent_merge_partner_job_context($request, $suggested_job_data, $token_row);
        if (is_wp_error($merged_ctx)) {
            return $merged_ctx;
        }
        $suggested_job_data = $merged_ctx;

        $gate_wallet = ($token_row && is_object($token_row)) ? (string) $token_row->wallet_address : '';
        $gate_merged = $this->agent_apply_token_gate_to_paid_job($request, $suggested_job_data, $gate_wallet);
        if (is_wp_error($gate_merged)) {
            return $gate_merged;
        }
        $suggested_job_data = $gate_merged;

        $parsed = PayAI_Flow::parse_payment_signature_header($payment_sig_header);
        if (is_wp_error($parsed)) {
            $st = intval($parsed->get_error_data()['status'] ?? 400);
            return new WP_Error($parsed->get_error_code(), $parsed->get_error_message(), ['status' => $st]);
        }

        $pay_err = $this->agent_validate_priced_payment($parsed, $atomic, $quote_fingerprint);
        if ($pay_err instanceof WP_Error) {
            return $pay_err;
        }

        $bound = $this->agent_settle_payai_paid_create($request, $token_row, $agent_token, $parsed, $suggested_job_data);
        if (is_wp_error($bound)) {
            return $bound;
        }
        $token_row = $bound['token_row'];
        $agent_token = $bound['agent_token'];
        $fd = $bound['fd'];
        $base = untrailingslashit(rest_url($this->namespace));
        $resp = $this->agent_paid_create_response($fd, $token_row, $agent_token, $total_cost);
        if (is_wp_error($resp)) return $resp;
        $d = $resp->get_data();
        $d['submissions_url']   = $base . '/jobs/' . rawurlencode($d['job_id']) . '/submissions?secret=' . rawurlencode($d['secret']);
        $d['verifications_url'] = $base . '/jobs/' . rawurlencode($d['job_id']) . '/verifications?agent_token=' . rawurlencode($agent_token);
        if (!empty($bound['agent_bound'])) {
            $d['agent_bound'] = true;
            if (!empty($bound['agent_name'])) {
                $d['agent_name'] = $bound['agent_name'];
            }
        }
        $final = rest_ensure_response($d);
        $this->agent_store_idempotency($request, 'jobs/manual', $final, false);
        return $final;
    }

    public function agent_v2_create_contest_job(WP_REST_Request $request) {
        $this->merge_json_and_get_for_paid_creates($request);
        $agent_token = $this->extract_agent_token($request);
        $token_row = $this->resolve_agent_token_row_optional($request);
        if (is_wp_error($token_row)) {
            return $token_row;
        }

        $title = sanitize_text_field($request->get_param('title') ?? '');
        $instructions = (string) ($request->get_param('instructions') ?? '');
        $total_prize_pool = (string) ($request->get_param('total_prize_pool') ?? '');
        $execution_mode = $this->agent_execution_mode_from_request($request, 'human');
        if (is_wp_error($execution_mode)) {
            return $execution_mode;
        }

        $payment_sig_header = trim((string) ($request->get_header('payment-signature') ?? ''));
        $has_payment_sig = ($payment_sig_header !== '');

        $probe = ($title === '' || $total_prize_pool === '' || floatval($total_prize_pool) <= 0);
        if ($probe) {
            $title = 'Contest';
            $total_prize_pool = '5';
            if ($instructions === '') $instructions = 'Describe rules, deadline, and how winners will be selected.';
        }

        $contest_conf       = (new GROWL_Job_Types())->get_job_type('contest');
        $min_prize_pool_usd = number_format((float) ($contest_conf['contest_min_prize_pool'] ?? 3.0), 6, '.', '');

        $fee = $this->get_platform_fee_percent() / 100.0;
        $expected_total = round(floatval($total_prize_pool) * (1 + $fee), 6);
        $total_cost = number_format($expected_total, 6, '.', '');

        $resource_url = untrailingslashit(rest_url($this->namespace . '/jobs/contest'));
        $rd =
            'Run a prize-based contest: set a total prize pool and how winners will be chosen. ' .
            'Use it for creative challenges, community competitions, or any campaign where people submit entries and you pick top results. ' .
            'The price includes the platform share. After payment you publish rules, review entries, and select winners in the contest tools.';
        $atomic = $this->quote_atomic_from_total_cost_usd_string($total_cost);
        $xa = $this->x402_accepts_for_atomic($resource_url, $atomic, $rd, $request);
        if (is_wp_error($xa)) {
            return $xa;
        }
        $quote_fingerprint = Earnfi_X402_Priced_Quote::fingerprint([
            'route'            => 'jobs/contest',
            'total_prize_pool' => (string) $total_prize_pool,
            'title'            => substr($title, 0, 100),
            'atomic'           => $atomic,
        ]);

        $idem = $this->agent_check_idempotency($request, 'jobs/contest', $probe, $has_payment_sig);
        if ($idem) {
            return $idem;
        }

        $payment_method = sanitize_key((string) ($request->get_param('payment_method') ?? 'connected_wallet'));
        if (!in_array($payment_method, ['connected_wallet', 'creator_wallet'], true)) {
            $payment_method = 'connected_wallet';
        }

        if ($payment_method === 'creator_wallet' && !$probe) {
            if (!$token_row || is_wp_error($token_row)) {
                return new WP_Error('missing_agent_token', 'agent_token is required for Creator Wallet create', ['status' => 401]);
            }
            $user_id = $this->ensure_user_id_for_wallet((string) $token_row->wallet_address);
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            $suggested_job_data = $this->agent_build_contest_job_data($request, [
                'job_type'            => 'contest',
                'action_type'         => 'contest',
                'title'               => substr($title, 0, 100),
                'custom_instructions' => $instructions,
                'mode'                => 'contest',
                'total_prize_pool'    => floatval($total_prize_pool),
                'execution_mode'      => $execution_mode,
                'creator_type'        => 'agent',
                'creator_agent_id'    => substr((string) $token_row->agent_id, 0, 32),
                'payment_method'      => 'creator_wallet',
                'target_clients'      => $request->get_param('target_clients'),
            ]);
            $merged_ctx = $this->agent_merge_partner_job_context($request, $suggested_job_data, $token_row);
            if (is_wp_error($merged_ctx)) {
                return $merged_ctx;
            }
            $suggested_job_data = $merged_ctx;
            $gate_merged = $this->agent_apply_token_gate_to_paid_job($request, $suggested_job_data, (string) $token_row->wallet_address);
            if (is_wp_error($gate_merged)) {
                return $gate_merged;
            }
            $suggested_job_data = $gate_merged;
            $growl_api = new GROWL_REST_API();
            $fin = $growl_api->creator_create_contest_with_wallet((int) $user_id, $suggested_job_data);
            if (is_wp_error($fin)) {
                return $fin;
            }
            $resp = $this->agent_paid_create_response($fin->get_data(), $token_row, $agent_token, $total_cost);
            if (is_wp_error($resp)) {
                return $resp;
            }
            $d = $resp->get_data();
            $d['payment_method'] = 'creator_wallet';
            $base = untrailingslashit(rest_url($this->namespace));
            $d['contest_submissions_url'] = $base . '/jobs/' . rawurlencode($d['job_id']) . '/contest/submissions?agent_token=' . rawurlencode($agent_token);
            $voting = GROWL_Vote_Service::resolve_voting_config_from_payload($suggested_job_data);
            $d['contest_mode_card'] = GROWL_Vote_Service::contest_mode_card_from_row((object) $voting);
            $d['voting_enabled'] = !empty($voting['voting_enabled']);
            $final = rest_ensure_response($d);
            $this->agent_store_idempotency($request, 'jobs/contest', $final, false);
            return $final;
        }

        if (!$has_payment_sig) {
            $qg = $this->agent_quote_token_gate_guard($request, $token_row, $probe);
            if (is_wp_error($qg)) {
                return $qg;
            }

            if (!$probe) {
                $preflight_job = $this->agent_build_contest_job_data($request, [
                    'job_type'            => 'contest',
                    'action_type'         => 'contest',
                    'title'               => substr($title, 0, 100),
                    'custom_instructions' => $instructions,
                    'mode'                => 'contest',
                    'total_prize_pool'    => floatval($total_prize_pool),
                    'execution_mode'      => $execution_mode,
                    'creator_type'        => 'agent',
                ]);
                $preflight_err = $this->agent_preflight_paid_create($preflight_job, false);
                if ($preflight_err) {
                    return $preflight_err;
                }
            }

            $extensions = [
                'bazaar' => [
                    'info' => [
                        'input' => [
                            'type'   => 'http',
                            'method' => $request->get_method(),
                            'queryParams' => (object) [],
                        ],
                        'output' => [
                            'type'    => 'json',
                            'example' => [
                                'ok'         => true,
                                'paid'       => true,
                                'total_cost' => $total_cost,
                            ],
                        ],
                    ],
                    'schema' => $this->agent_contest_x402_extensions_schema(),
                ],
            ];
            $x402_payload = [
                'x402Version' => 2,
                'accepts'     => $xa['accepts'],
                'resource'    => $xa['resource'],
                'extensions'  => $extensions,
            ];
            Earnfi_X402_Priced_Quote::attach_fingerprint($x402_payload, $quote_fingerprint);
            $this->agent_send_402_quote($x402_payload, null);
            $resp402 = new WP_REST_Response($this->agent_attach_quote_guidance([
                'payment_required'   => true,
                'x402Version'        => 2,
                'accepts'            => $x402_payload['accepts'],
                'resource'           => $x402_payload['resource'],
                'extensions'         => $x402_payload['extensions'],
                'x402'               => $x402_payload,
                'total_cost'         => $total_cost,
                'min_prize_pool_usd' => $min_prize_pool_usd,
                'descriptor_probe'   => $probe ? true : false,
            ]), 402);
            $this->agent_store_idempotency($request, 'jobs/contest', $resp402, $probe);
            return $resp402;
        }

        if ($probe) return new WP_Error('invalid_params', 'Provide title and total_prize_pool before paying', ['status' => 400]);

        $suggested_job_data = $this->agent_build_contest_job_data($request, [
            'job_type'            => 'contest',
            'action_type'         => 'contest',
            'title'               => substr($title, 0, 100),
            'custom_instructions' => $instructions,
            'mode'                => 'contest',
            'total_prize_pool'    => floatval($total_prize_pool),
            'execution_mode'      => $execution_mode,
            'creator_type'        => 'agent',
        ]);
        if ($token_row && is_object($token_row)) {
            $suggested_job_data['creator_agent_id'] = substr((string) $token_row->agent_id, 0, 32);
        }

        $merged_ctx = $this->agent_merge_partner_job_context($request, $suggested_job_data, $token_row);
        if (is_wp_error($merged_ctx)) {
            return $merged_ctx;
        }
        $suggested_job_data = $merged_ctx;

        $gate_wallet = ($token_row && is_object($token_row)) ? (string) $token_row->wallet_address : '';
        $gate_merged = $this->agent_apply_token_gate_to_paid_job($request, $suggested_job_data, $gate_wallet);
        if (is_wp_error($gate_merged)) {
            return $gate_merged;
        }
        $suggested_job_data = $gate_merged;

        $parsed = PayAI_Flow::parse_payment_signature_header($payment_sig_header);
        if (is_wp_error($parsed)) {
            $st = intval($parsed->get_error_data()['status'] ?? 400);
            return new WP_Error($parsed->get_error_code(), $parsed->get_error_message(), ['status' => $st]);
        }
        $pay_err = $this->agent_validate_priced_payment($parsed, $atomic, $quote_fingerprint);
        if ($pay_err instanceof WP_Error) {
            return $pay_err;
        }

        $bound = $this->agent_settle_payai_paid_create($request, $token_row, $agent_token, $parsed, $suggested_job_data);
        if (is_wp_error($bound)) {
            return $bound;
        }
        $token_row = $bound['token_row'];
        $agent_token = $bound['agent_token'];
        $fd = $bound['fd'];
        $resp = $this->agent_paid_create_response($fd, $token_row, $agent_token, $total_cost);
        if (is_wp_error($resp)) return $resp;
        $d = $resp->get_data();
        $base = untrailingslashit(rest_url($this->namespace));
        $d['contest_submissions_url'] = $base . '/jobs/' . rawurlencode($d['job_id']) . '/contest/submissions?agent_token=' . rawurlencode($agent_token);
        $voting = GROWL_Vote_Service::resolve_voting_config_from_payload($suggested_job_data);
        $d['contest_mode_card'] = GROWL_Vote_Service::contest_mode_card_from_row((object) $voting);
        $d['voting_enabled'] = !empty($voting['voting_enabled']);
        if (!empty($bound['agent_bound'])) {
            $d['agent_bound'] = true;
            if (!empty($bound['agent_name'])) {
                $d['agent_name'] = $bound['agent_name'];
            }
        }
        $final = rest_ensure_response($d);
        $this->agent_store_idempotency($request, 'jobs/contest', $final, false);
        return $final;
    }

    public function agent_v2_create_social_job(WP_REST_Request $request) {
        $this->merge_json_and_get_for_paid_creates($request);
        $task_type = sanitize_text_field($request->get_param('task_type') ?? '');
        $slots = intval($request->get_param('slots') ?? 0);
        $reward_per_user = (string) ($request->get_param('reward_per_user') ?? '');
        $execution_mode = sanitize_text_field($request->get_param('execution_mode') ?? 'human');
        $ex = earnfi_normalize_execution_mode_for_create($execution_mode);
        if (empty($ex['ok'])) {
            return new WP_Error(
                isset($ex['error']) ? (string) $ex['error'] : 'execution_mode_unavailable',
                isset($ex['message']) ? (string) $ex['message'] : 'Invalid execution_mode',
                ['status' => isset($ex['status']) ? (int) $ex['status'] : 400]
            );
        }
        $execution_mode = (string) $ex['mode'];
        $quick = filter_var($request->get_param('quick') ?? false, FILTER_VALIDATE_BOOLEAN);
        $content_url = trim((string) ($request->get_param('content_url') ?? ''));
        $content_url = $content_url !== '' ? esc_url_raw($content_url) : '';
        $title = sanitize_text_field($request->get_param('title') ?? '');
        if ($title !== '') {
            $title = substr($title, 0, 100);
        }

        $agent_token = $this->extract_agent_token($request);
        $token_row = $this->resolve_agent_token_row_optional($request);
        if (is_wp_error($token_row)) {
            return $token_row;
        }

        $payment_sig_header = $request->get_header('payment-signature');
        if (!is_string($payment_sig_header)) {
            $payment_sig_header = '';
        }
        $payment_sig_header = trim($payment_sig_header);
        $has_payment_sig = ($payment_sig_header !== '');

        $probe = ($task_type === '' || $slots <= 0 || $reward_per_user === '');
        if ($probe) {
            $task_type = 'like';
            $slots = 10;
            $reward_per_user = '0.05';
        } else {
            $job_type_probe = (new GROWL_Job_Types())->get_job_type($task_type);
            if (!$job_type_probe) {
                return new WP_Error('invalid_task_type', 'Unknown task_type. Use GET /agent/v2/catalog for supported social job types.', ['status' => 400]);
            }
            $cat = (string) ($job_type_probe['category'] ?? '');
            if ($cat === 'manual' || $cat === 'campaigns') {
                return new WP_Error('invalid_task_type', 'task_type is not supported on /jobs/social. Use /jobs/manual or /jobs/contest for this job type.', ['status' => 400]);
            }
        }

        // Quote using the same pricing engine as Work API.
        $work_api = new Earnfi_Work_REST_API();
        $fake = new WP_REST_Request('GET', '/work/v1/pricing/estimate');
        $fake->set_param('task_type', $task_type);
        $fake->set_param('slots', $slots);
        $fake->set_param('reward_per_user', $reward_per_user);
        $estimate = $work_api->get_pricing_estimate($fake);
        if (is_wp_error($estimate)) {
            return $estimate;
        }
        $estimate_data = $estimate->get_data();

        $resource_url = untrailingslashit(rest_url($this->namespace . '/jobs/social'));
        $rd =
            'Fund social and quick-engagement work: follows, likes, reposts, quotes, comments, short video watch tasks, and other catalog job types. ' .
            'Set how many people you need and the reward per person, and add the post, video, or profile you want to promote. ' .
            'Typical uses: grow a profile, boost a post or clip, or spread a simple action to many participants. ' .
            'After payment you get links to track the job and review submissions. Best for public, easy-to-explain actions; not for highly niche or expert-only work.';

        $settings = get_option('growl_settings', []);
        $decimals = intval($settings['usdc_decimals'] ?? 6);
        $multiplier = bcpow('10', (string) $decimals, 0);
        $total_cost = (string) ($estimate_data['total_cost'] ?? '0');
        $atomic = bcmul($total_cost, $multiplier, 0);

        $xa = $this->x402_accepts_for_atomic($resource_url, $atomic, $rd, $request);
        if (is_wp_error($xa)) {
            return $xa;
        }
        $quote_fingerprint = Earnfi_X402_Priced_Quote::fingerprint([
            'route'           => 'jobs/social',
            'task_type'       => $task_type,
            'slots'           => (string) $slots,
            'reward_per_user' => (string) $reward_per_user,
            'atomic'          => $atomic,
        ]);

        $idem = $this->agent_check_idempotency($request, 'jobs/social', $probe, $has_payment_sig);
        if ($idem) {
            return $idem;
        }

        $effort_bucket = sanitize_text_field((string) ($request->get_param('effort_bucket') ?? ''));
        $target_clients = $request->get_param('target_clients');
        $payment_method = sanitize_key((string) ($request->get_param('payment_method') ?? 'connected_wallet'));
        if (!in_array($payment_method, ['connected_wallet', 'creator_wallet'], true)) {
            $payment_method = 'connected_wallet';
        }

        // Creator Wallet path - maps agent wallet â†’ Growl user, debit wallet, create.
        if ($payment_method === 'creator_wallet' && !$probe) {
            if (!$token_row || is_wp_error($token_row)) {
                return new WP_Error('missing_agent_token', 'agent_token is required for Creator Wallet create', ['status' => 401]);
            }
            $user_id = $this->ensure_user_id_for_wallet((string) $token_row->wallet_address);
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            $suggested_job_data = $this->agent_build_social_suggested_job_data(
                $task_type,
                $slots,
                $reward_per_user,
                $execution_mode,
                $quick,
                $content_url,
                $title,
                $token_row,
                $effort_bucket,
                $target_clients
            );
            $merged_ctx = $this->agent_merge_partner_job_context($request, $suggested_job_data, $token_row);
            if (is_wp_error($merged_ctx)) {
                return $merged_ctx;
            }
            $suggested_job_data = $merged_ctx;
            $gate_merged = $this->agent_apply_token_gate_to_paid_job($request, $suggested_job_data, (string) $token_row->wallet_address);
            if (is_wp_error($gate_merged)) {
                return $gate_merged;
            }
            $suggested_job_data = $gate_merged;
            $growl_api = new GROWL_REST_API();
            $fin = $growl_api->creator_create_job_with_wallet((int) $user_id, $suggested_job_data, $task_type);
            if (is_wp_error($fin)) {
                return $fin;
            }
            $fd = $fin->get_data();
            $resp = $this->agent_paid_create_response(is_array($fd) ? $fd : [], $token_row, $agent_token, $total_cost);
            if (is_wp_error($resp)) {
                return $resp;
            }
            $d = $resp->get_data();
            $d['payment_method'] = 'creator_wallet';
            $final = rest_ensure_response($d);
            $this->agent_store_idempotency($request, 'jobs/social', $final, false);
            return $final;
        }

        if (!$has_payment_sig) {
            $qg = $this->agent_quote_token_gate_guard($request, $token_row, $probe);
            if (is_wp_error($qg)) {
                return $qg;
            }

            $preflight_job = null;
            $min_reward_usd = number_format((float) (new GROWL_Job_Types())->calculate_minimum_reward($task_type, []), 6, '.', '');
            if (!$probe) {
                $preflight_job = $this->agent_build_social_suggested_job_data(
                    $task_type,
                    $slots,
                    $reward_per_user,
                    $execution_mode,
                    $quick,
                    $content_url,
                    $title,
                    $token_row,
                    $effort_bucket,
                    $target_clients
                );
                $min_reward_usd = number_format(
                    (float) (new GROWL_Job_Types())->calculate_minimum_reward($task_type, $preflight_job['requirements'] ?? []),
                    6,
                    '.',
                    ''
                );
                $preflight_err = $this->agent_preflight_paid_create($preflight_job, false);
                if ($preflight_err) {
                    return $preflight_err;
                }
            }

            $extensions    = $this->build_agent_social_bazaar($request, $estimate_data, $task_type, $slots, $reward_per_user);
            $x402_payload  = [
                'x402Version' => 2,
                'accepts'     => $xa['accepts'],
                'resource'    => $xa['resource'],
                'extensions'  => $extensions,
            ];
            Earnfi_X402_Priced_Quote::attach_fingerprint($x402_payload, $quote_fingerprint);
            $this->agent_send_402_quote($x402_payload, null);
            $resp402 = new WP_REST_Response($this->agent_attach_quote_guidance([
                'payment_required'  => true,
                'x402Version'      => 2,
                'accepts'          => $x402_payload['accepts'],
                'resource'         => $x402_payload['resource'],
                'extensions'       => $x402_payload['extensions'],
                'x402'             => $x402_payload,
                'total_cost'       => $total_cost,
                'task_type'        => $task_type,
                'slots'            => $slots,
                'reward_per_user'  => (string) $reward_per_user,
                'min_reward_usd'   => $min_reward_usd,
                'descriptor_probe' => $probe ? true : false,
            ]), 402);
            $this->agent_store_idempotency($request, 'jobs/social', $resp402, $probe);
            return $resp402;
        }

        if ($probe) {
            return new WP_Error('invalid_params', 'Provide task_type, slots, and reward_per_user before paying', ['status' => 400]);
        }

        // Build job data (same shape as Work API suggested_job_data).
        $suggested_job_data = $this->agent_build_social_suggested_job_data(
            $task_type,
            $slots,
            $reward_per_user,
            $execution_mode,
            $quick,
            $content_url,
            $title,
            $token_row,
            $effort_bucket,
            $target_clients
        );

        $merged_ctx = $this->agent_merge_partner_job_context($request, $suggested_job_data, $token_row);
        if (is_wp_error($merged_ctx)) {
            return $merged_ctx;
        }
        $suggested_job_data = $merged_ctx;

        $gate_wallet = ($token_row && is_object($token_row)) ? (string) $token_row->wallet_address : '';
        $gate_merged = $this->agent_apply_token_gate_to_paid_job($request, $suggested_job_data, $gate_wallet);
        if (is_wp_error($gate_merged)) {
            return $gate_merged;
        }
        $suggested_job_data = $gate_merged;

        $parsed = PayAI_Flow::parse_payment_signature_header($payment_sig_header);
        if (is_wp_error($parsed)) {
            $st = intval($parsed->get_error_data()['status'] ?? 400);
            return new WP_Error($parsed->get_error_code(), $parsed->get_error_message(), ['status' => $st]);
        }

        $pay_err = $this->agent_validate_priced_payment($parsed, (string) $atomic, $quote_fingerprint);
        if ($pay_err instanceof WP_Error) {
            return $pay_err;
        }

        $bound = $this->agent_settle_payai_paid_create($request, $token_row, $agent_token, $parsed, $suggested_job_data);
        if (is_wp_error($bound)) {
            return $bound;
        }
        $token_row = $bound['token_row'];
        $agent_token = $bound['agent_token'];
        $fd = $bound['fd'];
        $resp = $this->agent_paid_create_response($fd, $token_row, $agent_token, $total_cost);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $d = $resp->get_data();
        $base = untrailingslashit(rest_url($this->namespace));
        $d['submissions_url'] = $base . '/jobs/' . rawurlencode($d['job_id']) . '/submissions?secret=' . rawurlencode($d['secret']);
        if (!empty($bound['agent_bound'])) {
            $d['agent_bound'] = true;
            if (!empty($bound['agent_name'])) {
                $d['agent_name'] = $bound['agent_name'];
            }
        }
        $final = rest_ensure_response($d);
        $this->agent_store_idempotency($request, 'jobs/social', $final, false);
        return $final;
    }

    public function agent_v2_create_interrupt(WP_REST_Request $request) {
        $this->merge_json_and_get_for_paid_creates($request);
        $question = trim((string) ($request->get_param('question') ?? ''));
        $slots = intval($request->get_param('slots') ?? 3);
        $reward_per_user = (string) ($request->get_param('reward_per_user') ?? '0.05');
        $execution_mode = $this->agent_execution_mode_from_request($request, 'human');
        if (is_wp_error($execution_mode)) {
            return $execution_mode;
        }
        $agent_token = $this->extract_agent_token($request);
        $token_row = $this->resolve_agent_token_row_optional($request);
        if (is_wp_error($token_row)) {
            return $token_row;
        }
        $probe = ($question === '');

        $payment_sig_header = $request->get_header('payment-signature');
        if (!is_string($payment_sig_header)) {
            $payment_sig_header = '';
        }
        $payment_sig_header = trim($payment_sig_header);
        $has_payment_sig = ($payment_sig_header !== '');

        if ($probe) {
            $question = 'Describe the question you want humans to answer.';
            if ($slots < 1 || $slots > 50) $slots = 3;
            if ($reward_per_user === '' || floatval($reward_per_user) <= 0) $reward_per_user = '0.05';
        } else {
            if ($slots < 1 || $slots > 50 || $reward_per_user === '' || floatval($reward_per_user) <= 0) {
                return new WP_Error('invalid_params', 'Provide question, slots (1-50), and a positive reward_per_user', ['status' => 400]);
            }
        }

        // Use Work pricing estimator (interrupt min reward must match create validation).
        $work_api = new Earnfi_Work_REST_API();
        $fake = new WP_REST_Request('GET', '/work/v1/pricing/estimate');
        $fake->set_param('task_type', 'interrupt');
        $fake->set_param('slots', $slots);
        $fake->set_param('reward_per_user', $reward_per_user);
        $estimate = $work_api->get_pricing_estimate($fake);
        if (is_wp_error($estimate)) {
            return $estimate;
        }
        $estimate_data = $estimate->get_data();

        $job_types = GROWL_Job_Types::get_instance();
        $min_reward_usd = number_format((float) $job_types->calculate_minimum_reward('interrupt', []), 6, '.', '');

        $resource_url = untrailingslashit(rest_url($this->namespace . '/interrupt'));
        $rd =
            'Put one clear question in front of many real people and pay for their answers: quick opinions, simple choices, short feedback, or â€œwhat do you think?â€ help. ' .
            'You choose how many responses and the reward per person. ' .
            'Use it for questions an average person can answer from common knowledge, not for deep research or long written reports. ' .
            'After payment, answers are collected and you can follow progress with the links returned for your run.';

        $settings = get_option('growl_settings', []);
        $decimals = intval($settings['usdc_decimals'] ?? 6);
        $multiplier = bcpow('10', (string) $decimals, 0);
        $total_cost = (string) ($estimate_data['total_cost'] ?? '0');
        $atomic = bcmul($total_cost, $multiplier, 0);

        $xa = $this->x402_accepts_for_atomic($resource_url, $atomic, $rd, $request);
        if (is_wp_error($xa)) {
            return $xa;
        }
        $quote_fingerprint = Earnfi_X402_Priced_Quote::fingerprint([
            'route'           => 'interrupt',
            'question'        => substr($question, 0, 200),
            'slots'           => (string) $slots,
            'reward_per_user' => (string) $reward_per_user,
            'atomic'          => $atomic,
        ]);

        $idem = $this->agent_check_idempotency($request, 'interrupt', $probe, $has_payment_sig);
        if ($idem) {
            return $idem;
        }

        if (!$has_payment_sig) {
            $qg = $this->agent_quote_token_gate_guard($request, $token_row, $probe);
            if (is_wp_error($qg)) {
                return $qg;
            }

            if (!$probe) {
                $growl_api = new GROWL_REST_API();
                $preflight_job = [
                    'job_type'            => 'interrupt',
                    'action_type'         => 'interrupt',
                    'base_reward'         => floatval($reward_per_user),
                    'user_count'          => $slots,
                    'lock_duration_hours' => 24,
                    'requirements'        => ['question' => $question],
                ];
                $preflight = $growl_api->validate_paid_job_data_before_settlement($preflight_job);
                if (is_wp_error($preflight)) {
                    return $preflight;
                }
            }

            $w = new Earnfi_Work_REST_API();
            $extensions = [
                'bazaar' => [
                    'info' => [
                        'input' => [
                            'type'   => 'http',
                            'method' => $request->get_method(),
                            'queryParams' => (object) [],
                        ],
                        'output' => [
                            'type'    => 'json',
                            'example' => [
                                'ok'         => true,
                                'paid'       => true,
                                'interrupt_id' => 'EFIâ€¦',
                                'total_cost' => $total_cost,
                            ],
                        ],
                    ],
                    'schema' => $w->get_interrupt_x402_extensions_schema(),
                ],
            ];
            $x402_payload = [
                'x402Version' => 2,
                'accepts'     => $xa['accepts'],
                'resource'    => $xa['resource'],
                'extensions'  => $extensions,
            ];
            Earnfi_X402_Priced_Quote::attach_fingerprint($x402_payload, $quote_fingerprint);
            $this->agent_send_402_quote($x402_payload, null);
            $resp402 = new WP_REST_Response($this->agent_attach_quote_guidance([
                'payment_required' => true,
                'x402Version'      => 2,
                'accepts'          => $x402_payload['accepts'],
                'resource'         => $x402_payload['resource'],
                'extensions'       => $x402_payload['extensions'],
                'x402'             => $x402_payload,
                'total_cost'       => $total_cost,
                'question'         => $question,
                'slots'            => $slots,
                'reward_per_user'  => (string) $reward_per_user,
                'min_reward_usd'   => $min_reward_usd,
                'descriptor_probe' => $probe ? true : false,
            ]), 402);
            $this->agent_store_idempotency($request, 'interrupt', $resp402, $probe);
            return $resp402;
        }

        if ($probe) {
            return new WP_Error('invalid_params', 'Provide question, slots, and reward_per_user before paying', ['status' => 400]);
        }

        $interrupt_id = 'EFI' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        $suggested_job_data = [
            'job_type'            => 'interrupt',
            'action_type'         => 'interrupt',
            'base_reward'         => floatval($reward_per_user),
            'user_count'          => $slots,
            'lock_duration_hours' => 24,
            'min_rank'            => 'basic',
            'requirements'        => ['interrupt_id' => $interrupt_id, 'question' => $question],
            'title'               => substr('Human Interrupt: ' . $question, 0, 100),
            'execution_mode'      => $execution_mode,
            'execution_tier'      => 'verified',
            'creator_type'        => 'agent',
        ];
        if ($token_row && is_object($token_row)) {
            $suggested_job_data['creator_agent_id'] = substr((string) $token_row->agent_id, 0, 32);
        }

        $merged_ctx = $this->agent_merge_partner_job_context($request, $suggested_job_data, $token_row);
        if (is_wp_error($merged_ctx)) {
            return $merged_ctx;
        }
        $suggested_job_data = $merged_ctx;

        $gate_wallet = ($token_row && is_object($token_row)) ? (string) $token_row->wallet_address : '';
        $gate_merged = $this->agent_apply_token_gate_to_paid_job($request, $suggested_job_data, $gate_wallet);
        if (is_wp_error($gate_merged)) {
            return $gate_merged;
        }
        $suggested_job_data = $gate_merged;

        $finalize_interrupt = function ($fd, $bound_token_row, $bound_agent_token) use ($interrupt_id, $total_cost, $request, $question) {
            $job_id = isset($fd['job_id']) ? (string) $fd['job_id'] : '';
            $this->database->insert('earnfi_interrupts', [
                'interrupt_id'      => $interrupt_id,
                'task_id'           => $job_id !== '' ? $job_id : null,
                'source_agent_id'   => substr((string) $bound_token_row->agent_id, 0, 32),
                'question'          => $question,
                'context'           => wp_json_encode([]),
                'expected_schema'   => null,
                'aggregated_answer' => null,
                'status'            => 'pending',
                'created_at'        => $this->now_mysql(),
                'updated_at'        => $this->now_mysql(),
            ]);
            $secret_plain = $this->base64url_encode(random_bytes(24));
            if ($job_id !== '') {
                $this->database->insert('ai_job_access', [
                    'job_id'      => $job_id,
                    'agent_id'    => substr((string) $bound_token_row->agent_id, 0, 32),
                    'secret_hash' => hash('sha256', $secret_plain),
                    'created_at'  => $this->now_mysql(),
                ]);
            }
            $base = untrailingslashit(rest_url($this->namespace));
            $payload = [
                'success'          => true,
                'paid'             => true,
                'interrupt_id'     => $interrupt_id,
                'job_id'           => $job_id ?: null,
                'transaction_hash' => $fd['transaction_hash'] ?? null,
                'total_cost'       => $total_cost,
                'secret'           => $secret_plain,
                'status_url'       => $job_id ? ($base . '/jobs/' . rawurlencode($job_id) . '?secret=' . rawurlencode($secret_plain)) : null,
                'submissions_url'  => $job_id ? ($base . '/jobs/' . rawurlencode($job_id) . '/submissions?secret=' . rawurlencode($secret_plain)) : null,
                'interrupt_url'    => $base . '/interrupt/' . rawurlencode($interrupt_id),
                'hint'             => 'Store secret and agent_token securely. Polling with secret does not require another payment.',
                'next_step'        => $this->paid_create_success_next_step(),
            ];
            if ($bound_agent_token !== '') {
                $payload['agent_token'] = $bound_agent_token;
            }
            if (!empty($fd['agent_bound'])) {
                $payload['agent_bound'] = true;
                if (!empty($fd['agent_name'])) {
                    $payload['agent_name'] = $fd['agent_name'];
                }
            }
            if (isset($fd['settlement'])) {
                $payload['settlement'] = $fd['settlement'];
            }
            $final = rest_ensure_response($payload);
            $this->agent_store_idempotency($request, 'interrupt', $final, false);
            return $final;
        };

        $parsed = PayAI_Flow::parse_payment_signature_header($payment_sig_header);
        if (is_wp_error($parsed)) {
            $st = intval($parsed->get_error_data()['status'] ?? 400);
            return new WP_Error($parsed->get_error_code(), $parsed->get_error_message(), ['status' => $st]);
        }
        $pay_err = $this->agent_validate_priced_payment($parsed, (string) $atomic, $quote_fingerprint);
        if ($pay_err instanceof WP_Error) {
            return $pay_err;
        }

        $bound = $this->agent_settle_payai_paid_create($request, $token_row, $agent_token, $parsed, $suggested_job_data);
        if (is_wp_error($bound)) {
            return $bound;
        }
        return $finalize_interrupt($bound['fd'], $bound['token_row'], $bound['agent_token']);
    }

    public function agent_v2_get_interrupt(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $id = sanitize_text_field($request['id'] ?? '');
        if ($id === '') {
            return new WP_Error('invalid_params', 'interrupt id is required', ['status' => 400]);
        }
        return $this->agent_poll_cached($request, 'interrupt', $id, function () use ($id, $request) {
            $table = $this->database->table_prefix . 'earnfi_interrupts';
            $row = $this->database->get_row("SELECT interrupt_id, task_id, source_agent_id, question, aggregated_answer, status, created_at, updated_at FROM {$table} WHERE interrupt_id = %s", [$id]);
            if (!$row) {
                return new WP_Error('not_found', 'Interrupt not found', ['status' => 404]);
            }
            if (!empty($row->task_id)) {
                $authorized = $this->authorize_job_read((string) $row->task_id, $request);
            } else {
                $token = $this->resolve_agent_from_token($this->extract_agent_token($request));
                $authorized = is_wp_error($token)
                    ? $token
                    : ((string) ($token->agent_id ?? '') === (string) ($row->source_agent_id ?? '')
                        ? true
                        : new WP_Error('forbidden', 'Not authorized for this action', ['status' => 403]));
            }
            if (is_wp_error($authorized)) {
                return $authorized;
            }
            // Repair status for interrupts that filled before terminal flip existed.
            if ((string) ($row->status ?? '') !== 'completed' && !empty($row->task_id)) {
                $task = $this->database->get_row(
                    "SELECT task_id, action_type, user_count, completed_count, status, requirements
                     FROM {$this->database->table_prefix}tasks WHERE task_id = %s LIMIT 1",
                    [(string) $row->task_id]
                );
                if ($task) {
                    $tm = new GROWL_Task_Manager();
                    $tm->maybe_complete_interrupt_for_task($task);
                    $fresh = $this->database->get_row(
                        "SELECT status, aggregated_answer, updated_at FROM {$table} WHERE interrupt_id = %s LIMIT 1",
                        [$id]
                    );
                    if ($fresh) {
                        $row->status = $fresh->status;
                        $row->aggregated_answer = $fresh->aggregated_answer;
                        $row->updated_at = $fresh->updated_at;
                    }
                }
            }
            $aggregated = $row->aggregated_answer;
            if (is_string($aggregated) && $aggregated !== '') {
                $decoded = json_decode($aggregated, true);
                if (is_array($decoded)) {
                    $aggregated = $decoded;
                }
            }
            return rest_ensure_response([
                'success' => true,
                'interrupt' => [
                    'interrupt_id' => $row->interrupt_id,
                    'task_id' => $row->task_id,
                    'question' => $row->question,
                    'aggregated_answer' => $aggregated,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ],
            ]);
        });
    }

    /**
     * GET /human-actions or /actions (no body) — x402 catalog quote for registries.
     */
    private function human_actions_catalog_probe(WP_REST_Request $request) {
        $request->set_param('question', 'Discovery probe — replace with your question.');
        $request->set_param('slots', max(1, (int) ($request->get_param('slots') ?: 1)));
        $request->set_param('reward_per_user', (string) ($request->get_param('reward_per_user') ?: '0.05'));
        return $this->agent_v2_create_interrupt($request);
    }

    /**
     * Create one of the stable Human Action contracts on the existing paid
     * interrupt or manual-job execution rails.
     */
    public function agent_v2_create_human_action(WP_REST_Request $request) {
        $this->merge_json_and_get_for_paid_creates($request);
        $type = sanitize_key((string) ($request['action_type'] ?? $request->get_param('action_type') ?? $request->get_param('type') ?? ''));
        $allowed = ['ask', 'review', 'vote', 'test', 'research', 'verify', 'moderate', 'feedback'];
        if ($type === '{action_type}' || $type === 'action_type') {
            $type = 'ask';
        }

        $prompt = trim((string) ($request->get_param('prompt') ?? $request->get_param('question') ?? $request->get_param('instructions') ?? ''));
        $payment_sig = trim((string) $request->get_header('payment-signature'));
        $route = (string) $request->get_route();
        $is_catalog_probe = (bool) preg_match(self::RE_HUMAN_ACTIONS_PROBE, $route);
        if ($is_catalog_probe && $payment_sig === '' && $request->get_method() === 'GET') {
            return $this->human_actions_catalog_probe($request);
        }
        if (!in_array($type, $allowed, true)) {
            return new WP_Error(
                'invalid_action_type',
                'Choose ask, review, vote, test, research, verify, moderate, or feedback',
                ['status' => 400, 'allowed' => $allowed]
            );
        }

        if ($prompt === '') {
            if ($payment_sig === '' && in_array($request->get_method(), ['GET', 'POST'], true)) {
                $request->set_param('prompt', 'Discovery probe — replace with your question.');
                $request->set_param('question', 'Discovery probe — replace with your question.');
                $request->set_param('slots', max(1, (int) ($request->get_param('slots') ?: 1)));
                $request->set_param('reward_per_user', (string) ($request->get_param('reward_per_user') ?: '0.05'));
                $prompt = 'Discovery probe — replace with your question.';
            } else {
                return new WP_Error('invalid_prompt', 'prompt is required', ['status' => 400]);
            }
        }
        if (strlen($prompt) > 10000) {
            return new WP_Error('invalid_prompt', 'prompt must be 10,000 characters or fewer', ['status' => 400]);
        }

        $mode = in_array($type, ['ask', 'vote', 'feedback'], true) ? 'interrupt' : 'manual';
        if ($mode === 'interrupt') {
            if ($prompt !== '') {
                $options = $request->get_param('options');
                if (is_string($options)) {
                    $decoded = json_decode($options, true);
                    $options = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $options)));
                }
                if ($type === 'vote' && is_array($options) && count($options) > 0) {
                    $clean_options = array_values(array_filter(array_map('sanitize_text_field', $options)));
                    $prompt .= "\n\nChoose one: " . implode(' | ', $clean_options);
                }
                $request->set_param('question', $prompt);
            }
            $response = $this->agent_v2_create_interrupt($request);
        } else {
            $title = trim((string) ($request->get_param('title') ?? ''));
            if ($title === '') {
                $title = 'Human ' . ucfirst($type);
            }
            $request->set_param('title', substr($title, 0, 100));
            if ($prompt !== '') {
                $request->set_param('instructions', $prompt);
            }
            if ($request->get_param('verification_method') === null) {
                $request->set_param('verification_method', 'manual');
            }
            $response = $this->agent_v2_create_manual_job($request);
        }

        return $this->normalize_human_action_response($response, $type, $mode, $request);
    }

    public function agent_v2_get_human_action_result(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $id = sanitize_text_field((string) ($request['id'] ?? ''));
        if ($id === '') {
            return new WP_Error('invalid_params', 'action id is required', ['status' => 400]);
        }

        if (preg_match(self::RE_INTERRUPT_ID, $id)) {
            $request->set_param('id', $id);
            $response = $this->agent_v2_get_interrupt($request);
            if (is_wp_error($response)) {
                return $response;
            }
            $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
            $interrupt = is_array($data) && isset($data['interrupt']) && is_array($data['interrupt']) ? $data['interrupt'] : [];
            $this->sync_human_action_state(
                $id,
                (string) ($interrupt['status'] ?? 'pending'),
                $interrupt['aggregated_answer'] ?? null
            );
            $payload = [
                'success'       => true,
                'action_id'     => $id,
                'job_id'        => $interrupt['task_id'] ?? null,
                'status'        => $interrupt['status'] ?? 'pending',
                'complete'      => (($interrupt['status'] ?? '') === 'completed'),
                'result'        => $interrupt['aggregated_answer'] ?? null,
                'poll_after_ms' => 60000,
            ];
            if ($payload['complete'] && class_exists('Growl_Work_Receipt_Service')) {
                require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-receipt-service.php';
                $payload = Growl_Work_Receipt_Service::attach_to_payload($payload, 'human_action', $id, 'completed');
            }
            return rest_ensure_response($payload);
        }

        $request->set_param('id', $id);
        $response = $this->agent_v2_get_job($request);
        if (is_wp_error($response)) {
            return $response;
        }
        $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
        $status = is_array($data) ? (string) ($data['status'] ?? $data['job']['status'] ?? 'pending') : 'pending';
        $this->sync_human_action_state($id, $status, $data);
        $payload = [
            'success'       => true,
            'action_id'     => $id,
            'job_id'        => $id,
            'status'        => $status,
            'complete'      => in_array(strtolower($status), ['completed', 'complete', 'closed', 'expired'], true),
            'result'        => $data,
            'poll_after_ms' => 60000,
        ];
        if ($payload['complete'] && class_exists('Growl_Work_Receipt_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-receipt-service.php';
            $payload = Growl_Work_Receipt_Service::attach_to_payload($payload, 'human_action', $id, 'completed');
        }
        return rest_ensure_response($payload);
    }

    private function normalize_human_action_response($response, string $type, string $mode, WP_REST_Request $request) {
        if (is_wp_error($response)) {
            return $response;
        }
        $status_code = $response instanceof WP_REST_Response ? $response->get_status() : 200;
        $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
        if (!is_array($data)) {
            return $response;
        }
        $action_id = (string) ($data['interrupt_id'] ?? $data['job_id'] ?? '');
        $base = untrailingslashit(rest_url($this->namespace));
        $normalized = $data;
        $normalized['action_type'] = $type;
        $normalized['action_mode'] = $mode;
        $normalized['action_id'] = $action_id !== '' ? $action_id : null;
        $normalized['status'] = $status_code === 402 ? 'payment_required' : (string) ($data['status'] ?? 'created');
        $normalized['poll_after_ms'] = 60000;
        if ($action_id !== '') {
            $normalized['result_url'] = $base . '/actions/' . rawurlencode($action_id) . '/result';
        }
        if ($action_id !== '' && $status_code >= 200 && $status_code < 300 && class_exists('GROWL_Human_Action_Service')) {
            $token_row = $this->resolve_agent_from_token($this->extract_agent_token($request));
            $task_id = (string) ($data['job_id'] ?? '');
            $task = $task_id !== ''
                ? $this->database->get_row(
                    "SELECT base_reward, user_count, lock_duration_hours
                     FROM {$this->database->table_prefix}tasks WHERE task_id = %s LIMIT 1",
                    [$task_id]
                )
                : null;
            $targeting = $request->get_param('targeting_policy');
            if (is_string($targeting)) {
                $decoded = json_decode($targeting, true);
                $targeting = is_array($decoded) ? $decoded : [];
            }
            $reward = (float) (
                $request->get_param('reward_per_user') ??
                $request->get_param('reward_usdc') ??
                $request->get_param('reward') ??
                ($task->base_reward ?? 0.05)
            );
            $hours = max(1, min(720, (int) (
                $request->get_param('lock_duration_hours') ??
                ($task->lock_duration_hours ?? 24)
            )));
            $registration = (new GROWL_Human_Action_Service($this->database))->register_execution(
                !is_wp_error($token_row) ? (string) $token_row->agent_id : (string) ($data['agent_id'] ?? 'agent'),
                [
                    'kind' => $type,
                    'prompt' => (string) (
                        $request->get_param('prompt') ??
                        $request->get_param('question') ??
                        $request->get_param('instructions') ??
                        ''
                    ),
                    'slots' => (int) ($request->get_param('slots') ?? ($task->user_count ?? 1)),
                    'reward_usdc' => max(0.000001, $reward),
                    'deadline' => (string) ($request->get_param('deadline') ?? gmdate('c', time() + $hours * HOUR_IN_SECONDS)),
                    'urgency' => (string) ($request->get_param('urgency') ?? 'standard'),
                    'aggregation' => (string) ($request->get_param('aggregation') ?? ''),
                    'verification_mode' => (string) ($request->get_param('verification_mode') ?? 'human'),
                    'targeting_policy' => is_array($targeting) ? $targeting : [],
                    'seeker_only' => !empty($request->get_param('seeker_only')),
                    'min_rank' => (string) ($request->get_param('min_rank') ?? 'basic'),
                    'input' => is_array($request->get_param('input')) ? $request->get_param('input') : [],
                    'result_schema' => is_array($request->get_param('result_schema')) ? $request->get_param('result_schema') : [],
                    'delivery' => is_array($request->get_param('delivery')) ? $request->get_param('delivery') : [],
                    'callback_url' => (string) ($request->get_param('callback_url') ?? ''),
                    'resume_token' => (string) ($request->get_param('resume_token') ?? ''),
                    'metadata' => is_array($request->get_param('metadata')) ? $request->get_param('metadata') : [],
                ],
                [
                    'action_id' => $action_id,
                    'task_id' => $task_id ?: null,
                    'interrupt_id' => $mode === 'interrupt' ? $action_id : null,
                    'status' => 'open',
                ]
            );
            if (!is_wp_error($registration)) {
                $normalized['human_action_registered'] = true;
                if (!empty($registration['callback_secret'])) {
                    $normalized['callback_secret'] = $registration['callback_secret'];
                }
            } else {
                error_log('EarnFi Human Action tracking failed: ' . $registration->get_error_code());
                $normalized['human_action_registered'] = false;
            }
        }
        return new WP_REST_Response($normalized, $status_code);
    }

    private function sync_human_action_state($action_id, $status, $result) {
        if (!class_exists('GROWL_Event_Outbox')) {
            return;
        }
        $table = $this->database->table_prefix . 'human_actions';
        $row = $this->database->get_row(
            "SELECT action_id, status, slots, callback_url, callback_secret_encrypted
             FROM {$table} WHERE action_id = %s LIMIT 1",
            [(string) $action_id]
        );
        if (!$row) {
            return;
        }
        $complete = in_array(strtolower((string) $status), ['completed', 'complete', 'closed', 'expired'], true);
        $canonical = $complete ? 'completed' : sanitize_key((string) $status);
        $transitioned = $complete && (string) $row->status !== 'completed';
        $update = [
            'status' => $canonical ?: 'open',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($complete) {
            $update['filled_slots'] = (int) $row->slots;
            $update['completed_at'] = gmdate('Y-m-d H:i:s');
        }
        $this->database->update('human_actions', $update, ['action_id' => (string) $action_id]);
        if ($transitioned) {
            if (!class_exists('Growl_Work_Receipt_Service')) {
                require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-receipt-service.php';
            }
            $receipt_svc = new Growl_Work_Receipt_Service($this->database);
            $receipt_svc->upsert_from_ref('human_action', (string) $action_id);
            $work_receipt = $receipt_svc->get_v1_by_ref('human_action', (string) $action_id);
            $event_payload = [
                'action_id' => (string) $action_id,
                'status' => 'completed',
                'result' => $result,
            ];
            if ($work_receipt) {
                $event_payload['work_receipt'] = $work_receipt;
            }
            (new GROWL_Event_Outbox($this->database))->enqueue(
                'human_action.completed',
                'human_action',
                (string) $action_id,
                $event_payload,
                [
                    'url' => (string) ($row->callback_url ?? ''),
                    'secret_encrypted' => (string) ($row->callback_secret_encrypted ?? ''),
                ]
            );
            wp_cache_delete('growl_live_activity_v1', 'growl_activity');
        }
    }

    private function authorize_job_read($job_id, WP_REST_Request $request) {
        $secret = (string) ($request->get_param('secret') ?? '');
        $agent_token = $this->extract_agent_token($request);

        if ($secret === '' && $agent_token === '') {
            return new WP_Error('missing_auth', 'Provide secret or agent_token', ['status' => 401]);
        }

        if ($secret !== '') {
            $hash = hash('sha256', $secret);
            $t = $this->database->table_prefix . 'ai_job_access';
            $row = $this->database->get_row("SELECT job_id FROM {$t} WHERE job_id = %s AND secret_hash = %s LIMIT 1", [$job_id, $hash]);
            if ($row) {
                return true;
            }
        }
        if ($agent_token !== '') {
            $tok = $this->resolve_agent_from_token($agent_token);
            if (is_wp_error($tok)) {
                return $tok;
            }
            // Agent-token auth is allowed for reads (either secret OR token).
            // Ownership is enforced by matching creator_agent_id on the task row.
            $tasks = $this->database->table_prefix . 'tasks';
            $task = $this->database->get_row("SELECT creator_type, creator_agent_id FROM {$tasks} WHERE task_id = %s LIMIT 1", [$job_id]);
            if ($task && (string) ($task->creator_type ?? '') === 'agent' && (string) ($task->creator_agent_id ?? '') === (string) $tok->agent_id) {
                return true;
            }
        }
        return new WP_Error('forbidden', 'Not authorized to read this job', ['status' => 403]);
    }

    public function agent_v2_get_job(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') {
            return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        }
        return $this->agent_poll_cached($request, 'job', $job_id, function () use ($request, $job_id) {
            $ok = $this->authorize_job_read($job_id, $request);
            if (is_wp_error($ok)) {
                return $ok;
            }
            $work = new Earnfi_Work_REST_API();
            $fake = new WP_REST_Request('GET', '/work/v1/jobs/' . $job_id);
            $fake->set_url_params(['id' => $job_id]);
            return $work->get_job($fake);
        });
    }

    public function agent_v2_list_job_completions(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') {
            return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        }
        return $this->agent_poll_cached($request, 'completions', $job_id, function () use ($request, $job_id) {
            $ok = $this->authorize_job_read($job_id, $request);
            if (is_wp_error($ok)) {
                return $ok;
            }
            $page = max(1, intval($request->get_param('page') ?? 1));
            $per_page = min(50, max(1, intval($request->get_param('per_page') ?? 20)));
            $offset = ($page - 1) * $per_page;
            $data = $this->database->get_job_completions_paged($job_id, $per_page, $offset);
            $rows = isset($data['results']) && is_array($data['results']) ? $data['results'] : [];
            $items = [];
            foreach ($rows as $c) {
                $items[] = $this->database->format_completion_for_public_api($c, [
                    'include_disputed'     => false,
                    'include_wallet_short' => true,
                ]);
            }
            return rest_ensure_response([
                'success'     => true,
                'job_id'      => $job_id,
                'completions' => $items,
                'total'       => isset($data['total']) ? (int) $data['total'] : count($items),
                'page'        => $page,
                'per_page'    => $per_page,
            ]);
        });
    }

    public function agent_v2_list_job_submissions(WP_REST_Request $request) {
        // For non-contest jobs, submissions are completions.
        return $this->agent_v2_list_job_completions($request);
    }

    public function agent_v2_pause_job(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') {
            return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        }
        $tok = $this->resolve_agent_from_token($this->extract_agent_token($request));
        if (is_wp_error($tok)) {
            return $tok;
        }

        // Ensure this agent is the creator of the job.
        $tasks = $this->database->table_prefix . 'tasks';
        $task = $this->database->get_row("SELECT creator_type, creator_agent_id FROM {$tasks} WHERE task_id = %s LIMIT 1", [$job_id]);
        if (!$task || (string) ($task->creator_type ?? '') !== 'agent' || (string) ($task->creator_agent_id ?? '') !== (string) $tok->agent_id) {
            return new WP_Error('forbidden', 'Not authorized to pause this job', ['status' => 403]);
        }

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('POST', '/growl/v1/creator/jobs/' . $job_id . '/pause');
        $fake->set_param('id', $job_id);
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);

        return $growl->creator_job_pause($fake);
    }

    public function agent_v2_close_job(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') {
            return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        }
        $tok = $this->resolve_agent_from_token($this->extract_agent_token($request));
        if (is_wp_error($tok)) {
            return $tok;
        }

        $tasks = $this->database->table_prefix . 'tasks';
        $task = $this->database->get_row("SELECT creator_type, creator_agent_id FROM {$tasks} WHERE task_id = %s LIMIT 1", [$job_id]);
        if (!$task || (string) ($task->creator_type ?? '') !== 'agent' || (string) ($task->creator_agent_id ?? '') !== (string) $tok->agent_id) {
            return new WP_Error('forbidden', 'Not authorized to close this job', ['status' => 403]);
        }

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('POST', '/growl/v1/creator/jobs/' . $job_id . '/close');
        $fake->set_param('id', $job_id);
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);

        return $growl->creator_job_close($fake);
    }

    public function agent_v2_update_job_metadata(WP_REST_Request $request) {
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) {
            return $tok;
        }
        $job_id = sanitize_text_field((string) ($request['id'] ?? ''));
        if ($job_id === '') {
            return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        }
        $owned = $this->assert_agent_owns_job($job_id, $tok);
        if (is_wp_error($owned)) {
            return $owned;
        }
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];
        $allowed = ['title', 'custom_instructions', 'keywords_statement', 'targeting_policy', 'lock_duration_hours'];
        $metadata = array_intersect_key($body, array_flip($allowed));
        $result = (new GROWL_Task_Manager())->update_task_metadata((string) $tok->wallet_address, $job_id, $metadata);
        if (empty($result['success'])) {
            $message = (string) ($result['error'] ?? 'Could not update job metadata');
            $status = stripos($message, 'not found') !== false ? 404 : 400;
            return new WP_Error('job_metadata_update_failed', $message, ['status' => $status]);
        }
        return rest_ensure_response([
            'success' => true,
            'job_id' => $job_id,
            'updated_fields' => $result['updated_fields'] ?? [],
            'has_worker_activity' => !empty($result['has_worker_activity']),
        ]);
    }

    public function agent_v2_create_hire_listing(WP_REST_Request $request) {
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) {
            return $tok;
        }
        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        $body = $request->get_json_params();
        $row = (new Growl_Job_Board_Service())->create_listing((int) $user_id, is_array($body) ? $body : []);
        return is_wp_error($row) ? $row : rest_ensure_response(['success' => true, 'listing' => $row]);
    }

    public function agent_v2_update_hire_listing(WP_REST_Request $request) {
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) {
            return $tok;
        }
        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        $body = $request->get_json_params();
        $row = (new Growl_Job_Board_Service())->update_listing(
            (int) ($request['id'] ?? 0),
            (int) $user_id,
            is_array($body) ? $body : []
        );
        return is_wp_error($row) ? $row : rest_ensure_response([
            'success' => true,
            'message' => 'Hire listing updated',
            'listing' => $row,
        ]);
    }

    public function agent_v2_list_job_verifications(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) return $tok;
        $own = $this->assert_agent_owns_job($job_id, $tok);
        if (is_wp_error($own)) return $own;

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) return $user_id;

        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('GET', '/growl/v1/creator/jobs/' . $job_id . '/verifications');
        $fake->set_param('id', $job_id);
        $fake->set_param('page', $request->get_param('page'));
        $fake->set_param('per_page', $request->get_param('per_page'));
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);
        return $growl->creator_job_verifications($fake);
    }

    public function agent_v2_approve_verification(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $verification_id = intval($request['id'] ?? 0);
        if ($verification_id <= 0) return new WP_Error('invalid_params', 'verification id is required', ['status' => 400]);
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) return $tok;

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) return $user_id;

        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('POST', '/growl/v1/creator/verifications/' . $verification_id . '/approve');
        $fake->set_param('id', (string) $verification_id);
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);
        return $growl->creator_approve_verification($fake);
    }

    public function agent_v2_reject_verification(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $verification_id = intval($request['id'] ?? 0);
        if ($verification_id <= 0) return new WP_Error('invalid_params', 'verification id is required', ['status' => 400]);
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) return $tok;

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) return $user_id;

        $reason = sanitize_text_field($request->get_param('reason') ?? '');
        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('POST', '/growl/v1/creator/verifications/' . $verification_id . '/reject');
        $fake->set_param('id', (string) $verification_id);
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);
        $fake->set_body_params(['rejection_reason' => $reason]);
        return $growl->creator_reject_verification($fake);
    }

    public function agent_v2_list_contest_submissions(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) return $tok;
        $own = $this->assert_agent_owns_job($job_id, $tok);
        if (is_wp_error($own)) return $own;

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) return $user_id;

        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('GET', '/growl/v1/creator/contest/' . $job_id . '/submissions');
        $fake->set_param('id', $job_id);
        $fake->set_param('pg', $request->get_param('pg'));
        $fake->set_param('pp', $request->get_param('pp'));
        $fake->set_param('tab', $request->get_param('tab'));
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);
        return $growl->get_creator_contest_submissions($fake);
    }

    public function agent_v2_mark_contest_winner(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) return $tok;
        $own = $this->assert_agent_owns_job($job_id, $tok);
        if (is_wp_error($own)) return $own;

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) return $user_id;

        $submission_id = sanitize_text_field($request->get_param('submission_id') ?? '');
        if ($submission_id === '') return new WP_Error('invalid_params', 'submission_id is required', ['status' => 400]);
        $rank = $request->get_param('rank_position');
        $rank_position = ($rank === null || $rank === '') ? null : intval($rank);
        $override_reason = trim((string) ($request->get_param('override_reason') ?? ''));

        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('POST', '/growl/v1/contest/' . $job_id . '/winner');
        $fake->set_param('id', $job_id);
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);
        $payload = ['submission_id' => $submission_id, 'rank_position' => $rank_position];
        if ($override_reason !== '') {
            $payload['override_reason'] = $override_reason;
        }
        $fake->set_json_params($payload);
        return $growl->mark_contest_winner($fake);
    }

    public function agent_v2_contest_leaderboard(WP_REST_Request $request) {
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') {
            return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        }
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) {
            return $tok;
        }
        $own = $this->assert_agent_owns_job($job_id, $tok);
        if (is_wp_error($own)) {
            return $own;
        }
        $growl = new GROWL_REST_API();
        $fake  = new WP_REST_Request('GET', '/growl/v1/contests/' . $job_id . '/leaderboard');
        $fake->set_param('id', $job_id);
        return $growl->get_contest_leaderboard($fake);
    }

    public function agent_v2_get_creator_job_detail(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) return $tok;
        $own = $this->assert_agent_owns_job($job_id, $tok);
        if (is_wp_error($own)) return $own;

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) return $user_id;

        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('GET', '/growl/v1/creator/jobs/' . $job_id);
        $fake->set_param('id', $job_id);
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);
        return $growl->creator_job_detail($fake);
    }

    public function agent_v2_get_creator_job_users(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) return $tok;
        $own = $this->assert_agent_owns_job($job_id, $tok);
        if (is_wp_error($own)) return $own;

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) return $user_id;

        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('GET', '/growl/v1/creator/jobs/' . $job_id . '/users');
        $fake->set_param('id', $job_id);
        $fake->set_param('page', $request->get_param('page'));
        $fake->set_param('per_page', $request->get_param('per_page'));
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);
        return $growl->creator_job_users($fake);
    }

    public function agent_v2_get_creator_job_payments(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $job_id = sanitize_text_field($request['id'] ?? '');
        if ($job_id === '') return new WP_Error('invalid_params', 'job id is required', ['status' => 400]);
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) return $tok;
        $own = $this->assert_agent_owns_job($job_id, $tok);
        if (is_wp_error($own)) return $own;

        $user_id = $this->ensure_user_id_for_wallet((string) $tok->wallet_address);
        if (is_wp_error($user_id)) return $user_id;

        $growl = new GROWL_REST_API();
        $fake = new WP_REST_Request('GET', '/growl/v1/creator/jobs/' . $job_id . '/payments');
        $fake->set_param('id', $job_id);
        $fake->set_param('jwt_payload', ['user_id' => (int) $user_id]);
        return $growl->get_creator_job_payments($fake);
    }

    public function agent_v2_list_agent_jobs(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $agent_id = sanitize_text_field($request['agent_id'] ?? '');
        if ($agent_id === '') {
            return new WP_Error('invalid_params', 'agent_id is required', ['status' => 400]);
        }
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) {
            return $tok;
        }
        if ((string) $tok->agent_id !== $agent_id) {
            return new WP_Error('forbidden', 'agent_token does not match agent_id', ['status' => 403]);
        }
        $page = max(1, intval($request->get_param('page') ?? 1));
        $per_page = min(50, max(1, intval($request->get_param('per_page') ?? 20)));
        $reporting = new EarnFi_Partner_Reporting();
        return rest_ensure_response($reporting->list_jobs_for_agent_owner($agent_id, $page, $per_page));
    }

    public function agent_v2_agent_usage(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $agent_id = sanitize_text_field($request['agent_id'] ?? '');
        if ($agent_id === '') {
            return new WP_Error('invalid_params', 'agent_id is required', ['status' => 400]);
        }
        $tok = $this->require_agent_token($request);
        if (is_wp_error($tok)) {
            return $tok;
        }
        if ((string) $tok->agent_id !== $agent_id) {
            return new WP_Error('forbidden', 'agent_token does not match agent_id', ['status' => 403]);
        }
        $reporting = new EarnFi_Partner_Reporting();
        return rest_ensure_response($reporting->get_usage_for_agent_owner($agent_id));
    }

    public function partner_report_usage(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $partner = EarnFi_Partner_Auth::resolve_partner_from_request($request);
        if (is_wp_error($partner)) {
            return $partner;
        }
        $reporting = new EarnFi_Partner_Reporting();
        return rest_ensure_response($reporting->get_partner_usage((int) $partner->partner_id));
    }

    public function partner_report_agents(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $partner = EarnFi_Partner_Auth::resolve_partner_from_request($request);
        if (is_wp_error($partner)) {
            return $partner;
        }
        $page = max(1, intval($request->get_param('page') ?? 1));
        $per_page = min(50, max(1, intval($request->get_param('per_page') ?? 20)));
        $reporting = new EarnFi_Partner_Reporting();
        return rest_ensure_response($reporting->list_partner_agents((int) $partner->partner_id, $page, $per_page));
    }

    public function partner_report_agent_usage(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $partner = EarnFi_Partner_Auth::resolve_partner_from_request($request);
        if (is_wp_error($partner)) {
            return $partner;
        }
        $agent_id = sanitize_text_field($request['agent_id'] ?? '');
        $own = EarnFi_Partner_Auth::assert_agent_belongs_to_partner($partner, $agent_id);
        if (is_wp_error($own)) {
            return $own;
        }
        $reporting = new EarnFi_Partner_Reporting();
        return rest_ensure_response($reporting->get_agent_usage((int) $partner->partner_id, $agent_id));
    }

    public function partner_report_agent_jobs(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $partner = EarnFi_Partner_Auth::resolve_partner_from_request($request);
        if (is_wp_error($partner)) {
            return $partner;
        }
        $agent_id = sanitize_text_field($request['agent_id'] ?? '');
        $own = EarnFi_Partner_Auth::assert_agent_belongs_to_partner($partner, $agent_id);
        if (is_wp_error($own)) {
            return $own;
        }
        $page = max(1, intval($request->get_param('page') ?? 1));
        $per_page = min(50, max(1, intval($request->get_param('per_page') ?? 20)));
        $reporting = new EarnFi_Partner_Reporting();
        return rest_ensure_response($reporting->list_agent_jobs((int) $partner->partner_id, $agent_id, $page, $per_page));
    }

    public function partner_report_lookup_usage(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $partner = EarnFi_Partner_Auth::resolve_partner_from_request($request);
        if (is_wp_error($partner)) {
            return $partner;
        }
        $tok = $this->resolve_agent_from_token($this->extract_agent_token($request));
        if (is_wp_error($tok)) {
            return $tok;
        }
        $own = EarnFi_Partner_Auth::assert_agent_belongs_to_partner($partner, (string) $tok->agent_id);
        if (is_wp_error($own)) {
            return $own;
        }
        $reporting = new EarnFi_Partner_Reporting();
        return rest_ensure_response($reporting->get_agent_usage((int) $partner->partner_id, (string) $tok->agent_id));
    }

    public function partner_report_lookup_jobs(WP_REST_Request $request) {
        $this->merge_json_body_into_request_for_reads($request);
        $partner = EarnFi_Partner_Auth::resolve_partner_from_request($request);
        if (is_wp_error($partner)) {
            return $partner;
        }
        $tok = $this->resolve_agent_from_token($this->extract_agent_token($request));
        if (is_wp_error($tok)) {
            return $tok;
        }
        $own = EarnFi_Partner_Auth::assert_agent_belongs_to_partner($partner, (string) $tok->agent_id);
        if (is_wp_error($own)) {
            return $own;
        }
        $page = max(1, intval($request->get_param('page') ?? 1));
        $per_page = min(50, max(1, intval($request->get_param('per_page') ?? 20)));
        $reporting = new EarnFi_Partner_Reporting();
        return rest_ensure_response($reporting->list_agent_jobs((int) $partner->partner_id, (string) $tok->agent_id, $page, $per_page));
    }

    /**
     * Link an agent to the authenticated partner (server-to-server).
     */
    public function partner_link_agent(WP_REST_Request $request) {
        $this->merge_json_body_into_request($request);
        $partner = EarnFi_Partner_Auth::resolve_partner_from_request($request);
        if (is_wp_error($partner)) {
            return $partner;
        }
        $agent_id = '';
        $tok = $this->resolve_agent_from_token($this->extract_agent_token($request));
        if (!is_wp_error($tok) && !empty($tok->agent_id)) {
            $agent_id = (string) $tok->agent_id;
        }
        $requested = sanitize_text_field((string) ($request->get_param('agent_id') ?? ''));
        if ($agent_id === '') {
            return new WP_Error(
                'invalid_params',
                'Agent consent required: pass a valid agent_token (agent_id alone is not accepted).',
                ['status' => 400]
            );
        }
        if ($requested !== '' && !hash_equals($agent_id, $requested)) {
            return new WP_Error('forbidden', 'agent_token does not match agent_id.', ['status' => 403]);
        }
        $backfill = filter_var($request->get_param('backfill_jobs') ?? false, FILTER_VALIDATE_BOOLEAN);
        $result = EarnFi_Partner_Context::link_agent_to_partner($agent_id, (int) $partner->partner_id, $backfill);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response($result);
    }
}

