<?php
/**
 * Work-Money REST: balances, payments, deals.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Growl_Money', false)) {
    require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
}
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-balance-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-x402-payment-service.php';
require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-deal-service.php';

class Growl_Work_Money_REST {

    private $namespace = 'growl/v1';

    /** @var GROWL_REST_API */
    private $core_api;

    /** @var Growl_Balance_Service */
    private $balance;

    /** @var Growl_X402_Payment_Service */
    private $payments;

    /** @var Growl_Deal_Service */
    private $deals;

    public function __construct() {
        $this->core_api = new GROWL_REST_API();
        $this->balance  = new Growl_Balance_Service();
        $this->payments = new Growl_X402_Payment_Service();
        $this->deals    = new Growl_Deal_Service();
    }

    public function register_routes() {
        $auth = [$this->core_api, 'verify_jwt'];
        $pub  = '__return_true';

        register_rest_route($this->namespace, '/balances', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_balances'],
            'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/payments/probe', [
            'methods'             => 'POST',
            'callback'            => [$this, 'payment_probe'],
            'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/deals', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_deals'],
                'permission_callback' => $auth,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_deal'],
                'permission_callback' => $auth,
            ],
        ]);

        register_rest_route($this->namespace, '/deals/(?P<slug>[a-z0-9]{8,16})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_deal'],
            'permission_callback' => $pub,
        ]);

        register_rest_route($this->namespace, '/deals/(?P<id>\d+)/accept', [
            'methods'             => 'POST',
            'callback'            => [$this, 'accept_deal'],
            'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/deals/(?P<id>\d+)/fund', [
            'methods'             => 'POST',
            'callback'            => [$this, 'fund_deal'],
            'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/deals/(?P<id>\d+)/deliver', [
            'methods'             => 'POST',
            'callback'            => [$this, 'deliver_deal'],
            'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/deals/(?P<id>\d+)/release', [
            'methods'             => 'POST',
            'callback'            => [$this, 'release_deal'],
            'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/deals/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cancel_deal'],
            'permission_callback' => $auth,
        ]);

        register_rest_route($this->namespace, '/receipts/(?P<receipt_id>[a-z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_receipt'],
            'permission_callback' => $pub,
        ]);
    }

    private function user_id(WP_REST_Request $request) {
        $payload = $request->get_param('jwt_payload');
        return is_array($payload) && !empty($payload['user_id']) ? (int) $payload['user_id'] : 0;
    }

    public function get_balances(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to view your balance.', ['status' => 401]);
        }
        $bal = $this->balance->get_user_balances($uid);
        if (!$bal) {
            return new WP_Error('not_found', 'Account not found.', ['status' => 404]);
        }
        if (!class_exists('GROWL_Creator_Wallet', false)) {
            require_once GROWL_PLUGIN_PATH . 'includes/class-creator-wallet.php';
        }
        $cw = GROWL_Creator_Wallet::get_balances($uid);
        $paid = (float) ($cw['paid'] ?? 0);
        return rest_ensure_response([
            'earnfi_balance' => [
                'available_micro'      => $bal['available_micro'],
                'available_display'    => $bal['available_display'],
                'escrow_held_micro'    => $bal['escrow_held_micro'],
                'escrow_held_display'  => $bal['escrow_held_display'],
                'dispute_held_micro'   => $bal['dispute_held_micro'],
                'dispute_held_display' => $bal['dispute_held_display'],
            ],
            'creator_wallet' => [
                'free'          => (float) ($cw['free'] ?? 0),
                'paid'          => $paid,
                'total'         => (float) ($cw['total'] ?? 0),
                'paid_display'  => number_format($paid, 2, '.', ''),
                'free_display'  => number_format((float) ($cw['free'] ?? 0), 2, '.', ''),
                'total_display' => number_format((float) ($cw['total'] ?? 0), 2, '.', ''),
            ],
        ]);
    }

    public function payment_probe(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to continue.', ['status' => 401]);
        }
        $amount = Growl_Money::resolve_amount_micro([
            'amount'       => $request->get_param('amount'),
            'amount_micro' => $request->get_param('amount_micro'),
        ]);
        $resource = esc_url_raw((string) ($request->get_param('resource_url') ?? rest_url($this->namespace . '/payments/probe')));
        $result = $this->payments->probe($resource, $amount, $uid, [
            'description' => sanitize_text_field((string) ($request->get_param('description') ?? 'EarnFi payment')),
        ]);
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }
        if (empty($result['can_use_balance'])) {
            return new WP_REST_Response($result, 402);
        }
        return rest_ensure_response($result);
    }

    public function create_deal(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to create a deal.', ['status' => 401]);
        }
        $role = sanitize_key((string) ($request->get_param('created_by_role') ?? 'seller'));
        $data = [
            'title'            => $request->get_param('title'),
            'scope_text'       => $request->get_param('scope_text'),
            'amount'           => $request->get_param('amount'),
            'created_by_role'  => $role,
            'kind'             => $request->get_param('kind'),
            'deadline_at'      => $request->get_param('deadline_at'),
            'inspection_window'=> $request->get_param('inspection_window'),
            'inspection_seconds'=> $request->get_param('inspection_seconds'),
        ];
        $p2p = $request->get_param('p2p');
        if (is_array($p2p)) {
            $data['p2p'] = $p2p;
        }
        if ($role === 'seller') {
            $data['seller_user_id'] = $uid;
            if ($request->get_param('buyer_user_id')) {
                $data['buyer_user_id'] = (int) $request->get_param('buyer_user_id');
            }
        } else {
            $data['buyer_user_id'] = $uid;
            $seller_param = (int) $request->get_param('seller_user_id');
            if ($seller_param > 0) {
                $data['seller_user_id'] = $seller_param;
            }
        }

        $inspection = Growl_Deal_Service::inspection_seconds_from_data($data);
        if ($inspection > 0) {
            $data['inspection_seconds'] = $inspection;
        }

        $counterparty = trim((string) ($request->get_param('counterparty') ?? ''));
        if ($counterparty !== '') {
            $other = $this->resolve_counterparty_user($counterparty);
            if (is_wp_error($other)) {
                return $other;
            }
            if ((int) $other === $uid) {
                return new WP_Error('invalid_deal', 'You cannot invite yourself.', ['status' => 400]);
            }
            if ($role === 'seller') {
                $data['buyer_user_id'] = (int) $other;
            } else {
                $data['seller_user_id'] = (int) $other;
            }
        }

        $deal = $this->deals->create_draft($data);
        if (is_wp_error($deal)) {
            return $deal;
        }
        return rest_ensure_response(['deal' => $deal]);
    }

    /**
     * Resolve @handle, email, or Solana wallet to user_id.
     *
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

    public function get_deal(WP_REST_Request $request) {
        $slug = sanitize_text_field((string) $request['slug']);
        $row  = $this->deals->get_deal_by_slug($slug);
        if (!$row) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        $uid = $this->user_id($request);
        return rest_ensure_response(['deal' => $this->deals->format_deal($row, $uid > 0 ? $uid : null)]);
    }

    public function list_deals(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to view deals.', ['status' => 401]);
        }
        $role = sanitize_key((string) ($request->get_param('role') ?? 'all'));
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $items = $this->deals->list_for_user($uid, $role, $per, ($page - 1) * $per);
        return rest_ensure_response(['deals' => $items, 'page' => $page, 'per_page' => $per]);
    }

    public function accept_deal(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to continue.', ['status' => 401]);
        }
        $role = sanitize_key((string) ($request->get_param('role') ?? 'buyer'));
        $invite = sanitize_text_field((string) ($request->get_param('invite_token') ?? ''));
        $deal = $this->deals->accept_deal((int) $request['id'], $uid, $role, $invite);
        if (is_wp_error($deal)) {
            return $deal;
        }
        return rest_ensure_response(['deal' => $deal]);
    }

    public function fund_deal(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to pay.', ['status' => 401]);
        }
        $source = sanitize_key((string) ($request->get_param('source') ?? 'balance'));
        $idem   = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');

        if ($source === 'wallet') {
            $signed = (string) ($request->get_param('signed_tx') ?? '');
            if ($signed === '') {
                return $this->initiate_deal_wallet_fund($request, $uid, $idem);
            }
            return $this->confirm_deal_wallet_fund($request, $uid, $signed, $idem);
        }

        if ($source === 'balance' || $source === 'creator_wallet') {
            $fund_source = $source;
        } else {
            $fund_source = 'x402';
        }

        $deal = $this->deals->fund_deal(
            (int) $request['id'],
            $uid,
            $fund_source,
            $request->get_param('settlement_id') ? (int) $request->get_param('settlement_id') : null,
            $idem
        );
        if (is_wp_error($deal)) {
            return $deal;
        }
        return rest_ensure_response(['deal' => $deal]);
    }

    private function initiate_deal_wallet_fund(WP_REST_Request $request, $uid, $idem) {
        $deal_id = (int) $request['id'];
        $deal_row = $this->deals->get_deal_by_id($deal_id);
        if (!$deal_row) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        if ((int) ($deal_row['buyer_user_id'] ?? 0) !== (int) $uid) {
            return new WP_Error('forbidden', 'Only the buyer can fund this deal.', ['status' => 403]);
        }
        if (($deal_row['status'] ?? '') !== 'pending_funding') {
            return new WP_Error('invalid_state', 'This deal is not awaiting payment.', ['status' => 400]);
        }

        $db = new GROWL_Database();
        $user = $db->get_row(
            "SELECT wallet_address FROM {$db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$uid]
        );
        if (!$user || empty($user->wallet_address)) {
            return new WP_Error('wallet_required', 'Connect a wallet to pay.', ['status' => 400]);
        }

        if (!class_exists('PayAI_Flow')) {
            return new WP_Error('payment_unavailable', 'Payment could not be processed. Try again.', ['status' => 503]);
        }

        $amount_micro = isset($deal_row['deposit_micro']) ? (string) $deal_row['deposit_micro'] : (string) $deal_row['amount_micro'];
        $amount_display = Growl_Money::to_display($amount_micro);
        $job_data = [
            'purpose'         => 'deal_fund',
            'payment_purpose' => 'deal_fund',
            'deal_id'         => $deal_id,
            'ref_type'        => 'deal',
            'ref_id'          => (string) $deal_row['public_slug'],
            'amount'          => $amount_display,
            '_funding_user_id'=> (int) $uid,
        ];
        $resource = rest_url($this->namespace . '/deals/' . $deal_id . '/fund');
        $result = PayAI_Flow::initiate($amount_display, (string) $user->wallet_address, $job_data);
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }
        $intent_id = $this->payments->create_quote_intent(
            (int) $uid,
            $amount_micro,
            'deal_fund',
            $resource,
            $idem !== '' ? $idem : ('deal_quote:' . $deal_id)
        );
        $result['resource_url'] = $resource;
        $result['intent_id'] = $intent_id;
        return new WP_REST_Response($result, 402);
    }

    private function confirm_deal_wallet_fund(WP_REST_Request $request, $uid, $signed_tx, $idem) {
        $deal_id = (int) $request['id'];
        $deal_row = $this->deals->get_deal_by_id($deal_id);
        if (!$deal_row) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        if ((int) ($deal_row['buyer_user_id'] ?? 0) !== (int) $uid) {
            return new WP_Error('forbidden', 'Only the buyer can fund this deal.', ['status' => 403]);
        }

        $requirements = $request->get_param('requirements');
        if (!is_array($requirements)) {
            return new WP_Error('invalid_params', 'Payment could not be verified.', ['status' => 400]);
        }

        $db = new GROWL_Database();
        $user = $db->get_row(
            "SELECT wallet_address FROM {$db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$uid]
        );

        $settlement_id = $this->payments->settle_wallet_payment($uid, $signed_tx, $requirements, [
            'purpose'         => 'deal_fund',
            'amount_micro'    => isset($deal_row['deposit_micro']) ? (string) $deal_row['deposit_micro'] : (string) $deal_row['amount_micro'],
            'ref_type'        => 'deal',
            'ref_id'          => (string) $deal_row['public_slug'],
            'payer_wallet'    => $user ? (string) $user->wallet_address : '',
            'idempotency_key' => $idem !== '' ? $idem : ('deal_wallet:' . $deal_id . ':' . $uid),
            'intent_id'       => sanitize_text_field((string) ($request->get_param('intent_id') ?? '')),
        ]);
        if (is_wp_error($settlement_id)) {
            return Growl_X402_Payment_Service::public_error($settlement_id);
        }

        $deal = $this->deals->fund_deal($deal_id, $uid, 'x402', (int) $settlement_id, $idem);
        if (is_wp_error($deal)) {
            return $deal;
        }
        return rest_ensure_response(['deal' => $deal]);
    }

    public function deliver_deal(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to continue.', ['status' => 401]);
        }
        $deal = $this->deals->deliver(
            (int) $request['id'],
            $uid,
            (string) $request->get_param('note'),
            (string) $request->get_param('file_url')
        );
        if (is_wp_error($deal)) {
            return $deal;
        }
        return rest_ensure_response(['deal' => $deal]);
    }

    public function release_deal(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to continue.', ['status' => 401]);
        }
        $idem = Growl_Money::sanitize_idempotency_key($request->get_header('Idempotency-Key') ?? '');
        $deal = $this->deals->release((int) $request['id'], $uid, $idem);
        if (is_wp_error($deal)) {
            return $deal;
        }
        return rest_ensure_response(['deal' => $deal]);
    }

    public function cancel_deal(WP_REST_Request $request) {
        $uid = $this->user_id($request);
        if ($uid < 1) {
            return new WP_Error('unauthorized', 'Sign in to continue.', ['status' => 401]);
        }
        $deal = $this->deals->cancel_deal((int) $request['id'], $uid);
        if (is_wp_error($deal)) {
            return $deal;
        }
        return rest_ensure_response(['deal' => $deal]);
    }

    public function get_receipt(WP_REST_Request $request) {
        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-receipt-service.php';
        }
        $id = sanitize_text_field((string) $request['receipt_id']);
        $receipt = (new Growl_Work_Receipt_Service())->get_v1_by_receipt_id($id);
        if (!$receipt) {
            return new WP_Error('not_found', 'Receipt not found.', ['status' => 404]);
        }
        return rest_ensure_response(['work_receipt' => $receipt]);
    }
}
