<?php
/**
 * Work-Money layer REST: jobs board, capabilities, agent catalog, work orders, allowances, receipts verify.
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-job-board-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-job-contract-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-order-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-allowance-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-deal-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-balance-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-fee-engine.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-x402-payment-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-open-work-service.php';

class Growl_Work_Money_Layer_REST {

    private $namespace = 'growl/v1';

    /** @var GROWL_REST_API */
    private $core_api;

    /** @var Growl_X402_Payment_Service */
    private $payments;

    public function __construct() {
        $this->core_api = new GROWL_REST_API();
        $this->payments = new Growl_X402_Payment_Service();
    }

    public function register_routes() {
        $auth = [$this->core_api, 'verify_jwt'];
        $pub  = '__return_true';

        register_rest_route($this->namespace, '/balances/activity', [
            'methods' => 'GET', 'callback' => [$this, 'balance_activity'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/balances/send', [
            'methods' => 'POST', 'callback' => [$this, 'send_p2p'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/balances/send-external', [
            'methods' => 'POST', 'callback' => [$this, 'send_external'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/balances/lookup', [
            'methods' => 'GET', 'callback' => [$this, 'lookup_recipient'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/payments/settle', [
            'methods' => 'POST', 'callback' => [$this, 'payment_settle'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/deals/(?P<id>\d+)/dispute', [
            'methods' => 'POST', 'callback' => [$this, 'dispute_deal'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/deals/(?P<id>\d+)/refund', [
            'methods' => 'POST', 'callback' => [$this, 'refund_deal'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/receipts/(?P<receipt_id>[a-z0-9_]+)/verify', [
            'methods' => 'GET', 'callback' => [$this, 'verify_receipt'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/telegram/link-code', [
            'methods' => 'POST', 'callback' => [$this, 'telegram_link_code'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/telegram/link-status', [
            'methods' => 'GET', 'callback' => [$this, 'telegram_link_status'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/telegram/unlink', [
            'methods' => 'POST', 'callback' => [$this, 'telegram_unlink'], 'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/jobs-board', [
            ['methods' => 'GET', 'callback' => [$this, 'list_jobs'], 'permission_callback' => $pub],
            ['methods' => 'POST', 'callback' => [$this, 'create_job_listing'], 'permission_callback' => $auth],
        ]);
        register_rest_route($this->namespace, '/jobs-board/(?P<slug>[a-z0-9]{8,16})', [
            'methods' => 'GET', 'callback' => [$this, 'get_job_listing'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/jobs-board/(?P<id>\d+)', [
            'methods' => 'PATCH', 'callback' => [$this, 'update_job_listing'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/jobs-board/(?P<id>\d+)/apply', [
            'methods' => 'POST', 'callback' => [$this, 'apply_job'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/jobs-board/(?P<id>\d+)/accept', [
            'methods' => 'POST', 'callback' => [$this, 'accept_job_application'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/jobs-board/(?P<id>\d+)/applications/(?P<app_id>\d+)/shortlist', [
            'methods' => 'POST', 'callback' => [$this, 'shortlist_job_application'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/job-contracts/(?P<slug>[a-z0-9]{8,16})', [
            'methods' => 'GET', 'callback' => [$this, 'get_job_contract'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/job-contracts/(?P<id>\d+)/fund', [
            'methods' => 'POST', 'callback' => [$this, 'fund_job_contract'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/job-contracts/(?P<id>\d+)/deliver', [
            'methods' => 'POST', 'callback' => [$this, 'deliver_job_contract'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/job-contracts/(?P<id>\d+)/release', [
            'methods' => 'POST', 'callback' => [$this, 'release_job_contract'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/job-contracts/(?P<id>\d+)/milestones/(?P<mid>\d+)/deliver', [
            'methods' => 'POST', 'callback' => [$this, 'deliver_job_milestone'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/job-contracts/(?P<id>\d+)/milestones/(?P<mid>\d+)/release', [
            'methods' => 'POST', 'callback' => [$this, 'release_job_milestone'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/work-fees/quote', [
            'methods' => 'GET', 'callback' => [$this, 'work_fee_quote'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/jobs-board/(?P<id>\d+)/applications', [
            'methods' => 'GET', 'callback' => [$this, 'list_job_applications'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/jobs-board/(?P<id>\d+)/my-application', [
            'methods' => 'GET', 'callback' => [$this, 'my_job_application'], 'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/capabilities', [
            'methods' => 'GET', 'callback' => [$this, 'list_capabilities'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/capabilities/(?P<slug>[a-z0-9_-]+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_capability'], 'permission_callback' => $pub,
        ]);

        register_rest_route($this->namespace, '/agents/catalog', [
            'methods' => 'GET', 'callback' => [$this, 'agent_catalog'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agents/orders', [
            'methods' => 'POST', 'callback' => [$this, 'create_agent_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/reference-upload', [
            'methods' => 'POST', 'callback' => [$this, 'upload_order_reference'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+)/fund', [
            'methods' => 'POST', 'callback' => [$this, 'fund_agent_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+)/release', [
            'methods' => 'POST', 'callback' => [$this, 'release_agent_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+)/deliver', [
            'methods' => 'POST', 'callback' => [$this, 'deliver_agent_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+)/revision', [
            'methods' => 'POST', 'callback' => [$this, 'revise_agent_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+)/dispute', [
            'methods' => 'POST', 'callback' => [$this, 'dispute_agent_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_agent_order'], 'permission_callback' => $pub,
        ]);

        register_rest_route($this->namespace, '/agent-deals/(?P<slug>[a-z0-9]{8,16})', [
            'methods' => 'GET', 'callback' => [$this, 'get_agent_deal_human'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agent-deals/(?P<id>\d+)/accept', [
            'methods' => 'POST', 'callback' => [$this, 'accept_agent_deal_human'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agent-deals/(?P<id>\d+)/fund', [
            'methods' => 'POST', 'callback' => [$this, 'fund_agent_deal_human'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agent-deals/(?P<id>\d+)/deliver', [
            'methods' => 'POST', 'callback' => [$this, 'deliver_agent_deal_human'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agent-deals/(?P<id>\d+)/release', [
            'methods' => 'POST', 'callback' => [$this, 'release_agent_deal_human'], 'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/work-orders', [
            'methods' => 'POST', 'callback' => [$this, 'create_work_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/work-orders/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_work_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/work-orders/(?P<id>\d+)/fund', [
            'methods' => 'POST', 'callback' => [$this, 'fund_work_order'], 'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/jobs-board/mine', [
            'methods' => 'GET', 'callback' => [$this, 'list_my_jobs'], 'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/marketplace/agents', [
            'methods' => 'GET', 'callback' => [$this, 'browse_agents'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/marketplace/services', [
            'methods' => 'GET', 'callback' => [$this, 'browse_services'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/marketplace/stats', [
            'methods' => 'GET', 'callback' => [$this, 'marketplace_stats'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/marketplace/leaderboard', [
            'methods' => 'GET', 'callback' => [$this, 'marketplace_leaderboard'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agents/provider/services', [
            'methods' => 'GET', 'callback' => [$this, 'provider_list_services'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/provider/services/(?P<id>\d+)/status', [
            'methods' => 'POST', 'callback' => [$this, 'provider_set_service_status'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/(?P<agent_id>[A-Za-z0-9_-]+)/services', [
            'methods' => 'GET', 'callback' => [$this, 'agent_services'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agents/orders/mine', [
            'methods' => 'GET', 'callback' => [$this, 'list_my_agent_orders'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/s/(?P<slug>[a-z0-9]{8,16})', [
            'methods' => 'GET', 'callback' => [$this, 'get_agent_order_slug'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+)/refund', [
            'methods' => 'POST', 'callback' => [$this, 'refund_agent_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+)/said-feedback', [
            'methods' => 'POST', 'callback' => [$this, 'said_feedback_order'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/(?P<agent_id>[A-Za-z0-9_-]+)/balance', [
            'methods' => 'GET', 'callback' => [$this, 'get_agent_balance'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/agents/(?P<agent_id>[A-Za-z0-9_-]+)/withdraw', [
            'methods' => 'POST', 'callback' => [$this, 'withdraw_agent_balance'], 'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/work/threads', [
            'methods' => 'GET', 'callback' => [$this, 'open_thread'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/work/threads/mine', [
            'methods' => 'GET', 'callback' => [$this, 'list_my_threads'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/work/payment-profiles', [
            ['methods' => 'GET', 'callback' => [$this, 'get_payment_profiles'], 'permission_callback' => $auth],
            ['methods' => 'POST', 'callback' => [$this, 'save_payment_profiles'], 'permission_callback' => $auth],
        ]);
        register_rest_route($this->namespace, '/work/threads/(?P<id>\d+)/messages', [
            ['methods' => 'GET', 'callback' => [$this, 'list_thread_messages'], 'permission_callback' => $auth],
            ['methods' => 'POST', 'callback' => [$this, 'post_thread_message'], 'permission_callback' => $auth],
        ]);
        register_rest_route($this->namespace, '/work/reviews', [
            ['methods' => 'GET', 'callback' => [$this, 'list_reviews'], 'permission_callback' => $pub],
            ['methods' => 'POST', 'callback' => [$this, 'submit_review'], 'permission_callback' => $auth],
        ]);
        // Dedicated submit path — avoids any GET/list collision or POST→GET redirect landing on list_reviews.
        register_rest_route($this->namespace, '/work/reviews/submit', [
            'methods' => 'POST',
            'callback' => [$this, 'submit_review'],
            'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/work/reviews/mine', [
            'methods' => 'GET', 'callback' => [$this, 'mine_review'], 'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/allowances', [
            ['methods' => 'GET', 'callback' => [$this, 'list_allowances'], 'permission_callback' => $auth],
            ['methods' => 'POST', 'callback' => [$this, 'grant_allowance'], 'permission_callback' => $auth],
        ]);

        register_rest_route($this->namespace, '/work/open', [
            'methods' => 'GET', 'callback' => [$this, 'list_open_work'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/open/(?P<ref>[A-Za-z0-9_-]+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_open_work'], 'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/open/(?P<ref>[A-Za-z0-9_-]+)/submit', [
            'methods' => 'POST', 'callback' => [$this, 'submit_open_work'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/work/open/(?P<ref>[A-Za-z0-9_-]+)/submissions', [
            'methods' => 'GET', 'callback' => [$this, 'list_open_work_submissions'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/work/open/submissions/(?P<id>\d+)/accept', [
            'methods' => 'POST', 'callback' => [$this, 'accept_open_work_submission'], 'permission_callback' => $auth,
        ]);
        register_rest_route($this->namespace, '/work/open/submissions/(?P<id>\d+)/reject', [
            'methods' => 'POST', 'callback' => [$this, 'reject_open_work_submission'], 'permission_callback' => $auth,
        ]);
    }

    private function user_id(WP_REST_Request $request) {
        $payload = $request->get_param('jwt_payload');
        return is_array($payload) && !empty($payload['user_id']) ? (int) $payload['user_id'] : 0;
    }

    /**
     * Public browse rate limit: 60 req/min/IP.
     */
    private function enforce_public_browse_rate_limit() {
        if (!class_exists('GROWL_Security')) {
            return true;
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'unknown';
        $sec = new GROWL_Security();
        if (!$sec->rate_limit_check('marketplace_' . $ip, 'public_browse', 60, 60)) {
            return new WP_Error('rate_limited', 'Too many requests. Try again in a minute.', ['status' => 429]);
        }
        return true;
    }

    /**
     * Resolve ai_agents.agent_id for a human user via wallet_address match.
     */
    private function agent_id_for_user($uid) {
        $uid = (int) $uid;
        if ($uid < 1) {
            return '';
        }
        $db = new GROWL_Database();
        $p  = $db->table_prefix;
        $row = $db->get_row(
            "SELECT a.agent_id
             FROM {$p}ai_agents a
             INNER JOIN {$p}users u ON LOWER(u.wallet_address) = LOWER(a.wallet_address)
             WHERE u.user_id = %d
             LIMIT 1",
            [$uid]
        );
        return $row && !empty($row->agent_id) ? (string) $row->agent_id : '';
    }

    public function balance_activity(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $db = new GROWL_Database();
        if (method_exists($db, 'ensure_work_money_layer')) {
            $db->ensure_work_money_layer();
        }
        if (!class_exists('Growl_Wallet_Tx_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-wallet-tx-service.php';
        }
        $wtx = new Growl_Wallet_Tx_Service($db);
        $wtx->backfill_user($uid);

        $p = $db->table_prefix;
        $rows = $db->get_results(
            "SELECT tx_id, type, status, amount_micro, title, subtitle, bucket, ref_type, ref_id, tx_hash, network, meta_json, created_at
             FROM {$p}wallet_transactions
             WHERE user_id = %d
             ORDER BY created_at DESC, tx_id DESC
             LIMIT 50",
            [$uid]
        );
        $items = [];
        foreach ($rows ?: [] as $row) {
            $item = $wtx->format_activity_row($row);
            if (in_array((string) ($row->type ?? ''), ['transfer_out', 'transfer_in', 'p2p_send', 'p2p_receive'], true)
                && (string) ($row->ref_type ?? '') === 'p2p'
                && !empty($row->ref_id)
                && $item['counterparty'] === ''
            ) {
                $other = $db->get_row(
                    "SELECT u.user_id, u.display_name, u.profile_slug, u.x_username, u.email, u.wallet_address
                     FROM {$p}wallet_transactions t
                     INNER JOIN {$p}users u ON u.user_id = t.user_id
                     WHERE t.ref_id = %s AND t.user_id != %d LIMIT 1",
                    [(string) $row->ref_id, (int) $uid]
                );
                if (!$other) {
                    $other = $db->get_row(
                        "SELECT u.user_id, u.display_name, u.profile_slug, u.x_username, u.email, u.wallet_address
                         FROM {$p}balance_ledger l
                         INNER JOIN {$p}users u ON u.user_id = l.user_id
                         WHERE l.ref_id = %s AND l.user_id != %d LIMIT 1",
                        [(string) $row->ref_id, (int) $uid]
                    );
                }
                if ($other) {
                    $item['counterparty'] = $this->balance_activity_counterparty_label($other);
                }
            }
            $items[] = $item;
        }
        return rest_ensure_response(['activity' => $items]);
    }

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    private function format_balance_activity_row($row, $uid, $p, $db) {
        $purpose = (string) ($row->purpose ?? '');
        $display_micro = (string) ($row->amount_micro ?? '0');
        if (Growl_Money::cmp($display_micro, '0') === 0) {
            $delta_avail = (string) ($row->delta_available ?? '0');
            if (Growl_Money::cmp($delta_avail, '0') !== 0) {
                $display_micro = $delta_avail;
            } else {
                $delta_esc = (string) ($row->delta_escrow ?? '0');
                if (Growl_Money::cmp($delta_esc, '0') !== 0) {
                    $display_micro = $delta_esc;
                }
            }
        }
        $item = [
            'ledger_id'   => (int) $row->ledger_id,
            'purpose'     => $purpose,
            'entry_type'  => (string) $row->entry_type,
            'display'     => Growl_Money::to_display($display_micro),
            'ref_type'    => (string) $row->ref_type,
            'created_at'  => (string) $row->created_at,
            'title'       => $this->balance_activity_title($purpose),
            'counterparty'=> '',
        ];
        if ($purpose === 'wallet_send' && !empty($row->ref_id)) {
            $wallet = trim((string) $row->ref_id);
            if (strlen($wallet) > 12) {
                $item['counterparty'] = substr($wallet, 0, 6) . '…' . substr($wallet, -4);
            } else {
                $item['counterparty'] = $wallet;
            }
            $tx = $db->get_var(
                "SELECT transaction_hash FROM {$p}withdrawals WHERE user_id = %d AND to_wallet = %s AND transaction_hash IS NOT NULL AND transaction_hash != '' ORDER BY withdrawal_id DESC LIMIT 1",
                [(int) $uid, $wallet]
            );
            if ($tx) {
                $item['tx_hash'] = (string) $tx;
            }
        }
        if (in_array($purpose, ['p2p_send', 'p2p_receive'], true) && !empty($row->ref_id)) {
            $other = $db->get_row(
                "SELECT u.user_id, u.display_name, u.profile_slug, u.x_username, u.email, u.wallet_address
                 FROM {$p}balance_ledger l
                 INNER JOIN {$p}users u ON u.user_id = l.user_id
                 WHERE l.ref_id = %s AND l.user_id != %d LIMIT 1",
                [(string) $row->ref_id, (int) $uid]
            );
            if ($other) {
                $item['counterparty'] = $this->balance_activity_counterparty_label($other);
                $item['title'] = $purpose === 'p2p_send' ? 'Transfer Out' : 'Transfer In';
            }
        }
        return $item;
    }

    private function balance_activity_title($purpose) {
        $map = [
            'p2p_send'    => 'Transfer Out',
            'p2p_receive' => 'Transfer In',
            'withdrawal'  => 'Withdrawal',
            'deposit'     => 'Deposit',
            'deal_fund'   => 'Deal funded',
            'deal_release'=> 'Deal released',
            'deal_refund' => 'Deal refunded',
            'wallet_send' => 'Sent',
        ];
        if (isset($map[$purpose])) {
            return $map[$purpose];
        }
        $label = str_replace('_', ' ', (string) $purpose);
        return $label !== '' ? ucwords($label) : 'Movement';
    }

    /**
     * @param object $user
     */
    private function balance_activity_counterparty_label($user) {
        $slug = trim((string) ($user->profile_slug ?? ''));
        if ($slug !== '') {
            return '@' . ltrim($slug, '@');
        }
        $x = trim((string) ($user->x_username ?? ''));
        if ($x !== '') {
            return '@' . ltrim($x, '@');
        }
        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            return $email;
        }
        $wallet = trim((string) ($user->wallet_address ?? ''));
        if ($wallet !== '') {
            return strlen($wallet) > 12 ? (substr($wallet, 0, 6) . '…' . substr($wallet, -4)) : $wallet;
        }
        $name = trim((string) ($user->display_name ?? ''));
        return $name !== '' ? $name : 'EarnFi user';
    }

    public function payment_settle(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $signed = (string) $request->get_param('signed_tx');
        $req    = $request->get_param('requirements');
        if ($signed === '' || !is_array($req)) {
            return new WP_Error('invalid', 'Payment could not be verified.', ['status' => 400]);
        }
        $payments = new Growl_X402_Payment_Service();
        $sid = $payments->settle_wallet_payment($uid, $signed, $req, [
            'purpose'      => sanitize_key((string) ($request->get_param('purpose') ?? 'direct_payment')),
            'amount_micro' => Growl_Money::to_micro($request->get_param('amount') ?? $request->get_param('amount_micro')),
            'ref_type'     => sanitize_key((string) ($request->get_param('ref_type') ?? '')),
            'ref_id'       => sanitize_text_field((string) ($request->get_param('ref_id') ?? '')),
            'idempotency_key' => Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? ''),
            'intent_id'       => sanitize_text_field((string) ($request->get_param('intent_id') ?? '')),
        ]);
        if (is_wp_error($sid)) {
            return Growl_X402_Payment_Service::public_error($sid);
        }
        return rest_ensure_response(['settlement_id' => (int) $sid]);
    }

    public function dispute_deal(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $deal = (new Growl_Deal_Service())->open_dispute((int) $request['id'], $uid, (string) $request->get_param('reason'));
        return is_wp_error($deal) ? $deal : rest_ensure_response(['deal' => $deal]);
    }

    public function refund_deal(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $deal = (new Growl_Deal_Service())->refund((int) $request['id'], $uid, $idem);
        return is_wp_error($deal) ? $deal : rest_ensure_response(['deal' => $deal]);
    }

    public function verify_receipt(WP_REST_Request $request) {
        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-receipt-service.php';
        }
        $id = sanitize_text_field((string) $request['receipt_id']);
        $out = (new Growl_Work_Receipt_Service())->verify($id);
        if (!$out) {
            return new WP_Error('not_found', 'Receipt not found.', ['status' => 404]);
        }
        return rest_ensure_response($out);
    }

    private function telegram_bot_username() {
        if (!class_exists('GROWL_Telegram_Copy') && defined('GROWL_PLUGIN_PATH')) {
            $copy = GROWL_PLUGIN_PATH . 'includes/telegram/class-telegram-copy.php';
            if (is_readable($copy)) {
                require_once $copy;
            }
        }
        if (class_exists('GROWL_Telegram_Copy')) {
            $name = (string) GROWL_Telegram_Copy::bot_username();
            if ($name !== '') {
                return $name;
            }
        }
        return 'EarnFiHubBot';
    }

    private function telegram_deep_link($code) {
        return 'https://t.me/' . $this->telegram_bot_username() . '?start=link_' . $code;
    }

    private function telegram_row_is_linked($row) {
        return $row
            && !empty($row->linked_at)
            && (int) $row->tg_user_id > 0;
    }

    public function telegram_link_code(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $expires_at = gmdate('Y-m-d H:i:s', time() + 900);
        $now = gmdate('Y-m-d H:i:s');
        $db = new GROWL_Database();
        $p = $db->table_prefix;
        $bot = $this->telegram_bot_username();
        $code = '';
        $deep_link = '';
        $last_error = '';

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $code = strtoupper(bin2hex(random_bytes(3)));
            $deep_link = $this->telegram_deep_link($code);
            $row = $db->get_row(
                "SELECT * FROM {$p}telegram_account_links WHERE user_id = %d LIMIT 1",
                [$uid]
            );
            if ($row) {
                $ok = $db->update('telegram_account_links', [
                    'link_code'            => $code,
                    'link_code_expires_at' => $expires_at,
                ], ['user_id' => $uid]);
            } else {
                $ok = $db->insert('telegram_account_links', [
                    'tg_user_id'           => 0 - $uid,
                    'user_id'              => $uid,
                    'link_code'            => $code,
                    'link_code_expires_at' => $expires_at,
                    'created_at'           => $now,
                ]);
                if (!$ok) {
                    $ok = $db->update('telegram_account_links', [
                        'link_code'            => $code,
                        'link_code_expires_at' => $expires_at,
                    ], ['user_id' => $uid]);
                }
            }
            $last_error = (string) ($db->wpdb->last_error ?? '');
            if ($ok && $last_error === '') {
                break;
            }
        }

        if ($last_error !== '') {
            return new WP_Error('db_error', 'Could not create a link code.', ['status' => 500]);
        }

        $fresh = $db->get_row(
            "SELECT * FROM {$p}telegram_account_links WHERE user_id = %d LIMIT 1",
            [$uid]
        );

        return rest_ensure_response([
            'code'         => $code,
            'command'      => '/link ' . $code,
            'bot_username' => $bot,
            'deep_link'    => $deep_link,
            'expires_at'   => $expires_at,
            'expires_in'   => 900,
            'linked'       => $this->telegram_row_is_linked($fresh),
        ]);
    }

    public function telegram_link_status(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $bot = $this->telegram_bot_username();
        $db = new GROWL_Database();
        $p = $db->table_prefix;
        $row = $db->get_row(
            "SELECT * FROM {$p}telegram_account_links WHERE user_id = %d LIMIT 1",
            [$uid]
        );

        $linked = $this->telegram_row_is_linked($row);
        $tg = $row ? (int) $row->tg_user_id : 0;
        $pending_code = null;
        $expires_at = null;
        $command = null;
        if ($row && !empty($row->link_code)) {
            $exp = isset($row->link_code_expires_at) ? (string) $row->link_code_expires_at : '';
            $unexpired = $exp === '' || gmdate('Y-m-d H:i:s') <= $exp;
            if ($unexpired) {
                $pending_code = strtoupper((string) $row->link_code);
                $expires_at = $exp !== '' ? $exp : null;
                $command = '/link ' . $pending_code;
            }
        }

        return rest_ensure_response([
            'linked'         => (bool) $linked,
            'bot_username'   => $bot,
            'deep_link_base' => 'https://t.me/' . $bot . '?start=',
            'tg_user_id'     => ($linked && $tg > 0) ? $tg : null,
            'pending_code'   => $pending_code,
            'expires_at'     => $expires_at,
            'command'        => $command,
        ]);
    }

    public function telegram_unlink(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $db = new GROWL_Database();
        $p = $db->table_prefix;
        $db->wpdb->query($db->wpdb->prepare(
            "DELETE FROM {$p}telegram_account_links WHERE user_id = %d",
            $uid
        ));
        return rest_ensure_response(['unlinked' => true]);
    }

    public function list_jobs(WP_REST_Request $request) {
        $svc = new Growl_Job_Board_Service();
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $q = sanitize_text_field((string) ($request->get_param('q') ?? ''));
        $category = sanitize_key((string) ($request->get_param('category') ?? ''));
        $execution_mode = sanitize_key((string) ($request->get_param('execution_mode') ?? ''));
        return rest_ensure_response(['listings' => $svc->list_open($per, ($page - 1) * $per, $q, $category, $execution_mode)]);
    }

    public function create_job_listing(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $row = (new Growl_Job_Board_Service())->create_listing($uid, $request->get_json_params() ?: []);
        return is_wp_error($row) ? $row : rest_ensure_response(['listing' => $row]);
    }

    public function update_job_listing(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];
        $row = (new Growl_Job_Board_Service())->update_listing((int) $request['id'], $uid, $body);
        return is_wp_error($row) ? $row : rest_ensure_response([
            'success' => true,
            'message' => 'Hire listing updated',
            'listing' => $row,
        ]);
    }

    public function get_job_listing(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $row = (new Growl_Job_Board_Service())->get_by_slug((string) $request['slug'], $uid);
        if (!$row) {
            return new WP_Error('not_found', 'Listing not found.', ['status' => 404]);
        }
        return rest_ensure_response(['listing' => $row]);
    }

    public function apply_job(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $res = (new Growl_Job_Board_Service())->apply((int) $request['id'], $uid, (string) $request->get_param('pitch'));
        return is_wp_error($res) ? $res : rest_ensure_response($res);
    }

    public function accept_job_application(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }
        $application_id = (int) ($body['application_id'] ?? $request->get_param('application_id'));
        $overrides = [];
        if (isset($body['amount']) || isset($body['amount_micro'])) {
            $overrides['amount'] = $body['amount'] ?? null;
            $overrides['amount_micro'] = $body['amount_micro'] ?? null;
        }
        if (!empty($body['payment_structure'])) {
            $overrides['payment_structure'] = $body['payment_structure'];
        }
        if (!empty($body['milestones']) && is_array($body['milestones'])) {
            $overrides['milestones'] = $body['milestones'];
        }
        $res = (new Growl_Job_Board_Service())->accept_application(
            (int) $request['id'],
            $application_id,
            $uid,
            $overrides
        );
        return is_wp_error($res) ? $res : rest_ensure_response($res);
    }

    public function shortlist_job_application(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }
        $shortlisted = array_key_exists('shortlisted', $body)
            ? (bool) $body['shortlisted']
            : true;
        $res = (new Growl_Job_Board_Service())->shortlist_application(
            (int) $request['id'],
            (int) $request['app_id'],
            $uid,
            $shortlisted
        );
        return is_wp_error($res) ? $res : rest_ensure_response(['application' => $res]);
    }

    public function get_job_contract(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $c = (new Growl_Job_Contract_Service())->get_by_slug((string) $request['slug']);
        if (!$c) {
            return new WP_Error('not_found', 'Contract not found.', ['status' => 404]);
        }
        if ($uid !== (int) $c['employer_user_id'] && $uid !== (int) $c['worker_user_id']) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        return rest_ensure_response(['contract' => $c]);
    }

    public function fund_job_contract(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to pay.', ['status' => 401]);
        }
        $source = sanitize_key((string) ($request->get_param('source') ?? 'balance'));
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');

        if ($source === 'wallet') {
            $signed = (string) ($request->get_param('signed_tx') ?? '');
            if ($signed === '') {
                return $this->initiate_job_contract_wallet_fund($request, $uid, $idem);
            }
            return $this->confirm_job_contract_wallet_fund($request, $uid, $signed, $idem);
        }

        $fund_source = 'x402';
        if ($source === 'balance') {
            $fund_source = 'balance';
        } elseif ($source === 'creator_wallet') {
            $fund_source = 'creator_wallet';
        }

        $res = (new Growl_Job_Contract_Service())->fund(
            (int) $request['id'],
            $uid,
            $fund_source,
            $request->get_param('settlement_id') ? (int) $request->get_param('settlement_id') : null,
            $idem
        );
        return is_wp_error($res) ? $res : rest_ensure_response(['contract' => $res]);
    }

    public function deliver_job_contract(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $res = (new Growl_Job_Contract_Service())->deliver(
            (int) $request['id'],
            $uid,
            (string) ($request->get_param('note') ?? '')
        );
        return is_wp_error($res) ? $res : rest_ensure_response(['contract' => $res]);
    }

    public function release_job_contract(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $res = (new Growl_Job_Contract_Service())->release((int) $request['id'], $uid, $idem);
        if (is_wp_error($res)) {
            return $res;
        }
        $slug = (string) ($res['public_slug'] ?? '');
        $payload = ['contract' => $res];
        if ($slug !== '' && class_exists('Growl_Work_Receipt_Service')) {
            $payload = Growl_Work_Receipt_Service::attach_to_payload($payload, 'job_contract', $slug, 'released');
        }
        return rest_ensure_response($payload);
    }

    public function deliver_job_milestone(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $res = (new Growl_Job_Contract_Service())->deliver_milestone(
            (int) $request['id'],
            (int) $request['mid'],
            $uid,
            (string) ($request->get_param('note') ?? '')
        );
        return is_wp_error($res) ? $res : rest_ensure_response(['contract' => $res]);
    }

    public function release_job_milestone(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $res = (new Growl_Job_Contract_Service())->release_milestone(
            (int) $request['id'],
            (int) $request['mid'],
            $uid,
            $idem
        );
        return is_wp_error($res) ? $res : rest_ensure_response(['contract' => $res]);
    }

    public function work_fee_quote(WP_REST_Request $request) {
        $product = sanitize_key((string) ($request->get_param('product') ?? 'deals'));
        $amount = $request->get_param('amount');
        $micro = Growl_Money::resolve_amount_micro([
            'amount' => $amount,
            'amount_micro' => $request->get_param('amount_micro'),
        ]);
        if (!Growl_Money::is_positive($micro)) {
            return rest_ensure_response(['quote' => Growl_Work_Fee_Engine::quote($product)]);
        }
        return rest_ensure_response(['quote' => Growl_Work_Fee_Engine::split_for_gross($micro, $product)]);
    }

    public function list_job_applications(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $res = (new Growl_Job_Board_Service())->list_applications((int) $request['id'], $uid);
        return is_wp_error($res) ? $res : rest_ensure_response(['applications' => $res]);
    }

    public function my_job_application(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $row = (new Growl_Job_Board_Service())->my_application((int) $request['id'], $uid);
        return rest_ensure_response(['application' => $row]);
    }

    public function list_capabilities() {
        $db = new GROWL_Database();
        $rows = $db->get_results("SELECT slug, title, description, provider, catalog_path FROM {$db->table_prefix}capabilities ORDER BY slug ASC");
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'slug'         => (string) $row->slug,
                'title'        => (string) $row->title,
                'description'  => (string) $row->description,
                'provider'     => (string) $row->provider,
                'catalog_path' => $row->catalog_path,
            ];
        }
        return rest_ensure_response(['capabilities' => $out]);
    }

    public function get_capability(WP_REST_Request $request) {
        $db = new GROWL_Database();
        $row = $db->get_row(
            "SELECT * FROM {$db->table_prefix}capabilities WHERE slug = %s LIMIT 1",
            [sanitize_key((string) $request['slug'])]
        );
        if (!$row) {
            return new WP_Error('not_found', 'Capability not found.', ['status' => 404]);
        }
        $orders = new Growl_Agent_Order_Service($db);
        return rest_ensure_response([
            'capability' => [
                'slug'        => (string) $row->slug,
                'title'       => (string) $row->title,
                'description' => (string) $row->description,
                'provider'    => (string) $row->provider,
                'catalog_path'=> $row->catalog_path,
            ],
            'agents' => $orders->catalog((string) $row->slug, 20, 0),
        ]);
    }

    public function agent_catalog(WP_REST_Request $request) {
        $cap = sanitize_key((string) ($request->get_param('capability') ?? ''));
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        return rest_ensure_response([
            'services' => (new Growl_Agent_Order_Service())->catalog($cap, $per, ($page - 1) * $per),
        ]);
    }

    public function create_agent_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $svc = new Growl_Agent_Order_Service();
        $order = $svc->create_order(
            'human',
            (string) $uid,
            (int) $request->get_param('service_id'),
            is_array($request->get_param('input')) ? $request->get_param('input') : []
        );
        if (is_wp_error($order)) {
            return $order;
        }

        // Optional create+fund in one request (Pay & start). Instant sources only here;
        // connected wallet still uses POST .../fund for the 402 → sign → confirm dance.
        $source = sanitize_key((string) ($request->get_param('source') ?? ''));
        $want_fund = !empty($request->get_param('fund')) || in_array($source, ['balance', 'creator_wallet'], true);
        if ($want_fund && $uid > 0 && !empty($order['order_id'])) {
            if ($source === '') {
                $source = 'balance';
            }
            if (in_array($source, ['balance', 'creator_wallet'], true)) {
                $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
                $funded = $svc->fund_order(
                    (int) $order['order_id'],
                    $uid,
                    $source,
                    $request->get_param('settlement_id') ? (int) $request->get_param('settlement_id') : null,
                    $idem
                );
                if (is_wp_error($funded)) {
                    // Order exists pending_funding — surface create + fund error for retry.
                    $envelope = Growl_Agent_Guidance::envelope('agent_order', $order, 'human', (string) $uid);
                    $envelope['fund_error'] = $funded->get_error_message();
                    $envelope['hint'] = 'Order created but payment failed. Retry fund with the same order_id or open the pay dialog.';
                    return rest_ensure_response($envelope);
                }
                return Growl_Agent_Guidance::rest_response('agent_order', $funded, 'human', (string) $uid);
            }
        }

        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'human', (string) $uid);
    }

    public function upload_order_reference(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to upload reference images.', ['status' => 401]);
        }
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }
        $result = (new Growl_Agent_Order_Service())->upload_reference_image($uid, (string) ($body['image'] ?? ''));
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(['success' => true, 'url' => $result['url']]);
    }

    public function fund_agent_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to pay.', ['status' => 401]);
        }
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $source = sanitize_key((string) ($request->get_param('source') ?? 'balance'));

        if ($source === 'wallet') {
            $signed = (string) ($request->get_param('signed_tx') ?? '');
            if ($signed === '') {
                return $this->initiate_agent_order_wallet_fund($request, $uid, $idem);
            }
            $order = $this->confirm_agent_order_wallet_fund($request, $uid, $signed, $idem);
            if (is_wp_error($order)) {
                return $order;
            }
            return Growl_Agent_Guidance::rest_response('agent_order', $order, 'human', (string) $uid);
        }

        $fund_source = 'x402';
        if ($source === 'balance') {
            $fund_source = 'balance';
        } elseif ($source === 'creator_wallet') {
            $fund_source = 'creator_wallet';
        }

        $order = (new Growl_Agent_Order_Service())->fund_order(
            (int) $request['id'],
            $uid,
            $fund_source,
            $request->get_param('settlement_id') ? (int) $request->get_param('settlement_id') : null,
            $idem
        );
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'human', (string) $uid);
    }

    public function release_agent_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $order = (new Growl_Agent_Order_Service())->release((int) $request['id'], $uid, $idem, []);
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'human', (string) $uid);
    }

    public function revise_agent_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $order = (new Growl_Agent_Order_Service())->request_revision(
            (int) $request['id'],
            $uid,
            (string) ($request->get_param('note') ?? '')
        );
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'human', (string) $uid);
    }

    public function dispute_agent_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $order = (new Growl_Agent_Order_Service())->open_dispute(
            (int) $request['id'],
            'human',
            (string) $uid,
            (string) ($request->get_param('reason') ?? '')
        );
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'human', (string) $uid);
    }

    public function get_agent_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $viewer_type = $uid > 0 ? 'human' : 'guest';
        $viewer_ref  = $uid > 0 ? (string) $uid : '';
        if ($uid > 0) {
            $agent_id = $this->agent_id_for_user($uid);
            if ($agent_id !== '') {
                $probe = (new Growl_Agent_Order_Service())->get_order(
                    (int) $request['id'],
                    '',
                    'agent',
                    $agent_id
                );
                if ($probe && (string) ($probe['provider_agent_id'] ?? '') === $agent_id) {
                    $viewer_type = 'agent';
                    $viewer_ref  = $agent_id;
                }
            }
        }
        $order = (new Growl_Agent_Order_Service())->get_order(
            (int) $request['id'],
            (string) ($request->get_param('secret') ?? ''),
            $viewer_type,
            $viewer_ref
        );
        if (!$order) {
            return new WP_Error('not_found', 'Order not found.', ['status' => 404]);
        }
        return Growl_Agent_Guidance::rest_response('agent_order', $order, $viewer_type, $viewer_ref);
    }

    public function create_work_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $wo = (new Growl_Work_Order_Service())->create($uid, $request->get_json_params() ?: []);
        return is_wp_error($wo) ? $wo : rest_ensure_response(['work_order' => $wo]);
    }

    public function get_work_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $wo = (new Growl_Work_Order_Service())->get((int) $request['id'], $uid);
        if (is_wp_error($wo)) {
            return $wo;
        }
        if (!$wo) {
            return new WP_Error('not_found', 'Work order not found.', ['status' => 404]);
        }
        return rest_ensure_response(['work_order' => $wo]);
    }

    public function fund_work_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $wo = (new Growl_Work_Order_Service())->fund(
            (int) $request['id'],
            $uid,
            sanitize_key((string) ($request->get_param('source') ?? 'balance')),
            $request->get_param('settlement_id') ? (int) $request->get_param('settlement_id') : null,
            $idem
        );
        return is_wp_error($wo) ? $wo : rest_ensure_response(['work_order' => $wo]);
    }

    public function list_allowances(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        return rest_ensure_response(['allowances' => (new Growl_Allowance_Service())->list_for_user($uid)]);
    }

    public function grant_allowance(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $row = (new Growl_Allowance_Service())->grant(
            $uid,
            (string) $request->get_param('agent_id'),
            $request->get_param('limit') ?? $request->get_param('limit_micro'),
            $request->get_param('expires_at')
        );
        return is_wp_error($row) ? $row : rest_ensure_response(['allowance' => $row]);
    }

    public function list_my_jobs(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        return rest_ensure_response([
            'listings' => (new Growl_Job_Board_Service())->list_mine($uid, $per, ($page - 1) * $per),
        ]);
    }

    /**
     * @return array{min_rating:float,min_price_micro:string,max_price_micro:string,model:string,delivery_type:string}
     */
    private function marketplace_browse_filters(WP_REST_Request $request) {
        $min_price = $request->get_param('min_price_micro');
        if ($min_price === null || $min_price === '') {
            $min_price = $request->get_param('min_price') ?? '';
        }
        $max_price = $request->get_param('max_price_micro');
        if ($max_price === null || $max_price === '') {
            $max_price = $request->get_param('max_price') ?? '';
        }
        return [
            'min_rating'       => (float) ($request->get_param('min_rating') ?? 0),
            'min_price_micro'  => $min_price !== null && $min_price !== '' ? (string) Growl_Money::to_micro($min_price) : '',
            'max_price_micro'  => $max_price !== null && $max_price !== '' ? (string) Growl_Money::to_micro($max_price) : '',
            'model'            => sanitize_text_field((string) ($request->get_param('model') ?? '')),
            'delivery_type'    => sanitize_key((string) ($request->get_param('delivery_type') ?? '')),
        ];
    }

    public function browse_agents(WP_REST_Request $request) {
        $rl = $this->enforce_public_browse_rate_limit();
        if (is_wp_error($rl)) {
            return $rl;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-marketplace-service.php';
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $filters = $this->marketplace_browse_filters($request);
        $svc  = new Growl_Agent_Marketplace_Service();
        return rest_ensure_response([
            'agents'   => $svc->browse_agents(
                sanitize_key((string) ($request->get_param('category') ?? '')),
                sanitize_text_field((string) ($request->get_param('q') ?? '')),
                sanitize_key((string) ($request->get_param('sort') ?? 'popular')),
                $per,
                ($page - 1) * $per,
                $request->get_param('said_verified') === '1' || $request->get_param('said_verified') === 'true',
                $filters['min_rating'],
                $filters['min_price_micro'],
                $filters['max_price_micro'],
                $filters['model'],
                $filters['delivery_type']
            ),
            'page'     => $page,
            'per_page' => $per,
        ]);
    }

    public function browse_services(WP_REST_Request $request) {
        $rl = $this->enforce_public_browse_rate_limit();
        if (is_wp_error($rl)) {
            return $rl;
        }
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $cap  = sanitize_key((string) ($request->get_param('category') ?? $request->get_param('capability') ?? ''));
        $q    = sanitize_text_field((string) ($request->get_param('q') ?? ''));
        $sort = sanitize_key((string) ($request->get_param('sort') ?? 'popular'));
        $said = $request->get_param('said_verified') === '1' || $request->get_param('said_verified') === 'true';
        $filters = $this->marketplace_browse_filters($request);
        return rest_ensure_response([
            'services' => (new Growl_Agent_Order_Service())->catalog(
                $cap,
                $per,
                ($page - 1) * $per,
                $q,
                $sort,
                $said,
                $filters['min_rating'],
                $filters['min_price_micro'],
                $filters['max_price_micro'],
                $filters['model'],
                $filters['delivery_type']
            ),
            'page'     => $page,
            'per_page' => $per,
        ]);
    }

    public function agent_services(WP_REST_Request $request) {
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-marketplace-service.php';
        $row = (new Growl_Agent_Marketplace_Service())->agent_services((string) $request['agent_id']);
        return is_wp_error($row) ? $row : rest_ensure_response($row);
    }

    public function list_my_agent_orders(WP_REST_Request $request) {
        $uid  = $this->user_id($request);
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $role = sanitize_key((string) ($request->get_param('role') ?? 'buyer'));
        $svc  = new Growl_Agent_Order_Service();
        if ($role === 'provider') {
            $agent_id = $this->agent_id_for_user($uid);
            if ($agent_id === '') {
                return new WP_Error('forbidden', 'Link a wallet that owns an agent profile.', ['status' => 403]);
            }
            $orders = $svc->list_for_provider($agent_id, $per, ($page - 1) * $per);
        } else {
            $orders = $svc->list_for_buyer('human', (string) $uid, $per, ($page - 1) * $per);
        }
        return rest_ensure_response([
            'orders'   => $orders,
            'role'     => $role === 'provider' ? 'provider' : 'buyer',
            'page'     => $page,
            'per_page' => $per,
        ]);
    }

    public function get_agent_order_slug(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $viewer_type = $uid > 0 ? 'human' : 'guest';
        $viewer_ref  = $uid > 0 ? (string) $uid : '';
        $agent_id    = $uid > 0 ? $this->agent_id_for_user($uid) : '';
        $order = (new Growl_Agent_Order_Service())->get_by_slug(
            (string) $request['slug'],
            (string) ($request->get_param('secret') ?? ''),
            $viewer_type,
            $viewer_ref
        );
        if (!$order) {
            return new WP_Error('not_found', 'Order not found.', ['status' => 404]);
        }
        $is_buyer = $uid > 0 && (string) $order['buyer_ref'] === (string) $uid && ($order['buyer_type'] ?? '') === 'human';
        $is_provider = $agent_id !== '' && (string) ($order['provider_agent_id'] ?? '') === $agent_id;
        if (!$is_buyer && !$is_provider) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        if ($is_provider) {
            $viewer_type = 'agent';
            $viewer_ref  = $agent_id;
            $order = (new Growl_Agent_Order_Service())->get_by_slug(
                (string) $request['slug'],
                '',
                'agent',
                $agent_id
            );
        }
        return Growl_Agent_Guidance::rest_response('agent_order', $order, $viewer_type, $viewer_ref);
    }

    public function deliver_agent_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to deliver.', ['status' => 401]);
        }
        $agent_id = $this->agent_id_for_user($uid);
        if ($agent_id === '') {
            return new WP_Error('forbidden', 'Link a wallet that owns an agent profile.', ['status' => 403]);
        }
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }
        $output = $request->get_param('output');
        if (!is_array($output) && isset($params['output']) && is_array($params['output'])) {
            $output = $params['output'];
        }
        if (!is_array($output)) {
            $output = [];
        }
        $order = (new Growl_Agent_Order_Service())->deliver((int) $request['id'], $agent_id, $output);
        return Growl_Agent_Guidance::rest_response('agent_order', $order, 'agent', $agent_id);
    }

    public function marketplace_stats(WP_REST_Request $request) {
        $rl = $this->enforce_public_browse_rate_limit();
        if (is_wp_error($rl)) {
            return $rl;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-marketplace-service.php';
        return rest_ensure_response(['stats' => (new Growl_Agent_Marketplace_Service())->get_stats()]);
    }

    public function marketplace_leaderboard(WP_REST_Request $request) {
        $rl = $this->enforce_public_browse_rate_limit();
        if (is_wp_error($rl)) {
            return $rl;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-marketplace-service.php';
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $period = sanitize_key((string) ($request->get_param('period') ?? 'all'));
        $board = (new Growl_Agent_Marketplace_Service())->leaderboard($period, $per, ($page - 1) * $per);
        return rest_ensure_response(['leaderboard' => $board]);
    }

    public function provider_list_services(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $agent_id = $this->agent_id_for_user($uid);
        if ($agent_id === '') {
            return new WP_Error('forbidden', 'Link a wallet that owns an agent profile.', ['status' => 403]);
        }
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(100, max(1, (int) ($request->get_param('per_page') ?? 50)));
        $svc  = new Growl_Agent_Order_Service();
        $all  = $svc->list_services_for_agent($agent_id, $per);
        return rest_ensure_response([
            'agent_id' => $agent_id,
            'services' => $all,
            'page'     => $page,
            'per_page' => $per,
        ]);
    }

    public function provider_set_service_status(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $agent_id = $this->agent_id_for_user($uid);
        if ($agent_id === '') {
            return new WP_Error('forbidden', 'Link a wallet that owns an agent profile.', ['status' => 403]);
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }
        $status = sanitize_key((string) ($request->get_param('status') ?? ($params['status'] ?? '')));
        $service = (new Growl_Agent_Order_Service())->set_service_status($agent_id, (int) $request['id'], $status);
        return is_wp_error($service) ? $service : rest_ensure_response(['service' => $service]);
    }

    public function refund_agent_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $order = (new Growl_Agent_Order_Service())->refund((int) $request['id'], $uid, $idem);
        return is_wp_error($order) ? $order : rest_ensure_response(['order' => $order]);
    }

    public function said_feedback_order(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        $order = (new Growl_Agent_Order_Service())->get_order((int) $request['id'], '', 'human', (string) $uid);
        if (!$order || (string) $order['buyer_ref'] !== (string) $uid) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-said-feedback-queue.php';
        $ok = (new Growl_Said_Feedback_Queue())->attach_signature((string) $order['public_slug'], [
            'signer_wallet' => sanitize_text_field((string) ($request->get_param('signer_wallet') ?? '')),
            'message'       => (string) ($request->get_param('message') ?? ''),
            'signature'     => $request->get_param('signature'),
        ]);
        return is_wp_error($ok) ? $ok : rest_ensure_response(['ok' => true]);
    }

    public function open_thread(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-thread-service.php';
        $ref_type = sanitize_key((string) ($request->get_param('ref_type') ?? ''));
        $ref_key  = sanitize_text_field((string) ($request->get_param('ref_key') ?? ''));
        $svc      = new Growl_Work_Thread_Service();
        $row = $svc->resolve($ref_type, $ref_key, 'human', (string) $uid);
        if (is_wp_error($row) && $ref_type === 'agent_order' && $uid > 0) {
            $agent_id = $this->agent_id_for_user($uid);
            if ($agent_id !== '') {
                $row = $svc->resolve($ref_type, $ref_key, 'agent', $agent_id);
            }
        }
        return is_wp_error($row) ? $row : rest_ensure_response($row);
    }

    public function list_my_threads(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-thread-service.php';
        $filter = sanitize_key((string) ($request->get_param('filter') ?? ''));
        $page = max(1, (int) ($request->get_param('page') ?? 1));
        $per = min(60, max(1, (int) ($request->get_param('per_page') ?? 40)));
        $res = (new Growl_Work_Thread_Service())->list_for_user($uid, $filter, $page, $per);
        return rest_ensure_response($res);
    }

    public function get_payment_profiles(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $db = new GROWL_Database();
        $raw = $db->get_var(
            "SELECT work_prefs_json FROM {$db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$uid]
        );
        $prefs = $raw ? json_decode((string) $raw, true) : [];
        if (!is_array($prefs)) {
            $prefs = [];
        }
        return rest_ensure_response([
            'profiles' => array_values((array) ($prefs['payment_profiles'] ?? [])),
        ]);
    }

    public function save_payment_profiles(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $profiles = $request->get_param('profiles');
        if (!is_array($profiles)) {
            return new WP_Error('invalid', 'Profiles must be an array.', ['status' => 400]);
        }
        $clean = [];
        foreach ($profiles as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $methods = [];
            foreach ((array) ($row['methods'] ?? $row['payment_methods'] ?? []) as $m) {
                $m = sanitize_text_field((string) $m);
                if ($m !== '') {
                    $methods[] = $m;
                }
            }
            $clean[] = [
                'id'      => sanitize_text_field((string) ($row['id'] ?? ('p' . ($i + 1)))),
                'label'   => sanitize_text_field((string) ($row['label'] ?? 'Payment method')),
                'region'  => sanitize_text_field((string) ($row['region'] ?? '')),
                'methods' => array_values(array_unique($methods)),
                'details' => sanitize_textarea_field((string) ($row['details'] ?? '')),
            ];
        }
        $db = new GROWL_Database();
        $db->update('users', [
            'work_prefs_json' => wp_json_encode(['payment_profiles' => $clean]),
        ], ['user_id' => $uid]);
        return rest_ensure_response(['profiles' => $clean]);
    }

    public function list_thread_messages(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-thread-service.php';
        $thread_id = (int) $request['id'];
        $svc = new Growl_Work_Thread_Service();
        $mark_read = !in_array($request->get_param('mark_read'), ['0', 'false', 0, false], true);
        $unread = $svc->unread_count_for_human($thread_id, $uid);
        $msgs = $svc->list_messages(
            $thread_id,
            'human',
            (string) $uid,
            (int) ($request->get_param('after') ?? 0),
            min(100, max(1, (int) ($request->get_param('per_page') ?? 50))),
            $mark_read
        );
        if (is_wp_error($msgs)) {
            return $msgs;
        }
        $labels = $svc->chat_role_labels($thread_id, $uid);
        return rest_ensure_response([
            'messages'     => $msgs,
            'unread_count' => $unread,
            'role_labels'  => $labels,
        ]);
    }

    public function post_thread_message(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-thread-service.php';
        $msg = (new Growl_Work_Thread_Service())->post(
            (int) $request['id'],
            'human',
            (string) $uid,
            (string) ($request->get_param('body') ?? '')
        );
        return is_wp_error($msg) ? $msg : rest_ensure_response(['message' => $msg]);
    }

    public function list_reviews(WP_REST_Request $request) {
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-review-service.php';
        $svc = new Growl_Work_Review_Service();

        // Guard: a submit-shaped payload must never be handled as a profile review list.
        // (Happens when POST is redirected/converted to GET and lands on this route.)
        $maybe_submit = $request->get_json_params();
        if (!is_array($maybe_submit)) {
            $maybe_submit = [];
        }
        $looks_like_submit = sanitize_key((string) ($maybe_submit['ref_type'] ?? $request->get_param('ref_type') ?? '')) !== ''
            && (
                isset($maybe_submit['stars'])
                || $request->get_param('stars') !== null
                || sanitize_text_field((string) ($maybe_submit['ref_id'] ?? $request->get_param('ref_id') ?? '')) !== ''
            );
        if ($looks_like_submit && strtoupper((string) $request->get_method()) !== 'POST') {
            return new WP_Error(
                'method_not_allowed',
                'Ratings must be submitted with POST to /work/reviews/submit.',
                ['status' => 405]
            );
        }

        $service_id = (int) ($request->get_param('service_id') ?? 0);
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        if ($service_id > 0) {
            return rest_ensure_response([
                'reviews' => $svc->list_for_service($service_id, $per, ($page - 1) * $per),
            ]);
        }
        $type = sanitize_key((string) ($request->get_param('ratee_type') ?? 'human'));
        $ref  = sanitize_text_field((string) ($request->get_param('ratee_ref') ?? ''));
        if ($ref === '') {
            return new WP_Error(
                'invalid',
                'ratee_ref is required to list reviews for a profile.',
                ['status' => 400]
            );
        }
        return rest_ensure_response([
            'reviews' => $svc->list_for_ratee($type, $ref, $per, ($page - 1) * $per),
        ]);
    }

    public function submit_review(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in required to submit a rating.', ['status' => 401]);
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-review-service.php';
        $params = $this->extract_review_submit_params($request);
        $row = (new Growl_Work_Review_Service())->submit('human', (string) $uid, $params);
        return is_wp_error($row) ? $row : rest_ensure_response(['review' => $row]);
    }

    /**
     * @return array<string,mixed>
     */
    private function extract_review_submit_params(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params) || $params === []) {
            $params = $request->get_body_params();
        }
        if (!is_array($params)) {
            $params = [];
        }
        foreach (['ref_type', 'ref_id', 'stars', 'comment', 'private_note', 'tags'] as $key) {
            if (!array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
                $v = $request->get_param($key);
                if ($v !== null && $v !== '') {
                    $params[$key] = $v;
                }
            }
        }
        return $params;
    }

    public function mine_review(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-review-service.php';
        $row = (new Growl_Work_Review_Service())->mine_for_work(
            sanitize_key((string) ($request->get_param('ref_type') ?? '')),
            sanitize_text_field((string) ($request->get_param('ref_id') ?? '')),
            'human',
            (string) $uid
        );
        return rest_ensure_response(['review' => $row]);
    }

    public function get_agent_balance(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in required.', ['status' => 401]);
        }
        $agent_id = sanitize_text_field((string) $request['agent_id']);
        $svc = new Growl_Agent_Order_Service();
        $bal = $svc->get_agent_balance($agent_id);
        return rest_ensure_response(['balance' => $bal]);
    }

    public function withdraw_agent_balance(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in required.', ['status' => 401]);
        }
        $agent_id = sanitize_text_field((string) $request['agent_id']);
        $amount = Growl_Money::resolve_amount_micro([
            'amount'       => $request->get_param('amount'),
            'amount_micro' => $request->get_param('amount_micro'),
        ]);
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $svc = new Growl_Agent_Order_Service();

        // Owner must share the agent wallet; payout goes on-chain to that agent wallet.
        $agent = $svc->get_agent_balance($agent_id);
        $db = new GROWL_Database();
        $user = $db->get_row(
            "SELECT user_id, wallet_address FROM {$db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$uid]
        );
        if (!$user || empty($user->wallet_address) || empty($agent['wallet_address'])
            || !hash_equals(strtolower((string) $user->wallet_address), strtolower((string) $agent['wallet_address']))) {
            return new WP_Error('forbidden', 'Only the wallet-linked owner can withdraw agent earnings.', ['status' => 403]);
        }

        $result = $svc->withdraw_agent_to_wallet($agent_id, $amount, $idem);
        return is_wp_error($result) ? $result : rest_ensure_response(['withdraw' => $result, 'balance' => $result]);
    }

    public function send_p2p(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to send.', ['status' => 401]);
        }
        $to_raw = (string) ($request->get_param('to') ?? '');
        $other = $this->resolve_counterparty_user($to_raw);
        if (is_wp_error($other)) {
            return $other;
        }
        if ((int) $other === $uid) {
            return new WP_Error('invalid_user', 'You cannot send to yourself.', ['status' => 400]);
        }
        $amount = Growl_Money::resolve_amount_micro([
            'amount'       => $request->get_param('amount'),
            'amount_micro' => $request->get_param('amount_micro'),
        ]);
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $note = sanitize_text_field((string) ($request->get_param('note') ?? ''));
        $res = (new Growl_Balance_Service())->transfer_p2p($uid, (int) $other, $amount, $idem, $note);
        if (is_wp_error($res)) {
            return $res;
        }
        return rest_ensure_response($res);
    }

    public function send_external(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to send.', ['status' => 401]);
        }
        $wallet = trim((string) ($request->get_param('wallet_address') ?? ''));
        if ($wallet === '') {
            return new WP_Error('invalid_wallet', 'Enter a destination wallet.', ['status' => 400]);
        }
        $amount = Growl_Money::resolve_amount_micro([
            'amount'       => $request->get_param('amount'),
            'amount_micro' => $request->get_param('amount_micro'),
        ]);
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $res = (new Growl_Balance_Service())->send_external($uid, $amount, $wallet, $idem);
        if (is_wp_error($res)) {
            return $res;
        }
        return rest_ensure_response($res);
    }

    public function lookup_recipient(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $q = trim((string) ($request->get_param('q') ?? ''));
        if ($q === '') {
            return new WP_Error('invalid', 'Enter a handle, email, or wallet.', ['status' => 400]);
        }
        $db = new GROWL_Database();
        $user = $db->find_user_by_query($q);
        if (!$user || empty($user->user_id)) {
            return rest_ensure_response(['user' => null]);
        }
        return rest_ensure_response([
            'user' => [
                'user_id'      => (int) $user->user_id,
                'display_name' => (string) ($user->display_name ?? ''),
                'profile_slug' => (string) ($user->profile_slug ?? ''),
                'handle'       => (string) ($user->profile_slug ?? $user->x_username ?? ''),
                'avatar'       => (string) ($user->avatar_url ?? $user->avatar ?? ''),
                'x_username'   => (string) ($user->x_username ?? ''),
            ],
        ]);
    }

    /**
     * @return int|WP_Error
     */
    private function resolve_counterparty_user($query) {
        $db = new GROWL_Database();
        $user = $db->find_user_by_query($query);
        if (!$user || empty($user->user_id)) {
            return new WP_Error('not_found', 'Could not find that user.', ['status' => 404]);
        }
        return (int) $user->user_id;
    }

    private function user_wallet($uid) {
        $db = new GROWL_Database();
        $user = $db->get_row(
            "SELECT wallet_address FROM {$db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [(int) $uid]
        );
        if (!$user || empty($user->wallet_address)) {
            return new WP_Error('wallet_required', 'Connect a wallet to pay.', ['status' => 400]);
        }
        return (string) $user->wallet_address;
    }

    private function initiate_agent_order_wallet_fund(WP_REST_Request $request, $uid, $idem) {
        $order_id = (int) $request['id'];
        $orders = new Growl_Agent_Order_Service();
        $order = $orders->get_order($order_id, '', 'human', (string) $uid);
        if (!$order) {
            return new WP_Error('not_found', 'Order not found.', ['status' => 404]);
        }
        if ((string) ($order['buyer_ref'] ?? '') !== (string) $uid) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        if (($order['status'] ?? '') !== 'pending_funding') {
            return new WP_Error('invalid_state', 'This order is not awaiting payment.', ['status' => 400]);
        }

        $wallet = $this->user_wallet($uid);
        if (is_wp_error($wallet)) {
            return $wallet;
        }
        if (!class_exists('PayAI_Flow')) {
            return new WP_Error('payment_unavailable', 'Payment could not be processed. Try again.', ['status' => 503]);
        }

        $amount_micro = (string) $order['amount_micro'];
        $amount_display = Growl_Money::to_display($amount_micro);
        $resource = untrailingslashit(rest_url('ai-agent/v1/agents/orders/' . $order_id . '/fund'));
        $job_data = [
            'purpose'                    => 'agent_order_fund',
            'payment_purpose'            => 'agent_order_fund',
            'order_id'                   => $order_id,
            'ref_type'                   => 'agent_order',
            'ref_id'                     => (string) $order['public_slug'],
            'amount'                     => $amount_display,
            '_funding_user_id'           => (int) $uid,
            '_x402_resource_url'         => $resource,
            '_x402_resource_description' => 'Fund marketplace agent order escrow on EarnFi.',
        ];
        $result = PayAI_Flow::initiate($amount_display, $wallet, $job_data);
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }
        $intent_id = $this->payments->create_quote_intent(
            (int) $uid,
            $amount_micro,
            'agent_order_fund',
            $resource,
            $idem !== '' ? $idem : ('agent_order_quote:' . $order_id)
        );
        $result['resource_url'] = $resource;
        $result['intent_id'] = $intent_id;
        return new WP_REST_Response($result, 402);
    }

    private function confirm_agent_order_wallet_fund(WP_REST_Request $request, $uid, $signed_tx, $idem) {
        $order_id = (int) $request['id'];
        $orders = new Growl_Agent_Order_Service();
        $order = $orders->get_order($order_id, '', 'human', (string) $uid);
        if (!$order) {
            return new WP_Error('not_found', 'Order not found.', ['status' => 404]);
        }
        if ((string) ($order['buyer_ref'] ?? '') !== (string) $uid) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }

        $requirements = $request->get_param('requirements');
        if (!is_array($requirements)) {
            return new WP_Error('invalid_params', 'Payment could not be verified.', ['status' => 400]);
        }

        $wallet = $this->user_wallet($uid);
        $payer = is_wp_error($wallet) ? '' : $wallet;

        $settlement_id = $this->payments->settle_wallet_payment($uid, $signed_tx, $requirements, [
            'purpose'         => 'agent_order_fund',
            'amount_micro'    => (string) $order['amount_micro'],
            'ref_type'        => 'agent_order',
            'ref_id'          => (string) $order['public_slug'],
            'payer_wallet'    => $payer,
            'idempotency_key' => $idem !== '' ? $idem : ('agent_order_wallet:' . $order_id . ':' . $uid),
            'intent_id'       => sanitize_text_field((string) ($request->get_param('intent_id') ?? '')),
        ]);
        if (is_wp_error($settlement_id)) {
            return Growl_X402_Payment_Service::public_error($settlement_id);
        }

        $funded = $orders->fund_order($order_id, $uid, 'x402', (int) $settlement_id, $idem);
        if (is_wp_error($funded)) {
            return $funded;
        }
        return rest_ensure_response(['order' => $funded]);
    }

    private function initiate_job_contract_wallet_fund(WP_REST_Request $request, $uid, $idem) {
        $contract_id = (int) $request['id'];
        $svc = new Growl_Job_Contract_Service();
        $contract = $svc->get_by_id($contract_id);
        if (!$contract) {
            return new WP_Error('not_found', 'Contract not found.', ['status' => 404]);
        }
        if ((int) ($contract['employer_user_id'] ?? 0) !== (int) $uid) {
            return new WP_Error('forbidden', 'Only the employer can fund this contract.', ['status' => 403]);
        }
        if (($contract['status'] ?? '') !== 'pending_funding') {
            return new WP_Error('invalid_state', 'This contract is not awaiting payment.', ['status' => 400]);
        }

        $wallet = $this->user_wallet($uid);
        if (is_wp_error($wallet)) {
            return $wallet;
        }
        if (!class_exists('PayAI_Flow')) {
            return new WP_Error('payment_unavailable', 'Payment could not be processed. Try again.', ['status' => 503]);
        }

        $amount_micro = (string) $contract['deposit_micro'];
        $amount_display = Growl_Money::to_display($amount_micro);
        $resource = untrailingslashit(rest_url($this->namespace . '/job-contracts/' . $contract_id . '/fund'));
        $job_data = [
            'purpose'                    => 'job_contract_fund',
            'payment_purpose'            => 'job_contract_fund',
            'contract_id'                => $contract_id,
            'ref_type'                   => 'job_contract',
            'ref_id'                     => (string) $contract['public_slug'],
            'amount'                     => $amount_display,
            '_funding_user_id'           => (int) $uid,
            '_x402_resource_url'         => $resource,
            '_x402_resource_description' => 'Fund job contract escrow on EarnFi.',
        ];
        $result = PayAI_Flow::initiate($amount_display, $wallet, $job_data);
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }
        $intent_id = $this->payments->create_quote_intent(
            (int) $uid,
            $amount_micro,
            'job_contract_fund',
            $resource,
            $idem !== '' ? $idem : ('job_contract_quote:' . $contract_id)
        );
        $result['resource_url'] = $resource;
        $result['intent_id'] = $intent_id;
        return new WP_REST_Response($result, 402);
    }

    private function confirm_job_contract_wallet_fund(WP_REST_Request $request, $uid, $signed_tx, $idem) {
        $contract_id = (int) $request['id'];
        $svc = new Growl_Job_Contract_Service();
        $contract = $svc->get_by_id($contract_id);
        if (!$contract) {
            return new WP_Error('not_found', 'Contract not found.', ['status' => 404]);
        }
        if ((int) ($contract['employer_user_id'] ?? 0) !== (int) $uid) {
            return new WP_Error('forbidden', 'Only the employer can fund this contract.', ['status' => 403]);
        }

        $requirements = $request->get_param('requirements');
        if (!is_array($requirements)) {
            return new WP_Error('invalid_params', 'Payment could not be verified.', ['status' => 400]);
        }

        $wallet = $this->user_wallet($uid);
        $payer = is_wp_error($wallet) ? '' : $wallet;

        $settlement_id = $this->payments->settle_wallet_payment($uid, $signed_tx, $requirements, [
            'purpose'         => 'job_contract_fund',
            'amount_micro'    => (string) $contract['deposit_micro'],
            'ref_type'        => 'job_contract',
            'ref_id'          => (string) $contract['public_slug'],
            'payer_wallet'    => $payer,
            'idempotency_key' => $idem !== '' ? $idem : ('job_contract_wallet:' . $contract_id . ':' . $uid),
            'intent_id'       => sanitize_text_field((string) ($request->get_param('intent_id') ?? '')),
        ]);
        if (is_wp_error($settlement_id)) {
            return Growl_X402_Payment_Service::public_error($settlement_id);
        }

        $funded = $svc->fund($contract_id, $uid, 'x402', (int) $settlement_id, $idem);
        if (is_wp_error($funded)) {
            return $funded;
        }
        return rest_ensure_response(['contract' => $funded]);
    }

    private function initiate_agent_deal_wallet_fund(WP_REST_Request $request, $uid, $idem) {
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        $deal_id = (int) $request['id'];
        $svc = new Growl_Agent_Deal_Service();
        $deal = $svc->get_by_id($deal_id, 'human', (string) $uid);
        if (!$deal) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        if (($deal['buyer_type'] ?? '') !== 'human' || (string) ($deal['buyer_ref'] ?? '') !== (string) $uid) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        if (($deal['status'] ?? '') !== 'pending_funding') {
            return new WP_Error('invalid_state', 'This deal is not awaiting payment.', ['status' => 400]);
        }

        $wallet = $this->user_wallet($uid);
        if (is_wp_error($wallet)) {
            return $wallet;
        }
        if (!class_exists('PayAI_Flow')) {
            return new WP_Error('payment_unavailable', 'Payment could not be processed. Try again.', ['status' => 503]);
        }

        if (!class_exists('Growl_Work_Fee_Engine')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-fee-engine.php';
        }
        $split = Growl_Work_Fee_Engine::split_for_gross($deal['amount_micro'], 'deals');
        $amount_micro = (string) $split['deposit_micro'];
        $amount_display = Growl_Money::to_display($amount_micro);
        $resource = untrailingslashit(rest_url('ai-agent/v1/agents/deals/' . $deal_id . '/fund'));
        $job_data = [
            'purpose'                    => 'agent_deal_fund',
            'payment_purpose'            => 'agent_deal_fund',
            'agent_deal_id'              => $deal_id,
            'deal_id'                    => $deal_id,
            'ref_type'                   => 'agent_deal',
            'ref_id'                     => (string) $deal['public_slug'],
            'amount'                     => $amount_display,
            '_funding_user_id'           => (int) $uid,
            '_x402_resource_url'         => $resource,
            '_x402_resource_description' => 'Fund custom agent deal escrow on EarnFi.',
        ];
        $result = PayAI_Flow::initiate($amount_display, $wallet, $job_data);
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }
        $intent_id = $this->payments->create_quote_intent(
            (int) $uid,
            $amount_micro,
            'agent_deal_fund',
            $resource,
            $idem !== '' ? $idem : ('agent_deal_quote:' . $deal_id)
        );
        $result['resource_url'] = $resource;
        $result['intent_id'] = $intent_id;
        return new WP_REST_Response($result, 402);
    }

    private function confirm_agent_deal_wallet_fund(WP_REST_Request $request, $uid, $signed_tx, $idem) {
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        $deal_id = (int) $request['id'];
        $svc = new Growl_Agent_Deal_Service();
        $deal = $svc->get_by_id($deal_id, 'human', (string) $uid);
        if (!$deal) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        if (($deal['buyer_type'] ?? '') !== 'human' || (string) ($deal['buyer_ref'] ?? '') !== (string) $uid) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }

        $requirements = $request->get_param('requirements');
        if (!is_array($requirements)) {
            return new WP_Error('invalid_params', 'Payment could not be verified.', ['status' => 400]);
        }

        $wallet = $this->user_wallet($uid);
        $payer = is_wp_error($wallet) ? '' : $wallet;
        if (!class_exists('Growl_Work_Fee_Engine')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-fee-engine.php';
        }
        $split = Growl_Work_Fee_Engine::split_for_gross($deal['amount_micro'], 'deals');
        $amount_micro = (string) $split['deposit_micro'];

        $settlement_id = $this->payments->settle_wallet_payment($uid, $signed_tx, $requirements, [
            'purpose'         => 'agent_deal_fund',
            'amount_micro'    => $amount_micro,
            'ref_type'        => 'agent_deal',
            'ref_id'          => (string) $deal['public_slug'],
            'payer_wallet'    => $payer,
            'idempotency_key' => $idem !== '' ? $idem : ('agent_deal_wallet:' . $deal_id . ':' . $uid),
            'intent_id'       => sanitize_text_field((string) ($request->get_param('intent_id') ?? '')),
        ]);
        if (is_wp_error($settlement_id)) {
            return Growl_X402_Payment_Service::public_error($settlement_id);
        }

        $funded = $svc->fund($deal_id, 'human', (string) $uid, 'x402', (int) $settlement_id, $idem);
        if (is_wp_error($funded)) {
            return $funded;
        }
        return $funded;
    }

    public function get_agent_deal_human(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $deal = (new Growl_Agent_Deal_Service())->get_by_slug(
            (string) $request['slug'],
            $uid > 0 ? 'human' : '',
            $uid > 0 ? (string) $uid : ''
        );
        if (!$deal) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, $uid > 0 ? 'human' : 'guest', $uid > 0 ? (string) $uid : '');
    }

    public function accept_agent_deal_human(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $params = $request->get_json_params() ?: [];
        $deal = (new Growl_Agent_Deal_Service())->accept(
            (int) $request['id'],
            'human',
            (string) $uid,
            (string) ($params['role'] ?? 'buyer'),
            (string) ($params['invite_token'] ?? '')
        );
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'human', (string) $uid);
    }

    public function fund_agent_deal_human(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to pay.', ['status' => 401]);
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $source = sanitize_key((string) ($request->get_param('source') ?? 'balance'));

        if ($source === 'wallet') {
            $signed = (string) ($request->get_param('signed_tx') ?? '');
            if ($signed === '') {
                return $this->initiate_agent_deal_wallet_fund($request, $uid, $idem);
            }
            $deal = $this->confirm_agent_deal_wallet_fund($request, $uid, $signed, $idem);
            return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'human', (string) $uid);
        }

        $fund_source = in_array($source, ['balance', 'creator_wallet'], true) ? $source : 'x402';
        $deal = (new Growl_Agent_Deal_Service())->fund(
            (int) $request['id'],
            'human',
            (string) $uid,
            $fund_source,
            $request->get_param('settlement_id') ? (int) $request->get_param('settlement_id') : null,
            $idem
        );
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'human', (string) $uid);
    }

    public function deliver_agent_deal_human(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $params = $request->get_json_params() ?: [];
        $deal = (new Growl_Agent_Deal_Service())->deliver(
            (int) $request['id'],
            'human',
            (string) $uid,
            (string) ($params['note'] ?? ''),
            (string) ($params['payload'] ?? '')
        );
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'human', (string) $uid);
    }

    public function release_agent_deal_human(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $deal = (new Growl_Agent_Deal_Service())->release(
            (int) $request['id'],
            'human',
            (string) $uid,
            $idem
        );
        return Growl_Agent_Guidance::rest_response('agent_deal', $deal, 'human', (string) $uid);
    }

    public function list_open_work(WP_REST_Request $request) {
        $rl = $this->enforce_public_browse_rate_limit();
        if (is_wp_error($rl)) {
            return $rl;
        }
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $svc  = new Growl_Open_Work_Service();
        $res  = $svc->list_gallery(
            sanitize_key((string) ($request->get_param('work_mode') ?? $request->get_param('mode') ?? '')),
            sanitize_key((string) ($request->get_param('execution_mode') ?? '')),
            sanitize_text_field((string) ($request->get_param('q') ?? '')),
            $page,
            $per
        );
        return rest_ensure_response($res);
    }

    public function get_open_work(WP_REST_Request $request) {
        $rl = $this->enforce_public_browse_rate_limit();
        if (is_wp_error($rl)) {
            return $rl;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $uid = $this->user_id($request);
        $svc = new Growl_Open_Work_Service();
        $detail = $svc->get_detail((string) $request['ref'], $uid);
        if (!$detail) {
            return new WP_Error('not_found', 'Open work not found.', ['status' => 404]);
        }
        return Growl_Agent_Guidance::rest_response('open_work', $detail, $uid > 0 ? 'human' : 'guest', $uid > 0 ? (string) $uid : '');
    }

    public function submit_open_work(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $svc = new Growl_Open_Work_Service();
        $resolved = $svc->resolve_ref((string) $request['ref']);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }
        $payload = is_array($request->get_param('payload')) ? $request->get_param('payload') : $params;
        $bid_micro = $request->get_param('bid_micro') ?? ($params['bid_micro'] ?? null);
        $sub = $svc->submit(
            $resolved['ref_type'],
            $resolved['ref_id'],
            'human',
            (string) $uid,
            $payload,
            $bid_micro
        );
        return Growl_Agent_Guidance::rest_response('open_work_submission', is_wp_error($sub) ? $sub : $sub, 'human', (string) $uid);
    }

    public function list_open_work_submissions(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        $svc = new Growl_Open_Work_Service();
        $resolved = $svc->resolve_ref((string) $request['ref']);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $res = $svc->list_submissions($resolved['ref_type'], $resolved['ref_id'], $uid, $page, $per);
        return is_wp_error($res) ? $res : rest_ensure_response($res);
    }

    public function accept_open_work_submission(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $sub = (new Growl_Open_Work_Service())->accept_submission((int) $request['id'], $uid);
        return Growl_Agent_Guidance::rest_response('open_work_submission', $sub, 'human', (string) $uid);
    }

    public function reject_open_work_submission(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in.', ['status' => 401]);
        }
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $sub = (new Growl_Open_Work_Service())->reject_submission((int) $request['id'], $uid);
        return Growl_Agent_Guidance::rest_response('open_work_submission', $sub, 'human', (string) $uid);
    }
}
