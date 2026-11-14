<?php
/**
 * Compound work orders: parent budget envelope + child tasks/orders.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Work_Order_Service {

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

    public function create($user_id, array $data) {
        $title  = sanitize_text_field((string) ($data['title'] ?? ''));
        $budget = Growl_Money::to_micro($data['budget'] ?? $data['budget_micro'] ?? '0');
        $steps  = isset($data['steps']) && is_array($data['steps']) ? $data['steps'] : [];
        if ($title === '' || !Growl_Money::is_positive($budget) || $steps === []) {
            return new WP_Error('invalid', 'Title, budget, and at least one step are required.', ['status' => 400]);
        }
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $slug  = '';
        for ($i = 0; $i < 12; $i++) {
            $slug .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $ok = $this->db->insert('work_orders', [
            'public_slug'    => $slug,
            'buyer_user_id'  => (int) $user_id,
            'title'          => mb_substr($title, 0, 200),
            'budget_micro'   => $budget,
            'spent_micro'    => '0',
            'status'         => 'draft',
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            return new WP_Error('create_failed', 'Could not create work order.', ['status' => 500]);
        }
        $wo_id = (int) $this->db->wpdb->insert_id;
        $sort  = 0;
        foreach ($steps as $step) {
            $amt = Growl_Money::resolve_amount_micro($step);
            $type = sanitize_key((string) ($step['type'] ?? 'task'));
            $ref  = 'pending';
            $meta = [];
            if (!empty($step['service_id'])) {
                $meta['service_id'] = (int) $step['service_id'];
            }
            if (!empty($step['seller_user_id'])) {
                $meta['seller_user_id'] = (int) $step['seller_user_id'];
            }
            if (!empty($step['title'])) {
                $meta['title'] = sanitize_text_field((string) $step['title']);
            }
            if (!empty($step['scope'])) {
                $meta['scope'] = sanitize_textarea_field((string) $step['scope']);
            }
            if (!empty($step['input']) && is_array($step['input'])) {
                $meta['input'] = $step['input'];
            }
            if ($meta !== []) {
                $ref = wp_json_encode($meta);
            } elseif (!empty($step['ref'])) {
                $ref = sanitize_text_field((string) $step['ref']);
            }
            $this->db->insert('work_order_children', [
                'work_order_id' => $wo_id,
                'child_type'    => $type,
                'child_ref'     => $ref,
                'amount_micro'  => $amt,
                'status'        => 'pending',
                'sort_order'    => $sort++,
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]);
        }
        return $this->get($wo_id);
    }

    public function fund($work_order_id, $user_id, $source, $settlement_id = null, $idem = '') {
        $wo = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}work_orders WHERE work_order_id = %d LIMIT 1",
            [(int) $work_order_id]
        );
        if (!$wo || (int) $wo->buyer_user_id !== (int) $user_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        if ($wo->status !== 'draft') {
            return new WP_Error('invalid_state', 'This work order is not awaiting payment.', ['status' => 400]);
        }
        $amount = (string) $wo->budget_micro;
        $ref    = (string) $wo->public_slug;
        $idem   = Growl_Money::sanitize_idempotency_key($idem) ?: 'work_order:' . $work_order_id;
        if ($source === 'balance') {
            $settlement_id = $this->payments->settle_from_balance(
                $user_id, $amount, 'campaign_fund', 'work_order', $ref, $idem
            );
            if (is_wp_error($settlement_id)) {
                return Growl_X402_Payment_Service::public_error($settlement_id);
            }
            $lock = $this->balance->lock_escrow(
                $user_id, $amount, 'campaign_fund', 'work_order', $ref, $idem . ':lock', $settlement_id
            );
            if (is_wp_error($lock)) {
                return Growl_X402_Payment_Service::public_error($lock);
            }
        } elseif (!$settlement_id) {
            return new WP_Error('payment_required', 'Payment is required.', ['status' => 402]);
        }
        $this->db->update('work_orders', [
            'status'             => 'running',
            'fund_settlement_id' => (int) $settlement_id,
            'updated_at'         => gmdate('Y-m-d H:i:s'),
        ], ['work_order_id' => (int) $work_order_id]);
        $this->spawn_children((int) $work_order_id, (int) $user_id);
        return $this->get($work_order_id);
    }

    /**
     * Spawn child deals or agent orders from pending step rows (one write per child, no extra reads per step).
     */
    private function spawn_children($work_order_id, $user_id) {
        $children = $this->db->get_results(
            "SELECT * FROM {$this->db->table_prefix}work_order_children WHERE work_order_id = %d ORDER BY sort_order ASC",
            [(int) $work_order_id]
        );
        if (!$children) {
            return;
        }
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-deal-service.php';
        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
        $deals  = new Growl_Deal_Service($this->db);
        $orders = new Growl_Agent_Order_Service($this->db);
        foreach ($children as $c) {
            if ((string) $c->child_ref !== 'pending' && (string) $c->status !== 'pending') {
                continue;
            }
            $type = (string) $c->child_type;
            $meta = [];
            if (!empty($c->child_ref) && $c->child_ref !== 'pending' && strpos((string) $c->child_ref, '{') === 0) {
                $decoded = json_decode((string) $c->child_ref, true);
                $meta = is_array($decoded) ? $decoded : [];
            }
            if ($type === 'agent_order' && !empty($meta['service_id'])) {
                $order = $orders->create_order('human', (string) $user_id, (int) $meta['service_id'], is_array($meta['input'] ?? null) ? $meta['input'] : []);
                if (!is_wp_error($order)) {
                    $this->db->update('work_order_children', [
                        'child_ref' => (string) $order['order_id'],
                        'status'    => 'running',
                    ], ['child_id' => (int) $c->child_id]);
                }
            } elseif ($type === 'deal') {
                $deal = $deals->create_draft([
                    'seller_user_id'  => (int) ($meta['seller_user_id'] ?? 0),
                    'buyer_user_id'   => (int) $user_id,
                    'created_by_role' => 'buyer',
                    'title'           => sanitize_text_field((string) ($meta['title'] ?? 'Work order step')),
                    'scope_text'      => sanitize_textarea_field((string) ($meta['scope'] ?? '')),
                    'amount_micro'    => (string) $c->amount_micro,
                ]);
                if (!is_wp_error($deal) && !empty($deal['deal_id'])) {
                    $this->db->update('work_order_children', [
                        'child_ref' => (string) $deal['deal_id'],
                        'status'    => 'running',
                    ], ['child_id' => (int) $c->child_id]);
                }
            } else {
                $this->db->update('work_order_children', ['status' => 'running'], ['child_id' => (int) $c->child_id]);
            }
        }
    }

    public function get($work_order_id, $user_id = 0) {
        $wo = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}work_orders WHERE work_order_id = %d LIMIT 1",
            [(int) $work_order_id]
        );
        if (!$wo) {
            return null;
        }
        if ((int) $user_id > 0 && (int) $wo->buyer_user_id !== (int) $user_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        $p = $this->db->table_prefix;
        $children = $this->db->get_results(
            "SELECT c.*, d.status AS deal_status, o.status AS order_status
             FROM {$p}work_order_children c
             LEFT JOIN {$p}deals d ON c.child_type = 'deal' AND d.deal_id = CAST(c.child_ref AS UNSIGNED)
             LEFT JOIN {$p}agent_orders o ON c.child_type = 'agent_order' AND o.order_id = CAST(c.child_ref AS UNSIGNED)
             WHERE c.work_order_id = %d
             ORDER BY c.sort_order ASC",
            [(int) $work_order_id]
        );
        $steps = [];
        $all_done = true;
        foreach ($children ?: [] as $c) {
            $live = (string) $c->status;
            if (!empty($c->deal_status) && in_array($c->deal_status, ['released', 'refunded'], true)) {
                $live = 'complete';
            } elseif (!empty($c->order_status) && in_array($c->order_status, ['released', 'refunded'], true)) {
                $live = 'complete';
            }
            $steps[] = [
                'child_id'       => (int) $c->child_id,
                'type'           => (string) $c->child_type,
                'ref'            => (string) $c->child_ref,
                'amount_display' => Growl_Money::to_display($c->amount_micro),
                'status'         => $live,
            ];
            if (!in_array($live, ['complete', 'released', 'terminal'], true)) {
                $all_done = false;
            }
        }
        $status = (string) $wo->status;
        if ($all_done && $status === 'running' && $children) {
            $status = 'complete';
        }
        return [
            'work_order_id'   => (int) $wo->work_order_id,
            'public_slug'     => (string) $wo->public_slug,
            'title'           => (string) $wo->title,
            'budget_display'  => Growl_Money::to_display($wo->budget_micro),
            'status'          => $status,
            'steps'           => $steps,
        ];
    }

    public function complete_due($limit = 25) {
        $limit = max(1, min(50, (int) $limit));
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT wo.work_order_id
             FROM {$p}work_orders wo
             WHERE wo.status = 'running'
               AND NOT EXISTS (
                    SELECT 1 FROM {$p}work_order_children c
                    LEFT JOIN {$p}deals d ON c.child_type = 'deal' AND d.deal_id = CAST(c.child_ref AS UNSIGNED)
                    LEFT JOIN {$p}agent_orders o ON c.child_type = 'agent_order' AND o.order_id = CAST(c.child_ref AS UNSIGNED)
                    WHERE c.work_order_id = wo.work_order_id
                      AND NOT (
                        c.status IN ('complete','released','terminal')
                        OR d.status IN ('released','refunded')
                        OR o.status IN ('released','refunded')
                      )
               )
             ORDER BY wo.work_order_id ASC
             LIMIT %d",
            [$limit]
        );
        $n = 0;
        foreach ($rows ?: [] as $row) {
            $wo = $this->db->get_row(
                "SELECT * FROM {$p}work_orders WHERE work_order_id = %d LIMIT 1",
                [(int) $row->work_order_id]
            );
            if ($wo) {
                $this->maybe_complete_parent($wo);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Persist parent completion + receipt once. Safe to call from GET after JOIN shows all children terminal.
     */
    private function maybe_complete_parent($wo) {
        $updated = $this->db->query(
            "UPDATE {$this->db->table_prefix}work_orders SET status = 'complete', updated_at = %s
             WHERE work_order_id = %d AND status = 'running'",
            [gmdate('Y-m-d H:i:s'), (int) $wo->work_order_id]
        );
        if (!$updated) {
            return;
        }
        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once __DIR__ . '/class-growl-work-receipt-service.php';
        }
        (new Growl_Work_Receipt_Service($this->db))->upsert_from_ref('work_order', (string) $wo->public_slug);
    }
}
