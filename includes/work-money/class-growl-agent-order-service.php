<?php
/**
 * Agent marketplace: services and orders (no owner_user_id).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Agent_Order_Service {

    /** @var GROWL_Database */
    private $db;

    /** @var Growl_X402_Payment_Service */
    private $payments;

    /** @var Growl_Balance_Service */
    private $balance;

    public function __construct(?GROWL_Database $db = null) {
        $this->db       = $db ?: new GROWL_Database();
        $this->payments = new Growl_X402_Payment_Service($this->db);
        $this->balance  = new Growl_Balance_Service($this->db);
    }

    public function upsert_service($agent_id, array $data) {
        $agent_id = sanitize_text_field((string) $agent_id);
        $title    = sanitize_text_field((string) ($data['title'] ?? ''));
        $category = sanitize_key((string) ($data['category'] ?? 'general'));
        $price    = Growl_Money::to_micro($data['price'] ?? $data['price_micro'] ?? '0');
        $delivery = sanitize_key((string) ($data['delivery_type'] ?? 'protected'));
        if (!in_array($delivery, ['instant', 'protected'], true)) {
            $delivery = 'protected';
        }
        if ($agent_id === '' || $title === '' || !Growl_Money::is_positive($price)) {
            return new WP_Error('invalid_service', 'Title and price are required.', ['status' => 400]);
        }
        $caps = $data['capabilities'] ?? [];
        $models = $data['models'] ?? [];
        $deliverables = $data['deliverables'] ?? [];
        $revs = isset($data['revision_count']) ? (int) $data['revision_count'] : (isset($data['revision_allowance']) ? (int) $data['revision_allowance'] : 2);
        $inspect = isset($data['inspection_seconds']) ? max(0, min(604800, (int) $data['inspection_seconds'])) : 0;
        $status = sanitize_key((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'paused', 'archived'], true)) {
            $status = 'active';
        }
        $row  = [
            'agent_id'           => $agent_id,
            'category'           => $category,
            'title'              => mb_substr($title, 0, 200),
            'description'        => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'pricing_mode'       => 'fixed',
            'price_micro'        => $price,
            'delivery_type'      => $delivery,
            'endpoint_base'      => isset($data['endpoint_base']) ? esc_url_raw((string) $data['endpoint_base']) : null,
            'output_schema_json' => isset($data['output_schema']) ? wp_json_encode($data['output_schema']) : null,
            'input_schema_json'  => isset($data['input_schema']) ? wp_json_encode($data['input_schema']) : null,
            'capabilities_json'  => is_array($caps) ? wp_json_encode($caps) : null,
            'models_json'        => is_array($models) ? wp_json_encode(array_values($models)) : null,
            'deliverables_json'  => is_array($deliverables) ? wp_json_encode(array_values($deliverables)) : null,
            'revision_count'     => max(0, min(10, $revs)),
            'inspection_seconds' => $inspect > 0 ? $inspect : null,
            'sla_seconds'        => max(60, (int) ($data['sla_seconds'] ?? 86400)),
            'status'             => $status,
            'updated_at'         => gmdate('Y-m-d H:i:s'),
        ];
        $service_id = isset($data['service_id']) ? (int) $data['service_id'] : 0;
        if ($service_id > 0) {
            $existing = $this->db->get_row(
                "SELECT * FROM {$this->db->table_prefix}agent_services WHERE service_id = %d AND agent_id = %s LIMIT 1",
                [$service_id, $agent_id]
            );
            if (!$existing) {
                return new WP_Error('not_found', 'Service not found.', ['status' => 404]);
            }
            $this->db->update('agent_services', $row, ['service_id' => $service_id]);
        } else {
            $row['created_at'] = gmdate('Y-m-d H:i:s');
            $this->db->insert('agent_services', $row);
            $service_id = (int) $this->db->wpdb->insert_id;
        }
        require_once __DIR__ . '/class-growl-agent-marketplace-service.php';
        (new Growl_Agent_Marketplace_Service($this->db))->refresh_agent_denorm($agent_id);
        return $this->get_service($service_id);
    }

    public function get_service($service_id) {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}agent_services WHERE service_id = %d LIMIT 1",
            [(int) $service_id]
        );
        return $row ? $this->format_service($row) : null;
    }

    public function set_service_status($agent_id, $service_id, $status) {
        $status = sanitize_key((string) $status);
        if (!in_array($status, ['active', 'paused', 'archived'], true)) {
            return new WP_Error('invalid', 'Use active, paused, or archived.', ['status' => 400]);
        }
        $existing = $this->db->get_row(
            "SELECT service_id FROM {$this->db->table_prefix}agent_services WHERE service_id = %d AND agent_id = %s LIMIT 1",
            [(int) $service_id, sanitize_text_field((string) $agent_id)]
        );
        if (!$existing) {
            return new WP_Error('not_found', 'Service not found.', ['status' => 404]);
        }
        $this->db->update('agent_services', [
            'status'     => $status,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['service_id' => (int) $service_id]);
        require_once __DIR__ . '/class-growl-agent-marketplace-service.php';
        (new Growl_Agent_Marketplace_Service($this->db))->refresh_agent_denorm($agent_id);
        return $this->get_service($service_id);
    }

    public function list_services_for_agent($agent_id, $limit = 50) {
        $limit = min(100, max(1, (int) $limit));
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->db->table_prefix}agent_services WHERE agent_id = %s ORDER BY updated_at DESC LIMIT %d",
            [sanitize_text_field((string) $agent_id), $limit]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->format_service($row);
        }
        return $out;
    }

    public function catalog($capability = '', $limit = 20, $offset = 0, $q = '', $sort = 'popular', $said_verified = false, $min_rating = 0, $min_price_micro = '', $max_price_micro = '', $model = '', $delivery_type = '') {
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $p      = $this->db->table_prefix;
        $where  = "WHERE s.status = 'active'";
        $params = [];
        if ($capability !== '') {
            $where   .= ' AND (s.category = %s OR s.capabilities_json LIKE %s)';
            $params[] = sanitize_key($capability);
            $params[] = '%' . $this->db->wpdb->esc_like(sanitize_key($capability)) . '%';
        }
        $q = trim((string) $q);
        if ($q !== '') {
            $like     = '%' . $this->db->wpdb->esc_like($q) . '%';
            $where   .= ' AND (s.title LIKE %s OR s.description LIKE %s OR a.agent_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $min_rating = (float) $min_rating;
        if ($min_rating > 0) {
            $where   .= ' AND s.rating_avg >= %f';
            $params[] = $min_rating;
        }
        $delivery_type = sanitize_key((string) $delivery_type);
        if ($delivery_type !== '' && in_array($delivery_type, ['instant', 'protected'], true)) {
            $where   .= ' AND s.delivery_type = %s';
            $params[] = $delivery_type;
        }
        $model = trim((string) $model);
        if ($model !== '') {
            $model_like = '%' . $this->db->wpdb->esc_like($model) . '%';
            $where     .= ' AND (JSON_CONTAINS(s.models_json, %s) OR s.models_json LIKE %s OR JSON_CONTAINS(a.models_json, %s) OR a.models_json LIKE %s)';
            $params[]   = wp_json_encode($model);
            $params[]   = $model_like;
            $params[]   = wp_json_encode($model);
            $params[]   = $model_like;
        }
        if ($min_price_micro !== '') {
            $min_micro = Growl_Money::to_micro($min_price_micro);
            if (Growl_Money::is_positive($min_micro)) {
                $where   .= ' AND s.price_micro >= %s';
                $params[] = Growl_Money::assert_micro($min_micro);
            }
        }
        if ($max_price_micro !== '') {
            $max_micro = Growl_Money::to_micro($max_price_micro);
            if (Growl_Money::is_positive($max_micro)) {
                $where   .= ' AND s.price_micro <= %s';
                $params[] = Growl_Money::assert_micro($max_micro);
            }
        }
        $sql_limit  = $said_verified ? min(200, max($limit + $offset, $limit * 5)) : $limit;
        $sql_offset = $said_verified ? 0 : $offset;
        $params[] = $sql_limit;
        $params[] = $sql_offset;
        $order = 's.completed_count DESC, s.rating_avg DESC, s.updated_at DESC';
        if ($sort === 'newest') {
            $order = 's.updated_at DESC';
        } elseif ($sort === 'price') {
            $order = 's.price_micro ASC, s.rating_avg DESC';
        } elseif ($sort === 'rating') {
            $order = 's.rating_avg DESC, s.rating_count DESC, s.completed_count DESC';
        }
        $rows = $this->db->get_results(
            "SELECT s.*, a.agent_name, a.avatar_url, a.wallet_address
             FROM {$p}agent_services s
             LEFT JOIN {$p}ai_agents a ON a.agent_id = s.agent_id
             {$where}
             ORDER BY {$order}
             LIMIT %d OFFSET %d",
            $params
        );
        $out = [];
        $wallets = [];
        require_once __DIR__ . '/class-growl-agent-marketplace-service.php';
        $market = new Growl_Agent_Marketplace_Service($this->db);
        foreach ($rows ?: [] as $row) {
            $item = $market->format_service($row, $row);
            $wallet = (string) ($row->wallet_address ?? '');
            if ($wallet !== '') {
                $wallets[] = $wallet;
            }
            $item['said_verified'] = false;
            $item['_wallet'] = $wallet;
            $out[] = $item;
        }
        $said_map = [];
        if ($wallets && class_exists('EarnFi_Said_Identity')) {
            $said_map = EarnFi_Said_Identity::get_instance()->batch_verified_flags(array_values(array_unique($wallets)));
        }
        foreach ($out as $i => $item) {
            $w = (string) ($item['_wallet'] ?? '');
            $out[$i]['said_verified'] = $w !== '' && !empty($said_map[$w]);
            unset($out[$i]['_wallet']);
        }
        if ($said_verified) {
            $out = array_values(array_filter($out, static function ($s) {
                return !empty($s['said_verified']);
            }));
            $out = array_slice($out, $offset, $limit);
        }
        return $out;
    }

    /**
     * Upload a reference image for an agent order brief (human buyers).
     *
     * @param int $user_id
     * @param string $data_url Base64 data URL (JPG/PNG, max 5MB)
     * @return array{url:string}|WP_Error
     */
    public function upload_reference_image($user_id, $data_url) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return new WP_Error('unauthorized', 'Sign in to upload reference images.', ['status' => 401]);
        }
        $data_url = trim((string) $data_url);
        if (!preg_match('#^data:image/(jpeg|jpg|png);base64,#i', $data_url, $m)) {
            return new WP_Error('invalid_image', 'Only JPG or PNG images are supported.', ['status' => 400]);
        }
        $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        $raw = base64_decode(substr($data_url, strpos($data_url, ',') + 1), true);
        if ($raw === false || strlen($raw) < 64) {
            return new WP_Error('invalid_image', 'Could not decode image.', ['status' => 400]);
        }
        if (strlen($raw) > 5 * 1024 * 1024) {
            return new WP_Error('image_too_large', 'Image must be under 5MB.', ['status' => 400]);
        }
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('upload_failed', 'Upload directory unavailable.', ['status' => 500]);
        }
        $subdir = 'earnfi-order-refs/' . gmdate('Y/m');
        $dir = trailingslashit($upload_dir['basedir']) . $subdir;
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('upload_failed', 'Could not create upload directory.', ['status' => 500]);
        }
        $filename = 'ref_' . $user_id . '_' . wp_generate_password(12, false, false) . '.' . $ext;
        $filepath = trailingslashit($dir) . $filename;
        if (file_put_contents($filepath, $raw) === false) {
            return new WP_Error('upload_failed', 'Could not save image.', ['status' => 500]);
        }
        $url = trailingslashit($upload_dir['baseurl']) . $subdir . '/' . $filename;
        return ['url' => esc_url_raw($url) ?: $url];
    }

    public function create_order($buyer_type, $buyer_ref, $service_id, $input = []) {
        $svc = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}agent_services WHERE service_id = %d AND status = 'active' LIMIT 1",
            [(int) $service_id]
        );
        if (!$svc) {
            return new WP_Error('not_found', 'Service not found.', ['status' => 404]);
        }
        require_once __DIR__ . '/class-growl-input-schema.php';
        $schema = !empty($svc->input_schema_json) ? json_decode($svc->input_schema_json, true) : null;
        $check  = Growl_Input_Schema::validate(is_array($schema) ? $schema : [], is_array($input) ? $input : [], 'input');
        if (is_wp_error($check)) {
            return $check;
        }
        $slug = $this->slug('agent_orders', 'public_slug');
        $secret_plain = bin2hex(random_bytes(16));
        $ok = $this->db->insert('agent_orders', [
            'public_slug'         => $slug,
            'service_id'          => (int) $service_id,
            'provider_agent_id'   => (string) $svc->agent_id,
            'buyer_type'          => $buyer_type === 'agent' ? 'agent' : 'human',
            'buyer_ref'           => sanitize_text_field((string) $buyer_ref),
            'amount_micro'        => (string) $svc->price_micro,
            'delivery_type'       => (string) $svc->delivery_type,
            'status'              => 'pending_funding',
            'input_json'          => wp_json_encode($input),
            'access_secret_hash'  => hash('sha256', $secret_plain),
            'created_at'          => gmdate('Y-m-d H:i:s'),
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            return new WP_Error('create_failed', 'Could not create order.', ['status' => 500]);
        }
        $order = $this->get_order((int) $this->db->wpdb->insert_id);
        $order['secret'] = $secret_plain;
        $this->open_thread((string) $order['public_slug']);
        $this->event((string) $order['public_slug'], 'order_created', $buyer_type, (string) $buyer_ref);
        return $order;
    }

    public function fund_order($order_id, $buyer_user_id, $source, $settlement_id = null, $idem = '') {
        $this->db->begin_transaction();
        try {
            $order = $this->db->get_row_for_update(
                "SELECT * FROM {$this->db->table_prefix}agent_orders WHERE order_id = %d LIMIT 1",
                [(int) $order_id]
            );
            if (!$order || $order->status !== 'pending_funding') {
                $this->db->rollback();
                return new WP_Error('invalid_state', 'This order is not awaiting payment.', ['status' => 400]);
            }
            if ($order->buyer_type === 'human' && (string) $order->buyer_ref !== (string) $buyer_user_id) {
                $this->db->rollback();
                return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
            }
            $amount = (string) $order->amount_micro;
            $ref    = (string) $order->public_slug;
            $idem   = Growl_Money::sanitize_idempotency_key($idem) ?: 'agent_order:' . $order_id;

            if (($source === 'balance' || $source === 'creator_wallet') && $order->buyer_type === 'human') {
                if ($source === 'creator_wallet') {
                    $settlement_id = $this->payments->settle_from_creator_wallet(
                        $buyer_user_id, $amount, 'agent_order_fund', 'agent_order', $ref, $idem
                    );
                } else {
                    $settlement_id = $this->payments->settle_from_balance(
                        $buyer_user_id, $amount, 'agent_order_fund', 'agent_order', $ref, $idem
                    );
                }
                if (is_wp_error($settlement_id)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($settlement_id);
                }
                $lock = $this->balance->lock_escrow(
                    $buyer_user_id, $amount, 'agent_order_fund', 'agent_order', $ref, $idem . ':lock', $settlement_id, false
                );
                if (is_wp_error($lock)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($lock);
                }
            } elseif ($settlement_id) {
                $settlement_id = (int) $settlement_id;
                if ($order->buyer_type === 'human') {
                    $lock = $this->balance->lock_escrow(
                        $buyer_user_id, $amount, 'agent_order_fund', 'agent_order', $ref, $idem . ':lock', $settlement_id, false
                    );
                    if (is_wp_error($lock)) {
                        $this->db->rollback();
                        return Growl_X402_Payment_Service::public_error($lock);
                    }
                }
            } else {
                $this->db->rollback();
                return new WP_Error('payment_required', 'Payment is required.', ['status' => 402]);
            }

            $svc = $this->db->get_row(
                "SELECT sla_seconds, revision_count, delivery_type, inspection_seconds FROM {$this->db->table_prefix}agent_services WHERE service_id = %d LIMIT 1",
                [(int) $order->service_id]
            );
            // Both instant and protected hold funds in escrow until the agent delivers
            // and the buyer releases (or auto-release after inspection). Never auto-payout on fund.
            $sla = max(60, (int) ($svc->sla_seconds ?? 86400));
            $next = 'funded';
            $sla_due = gmdate('Y-m-d H:i:s', time() + $sla);
            $this->db->update('agent_orders', [
                'status'             => $next,
                'fund_settlement_id' => $settlement_id,
                'sla_due_at'         => $sla_due,
                'updated_at'         => gmdate('Y-m-d H:i:s'),
            ], ['order_id' => (int) $order_id]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('fund_failed', 'Payment could not be completed.', ['status' => 500]);
        }
        $this->event($ref, 'order_funded', 'human', (string) $buyer_user_id);
        $this->webhook($order_id, 'order_funded');
        return $this->get_order($order_id);
    }

    public function fund_order_as_agent($order_id, $agent_id, $settlement_id, $idem = '') {
        $order = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}agent_orders WHERE order_id = %d LIMIT 1",
            [(int) $order_id]
        );
        if (!$order || $order->buyer_type !== 'agent' || (string) $order->buyer_ref !== (string) $agent_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        if ($order->status !== 'pending_funding') {
            return new WP_Error('invalid_state', 'This order is not awaiting payment.', ['status' => 400]);
        }
        if (!$settlement_id) {
            return new WP_Error('payment_required', 'Payment is required.', ['status' => 402]);
        }
        return $this->fund_order($order_id, 0, 'wallet', (int) $settlement_id, $idem);
    }

    public function request_revision($order_id, $buyer_ref, $note = '') {
        $order = $this->db->get_row(
            "SELECT buyer_type, buyer_ref FROM {$this->db->table_prefix}agent_orders WHERE order_id = %d LIMIT 1",
            [(int) $order_id]
        );
        if (!$order || (string) $order->buyer_ref !== (string) $buyer_ref) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        return $this->request_revision_by_buyer($order_id, (string) $order->buyer_type, (string) $buyer_ref, $note);
    }

    public function request_revision_by_buyer($order_id, $buyer_type, $buyer_ref, $note = '') {
        $buyer_type = $buyer_type === 'agent' ? 'agent' : 'human';
        $buyer_ref  = sanitize_text_field((string) $buyer_ref);
        $this->db->begin_transaction();
        try {
            $order = $this->db->get_row_for_update(
                "SELECT * FROM {$this->db->table_prefix}agent_orders WHERE order_id = %d LIMIT 1",
                [(int) $order_id]
            );
            if (!$order || (string) $order->buyer_type !== $buyer_type || (string) $order->buyer_ref !== $buyer_ref) {
                $this->db->rollback();
                return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
            }
            if ($order->status !== 'delivered') {
                $this->db->rollback();
                return new WP_Error('invalid_state', 'A revision can only be requested after delivery.', ['status' => 400]);
            }
            $svc = $this->db->get_row(
                "SELECT revision_count FROM {$this->db->table_prefix}agent_services WHERE service_id = %d LIMIT 1",
                [(int) $order->service_id]
            );
            $allowance = max(0, (int) ($svc->revision_count ?? 2));
            $count = (int) ($order->revision_count ?? 0);
            if ($count >= $allowance) {
                $this->db->rollback();
                return new WP_Error('revision_limit', 'No further revisions are available.', ['status' => 400]);
            }
            $note = sanitize_textarea_field((string) $note);
            $this->db->update('agent_orders', [
                'status'         => 'funded',
                'revision_count' => $count + 1,
                'output_json'    => wp_json_encode(['revision_requested' => $note]),
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ], ['order_id' => (int) $order_id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('update_failed', 'Could not request a revision.', ['status' => 500]);
        }
        $this->post_system((string) $order->public_slug, $note !== '' ? 'Revision requested: ' . $note : 'A revision was requested.');
        $this->event((string) $order->public_slug, 'revision_requested', $buyer_type, $buyer_ref);
        $this->webhook($order_id, 'revision_requested');
        return $this->get_order($order_id);
    }

    public function open_dispute($order_id, $buyer_type, $buyer_ref, $reason) {
        $buyer_type = $buyer_type === 'agent' ? 'agent' : 'human';
        $buyer_ref  = sanitize_text_field((string) $buyer_ref);
        $this->db->begin_transaction();
        try {
            $order = $this->db->get_row_for_update(
                "SELECT * FROM {$this->db->table_prefix}agent_orders WHERE order_id = %d LIMIT 1",
                [(int) $order_id]
            );
            if (!$order || (string) $order->buyer_ref !== $buyer_ref || (string) $order->buyer_type !== $buyer_type) {
                $this->db->rollback();
                return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
            }
            if (!in_array($order->status, ['funded', 'delivered'], true)) {
                $this->db->rollback();
                return new WP_Error('invalid_state', 'This order cannot be disputed.', ['status' => 400]);
            }
            $reason = sanitize_textarea_field((string) $reason);
            if ($reason === '') {
                $this->db->rollback();
                return new WP_Error('invalid', 'A reason is required.', ['status' => 400]);
            }
            $opener_uid = $buyer_type === 'human' ? (int) $buyer_ref : 0;
            $ok = $this->db->insert('work_disputes', [
                'ref_type'       => 'agent_order',
                'ref_id'         => (string) $order->public_slug,
                'opener_user_id' => $opener_uid,
                'reason'         => $reason,
                'status'         => 'open',
                'created_at'     => gmdate('Y-m-d H:i:s'),
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ]);
            if (!$ok) {
                $this->db->rollback();
                return new WP_Error('exists', 'A dispute is already open for this order.', ['status' => 400]);
            }
            if ($order->buyer_type === 'human') {
                $hold = $this->balance->hold_dispute(
                    (int) $buyer_ref,
                    (string) $order->amount_micro,
                    'agent_order',
                    (string) $order->public_slug,
                    'agent_order_dispute:' . $order_id
                );
                if (is_wp_error($hold)) {
                    // Wallet-funded orders may have no EarnFi escrow row.
                    error_log('agent_order dispute hold: ' . $hold->get_error_message());
                }
            }
            $this->db->query(
                "UPDATE {$this->db->table_prefix}escrow_ledger SET status = 'disputed', updated_at = %s
                 WHERE ref_type = 'agent_order' AND ref_id = %s AND status = 'locked' LIMIT 1",
                [gmdate('Y-m-d H:i:s'), (string) $order->public_slug]
            );
            $this->db->update('agent_orders', [
                'status'     => 'disputed',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['order_id' => (int) $order_id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('update_failed', 'Could not open a dispute.', ['status' => 500]);
        }
        $this->post_system((string) $order->public_slug, 'A dispute was opened.');
        $this->event((string) $order->public_slug, 'order_disputed', $buyer_type, $buyer_ref);
        $this->webhook($order_id, 'order_disputed');
        return $this->get_order($order_id);
    }

    public function refund($order_id, $actor_user_id, $idem = '') {
        $this->db->begin_transaction();
        try {
            $order = $this->db->get_row_for_update(
                "SELECT * FROM {$this->db->table_prefix}agent_orders WHERE order_id = %d LIMIT 1",
                [(int) $order_id]
            );
            if (!$order) {
                $this->db->rollback();
                return new WP_Error('not_found', 'Order not found.', ['status' => 404]);
            }
            if ($order->buyer_type !== 'human' || (string) $order->buyer_ref !== (string) $actor_user_id) {
                $this->db->rollback();
                return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
            }
            if (!in_array($order->status, ['funded', 'disputed'], true)) {
                $this->db->rollback();
                return new WP_Error('invalid_state', 'This order cannot be refunded.', ['status' => 400]);
            }
            $idem = Growl_Money::sanitize_idempotency_key($idem) ?: 'agent_order_refund:' . $order_id;
            $from_dispute = $order->status === 'disputed';
            $refund = $this->balance->refund_escrow_to_buyer(
                (int) $actor_user_id,
                (string) $order->amount_micro,
                'agent_order',
                (string) $order->public_slug,
                $idem,
                $from_dispute
            );
            if (is_wp_error($refund)) {
                $this->db->rollback();
                return Growl_X402_Payment_Service::public_error($refund);
            }
            $this->db->update('agent_orders', [
                'status'     => 'refunded',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['order_id' => (int) $order_id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('refund_failed', 'Refund failed.', ['status' => 500]);
        }
        $this->event((string) $order->public_slug, 'order_refunded', 'human', (string) $actor_user_id);
        $this->webhook($order_id, 'order_refunded');
        return $this->get_order($order_id);
    }

    public function deliver($order_id, $agent_id, $output = []) {
        $this->db->begin_transaction();
        try {
            $order = $this->db->get_row_for_update(
                "SELECT * FROM {$this->db->table_prefix}agent_orders WHERE order_id = %d LIMIT 1",
                [(int) $order_id]
            );
            if (!$order || (string) $order->provider_agent_id !== (string) $agent_id) {
                $this->db->rollback();
                return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
            }
            if ($order->status !== 'funded') {
                $this->db->rollback();
                return new WP_Error('invalid_state', 'Order is not ready for delivery.', ['status' => 400]);
            }
            $svc = $this->db->get_row(
                "SELECT output_schema_json, inspection_seconds, delivery_type FROM {$this->db->table_prefix}agent_services WHERE service_id = %d LIMIT 1",
                [(int) $order->service_id]
            );
            require_once __DIR__ . '/class-growl-input-schema.php';
            $schema = $svc && !empty($svc->output_schema_json) ? json_decode($svc->output_schema_json, true) : null;
            $check  = Growl_Input_Schema::validate(is_array($schema) ? $schema : [], is_array($output) ? $output : [], 'output');
            if (is_wp_error($check)) {
                $this->db->rollback();
                return $check;
            }
            // Buyer inspection window before auto-release (instant defaults shorter).
            $inspect = (int) ($svc->inspection_seconds ?? 0);
            if ($inspect < 1) {
                $inspect = ((string) ($order->delivery_type ?? '') === 'instant') ? 3600 : 86400;
            }
            $inspect = max(60, $inspect);
            $this->db->update('agent_orders', [
                'status'      => 'delivered',
                'output_json' => wp_json_encode($output),
                'sla_due_at'  => gmdate('Y-m-d H:i:s', time() + $inspect),
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ], ['order_id' => (int) $order_id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('update_failed', 'Could not mark delivery.', ['status' => 500]);
        }
        $this->post_system((string) $order->public_slug, 'Work was delivered.');
        $this->event((string) $order->public_slug, 'order_delivered', 'agent', (string) $agent_id);
        $this->webhook($order_id, 'order_delivered');
        if (class_exists('Growl_Work_Notify') && $order->buyer_type === 'human') {
            Growl_Work_Notify::human(
                (int) $order->buyer_ref,
                'Agent delivered',
                'Review the result and release payment if it matches the brief.',
                'order/' . $order->public_slug,
                'info'
            );
        }
        return $this->get_order($order_id);
    }

    public function release($order_id, $buyer_ref, $idem = '', array $said_auth = []) {
        $this->db->begin_transaction();
        try {
            $order = $this->db->get_row_for_update(
                "SELECT * FROM {$this->db->table_prefix}agent_orders WHERE order_id = %d LIMIT 1",
                [(int) $order_id]
            );
            if (!$order || (string) $order->buyer_ref !== (string) $buyer_ref) {
                $this->db->rollback();
                return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
            }
            if (!in_array($order->status, ['delivered', 'disputed'], true)) {
                $this->db->rollback();
                return new WP_Error('invalid_state', 'Release payment after the agent delivers (or from a dispute resolution).', ['status' => 400]);
            }
            $from_dispute = $order->status === 'disputed';
            $idem = Growl_Money::sanitize_idempotency_key($idem) ?: 'agent_order_release:' . $order_id;
            if (!class_exists('Growl_Work_Fee_Engine')) {
                require_once __DIR__ . '/class-growl-work-fee-engine.php';
            }
            $split = Growl_Work_Fee_Engine::split_for_gross($order->amount_micro, 'agent_orders');
            $this->credit_agent($order, $order->fund_settlement_id, $idem, $split['net_to_earner_micro']);
            if ($order->buyer_type === 'human') {
                $rel = $this->balance->release_escrow_to_seller(
                    (int) $buyer_ref,
                    0,
                    (string) $order->amount_micro,
                    (int) $split['earner_bps'],
                    'agent_order',
                    (string) $order->public_slug,
                    $idem
                );
                if (is_wp_error($rel)) {
                    error_log('agent_order release escrow: ' . $rel->get_error_message());
                }
            } elseif (Growl_Money::is_positive($split['platform_fee_micro'])) {
                $this->balance->credit_platform_fee(
                    $split['platform_fee_micro'],
                    'agent_order',
                    (string) $order->public_slug,
                    $idem . ':fee'
                );
            }
            $now = gmdate('Y-m-d H:i:s');
            $this->db->query(
                "UPDATE {$this->db->table_prefix}escrow_ledger SET status = 'released', updated_at = %s
                 WHERE ref_type = 'agent_order' AND ref_id = %s AND status IN ('locked','disputed') LIMIT 1",
                [$now, (string) $order->public_slug]
            );
            if ($from_dispute) {
                $this->db->query(
                    "UPDATE {$this->db->table_prefix}work_disputes SET status = 'closed', updated_at = %s
                     WHERE ref_type = 'agent_order' AND ref_id = %s AND status = 'open'",
                    [$now, (string) $order->public_slug]
                );
            }
            $this->db->update('agent_orders', [
                'status'     => 'released',
                'updated_at' => $now,
            ], ['order_id' => (int) $order_id]);
            $this->bump_completed($order);
            $this->create_receipt($order, $split);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('release_failed', 'Release failed.', ['status' => 500]);
        }
        $this->event((string) $order->public_slug, 'order_released', (string) $order->buyer_type, (string) $buyer_ref);
        $this->webhook($order_id, 'order_released');
        if (class_exists('Growl_Work_Notify')) {
            Growl_Work_Notify::prompt_work_rating('agent_order', (string) $order->public_slug, (string) $order->buyer_type, (string) $order->buyer_ref);
            Growl_Work_Notify::prompt_work_rating('agent_order', (string) $order->public_slug, 'agent', (string) $order->provider_agent_id);
        }
        return $this->get_order($order_id);
    }

    public function get_order($order_id, $secret = '', $actor_type = '', $actor_ref = '') {
        $row = $this->db->get_row(
            "SELECT o.*, s.title AS service_title, s.revision_count AS revision_allowance, s.sla_seconds,
                    s.output_schema_json, a.agent_name, a.avatar_url, a.wallet_address, a.identity_provider, a.capabilities
             FROM {$this->db->table_prefix}agent_orders o
             LEFT JOIN {$this->db->table_prefix}agent_services s ON s.service_id = o.service_id
             LEFT JOIN {$this->db->table_prefix}ai_agents a ON a.agent_id = o.provider_agent_id
             WHERE o.order_id = %d LIMIT 1",
            [(int) $order_id]
        );
        if (!$row) {
            return null;
        }
        return $this->present_order($row, $secret, $actor_type, $actor_ref);
    }

    public function get_by_slug($slug, $secret = '', $actor_type = '', $actor_ref = '') {
        $row = $this->db->get_row(
            "SELECT o.*, s.title AS service_title, s.revision_count AS revision_allowance, s.sla_seconds,
                    s.output_schema_json, a.agent_name, a.avatar_url, a.wallet_address, a.identity_provider, a.capabilities
             FROM {$this->db->table_prefix}agent_orders o
             LEFT JOIN {$this->db->table_prefix}agent_services s ON s.service_id = o.service_id
             LEFT JOIN {$this->db->table_prefix}ai_agents a ON a.agent_id = o.provider_agent_id
             WHERE o.public_slug = %s LIMIT 1",
            [sanitize_text_field((string) $slug)]
        );
        if (!$row) {
            return null;
        }
        return $this->present_order($row, $secret, $actor_type, $actor_ref);
    }

    public function list_for_buyer($buyer_type, $buyer_ref, $limit = 20, $offset = 0) {
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT o.*, s.title AS service_title, s.revision_count AS revision_allowance, a.agent_name, a.avatar_url,
                    a.wallet_address, a.identity_provider, a.capabilities
             FROM {$p}agent_orders o
             LEFT JOIN {$p}agent_services s ON s.service_id = o.service_id
             LEFT JOIN {$p}ai_agents a ON a.agent_id = o.provider_agent_id
             WHERE o.buyer_type = %s AND o.buyer_ref = %s
             ORDER BY o.order_id DESC
             LIMIT %d OFFSET %d",
            [$buyer_type === 'agent' ? 'agent' : 'human', sanitize_text_field((string) $buyer_ref), $limit, $offset]
        );
        $flags = $this->batch_said_flags_for_orders($rows);
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->present_order($row, '', $buyer_type === 'agent' ? 'agent' : 'human', (string) $buyer_ref, $flags);
        }
        return $out;
    }

    public function list_for_provider($agent_id, $limit = 20, $offset = 0) {
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT o.*, s.title AS service_title, s.revision_count AS revision_allowance, a.agent_name, a.avatar_url,
                    a.wallet_address, a.identity_provider, a.capabilities
             FROM {$p}agent_orders o
             LEFT JOIN {$p}agent_services s ON s.service_id = o.service_id
             LEFT JOIN {$p}ai_agents a ON a.agent_id = o.provider_agent_id
             WHERE o.provider_agent_id = %s
             ORDER BY o.order_id DESC
             LIMIT %d OFFSET %d",
            [sanitize_text_field((string) $agent_id), $limit, $offset]
        );
        $flags = $this->batch_said_flags_for_orders($rows);
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->present_order($row, '', 'agent', (string) $agent_id, $flags);
        }
        return $out;
    }

    public function auto_release_due($limit = 25) {
        $limit = max(1, min(50, (int) $limit));
        $rows = $this->db->get_results(
            "SELECT order_id, buyer_ref FROM {$this->db->table_prefix}agent_orders
             WHERE status = 'delivered'
               AND sla_due_at IS NOT NULL AND sla_due_at <= UTC_TIMESTAMP()
             ORDER BY sla_due_at ASC LIMIT %d",
            [$limit]
        );
        $n = 0;
        foreach ($rows ?: [] as $row) {
            $res = $this->release((int) $row->order_id, (string) $row->buyer_ref, 'auto_release:' . $row->order_id);
            if (!is_wp_error($res)) {
                $n++;
            }
        }
        return $n;
    }

    public function notify_overdue($limit = 25) {
        $limit = max(1, min(50, (int) $limit));
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT o.order_id, o.public_slug, o.buyer_type, o.buyer_ref
             FROM {$p}agent_orders o
             WHERE o.status IN ('funded','delivered')
               AND o.sla_due_at IS NOT NULL AND o.sla_due_at <= UTC_TIMESTAMP()
               AND NOT EXISTS (
                    SELECT 1 FROM {$p}work_events e
                    WHERE e.ref_type = 'agent_order' AND e.ref_id = o.public_slug AND e.event_key = 'sla_overdue'
               )
             ORDER BY o.sla_due_at ASC LIMIT %d",
            [$limit]
        );
        $n = 0;
        foreach ($rows ?: [] as $row) {
            $this->event((string) $row->public_slug, 'sla_overdue', 'system', '');
            $this->webhook((int) $row->order_id, 'order_overdue');
            if ($row->buyer_type === 'human' && class_exists('Growl_Work_Notify')) {
                Growl_Work_Notify::human(
                    (int) $row->buyer_ref,
                    'Delivery is late',
                    'This order passed its expected delivery time. You can wait, open a dispute, or request a refund if work was never delivered.',
                    'order/' . $row->public_slug,
                    'warning'
                );
            }
            $n++;
        }
        return $n;
    }

    public function auto_refund_undelivered($limit = 25) {
        $limit = max(1, min(50, (int) $limit));
        $rows = $this->db->get_results(
            "SELECT order_id, buyer_ref, buyer_type FROM {$this->db->table_prefix}agent_orders
             WHERE status = 'funded'
               AND delivery_type IN ('protected', 'instant')
               AND sla_due_at IS NOT NULL AND sla_due_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)
             ORDER BY sla_due_at ASC LIMIT %d",
            [$limit]
        );
        $n = 0;
        foreach ($rows ?: [] as $row) {
            if ($row->buyer_type !== 'human') {
                continue;
            }
            $res = $this->refund((int) $row->order_id, (int) $row->buyer_ref, 'sla_auto_refund:' . $row->order_id);
            if (!is_wp_error($res)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Cancel stale unfunded orders (no escrow locked).
     */
    public function expire_unfunded_orders($limit = 50) {
        $limit = max(1, min(100, (int) $limit));
        $rows = $this->db->get_results(
            "SELECT order_id, public_slug, buyer_type, buyer_ref
             FROM {$this->db->table_prefix}agent_orders
             WHERE status = 'pending_funding'
               AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 72 HOUR)
             ORDER BY created_at ASC
             LIMIT %d",
            [$limit]
        );
        $n = 0;
        foreach ($rows ?: [] as $row) {
            $ok = $this->db->update('agent_orders', [
                'status'     => 'refunded',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], [
                'order_id' => (int) $row->order_id,
                'status'   => 'pending_funding',
            ]);
            if (!$ok) {
                continue;
            }
            $this->event((string) $row->public_slug, 'order_expired', 'system', '');
            $this->webhook((int) $row->order_id, 'order_expired');
            if ($row->buyer_type === 'human' && class_exists('Growl_Work_Notify')) {
                Growl_Work_Notify::human(
                    (int) $row->buyer_ref,
                    'Order expired',
                    'This unfunded order was cancelled after 72 hours without payment.',
                    'order/' . $row->public_slug,
                    'info'
                );
            }
            $n++;
        }
        return $n;
    }

    private function credit_agent($order, $settlement_id, $idem, $amount_micro = null) {
        $key = $idem . ':agent';
        $exists = $this->db->get_var(
            "SELECT ledger_id FROM {$this->db->table_prefix}agent_balance_ledger WHERE idempotency_key = %s LIMIT 1",
            [$key]
        );
        if ($exists) {
            return;
        }
        $credit = $amount_micro !== null
            ? Growl_Money::assert_micro($amount_micro)
            : Growl_Money::assert_micro($order->amount_micro);
        $this->db->insert('agent_balance_ledger', [
            'agent_id'         => (string) $order->provider_agent_id,
            'amount_micro'     => $credit,
            'entry_type'       => 'credit',
            'purpose'          => 'agent_order_release',
            'ref_type'         => 'agent_order',
            'ref_id'           => (string) $order->public_slug,
            'settlement_id'    => $settlement_id ? (int) $settlement_id : null,
            'idempotency_key'  => $key,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function create_receipt($order, $split = null) {
        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once __DIR__ . '/class-growl-work-receipt-service.php';
        }
        (new Growl_Work_Receipt_Service($this->db))->upsert_from_ref(
            'agent_order',
            (string) $order->public_slug,
            $split ? ['fee_micro' => (string) ($split['platform_fee_micro'] ?? '0'), 'net_micro' => (string) ($split['net_to_earner_micro'] ?? $order->amount_micro)] : []
        );
    }

    private function slug($table, $col) {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        do {
            $s = '';
            for ($i = 0; $i < 12; $i++) {
                $s .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while ($this->db->get_var("SELECT 1 FROM {$this->db->table_prefix}{$table} WHERE {$col} = %s LIMIT 1", [$s]));
        return $s;
    }

    public function format_service($row) {
        require_once __DIR__ . '/class-growl-agent-marketplace-service.php';
        return (new Growl_Agent_Marketplace_Service($this->db))->format_service($row, $row);
    }

    public function format_order($row, ?array $said_flags = null) {
        $overdue = !empty($row->sla_due_at)
            && in_array((string) $row->status, ['funded', 'delivered'], true)
            && strtotime($row->sla_due_at . ' UTC') < time();
        $avatar = (string) ($row->avatar_url ?? '');
        if (class_exists('GROWL_Avatar_Service')) {
            $avatar = GROWL_Avatar_Service::get_instance()->fast_url_for_agent((object) [
                'agent_id'   => (string) ($row->provider_agent_id ?? ''),
                'avatar_url' => (string) ($row->avatar_url ?? ''),
            ]);
        }
        $wallet = trim((string) ($row->wallet_address ?? ''));
        $said_verified = false;
        $identity_url  = '';
        if ($wallet !== '' && class_exists('EarnFi_Said_Identity')) {
            $said = EarnFi_Said_Identity::get_instance();
            $said_verified = $said->verified_from_agent_row($row)
                || strtolower((string) ($row->identity_provider ?? '')) === 'said';
            if (!$said_verified) {
                if (is_array($said_flags)) {
                    $said_verified = !empty($said_flags[$wallet]);
                } else {
                    $flags = $said->batch_verified_flags([$wallet], [$wallet => $row]);
                    $said_verified = !empty($flags[$wallet]);
                }
            }
            if ($said_verified) {
                $identity_url = EarnFi_Said_Identity::public_profile_url($wallet);
            }
        } elseif (strtolower((string) ($row->identity_provider ?? '')) === 'said') {
            $said_verified = true;
        }
        return [
            'order_id'            => (int) $row->order_id,
            'public_slug'         => (string) $row->public_slug,
            'service_id'          => (int) $row->service_id,
            'service_title'       => (string) ($row->service_title ?? ''),
            'provider_agent_id'   => (string) $row->provider_agent_id,
            'agent_name'          => (string) ($row->agent_name ?? ''),
            'avatar_url'          => $avatar,
            'wallet_address'      => $wallet,
            'identity_provider'   => (string) ($row->identity_provider ?? ''),
            'identity_profile_url'=> $identity_url,
            'said_verified'       => (bool) $said_verified,
            'buyer_type'          => (string) $row->buyer_type,
            'buyer_ref'           => (string) $row->buyer_ref,
            'amount_micro'        => (string) $row->amount_micro,
            'amount_display'      => Growl_Money::to_display($row->amount_micro),
            'delivery_type'       => (string) $row->delivery_type,
            'status'              => (string) $row->status,
            'revision_count'      => (int) ($row->revision_count ?? 0),
            'revision_allowance'  => (int) ($row->revision_allowance ?? 2),
            'sla_due_at'          => $row->sla_due_at ? (string) $row->sla_due_at : null,
            'overdue'             => (bool) $overdue,
            'created_at'          => (string) $row->created_at,
            'output_schema'       => !empty($row->output_schema_json) ? json_decode($row->output_schema_json, true) : null,
        ];
    }

    private function present_order($row, $secret = '', $actor_type = '', $actor_ref = '', ?array $said_flags = null) {
        $out = $this->format_order($row, $said_flags);
        $is_buyer = $actor_type !== '' && (string) $row->buyer_type === (string) $actor_type && (string) $row->buyer_ref === (string) $actor_ref;
        $is_provider = $actor_type === 'agent' && (string) $row->provider_agent_id === (string) $actor_ref;
        $secret_ok = $secret !== '' && hash_equals((string) $row->access_secret_hash, hash('sha256', $secret));
        if ($is_buyer || $is_provider || $secret_ok) {
            $out['input']  = $row->input_json ? json_decode($row->input_json, true) : null;
            $out['output'] = $row->output_json ? json_decode($row->output_json, true) : null;
        } else {
            // Public / unauthenticated viewers: no buyer identity, truncate provider wallet.
            unset($out['buyer_ref']);
            $w = (string) ($out['wallet_address'] ?? '');
            if (strlen($w) > 8) {
                $out['wallet_address'] = substr($w, 0, 4) . '…' . substr($w, -4);
            }
            unset($out['output_schema']);
        }
        return $out;
    }

    /**
     * Batch SAID verification for a list of order rows (avoids N+1).
     *
     * @param array<int,object> $rows
     * @return array<string,bool>
     */
    private function batch_said_flags_for_orders($rows) {
        $map = [];
        foreach ($rows ?: [] as $row) {
            $w = trim((string) ($row->wallet_address ?? ''));
            if ($w !== '') {
                $map[$w] = $row;
            }
        }
        if ($map === [] || !class_exists('EarnFi_Said_Identity')) {
            return [];
        }
        return EarnFi_Said_Identity::get_instance()->batch_verified_flags(array_keys($map), $map);
    }

    private function bump_completed($order) {
        $p = $this->db->table_prefix;
        $this->db->wpdb->query($this->db->wpdb->prepare(
            "UPDATE {$p}agent_services SET completed_count = completed_count + 1 WHERE service_id = %d",
            (int) $order->service_id
        ));
        $this->db->wpdb->query($this->db->wpdb->prepare(
            "UPDATE {$p}ai_agents SET completed_orders = completed_orders + 1 WHERE agent_id = %s",
            (string) $order->provider_agent_id
        ));
    }

    /**
     * Enqueue SAID feedback for a provider agent wallet (public for review hook).
     */
    public function enqueue_said_for_order_slug($order_slug, $provider_agent_id, $outcome, array $auth = []) {
        $agent = $this->db->get_row(
            "SELECT wallet_address FROM {$this->db->table_prefix}ai_agents WHERE agent_id = %s LIMIT 1",
            [(string) $provider_agent_id]
        );
        $wallet = trim((string) ($agent->wallet_address ?? ''));
        if ($wallet === '') {
            return false;
        }
        require_once __DIR__ . '/class-growl-said-feedback-queue.php';
        return (new Growl_Said_Feedback_Queue($this->db))->enqueue(
            $wallet,
            (string) $order_slug,
            $outcome === 'negative' ? 'negative' : 'positive',
            $auth
        );
    }

    private function enqueue_said($order, array $auth = []) {
        $agent = $this->db->get_row(
            "SELECT wallet_address FROM {$this->db->table_prefix}ai_agents WHERE agent_id = %s LIMIT 1",
            [(string) $order->provider_agent_id]
        );
        $wallet = trim((string) ($agent->wallet_address ?? ''));
        if ($wallet === '') {
            return;
        }
        require_once __DIR__ . '/class-growl-said-feedback-queue.php';
        (new Growl_Said_Feedback_Queue($this->db))->enqueue(
            $wallet,
            (string) $order->public_slug,
            'positive',
            $auth
        );
    }

    private function webhook($order_id, $event_key) {
        require_once __DIR__ . '/class-growl-agent-order-webhook.php';
        $wh = new Growl_Agent_Order_Webhook($this->db);
        $wh->enqueue($order_id, $event_key);
        // Fire now so autonomous agents are pinged within the request, not only on cron.
        $wh->deliver_pending((int) $order_id, $event_key);
    }

    private function event($slug, $key, $actor_type = 'system', $actor_ref = '') {
        require_once __DIR__ . '/class-growl-work-event-service.php';
        (new Growl_Work_Event_Service($this->db))->record('agent_order', $slug, $key, $actor_type, $actor_ref);
    }

    private function open_thread($slug) {
        require_once __DIR__ . '/class-growl-work-thread-service.php';
        (new Growl_Work_Thread_Service($this->db))->get_or_create('agent_order', $slug);
    }

    private function post_system($slug, $body) {
        require_once __DIR__ . '/class-growl-work-thread-service.php';
        $threads = new Growl_Work_Thread_Service($this->db);
        $tid = $threads->get_or_create('agent_order', $slug);
        if (!is_wp_error($tid)) {
            $threads->post($tid, 'system', 'system', $body, 'system');
        }
    }

    /**
     * Sum agent_balance_ledger (credits positive, withdraws negative). O(1) indexed aggregate.
     *
     * @return array{agent_id:string,available_micro:string,available_display:string,wallet_address:string}
     */
    public function get_agent_balance($agent_id) {
        $agent_id = sanitize_text_field((string) $agent_id);
        $sum = $this->db->get_var(
            "SELECT COALESCE(SUM(amount_micro), 0) FROM {$this->db->table_prefix}agent_balance_ledger WHERE agent_id = %s",
            [$agent_id]
        );
        $micro = Growl_Money::assert_micro($sum);
        $wallet = (string) $this->db->get_var(
            "SELECT wallet_address FROM {$this->db->table_prefix}ai_agents WHERE agent_id = %s LIMIT 1",
            [$agent_id]
        );
        return [
            'agent_id'           => $agent_id,
            'available_micro'    => $micro,
            'available_display'  => Growl_Money::to_display($micro),
            'wallet_address'     => $wallet,
        ];
    }

    /**
     * Debit agent ledger and send USDC on-chain to the agent's registered Solana wallet.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function withdraw_agent_to_wallet($agent_id, $amount_micro, $idempotency_key = '') {
        $agent_id = sanitize_text_field((string) $agent_id);
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        if ($agent_id === '' || !Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid', 'Invalid withdraw request.', ['status' => 400]);
        }
        if (Growl_Money::cmp($amount_micro, '10000') < 0) {
            return new WP_Error('invalid_amount', 'Minimum withdraw is $0.01.', ['status' => 400]);
        }

        $agent = $this->db->get_row(
            "SELECT agent_id, wallet_address FROM {$this->db->table_prefix}ai_agents WHERE agent_id = %s LIMIT 1",
            [$agent_id]
        );
        if (!$agent) {
            return new WP_Error('not_found', 'Agent not found.', ['status' => 404]);
        }
        $destination = trim((string) ($agent->wallet_address ?? ''));
        if ($destination === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $destination)) {
            return new WP_Error('wallet_required', 'Agent has no valid Solana wallet address.', ['status' => 400]);
        }

        $bal = $this->get_agent_balance($agent_id);
        if (Growl_Money::cmp($bal['available_micro'], $amount_micro) < 0) {
            return new WP_Error('insufficient', 'Insufficient agent balance.', ['status' => 402]);
        }

        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key);
        if ($idem === '') {
            $idem = 'agent_wallet_withdraw:' . $agent_id . ':' . $amount_micro . ':' . bin2hex(random_bytes(6));
        }
        $exists = $this->db->get_var(
            "SELECT ledger_id FROM {$this->db->table_prefix}agent_balance_ledger WHERE idempotency_key = %s LIMIT 1",
            [$idem]
        );
        if ($exists) {
            $out = $this->get_agent_balance($agent_id);
            $out['idempotent'] = true;
            return $out;
        }

        if (!class_exists('GROWL_Wallet_Manager', false)) {
            require_once GROWL_PLUGIN_PATH . 'includes/class-wallet-manager.php';
        }
        $wallet_manager = new GROWL_Wallet_Manager();
        if (!$wallet_manager->validate_wallet_address($destination)) {
            return new WP_Error('invalid_wallet', 'Invalid agent wallet address.', ['status' => 400]);
        }

        $amount_usd = (float) Growl_Money::to_usdc_string($amount_micro);
        if (!class_exists('GROWL_GasFeeService', false)) {
            require_once GROWL_PLUGIN_PATH . 'includes/class-gas-fee-service.php';
        }
        $gas_fee = (new GROWL_GasFeeService())->get_current_gas_fee()['gas_fee'];
        if ($gas_fee < 0.0000055) {
            $gas_fee = 0.0000055;
        }
        if (!class_exists('GROWL_Currency', false)) {
            require_once GROWL_PLUGIN_PATH . 'includes/class-currency.php';
        }
        $sol_price = (new GROWL_Currency())->get_sol_exchange_rate();
        $gas_fee_usd = $sol_price > 0 ? $gas_fee * $sol_price : 0;
        $net_amount = max(0, $amount_usd - $gas_fee_usd);
        if ($net_amount <= 0) {
            return new WP_Error('invalid_amount', 'Amount is too small after network fees.', ['status' => 400]);
        }

        $linked_user_id = (int) $this->db->get_var(
            "SELECT user_id FROM {$this->db->table_prefix}users WHERE LOWER(wallet_address) = LOWER(%s) LIMIT 1",
            [$destination]
        );

        $ledger_id = $this->db->insert('agent_balance_ledger', [
            'agent_id'        => $agent_id,
            'amount_micro'    => Growl_Money::sub('0', $amount_micro),
            'entry_type'      => 'debit',
            'purpose'         => 'agent_wallet_withdraw',
            'ref_type'        => 'wallet',
            'ref_id'          => $destination,
            'idempotency_key' => $idem,
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ledger_id) {
            return new WP_Error('withdraw_failed', 'Could not record withdraw.', ['status' => 500]);
        }

        $result = $wallet_manager->process_withdrawal(
            $linked_user_id > 0 ? $linked_user_id : 0,
            $net_amount,
            $destination,
            (int) $ledger_id
        );

        if (empty($result['success'])) {
            $tx_hash = isset($result['transaction_hash']) ? trim((string) $result['transaction_hash']) : '';
            if ($tx_hash !== '') {
                $verify = $wallet_manager->verify_transaction_on_chain($tx_hash, [
                    'max_retries'     => 4,
                    'retry_delay_sec' => 2,
                    'context'         => 'agent_wallet_withdraw_after_signer_fail',
                ]);
                if (!empty($verify['success'])) {
                    $out = $this->get_agent_balance($agent_id);
                    $out['ok'] = true;
                    $out['withdrawn_micro'] = $amount_micro;
                    $out['withdrawn_display'] = Growl_Money::to_display($amount_micro);
                    $out['net_amount'] = $net_amount;
                    $out['transaction_hash'] = $tx_hash;
                    $out['destination_wallet'] = $destination;
                    return $out;
                }
            }
            $this->db->insert('agent_balance_ledger', [
                'agent_id'        => $agent_id,
                'amount_micro'    => $amount_micro,
                'entry_type'      => 'credit',
                'purpose'         => 'agent_wallet_withdraw_reversal',
                'ref_type'        => 'wallet',
                'ref_id'          => $destination,
                'idempotency_key' => $idem . ':rev',
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            $msg = isset($result['error']) ? (string) $result['error'] : (isset($result['message']) ? (string) $result['message'] : 'Withdraw could not be completed.');
            return new WP_Error('withdraw_failed', $msg, ['status' => 500]);
        }

        $out = $this->get_agent_balance($agent_id);
        $out['ok'] = true;
        $out['withdrawn_micro'] = $amount_micro;
        $out['withdrawn_display'] = Growl_Money::to_display($amount_micro);
        $out['net_amount'] = $net_amount;
        $out['transaction_hash'] = (string) ($result['transaction_hash'] ?? '');
        $out['destination_wallet'] = $destination;
        return $out;
    }

    /**
     * Disputes where this agent is buyer or provider on an agent_order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_disputes_for_agent($agent_id, $limit = 20, $offset = 0) {
        $agent_id = sanitize_text_field((string) $agent_id);
        $limit = max(1, min(50, (int) $limit));
        $offset = max(0, (int) $offset);
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT d.dispute_id, d.ref_type, d.ref_id, d.reason, d.status AS dispute_status,
                    d.created_at AS dispute_created_at, d.updated_at AS dispute_updated_at,
                    o.order_id, o.public_slug, o.status AS order_status, o.amount_micro,
                    o.provider_agent_id, o.buyer_type, o.buyer_ref, s.title AS service_title
             FROM {$p}work_disputes d
             INNER JOIN {$p}agent_orders o
                ON d.ref_type = 'agent_order' AND d.ref_id = o.public_slug
             LEFT JOIN {$p}agent_services s ON s.service_id = o.service_id
             WHERE o.provider_agent_id = %s
                OR (o.buyer_type = 'agent' AND o.buyer_ref = %s)
             ORDER BY d.created_at DESC
             LIMIT %d OFFSET %d",
            [$agent_id, $agent_id, $limit, $offset]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = [
                'dispute_id'     => (int) $row->dispute_id,
                'ref_type'       => (string) $row->ref_type,
                'ref_id'         => (string) $row->ref_id,
                'reason'         => (string) $row->reason,
                'status'         => (string) $row->dispute_status,
                'created_at'     => (string) $row->dispute_created_at,
                'updated_at'     => (string) $row->dispute_updated_at,
                'order_id'       => (int) $row->order_id,
                'public_slug'    => (string) $row->public_slug,
                'order_status'   => (string) $row->order_status,
                'amount_micro'   => Growl_Money::assert_micro($row->amount_micro),
                'amount_display' => Growl_Money::to_display($row->amount_micro),
                'title'          => (string) ($row->service_title ?? ''),
                'role'           => ((string) $row->provider_agent_id === $agent_id) ? 'provider' : 'buyer',
            ];
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null|WP_Error
     */
    public function get_dispute_for_agent($dispute_id, $agent_id) {
        $dispute_id = (int) $dispute_id;
        $agent_id = sanitize_text_field((string) $agent_id);
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT d.dispute_id, d.ref_type, d.ref_id, d.reason, d.status AS dispute_status,
                    d.created_at AS dispute_created_at, d.updated_at AS dispute_updated_at,
                    o.order_id, o.public_slug, o.status AS order_status, o.amount_micro,
                    o.provider_agent_id, o.buyer_type, o.buyer_ref, s.title AS service_title
             FROM {$p}work_disputes d
             INNER JOIN {$p}agent_orders o
                ON d.ref_type = 'agent_order' AND d.ref_id = o.public_slug
             LEFT JOIN {$p}agent_services s ON s.service_id = o.service_id
             WHERE d.dispute_id = %d
               AND (o.provider_agent_id = %s OR (o.buyer_type = 'agent' AND o.buyer_ref = %s))
             LIMIT 1",
            [$dispute_id, $agent_id, $agent_id]
        );
        if (!$row) {
            return null;
        }
        return [
            'dispute_id'     => (int) $row->dispute_id,
            'ref_type'       => (string) $row->ref_type,
            'ref_id'         => (string) $row->ref_id,
            'reason'         => (string) $row->reason,
            'status'         => (string) $row->dispute_status,
            'created_at'     => (string) $row->dispute_created_at,
            'updated_at'     => (string) $row->dispute_updated_at,
            'order_id'       => (int) $row->order_id,
            'public_slug'    => (string) $row->public_slug,
            'order_status'   => (string) $row->order_status,
            'amount_micro'   => Growl_Money::assert_micro($row->amount_micro),
            'amount_display' => Growl_Money::to_display($row->amount_micro),
            'title'          => (string) ($row->service_title ?? ''),
            'role'           => ((string) $row->provider_agent_id === $agent_id) ? 'provider' : 'buyer',
        ];
    }

    /**
     * Move agent ledger balance into the linked human EarnFi balance (wallet match).
     * Prefer {@see withdraw_agent_to_wallet} for on-chain payout to the agent wallet.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function withdraw_agent_to_user($agent_id, $user_id, $amount_micro, $idempotency_key = '') {
        $agent_id = sanitize_text_field((string) $agent_id);
        $user_id = (int) $user_id;
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        if ($user_id < 1 || !Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid', 'Invalid withdraw request.', ['status' => 400]);
        }

        $agent = $this->db->get_row(
            "SELECT agent_id, wallet_address FROM {$this->db->table_prefix}ai_agents WHERE agent_id = %s LIMIT 1",
            [$agent_id]
        );
        if (!$agent) {
            return new WP_Error('not_found', 'Agent not found.', ['status' => 404]);
        }
        $user = $this->db->get_row(
            "SELECT user_id, wallet_address FROM {$this->db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$user_id]
        );
        if (!$user || empty($user->wallet_address) || empty($agent->wallet_address)
            || !hash_equals(strtolower((string) $user->wallet_address), strtolower((string) $agent->wallet_address))) {
            return new WP_Error('forbidden', 'Only the wallet-linked owner can withdraw agent earnings.', ['status' => 403]);
        }

        $bal = $this->get_agent_balance($agent_id);
        if (Growl_Money::cmp($bal['available_micro'], $amount_micro) < 0) {
            return new WP_Error('insufficient', 'Insufficient agent balance.', ['status' => 402]);
        }

        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key) ?: ('agent_withdraw:' . $agent_id . ':' . $amount_micro . ':' . time());
        $exists = $this->db->get_var(
            "SELECT ledger_id FROM {$this->db->table_prefix}agent_balance_ledger WHERE idempotency_key = %s LIMIT 1",
            [$idem]
        );
        if ($exists) {
            return $this->get_agent_balance($agent_id);
        }

        $this->db->begin_transaction();
        try {
            $this->db->insert('agent_balance_ledger', [
                'agent_id'        => $agent_id,
                'amount_micro'    => Growl_Money::sub('0', $amount_micro),
                'entry_type'      => 'debit',
                'purpose'         => 'agent_withdraw',
                'ref_type'        => 'user',
                'ref_id'          => (string) $user_id,
                'idempotency_key' => $idem,
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            $credit = $this->balance->apply_ledger_entry([
                'user_id'               => $user_id,
                'delta_available_micro' => $amount_micro,
                'delta_escrow_micro'    => '0',
                'entry_type'            => 'agent_withdraw',
                'purpose'               => 'agent_withdraw',
                'ref_type'              => 'agent',
                'ref_id'                => $agent_id,
                'idempotency_key'       => $idem . ':user',
            ], false);
            if (is_wp_error($credit)) {
                $this->db->rollback();
                return $credit;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('withdraw_failed', 'Withdraw failed.', ['status' => 500]);
        }

        $out = $this->get_agent_balance($agent_id);
        $out['withdrawn_micro'] = $amount_micro;
        $out['withdrawn_display'] = Growl_Money::to_display($amount_micro);
        return $out;
    }
}
