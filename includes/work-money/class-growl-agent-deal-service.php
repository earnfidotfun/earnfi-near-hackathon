<?php
/**
 * Agent-native escrow deals (human | agent parties). Separate from human Deals.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Agent_Deal_Service {

    public const STATUSES = [
        'draft', 'pending_funding', 'funded', 'delivered', 'released', 'disputed', 'refunded', 'cancelled',
    ];

    /** @var GROWL_Database */
    private $db;

    /** @var Growl_Balance_Service */
    private $balance;

    /** @var Growl_X402_Payment_Service */
    private $payments;

    public function __construct(?GROWL_Database $db = null) {
        $this->db       = $db ?: new GROWL_Database();
        $this->balance  = new Growl_Balance_Service($this->db);
        $this->payments = new Growl_X402_Payment_Service($this->db);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|WP_Error
     */
    public function create_draft($creator_type, $creator_ref, array $data) {
        $creator_type = $creator_type === 'agent' ? 'agent' : 'human';
        $creator_ref  = sanitize_text_field((string) $creator_ref);
        $role         = sanitize_key((string) ($data['created_by_role'] ?? 'seller'));
        if (!in_array($role, ['seller', 'buyer'], true)) {
            $role = 'seller';
        }
        $kind = sanitize_key((string) ($data['kind'] ?? 'service'));
        if (!in_array($kind, ['service', 'digital', 'physical', 'currency'], true)) {
            $kind = 'service';
        }
        $amount_micro = Growl_Money::resolve_amount_micro($data);
        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        $scope = sanitize_textarea_field((string) ($data['scope_text'] ?? ''));
        if ($title === '' || !Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid', 'Title and amount are required.', ['status' => 400]);
        }

        $seller_type = sanitize_key((string) ($data['seller_type'] ?? ($role === 'seller' ? $creator_type : '')));
        $seller_ref  = sanitize_text_field((string) ($data['seller_ref'] ?? ($role === 'seller' ? $creator_ref : '')));
        $buyer_type  = sanitize_key((string) ($data['buyer_type'] ?? ($role === 'buyer' ? $creator_type : '')));
        $buyer_ref   = sanitize_text_field((string) ($data['buyer_ref'] ?? ($role === 'buyer' ? $creator_ref : '')));

        if ($role === 'seller') {
            $seller_type = $creator_type;
            $seller_ref  = $creator_ref;
        } else {
            $buyer_type = $creator_type;
            $buyer_ref  = $creator_ref;
        }
        if ($seller_ref === '' || !in_array($seller_type, ['human', 'agent'], true)) {
            return new WP_Error('invalid', 'Seller party is required.', ['status' => 400]);
        }
        if ($buyer_ref !== '' && !in_array($buyer_type, ['human', 'agent'], true)) {
            return new WP_Error('invalid', 'Invalid buyer party.', ['status' => 400]);
        }
        if ($buyer_ref !== '' && $seller_type === $buyer_type && $seller_ref === $buyer_ref) {
            return new WP_Error('invalid', 'Buyer and seller must differ.', ['status' => 400]);
        }

        if (!class_exists('Growl_Work_Fee_Engine')) {
            require_once __DIR__ . '/class-growl-work-fee-engine.php';
        }
        $fee_quote = Growl_Work_Fee_Engine::quote('deals');
        $inspection = Growl_Deal_Service::inspection_seconds_from_data($data);
        $slug = $this->slug();
        $invite = bin2hex(random_bytes(16));
        $has_buyer = $buyer_ref !== '';
        $row = [
            'public_slug'       => $slug,
            'created_by_type'   => $creator_type,
            'created_by_ref'    => $creator_ref,
            'created_by_role'   => $role,
            'seller_type'       => $seller_type,
            'seller_ref'        => $seller_ref,
            'buyer_type'        => $has_buyer ? $buyer_type : null,
            'buyer_ref'         => $has_buyer ? $buyer_ref : null,
            'kind'              => $kind,
            'title'             => mb_substr($title, 0, 200),
            'scope_text'        => $scope,
            'amount_micro'      => $amount_micro,
            'currency'          => 'USDC',
            'status'            => $has_buyer ? 'pending_funding' : 'draft',
            'platform_fee_bps'  => (int) $fee_quote['earner_bps'],
            'employer_fee_bps'  => (int) $fee_quote['employer_bps'],
            'invite_token'      => $invite,
            'inspection_seconds'=> $inspection > 0 ? $inspection : null,
            'created_at'        => gmdate('Y-m-d H:i:s'),
            'updated_at'        => gmdate('Y-m-d H:i:s'),
        ];
        if (!$this->db->insert('agent_deals', $row)) {
            return new WP_Error('create_failed', 'Could not create deal.', ['status' => 500]);
        }
        $deal_id = (int) $this->db->wpdb->insert_id;
        require_once __DIR__ . '/class-growl-work-thread-service.php';
        (new Growl_Work_Thread_Service($this->db))->get_or_create('agent_deal', $slug);
        return $this->get_by_id($deal_id, $creator_type, $creator_ref);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function accept($deal_id, $party_type, $party_ref, $role, $invite_token = '') {
        $party_type = $party_type === 'agent' ? 'agent' : 'human';
        $party_ref  = sanitize_text_field((string) $party_ref);
        $role       = sanitize_key((string) $role);
        if (!in_array($role, ['buyer', 'seller'], true)) {
            return new WP_Error('invalid', 'Role must be buyer or seller.', ['status' => 400]);
        }
        $deal = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}agent_deals WHERE agent_deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal || !in_array($deal->status, ['draft', 'pending_funding'], true)) {
            return new WP_Error('invalid_state', 'This deal cannot be joined.', ['status' => 400]);
        }
        if ($invite_token === '' || !hash_equals((string) ($deal->invite_token ?? ''), sanitize_text_field($invite_token))) {
            return new WP_Error('forbidden', 'A valid invite is required to join this deal.', ['status' => 403]);
        }
        if ($role === 'buyer') {
            if (!empty($deal->buyer_ref)) {
                return new WP_Error('invalid_state', 'Buyer already set.', ['status' => 400]);
            }
            if ($party_type === $deal->seller_type && $party_ref === $deal->seller_ref) {
                return new WP_Error('invalid', 'You cannot be both parties.', ['status' => 400]);
            }
            $this->db->update('agent_deals', [
                'buyer_type' => $party_type,
                'buyer_ref'  => $party_ref,
                'status'     => 'pending_funding',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['agent_deal_id' => (int) $deal_id]);
        } else {
            if (!empty($deal->seller_ref) && !($deal->seller_type === $party_type && $deal->seller_ref === $party_ref)) {
                if ($deal->seller_ref !== '0' && $deal->seller_ref !== '') {
                    return new WP_Error('invalid_state', 'Seller already set.', ['status' => 400]);
                }
            }
            if ($party_type === $deal->buyer_type && $party_ref === (string) $deal->buyer_ref) {
                return new WP_Error('invalid', 'You cannot be both parties.', ['status' => 400]);
            }
            $this->db->update('agent_deals', [
                'seller_type' => $party_type,
                'seller_ref'  => $party_ref,
                'status'      => !empty($deal->buyer_ref) ? 'pending_funding' : 'draft',
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ], ['agent_deal_id' => (int) $deal_id]);
        }
        return $this->get_by_id($deal_id, $party_type, $party_ref);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function fund($deal_id, $buyer_type, $buyer_ref, $source, $settlement_id = null, $idem = '') {
        $buyer_type = $buyer_type === 'agent' ? 'agent' : 'human';
        $buyer_ref  = sanitize_text_field((string) $buyer_ref);
        $deal = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}agent_deals WHERE agent_deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal || $deal->status !== 'pending_funding') {
            return new WP_Error('invalid_state', 'This deal is not awaiting payment.', ['status' => 400]);
        }
        if ($deal->buyer_type !== $buyer_type || (string) $deal->buyer_ref !== $buyer_ref) {
            return new WP_Error('forbidden', 'Only the buyer can fund.', ['status' => 403]);
        }
        $amount = $this->deposit_micro($deal);
        $ref    = (string) $deal->public_slug;
        $idem   = Growl_Money::sanitize_idempotency_key($idem) ?: ('agent_deal_fund:' . $deal_id);

        $this->db->begin_transaction();
        try {
            $buyer_user_id = $buyer_type === 'human' ? (int) $buyer_ref : 0;
            if (($source === 'balance' || $source === 'creator_wallet') && $buyer_type === 'human') {
                if ($source === 'creator_wallet') {
                    $settlement_id = $this->payments->settle_from_creator_wallet(
                        $buyer_user_id, $amount, 'agent_deal_fund', 'agent_deal', $ref, $idem
                    );
                } else {
                    $settlement_id = $this->payments->settle_from_balance(
                        $buyer_user_id, $amount, 'agent_deal_fund', 'agent_deal', $ref, $idem
                    );
                }
                if (is_wp_error($settlement_id)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($settlement_id);
                }
                $lock = $this->balance->lock_escrow(
                    $buyer_user_id, $amount, 'agent_deal_fund', 'agent_deal', $ref, $idem . ':lock', (int) $settlement_id, false
                );
                if (is_wp_error($lock)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($lock);
                }
            } elseif ($settlement_id) {
                $settlement_id = (int) $settlement_id;
                if ($buyer_type === 'human') {
                    $lock = $this->balance->lock_escrow(
                        $buyer_user_id, $amount, 'agent_deal_fund', 'agent_deal', $ref, $idem . ':lock', $settlement_id, false
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

            $this->db->insert('escrow_ledger', [
                'ref_type'        => 'agent_deal',
                'ref_id'          => $ref,
                'buyer_type'      => $buyer_type,
                'buyer_ref'       => $buyer_ref,
                'seller_type'     => (string) $deal->seller_type,
                'seller_ref'      => (string) $deal->seller_ref,
                'amount_micro'    => $amount,
                'status'          => 'locked',
                'settlement_id'   => $settlement_id,
                'idempotency_key' => $idem . ':escrow',
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            $this->db->update('agent_deals', [
                'status'             => 'funded',
                'fund_settlement_id' => $settlement_id,
                'updated_at'         => gmdate('Y-m-d H:i:s'),
            ], ['agent_deal_id' => (int) $deal_id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('fund_failed', 'Payment could not be completed.', ['status' => 500]);
        }
        $out = $this->get_by_id($deal_id, $buyer_type, $buyer_ref);
        $this->notify_party($deal, 'seller', 'Deal funded', 'Payment is held. You can deliver the work.', 'success');
        return $out;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function deliver($deal_id, $seller_type, $seller_ref, $note = '', $payload = '') {
        $seller_type = $seller_type === 'agent' ? 'agent' : 'human';
        $seller_ref  = sanitize_text_field((string) $seller_ref);
        $deal = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}agent_deals WHERE agent_deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal || $deal->seller_type !== $seller_type || (string) $deal->seller_ref !== $seller_ref) {
            return new WP_Error('forbidden', 'Only the seller can deliver.', ['status' => 403]);
        }
        if ($deal->status !== 'funded') {
            return new WP_Error('invalid_state', 'Not ready for delivery.', ['status' => 400]);
        }
        $note = sanitize_textarea_field((string) $note);
        $payload = sanitize_textarea_field((string) $payload);
        $deliverable = trim($note . ($payload !== '' ? "\n" . $payload : ''));
        $this->db->update('agent_deals', [
            'status'          => 'delivered',
            'deliverable_text'=> mb_substr($deliverable, 0, 8000),
            'auto_release_at' => $this->auto_release_at($deal),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
        ], ['agent_deal_id' => (int) $deal_id]);
        $out = $this->get_by_id($deal_id, $seller_type, $seller_ref);
        $this->notify_party($deal, 'buyer', 'Work delivered', 'Review and release payment if it matches the scope.', 'info');
        return $out;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function release($deal_id, $buyer_type, $buyer_ref, $idem = '') {
        $buyer_type = $buyer_type === 'agent' ? 'agent' : 'human';
        $buyer_ref  = sanitize_text_field((string) $buyer_ref);
        $deal = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}agent_deals WHERE agent_deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal || $deal->buyer_type !== $buyer_type || (string) $deal->buyer_ref !== $buyer_ref) {
            return new WP_Error('forbidden', 'Only the buyer can release.', ['status' => 403]);
        }
        if (!in_array($deal->status, ['delivered', 'funded', 'disputed'], true)) {
            return new WP_Error('invalid_state', 'Cannot release yet.', ['status' => 400]);
        }
        if (!class_exists('Growl_Work_Fee_Engine')) {
            require_once __DIR__ . '/class-growl-work-fee-engine.php';
        }
        $split = Growl_Work_Fee_Engine::split_for_gross($deal->amount_micro, 'deals');
        $deposit = $this->deposit_micro($deal);
        $net = (string) $split['net_to_earner_micro'];
        $ref = (string) $deal->public_slug;
        $idem = Growl_Money::sanitize_idempotency_key($idem) ?: ('agent_deal_release:' . $deal_id);
        $buyer_user_id = $buyer_type === 'human' ? (int) $buyer_ref : 0;
        $seller_user_id = $deal->seller_type === 'human' ? (int) $deal->seller_ref : 0;
        $from_dispute = $deal->status === 'disputed';

        $this->db->begin_transaction();
        try {
            if ($deal->seller_type === 'human' && $seller_user_id > 0) {
                $rel = $this->balance->release_escrow_split(
                    $buyer_user_id,
                    $seller_user_id,
                    $deposit,
                    $net,
                    (string) $split['platform_fee_micro'],
                    'agent_deal',
                    $ref,
                    $idem
                );
                if (is_wp_error($rel)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($rel);
                }
            } elseif ($deal->seller_type === 'agent') {
                if ($buyer_type === 'human' && $buyer_user_id > 0) {
                    $rel = $this->balance->release_escrow_to_seller(
                        $buyer_user_id,
                        0,
                        $deposit,
                        (int) $split['earner_bps'],
                        'agent_deal',
                        $ref,
                        $idem
                    );
                    if (is_wp_error($rel)) {
                        $this->db->rollback();
                        return Growl_X402_Payment_Service::public_error($rel);
                    }
                } else {
                    // Agent→agent: escrow may be x402-settled; still book platform take.
                    $fee = Growl_Money::assert_micro($split['platform_fee_micro'] ?? '0');
                    if (Growl_Money::is_positive($fee)) {
                        $fee_ok = $this->balance->credit_platform_fee(
                            $fee,
                            'agent_deal',
                            $ref,
                            $idem . ':plat'
                        );
                        if (is_wp_error($fee_ok)) {
                            $this->db->rollback();
                            return Growl_X402_Payment_Service::public_error($fee_ok);
                        }
                    }
                }
                $this->credit_agent_ledger($deal, (int) $deal->fund_settlement_id, $idem, $net);
            }
            $p = $this->db->table_prefix;
            $this->db->query(
                "UPDATE {$p}escrow_ledger SET status = 'released', updated_at = %s
                 WHERE ref_type = 'agent_deal' AND ref_id = %s AND status IN ('locked','disputed') LIMIT 1",
                [gmdate('Y-m-d H:i:s'), $ref]
            );
            $now = gmdate('Y-m-d H:i:s');
            $this->db->update('agent_deals', [
                'status'     => 'released',
                'updated_at' => $now,
            ], ['agent_deal_id' => (int) $deal_id]);
            if ($from_dispute) {
                $this->db->query(
                    "UPDATE {$p}work_disputes SET status = 'closed', updated_at = %s
                     WHERE ref_type = 'agent_deal' AND ref_id = %s AND status = 'open'",
                    [$now, $ref]
                );
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('release_failed', 'Release failed.', ['status' => 500]);
        }
        $out = $this->get_by_id($deal_id, $buyer_type, $buyer_ref);
        $this->notify_party($deal, 'seller', 'Payment released', 'Funds are available.', 'success');
        $this->prompt_rating($deal, $buyer_type, $buyer_ref);
        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once __DIR__ . '/class-growl-work-receipt-service.php';
        }
        (new Growl_Work_Receipt_Service($this->db))->upsert_from_ref('agent_deal', (string) $deal->public_slug);
        return $out;
    }

    /**
     * Refund a disputed or funded agent-deal to the human buyer.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function refund($deal_id, $buyer_type, $buyer_ref, $idem = '') {
        $buyer_type = $buyer_type === 'agent' ? 'agent' : 'human';
        $buyer_ref  = sanitize_text_field((string) $buyer_ref);
        if ($buyer_type !== 'human') {
            return new WP_Error('unsupported', 'Only human buyers can receive refunds here.', ['status' => 400]);
        }
        $deal = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}agent_deals WHERE agent_deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal || $deal->buyer_type !== $buyer_type || (string) $deal->buyer_ref !== $buyer_ref) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        if (!in_array($deal->status, ['funded', 'delivered', 'disputed'], true)) {
            return new WP_Error('invalid_state', 'This deal cannot be refunded.', ['status' => 400]);
        }
        $deposit = $this->deposit_micro($deal);
        $ref = (string) $deal->public_slug;
        $idem = Growl_Money::sanitize_idempotency_key($idem) ?: ('agent_deal_refund:' . $deal_id);
        $from_dispute = $deal->status === 'disputed';
        $buyer_user_id = (int) $buyer_ref;

        $this->db->begin_transaction();
        try {
            $refund = $this->balance->refund_escrow_to_buyer(
                $buyer_user_id,
                $deposit,
                'agent_deal',
                $ref,
                $idem,
                $from_dispute
            );
            if (is_wp_error($refund)) {
                $this->db->rollback();
                return Growl_X402_Payment_Service::public_error($refund);
            }
            $p = $this->db->table_prefix;
            $now = gmdate('Y-m-d H:i:s');
            $this->db->query(
                "UPDATE {$p}escrow_ledger SET status = 'refunded', updated_at = %s
                 WHERE ref_type = 'agent_deal' AND ref_id = %s AND status IN ('locked','disputed') LIMIT 1",
                [$now, $ref]
            );
            $this->db->query(
                "UPDATE {$p}work_disputes SET status = 'closed', updated_at = %s
                 WHERE ref_type = 'agent_deal' AND ref_id = %s AND status = 'open'",
                [$now, $ref]
            );
            $this->db->update('agent_deals', [
                'status'     => 'refunded',
                'updated_at' => $now,
            ], ['agent_deal_id' => (int) $deal_id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('refund_failed', 'Refund failed.', ['status' => 500]);
        }
        return $this->get_by_id($deal_id, $buyer_type, $buyer_ref);
    }

    public function get_by_slug($slug, $actor_type = '', $actor_ref = '') {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}agent_deals WHERE public_slug = %s LIMIT 1",
            [sanitize_text_field($slug)]
        );
        return $row ? $this->present($row, $actor_type, $actor_ref) : null;
    }

    public function get_by_id($deal_id, $actor_type = '', $actor_ref = '') {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}agent_deals WHERE agent_deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        return $row ? $this->present($row, $actor_type, $actor_ref) : null;
    }

    /**
     * Auto-release delivered agent-deals past inspection window.
     */
    public function auto_release_due($limit = 25) {
        $limit = min(50, max(1, (int) $limit));
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT agent_deal_id, buyer_type, buyer_ref FROM {$p}agent_deals
             WHERE status = 'delivered' AND auto_release_at IS NOT NULL AND auto_release_at <= UTC_TIMESTAMP()
             ORDER BY auto_release_at ASC LIMIT %d",
            [$limit]
        );
        $n = 0;
        foreach ($rows ?: [] as $row) {
            $res = $this->release(
                (int) $row->agent_deal_id,
                (string) $row->buyer_type,
                (string) $row->buyer_ref,
                'auto_release:' . $row->agent_deal_id
            );
            if (!is_wp_error($res)) {
                $n++;
            }
        }
        return $n;
    }

    public function list_for_party($party_type, $party_ref, $limit = 20, $offset = 0) {
        $party_type = $party_type === 'agent' ? 'agent' : 'human';
        $party_ref  = sanitize_text_field((string) $party_ref);
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT * FROM {$p}agent_deals
             WHERE (seller_type = %s AND seller_ref = %s) OR (buyer_type = %s AND buyer_ref = %s)
             ORDER BY updated_at DESC LIMIT %d OFFSET %d",
            [$party_type, $party_ref, $party_type, $party_ref, $limit, $offset]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->present($row, $party_type, $party_ref);
        }
        return $out;
    }

    private function deposit_micro($deal) {
        if (!class_exists('Growl_Work_Fee_Engine')) {
            require_once __DIR__ . '/class-growl-work-fee-engine.php';
        }
        $split = Growl_Work_Fee_Engine::split_for_gross($deal->amount_micro, 'deals');
        return (string) $split['deposit_micro'];
    }

    private function auto_release_at($deal) {
        $sec = (int) ($deal->inspection_seconds ?? 0);
        if ($sec < 1) {
            $sec = 86400;
        }
        return gmdate('Y-m-d H:i:s', time() + $sec);
    }

    private function credit_agent_ledger($deal, $settlement_id, $idem, $amount_micro) {
        $key = $idem . ':agent';
        $exists = $this->db->get_var(
            "SELECT ledger_id FROM {$this->db->table_prefix}agent_balance_ledger WHERE idempotency_key = %s LIMIT 1",
            [$key]
        );
        if ($exists) {
            return;
        }
        $this->db->insert('agent_balance_ledger', [
            'agent_id'        => (string) $deal->seller_ref,
            'amount_micro'    => Growl_Money::assert_micro($amount_micro),
            'entry_type'      => 'credit',
            'purpose'         => 'agent_deal_release',
            'ref_type'        => 'agent_deal',
            'ref_id'          => (string) $deal->public_slug,
            'settlement_id'   => $settlement_id ?: null,
            'idempotency_key' => $key,
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function notify_party($deal, $side, $title, $message, $type) {
        if (!class_exists('Growl_Work_Notify')) {
            return;
        }
        $type_key = $side === 'buyer' ? $deal->buyer_type : $deal->seller_type;
        $ref = $side === 'buyer' ? $deal->buyer_ref : $deal->seller_ref;
        if ($type_key === 'human' && (int) $ref > 0) {
            Growl_Work_Notify::human((int) $ref, $title, $message, 'agent-deal/' . $deal->public_slug, $type);
        }
    }

    private function prompt_rating($deal, $buyer_type, $buyer_ref) {
        if (!class_exists('Growl_Work_Notify')) {
            require_once __DIR__ . '/class-growl-work-notify.php';
        }
        Growl_Work_Notify::prompt_work_rating('agent_deal', (string) $deal->public_slug, $buyer_type, $buyer_ref);
        Growl_Work_Notify::prompt_work_rating(
            'agent_deal',
            (string) $deal->public_slug,
            (string) $deal->seller_type,
            (string) $deal->seller_ref
        );
    }

    private function slug() {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        do {
            $s = '';
            for ($i = 0; $i < 12; $i++) {
                $s .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while ($this->db->get_var(
            "SELECT 1 FROM {$this->db->table_prefix}agent_deals WHERE public_slug = %s LIMIT 1",
            [$s]
        ));
        return $s;
    }

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    private function present($row, $actor_type = '', $actor_ref = '') {
        $deposit = $this->deposit_micro($row);
        $out = [
            'agent_deal_id'   => (int) $row->agent_deal_id,
            'public_slug'     => (string) $row->public_slug,
            'title'           => (string) $row->title,
            'scope_text'      => (string) ($row->scope_text ?? ''),
            'kind'            => (string) $row->kind,
            'status'          => (string) $row->status,
            'amount_micro'    => (string) $row->amount_micro,
            'amount_display'  => Growl_Money::to_display($row->amount_micro),
            'deposit_display' => Growl_Money::to_display($deposit),
            'seller_type'     => (string) $row->seller_type,
            'seller_ref'      => (string) $row->seller_ref,
            'buyer_type'      => (string) ($row->buyer_type ?? ''),
            'buyer_ref'       => (string) ($row->buyer_ref ?? ''),
            'deliverable_text'=> (string) ($row->deliverable_text ?? ''),
            'created_at'      => (string) $row->created_at,
            'updated_at'      => (string) $row->updated_at,
        ];
        $is_party = ($actor_type !== '' && $actor_ref !== ''
            && (((string) $row->seller_type === $actor_type && (string) $row->seller_ref === (string) $actor_ref)
            || ((string) $row->buyer_type === $actor_type && (string) $row->buyer_ref === (string) $actor_ref)));
        if ($is_party && !empty($row->invite_token)) {
            $out['invite_token'] = (string) $row->invite_token;
            if (function_exists('earnfi_spa_url')) {
                $out['invite_url'] = earnfi_spa_url('agent-deal/' . $row->public_slug . '?invite=' . urlencode($row->invite_token));
            }
        }
        if (!$is_party) {
            // Non-parties only see public summary — no scope/deliverable or party refs.
            unset($out['scope_text'], $out['deliverable_text'], $out['buyer_ref'], $out['seller_ref']);
        }
        return $out;
    }
}
