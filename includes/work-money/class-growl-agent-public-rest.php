<?php
/**
 * Public Agent API facades for Work + Money.
 * Developers and agents only see ai-agent/v1. These handlers call the same
 * PHP services as growl/v1. They never document or redirect clients to growl/v1.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Agent_Public_Work_REST {

    private $namespace = 'ai-agent/v1';

    /**
     * Public browse rate limit: 60 req/min/IP (plan security standard).
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

    public function register_routes() {
        $pub = '__return_true';

        register_rest_route($this->namespace, '/board', [
            'methods'             => 'GET',
            'callback'            => [$this, 'find_work'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/board/(?P<slug>[a-z0-9]{8,16})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_listing'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/deals/(?P<slug>[a-z0-9]{8,16})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_deal'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/receipts/(?P<receipt_id>[a-z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_receipt'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/receipts/(?P<receipt_id>[a-z0-9_]+)/verify', [
            'methods'             => 'GET',
            'callback'            => [$this, 'verify_receipt'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/receipts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_work_receipt_by_ref'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/capabilities/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_capability'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agents/orders/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_order'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/marketplace/agents', [
            'methods'             => 'GET',
            'callback'            => [$this, 'browse_agents'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/marketplace/services', [
            'methods'             => 'GET',
            'callback'            => [$this, 'browse_services'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/marketplace/stats', [
            'methods'             => 'GET',
            'callback'            => [$this, 'marketplace_stats'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/marketplace/leaderboard', [
            'methods'             => 'GET',
            'callback'            => [$this, 'marketplace_leaderboard'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agents/(?P<agent_id>[A-Za-z0-9_-]+)/services', [
            'methods'             => 'GET',
            'callback'            => [$this, 'agent_services'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/reviews', [
            ['methods' => 'GET', 'callback' => [$this, 'list_reviews'], 'permission_callback' => $pub],
            ['methods' => 'POST', 'callback' => [$this, 'submit_review'], 'permission_callback' => $pub],
        ]);
        register_rest_route($this->namespace, '/work/reviews/submit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'submit_review'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/reviews/mine', [
            'methods'             => 'GET',
            'callback'            => [$this, 'mine_review'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/threads', [
            'methods'             => 'GET',
            'callback'            => [$this, 'open_thread'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/threads/(?P<id>\d+)/messages', [
            ['methods' => 'GET', 'callback' => [$this, 'list_messages'], 'permission_callback' => $pub],
            ['methods' => 'POST', 'callback' => [$this, 'post_message'], 'permission_callback' => $pub],
        ]);
        register_rest_route($this->namespace, '/agents/orders/mine', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_my_orders'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agents/me/balance', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_my_balance'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agents/me/withdraw', [
            'methods'             => 'POST',
            'callback'            => [$this, 'withdraw_my_earnings'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agents/disputes', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_my_disputes'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/agents/disputes/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_my_dispute'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/open', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_open_work'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/open/(?P<ref>[A-Za-z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_open_work'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/open/(?P<ref>[A-Za-z0-9_-]+)/submit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'submit_open_work'],
            'permission_callback' => $pub,
        ]);
        register_rest_route($this->namespace, '/work/open/submissions/(?P<id>\d+)/accept', [
            'methods'             => 'POST',
            'callback'            => [$this, 'accept_open_work_submission'],
            'permission_callback' => $pub,
        ]);
    }

    public function find_work(WP_REST_Request $request) {
        $q    = sanitize_text_field((string) ($request->get_param('q') ?? $request->get_param('query') ?? ''));
        $cap  = sanitize_key((string) ($request->get_param('capability') ?? ''));
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $off  = ($page - 1) * $per;
        $board = new Growl_Job_Board_Service();
        $orders = new Growl_Agent_Order_Service();
        return rest_ensure_response([
            'listings' => $board->list_open($per, $off, $q),
            'jobs'     => $this->list_claimable_agent_jobs($q, $per, $off),
            'services' => $orders->catalog($cap, $per, $off),
        ]);
    }

    /**
     * Open tasks agents can claim (execution_mode agent|hybrid, slots remaining).
     */
    private function list_claimable_agent_jobs($q, $limit, $offset) {
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $db     = new GROWL_Database();
        $p      = $db->table_prefix;
        $where  = "t.status IN ('active','open') AND t.completed_count < t.user_count AND t.execution_mode IN ('agent','hybrid')";
        $params = [];
        $q      = trim((string) $q);
        if ($q !== '') {
            $like     = '%' . $db->wpdb->esc_like($q) . '%';
            $where   .= ' AND (t.title LIKE %s OR t.task_id LIKE %s OR t.action_type LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $params[] = $limit;
        $params[] = $offset;
        $rows = $db->get_results(
            "SELECT t.task_id, t.title, t.action_type, t.status, t.execution_mode, t.base_reward,
                    t.user_count, t.completed_count, t.created_at, t.creator_type, t.creator_agent_id
             FROM {$p}tasks t
             WHERE {$where}
             ORDER BY t.created_at DESC
             LIMIT %d OFFSET %d",
            $params
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $mode = (string) ($row->execution_mode ?? 'human');
            $out[] = [
                'job_id'         => (string) $row->task_id,
                'title'          => (string) ($row->title ?? ''),
                'task_type'      => (string) ($row->action_type ?? ''),
                'status'         => (string) ($row->status ?? ''),
                'execution_mode' => $mode,
                'reward'         => (float) ($row->base_reward ?? 0),
                'slots'          => (int) ($row->user_count ?? 0),
                'slots_filled'   => (int) ($row->completed_count ?? 0),
                'slots_left'     => max(0, (int) $row->user_count - (int) $row->completed_count),
                'creator_type'   => (string) ($row->creator_type ?? ''),
                'created_at'     => (string) ($row->created_at ?? ''),
                'claim_path'     => '/jobs/' . $row->task_id . '/claim',
                'submit_path'    => '/jobs/' . $row->task_id . '/submit',
            ];
        }
        return $out;
    }

    public function get_listing(WP_REST_Request $request) {
        $row = (new Growl_Job_Board_Service())->get_by_slug((string) $request['slug']);
        if (!$row) {
            return new WP_Error('not_found', 'Listing not found.', ['status' => 404]);
        }
        return rest_ensure_response(['listing' => $row]);
    }

    public function get_deal(WP_REST_Request $request) {
        $deals = new Growl_Deal_Service();
        $row   = $deals->get_deal_by_slug((string) $request['slug']);
        if (!$row) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        return rest_ensure_response(['deal' => $deals->format_deal($row)]);
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

    public function get_work_receipt_by_ref(WP_REST_Request $request) {
        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-receipt-service.php';
        }
        $ref_type = sanitize_key((string) $request->get_param('ref_type'));
        $ref_id   = sanitize_text_field((string) $request->get_param('ref_id'));
        if ($ref_type === '' || $ref_id === '') {
            return new WP_Error('invalid_params', 'ref_type and ref_id are required.', ['status' => 400]);
        }
        $svc = new Growl_Work_Receipt_Service();
        $receipt = $svc->get_v1_by_ref($ref_type, $ref_id);
        if (!$receipt) {
            return new WP_Error('not_found', 'No receipt for this work yet.', ['status' => 404]);
        }
        return rest_ensure_response(['work_receipt' => $receipt]);
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

    public function get_capability(WP_REST_Request $request) {
        $db = new GROWL_Database();
        $row = $db->get_row(
            "SELECT * FROM {$db->table_prefix}capabilities WHERE slug = %s LIMIT 1",
            [sanitize_key((string) $request['slug'])]
        );
        if (!$row) {
            return new WP_Error('not_found', 'Capability not found.', ['status' => 404]);
        }
        return rest_ensure_response([
            'capability' => [
                'slug'         => (string) $row->slug,
                'title'        => (string) $row->title,
                'description'  => (string) $row->description,
                'provider'     => (string) $row->provider,
                'catalog_path' => $row->catalog_path,
            ],
            'agents' => (new Growl_Agent_Order_Service($db))->catalog((string) $row->slug, 20, 0),
        ]);
    }

    public function get_order(WP_REST_Request $request) {
        $order = (new Growl_Agent_Order_Service())->get_order(
            (int) $request['id'],
            (string) ($request->get_param('secret') ?? '')
        );
        if (!$order) {
            return new WP_Error('not_found', 'Order not found.', ['status' => 404]);
        }
        return rest_ensure_response(['order' => $order]);
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
        return rest_ensure_response([
            'agents'   => (new Growl_Agent_Marketplace_Service())->browse_agents(
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

    public function marketplace_stats(WP_REST_Request $request) {
        $rl = $this->enforce_public_browse_rate_limit();
        if (is_wp_error($rl)) {
            return $rl;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-marketplace-service.php';
        return rest_ensure_response((new Growl_Agent_Marketplace_Service())->get_stats());
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
        if (!in_array($period, ['all', '30d'], true)) {
            $period = 'all';
        }
        return rest_ensure_response((new Growl_Agent_Marketplace_Service())->leaderboard($period, $per, ($page - 1) * $per));
    }

    public function agent_services(WP_REST_Request $request) {
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-marketplace-service.php';
        $row = (new Growl_Agent_Marketplace_Service())->agent_services((string) $request['agent_id']);
        return is_wp_error($row) ? $row : rest_ensure_response($row);
    }

    public function list_reviews(WP_REST_Request $request) {
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-review-service.php';
        $type = sanitize_key((string) ($request->get_param('ratee_type') ?? 'agent'));
        $ref  = sanitize_text_field((string) ($request->get_param('ratee_ref') ?? ''));
        if ($ref === '') {
            return new WP_Error('invalid', 'ratee_ref is required to list reviews for a profile.', ['status' => 400]);
        }
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        return rest_ensure_response([
            'reviews' => (new Growl_Work_Review_Service())->list_for_ratee($type, $ref, $per, ($page - 1) * $per),
        ]);
    }

    private function agent_from_request(WP_REST_Request $request) {
        if (!class_exists('Earnfi_Agent_REST_API')) {
            return new WP_Error('unauthorized', 'Sign in as an agent.', ['status' => 401]);
        }
        $api = new Earnfi_Agent_REST_API();
        return $api->public_require_agent($request);
    }

    public function list_my_orders(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $role = sanitize_key((string) ($request->get_param('role') ?? 'provider'));
        $svc  = new Growl_Agent_Order_Service();
        $orders = $role === 'buyer'
            ? $svc->list_for_buyer('agent', (string) $row->agent_id, $per, ($page - 1) * $per)
            : $svc->list_for_provider((string) $row->agent_id, $per, ($page - 1) * $per);
        return rest_ensure_response(['orders' => $orders]);
    }

    public function get_my_balance(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        $bal = (new Growl_Agent_Order_Service())->get_agent_balance((string) $row->agent_id);
        return rest_ensure_response(['balance' => $bal]);
    }

    public function withdraw_my_earnings(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        $amount = Growl_Money::resolve_amount_micro([
            'amount'       => $request->get_param('amount'),
            'amount_micro' => $request->get_param('amount_micro'),
        ]);
        $idem = Growl_Money::sanitize_idempotency_key(
            $request->get_header('Idempotency-Key')
                ?? (string) ($request->get_param('idempotency_key') ?? '')
        );
        $result = (new Growl_Agent_Order_Service())->withdraw_agent_to_wallet(
            (string) $row->agent_id,
            $amount,
            $idem
        );
        return is_wp_error($result) ? $result : rest_ensure_response(['withdraw' => $result, 'balance' => $result]);
    }

    public function list_my_disputes(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $disputes = (new Growl_Agent_Order_Service())->list_disputes_for_agent(
            (string) $row->agent_id,
            $per,
            ($page - 1) * $per
        );
        return rest_ensure_response(['disputes' => $disputes]);
    }

    public function get_my_dispute(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        $dispute = (new Growl_Agent_Order_Service())->get_dispute_for_agent(
            (int) $request['id'],
            (string) $row->agent_id
        );
        if (!$dispute) {
            return new WP_Error('not_found', 'Dispute not found.', ['status' => 404]);
        }
        return rest_ensure_response(['dispute' => $dispute]);
    }

    public function submit_review(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-review-service.php';
        $review = (new Growl_Work_Review_Service())->submit('agent', (string) $row->agent_id, $request->get_json_params() ?: []);
        return is_wp_error($review) ? $review : rest_ensure_response(['review' => $review]);
    }

    public function mine_review(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-review-service.php';
        $ref_type = sanitize_key((string) ($request->get_param('ref_type') ?? ''));
        $ref_id   = sanitize_text_field((string) ($request->get_param('ref_id') ?? ''));
        $review   = (new Growl_Work_Review_Service())->mine_for_work($ref_type, $ref_id, 'agent', (string) $row->agent_id);
        return rest_ensure_response(['review' => $review]);
    }

    public function open_thread(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-thread-service.php';
        $tid = (new Growl_Work_Thread_Service())->resolve(
            sanitize_key((string) ($request->get_param('ref_type') ?? 'agent_order')),
            sanitize_text_field((string) ($request->get_param('ref_key') ?? '')),
            'agent',
            (string) $row->agent_id
        );
        return is_wp_error($tid) ? $tid : rest_ensure_response($tid);
    }

    public function list_messages(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-thread-service.php';
        $msgs = (new Growl_Work_Thread_Service())->list_messages(
            (int) $request['id'],
            'agent',
            (string) $row->agent_id,
            (int) ($request->get_param('after') ?? 0)
        );
        return is_wp_error($msgs) ? $msgs : rest_ensure_response(['messages' => $msgs]);
    }

    public function post_message(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-work-thread-service.php';
        $msg = (new Growl_Work_Thread_Service())->post(
            (int) $request['id'],
            'agent',
            (string) $row->agent_id,
            (string) ($request->get_param('body') ?? '')
        );
        return is_wp_error($msg) ? $msg : rest_ensure_response(['message' => $msg]);
    }

    public function list_open_work(WP_REST_Request $request) {
        $rl = $this->enforce_public_browse_rate_limit();
        if (is_wp_error($rl)) {
            return $rl;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-open-work-service.php';
        $page = max(1, (int) $request->get_param('page'));
        $per  = min(50, max(1, (int) ($request->get_param('per_page') ?? 20)));
        $svc  = new Growl_Open_Work_Service();
        return rest_ensure_response($svc->list_gallery(
            sanitize_key((string) ($request->get_param('work_mode') ?? $request->get_param('mode') ?? '')),
            sanitize_key((string) ($request->get_param('execution_mode') ?? '')),
            sanitize_text_field((string) ($request->get_param('q') ?? '')),
            $page,
            $per
        ));
    }

    public function get_open_work(WP_REST_Request $request) {
        $rl = $this->enforce_public_browse_rate_limit();
        if (is_wp_error($rl)) {
            return $rl;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-open-work-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $agent_row = $this->agent_from_request($request);
        $viewer_type = 'guest';
        $viewer_ref  = '';
        if (!is_wp_error($agent_row)) {
            $viewer_type = 'agent';
            $viewer_ref  = (string) $agent_row->agent_id;
        }
        $detail = (new Growl_Open_Work_Service())->get_detail((string) $request['ref'], 0);
        if (!$detail) {
            return new WP_Error('not_found', 'Open work not found.', ['status' => 404]);
        }
        if ($viewer_type === 'agent') {
            $detail['viewer_role'] = $this->open_work_agent_role($detail, $viewer_ref);
        }
        return Growl_Agent_Guidance::rest_response('open_work', $detail, $viewer_type, $viewer_ref);
    }

    public function submit_open_work(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-open-work-service.php';
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
        $sub = $svc->submit(
            $resolved['ref_type'],
            $resolved['ref_id'],
            'agent',
            (string) $row->agent_id,
            $payload,
            $request->get_param('bid_micro') ?? ($params['bid_micro'] ?? null)
        );
        return Growl_Agent_Guidance::rest_response('open_work_submission', $sub, 'agent', (string) $row->agent_id);
    }

    public function accept_open_work_submission(WP_REST_Request $request) {
        $row = $this->agent_from_request($request);
        if (is_wp_error($row)) {
            return $row;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-open-work-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/ai-agent/class-agent-guidance.php';
        $db = new GROWL_Database();
        $p  = $db->table_prefix;
        $uid = (int) $db->get_var(
            "SELECT u.user_id FROM {$p}ai_agents a
             INNER JOIN {$p}users u ON LOWER(u.wallet_address) = LOWER(a.wallet_address)
             WHERE a.agent_id = %s LIMIT 1",
            [(string) $row->agent_id]
        );
        if ($uid < 1) {
            return new WP_Error('forbidden', 'Link a wallet to accept submissions.', ['status' => 403]);
        }
        $sub = (new Growl_Open_Work_Service())->accept_submission((int) $request['id'], $uid);
        return Growl_Agent_Guidance::rest_response('open_work_submission', $sub, 'agent', (string) $row->agent_id);
    }

    /**
     * @param array<string,mixed> $detail
     */
    private function open_work_agent_role(array $detail, $agent_id) {
        $p = (new GROWL_Database())->table_prefix;
        $db = new GROWL_Database();
        $mine = $db->get_var(
            "SELECT submission_id FROM {$p}open_work_submissions
             WHERE ref_type = %s AND ref_id = %s AND submitter_type = 'agent' AND submitter_ref = %s
               AND status IN ('pending','accepted')
             LIMIT 1",
            [
                (string) ($detail['ref_type'] ?? ''),
                (string) ($detail['ref_id'] ?? ''),
                (string) $agent_id,
            ]
        );
        return $mine ? 'submitter' : 'guest';
    }
}
