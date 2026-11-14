<?php
/**
 * Deal link state machine and escrow orchestration.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Growl_Money', false)) {
    require_once dirname(__DIR__) . '/money/class-growl-money.php';
}
if (!class_exists('Growl_Balance_Service', false)) {
    require_once __DIR__ . '/class-growl-balance-service.php';
}
if (!class_exists('Growl_X402_Payment_Service', false)) {
    require_once __DIR__ . '/class-growl-x402-payment-service.php';
}

class Growl_Deal_Service {

    public const STATUSES = [
        'draft', 'pending_funding', 'funded', 'delivered', 'released', 'disputed', 'refunded', 'expired', 'cancelled',
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

    public function generate_slug() {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        do {
            $slug = '';
            for ($i = 0; $i < 12; $i++) {
                $slug .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $exists = $this->db->get_var(
                "SELECT deal_id FROM {$this->db->table_prefix}deals WHERE public_slug = %s LIMIT 1",
                [$slug]
            );
        } while ($exists);
        return $slug;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|WP_Error
     */
    public static function kinds() {
        return ['service', 'digital', 'physical', 'currency'];
    }

    /**
     * Map inspection_window / inspection_seconds to allowed seconds (0 if unset/invalid).
     *
     * @param array<string,mixed> $data
     */
    public static function inspection_seconds_from_data(array $data) {
        $allowed = [86400, 259200, 432000, 604800];
        $windows = [
            '24h' => 86400,
            '3d'  => 259200,
            '5d'  => 432000,
            '7d'  => 604800,
        ];
        if (isset($data['inspection_seconds']) && $data['inspection_seconds'] !== '' && $data['inspection_seconds'] !== null) {
            $sec = (int) $data['inspection_seconds'];
            if (in_array($sec, $allowed, true)) {
                return $sec;
            }
        }
        $window = sanitize_key((string) ($data['inspection_window'] ?? ''));
        return isset($windows[$window]) ? (int) $windows[$window] : 0;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public static function normalize_deal_meta(array $data) {
        $p2p = $data['p2p'] ?? $data['p2p_meta'] ?? null;
        if (!is_array($p2p) || empty($p2p)) {
            return null;
        }
        $methods = [];
        foreach ((array) ($p2p['payment_methods'] ?? []) as $m) {
            $m = sanitize_text_field((string) $m);
            if ($m !== '') {
                $methods[] = $m;
            }
        }
        return [
            'p2p' => [
                'offer_currency'   => sanitize_text_field((string) ($p2p['offer_currency'] ?? 'USDC')),
                'receive_currency' => sanitize_text_field((string) ($p2p['receive_currency'] ?? '')),
                'exchange_rate'    => sanitize_text_field((string) ($p2p['exchange_rate'] ?? '')),
                'rate_type'        => in_array(($p2p['rate_type'] ?? 'fixed'), ['fixed', 'market'], true) ? $p2p['rate_type'] : 'fixed',
                'region'           => sanitize_text_field((string) ($p2p['region'] ?? '')),
                'payment_methods'  => array_values(array_unique($methods)),
                'payment_details'  => sanitize_textarea_field((string) ($p2p['payment_details'] ?? '')),
                'delivery_side'    => sanitize_key((string) ($p2p['delivery_side'] ?? 'seller')),
            ],
        ];
    }

    /**
     * @param object $deal
     * @return array<string,mixed>|null
     */
    private function decode_deal_meta($deal) {
        $raw = $deal->deal_meta_json ?? null;
        if (!$raw) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || empty($decoded['p2p'])) {
            return null;
        }
        return $decoded['p2p'];
    }

    public function create_draft(array $data) {
        $seller_id = (int) ($data['seller_user_id'] ?? 0);
        $buyer_id  = isset($data['buyer_user_id']) ? (int) $data['buyer_user_id'] : 0;
        $created_by = sanitize_key((string) ($data['created_by_role'] ?? 'seller'));
        if (!in_array($created_by, ['seller', 'buyer'], true)) {
            $created_by = 'seller';
        }
        $kind = sanitize_key((string) ($data['kind'] ?? 'service'));
        if (!in_array($kind, self::kinds(), true)) {
            $kind = 'service';
        }
        $amount_micro = Growl_Money::resolve_amount_micro($data);
        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        $scope = sanitize_textarea_field((string) ($data['scope_text'] ?? ''));

        if ($title === '' || !Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid_deal', 'Title and amount are required.', ['status' => 400]);
        }
        if ($created_by === 'seller' && $seller_id < 1) {
            return new WP_Error('invalid_deal', 'Seller is required.', ['status' => 400]);
        }
        if ($created_by === 'buyer' && $buyer_id < 1) {
            return new WP_Error('invalid_deal', 'Buyer is required for buyer-created deals.', ['status' => 400]);
        }
        if ($seller_id > 0 && $buyer_id > 0 && $seller_id === $buyer_id) {
            return new WP_Error('invalid_deal', 'You cannot create a deal with yourself.', ['status' => 400]);
        }

        $has_seller = $seller_id > 0;
        $has_buyer  = $buyer_id > 0;
        $slug = $this->generate_slug();
        if (!class_exists('Growl_Work_Fee_Engine')) {
            require_once __DIR__ . '/class-growl-work-fee-engine.php';
        }
        $fee_quote = Growl_Work_Fee_Engine::quote('deals');
        $fee_bps = (int) $fee_quote['earner_bps']; // snapshot earner-side cut on release
        $invite = bin2hex(random_bytes(16));
        $inspection_seconds = self::inspection_seconds_from_data($data);

        $row = [
            'public_slug'      => $slug,
            'seller_user_id'   => $has_seller ? $seller_id : 0,
            'buyer_user_id'    => $has_buyer ? $buyer_id : null,
            'created_by_role'  => $created_by,
            'kind'             => $kind,
            'title'            => mb_substr($title, 0, 200),
            'scope_text'       => $scope,
            'amount_micro'     => $amount_micro,
            'currency'         => 'USDC',
            'status'           => ($has_seller && $has_buyer) ? 'pending_funding' : 'draft',
            'platform_fee_bps' => $fee_bps,
            'invite_token'     => $invite,
            'employer_fee_bps' => (int) $fee_quote['employer_bps'],
            'deadline_at'      => isset($data['deadline_at']) && $data['deadline_at'] ? sanitize_text_field((string) $data['deadline_at']) : null,
            'created_at'       => gmdate('Y-m-d H:i:s'),
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ];
        if ($inspection_seconds > 0) {
            $row['inspection_seconds'] = $inspection_seconds;
        }
        $meta = self::normalize_deal_meta($data);
        if ($meta !== null) {
            $row['deal_meta_json'] = wp_json_encode($meta);
        }

        if (!$this->db->insert('deals', $row)) {
            $fallback = $row;
            unset($fallback['kind'], $fallback['invite_token'], $fallback['employer_fee_bps'], $fallback['inspection_seconds']);
            if (!$this->db->insert('deals', $fallback)) {
                return new WP_Error('create_failed', 'Could not create deal.', ['status' => 500]);
            }
        }

        $deal_id = (int) $this->db->wpdb->insert_id;
        // Creator is always a party — include invite for share URL.
        $viewer = $created_by === 'buyer' ? $buyer_id : $seller_id;
        $deal = $this->get_deal_by_id($deal_id, $viewer > 0 ? $viewer : null);
        if ($deal && !empty($deal['public_slug'])) {
            require_once __DIR__ . '/class-growl-work-thread-service.php';
            (new Growl_Work_Thread_Service($this->db))->get_or_create('deal', (string) $deal['public_slug']);
        }
        return $deal;
    }

    /**
     * @return object|null
     */
    public function get_deal_by_slug($slug) {
        $slug = sanitize_text_field((string) $slug);
        return $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}deals WHERE public_slug = %s LIMIT 1",
            [$slug]
        );
    }

    /**
     * @param int|null $viewer_user_id
     * @return array<string,mixed>|null
     */
    public function get_deal_by_id($deal_id, $viewer_user_id = null) {
        $deal = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}deals WHERE deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        return $deal ? $this->format_deal($deal, $viewer_user_id) : null;
    }

    /**
     * Deposit = gross + employer fee (snapshot bps).
     *
     * @param object $deal
     * @return array{gross_micro:string,employer_fee_micro:string,deposit_micro:string,earner_fee_micro:string,platform_fee_micro:string,net_to_earner_micro:string}
     */
    public function economics_for_deal($deal) {
        $gross = Growl_Money::assert_micro($deal->amount_micro);
        $employer_bps = isset($deal->employer_fee_bps) ? (int) $deal->employer_fee_bps : 0;
        $earner_bps = (int) ($deal->platform_fee_bps ?? 0);
        $employer_fee = Growl_Money::fee_micro($gross, $employer_bps);
        $earner_fee = Growl_Money::fee_micro($gross, $earner_bps);
        return [
            'gross_micro'           => $gross,
            'employer_fee_micro'    => $employer_fee,
            'employer_fee_display'  => Growl_Money::to_display($employer_fee),
            'earner_fee_micro'      => $earner_fee,
            'earner_fee_display'    => Growl_Money::to_display($earner_fee),
            'deposit_micro'         => Growl_Money::add($gross, $employer_fee),
            'deposit_display'       => Growl_Money::to_display(Growl_Money::add($gross, $employer_fee)),
            'platform_fee_micro'    => Growl_Money::add($employer_fee, $earner_fee),
            'platform_fee_display'  => Growl_Money::to_display(Growl_Money::add($employer_fee, $earner_fee)),
            'net_to_earner_micro'   => Growl_Money::sub($gross, $earner_fee),
            'net_to_earner_display' => Growl_Money::to_display(Growl_Money::sub($gross, $earner_fee)),
            'employer_bps'          => $employer_bps,
            'earner_bps'            => $earner_bps,
        ];
    }

    /**
     * @param object $deal
     * @param int|null $viewer_user_id When set, invite_token is included only for buyer/seller parties.
     * @return array<string,mixed>
     */
    public function format_deal($deal, $viewer_user_id = null) {
        $seller_id = (int) $deal->seller_user_id;
        $buyer_id  = $deal->buyer_user_id ? (int) $deal->buyer_user_id : 0;
        $kind = isset($deal->kind) ? (string) $deal->kind : 'service';
        if (!in_array($kind, self::kinds(), true)) {
            $kind = 'service';
        }
        $econ = $this->economics_for_deal($deal);
        $invite = isset($deal->invite_token) ? (string) $deal->invite_token : '';
        $viewer = $viewer_user_id !== null ? (int) $viewer_user_id : 0;
        $is_party = $viewer > 0 && (
            ($seller_id > 0 && $viewer === $seller_id) ||
            ($buyer_id > 0 && $viewer === $buyer_id) ||
            // Creator may still be alone on a draft (other side empty)
            (($seller_id < 1 || $buyer_id < 1) && (
                ((string) $deal->created_by_role === 'seller' && $viewer === $seller_id) ||
                ((string) $deal->created_by_role === 'buyer' && $viewer === $buyer_id)
            ))
        );
        $out = [
            'deal_id'           => (int) $deal->deal_id,
            'public_slug'       => (string) $deal->public_slug,
            'url'               => function_exists('earnfi_spa_url') ? earnfi_spa_url('deal/' . $deal->public_slug) : home_url('/deal/' . $deal->public_slug),
            'seller_user_id'    => $seller_id > 0 ? $seller_id : null,
            'buyer_user_id'     => $buyer_id > 0 ? $buyer_id : null,
            'created_by_role'   => (string) $deal->created_by_role,
            'kind'              => $kind,
            'title'             => (string) $deal->title,
            'scope_text'        => (string) $deal->scope_text,
            'amount_micro'      => (string) $deal->amount_micro,
            'amount_display'    => Growl_Money::to_display($deal->amount_micro),
            'deposit_micro'     => $econ['deposit_micro'],
            'deposit_display'   => $econ['deposit_display'],
            'currency'          => (string) $deal->currency,
            'status'            => (string) $deal->status,
            'deadline_at'       => $deal->deadline_at,
            'inspection_seconds'=> isset($deal->inspection_seconds) ? (int) $deal->inspection_seconds : null,
            'fund_settlement_id'=> $deal->fund_settlement_id ? (int) $deal->fund_settlement_id : null,
            'platform_fee_bps'  => (int) $deal->platform_fee_bps,
            'employer_fee_bps'  => $econ['employer_bps'],
            'fee_preview'       => $econ,
            'created_at'        => (string) $deal->created_at,
            'updated_at'        => (string) $deal->updated_at,
            'history'           => $this->build_history($deal),
            'latest_delivery'   => $this->latest_delivery((int) $deal->deal_id),
            'p2p'               => $this->decode_deal_meta($deal),
        ];
        if ($is_party && $invite !== '') {
            $out['invite_token'] = $invite;
            $out['invite_url'] = (function_exists('earnfi_spa_url') ? earnfi_spa_url('deal/' . $deal->public_slug) : home_url('/deal/' . $deal->public_slug))
                . '?invite=' . rawurlencode($invite);
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latest_delivery($deal_id) {
        $row = $this->db->get_row(
            "SELECT note, file_url, submitted_at FROM {$this->db->table_prefix}deal_deliverables WHERE deal_id = %d ORDER BY deliverable_id DESC LIMIT 1",
            [(int) $deal_id]
        );
        if (!$row) {
            return null;
        }
        return [
            'note'         => (string) ($row->note ?? ''),
            'file_url'     => (string) ($row->file_url ?? ''),
            'submitted_at' => (string) ($row->submitted_at ?? ''),
        ];
    }

    /**
     * @param object $deal
     * @return list<array<string,mixed>>
     */
    private function build_history($deal) {
        $st = (string) $deal->status;
        $order = ['draft', 'pending_funding', 'funded', 'delivered', 'released'];
        $idx = array_search($st, $order, true);
        if ($st === 'disputed') {
            $idx = 2;
        }
        if ($st === 'refunded') {
            $idx = 2;
        }
        $created_by = (string) ($deal->created_by_role ?? 'seller');
        $events = [
            [
                'key'    => 'created',
                'label'  => $created_by === 'buyer' ? 'Buyer opened this deal' : 'Seller opened this deal',
                'detail' => 'Protected escrow link created. Share it with the other party.',
                'at'     => (string) $deal->created_at,
                'icon'   => 'file-text',
                'done'   => true,
            ],
        ];
        $has_buyer = !empty($deal->buyer_user_id) && (int) $deal->buyer_user_id > 0;
        $has_seller = !empty($deal->seller_user_id) && (int) $deal->seller_user_id > 0;
        if ($has_buyer && $has_seller) {
            $events[] = [
                'key'    => 'joined',
                'label'  => 'Both parties confirmed',
                'detail' => 'Buyer and seller are assigned. Payment can be secured.',
                'at'     => (string) $deal->updated_at,
                'icon'   => 'users',
                'done'   => true,
            ];
        }
        $funded = is_int($idx) ? $idx >= 2 : in_array($st, ['funded', 'delivered', 'released', 'disputed', 'refunded'], true);
        $events[] = [
            'key'    => 'funded',
            'label'  => 'Payment secured',
            'detail' => 'Buyer funds are held by EarnFi until release or refund.',
            'at'     => $funded ? (string) $deal->updated_at : null,
            'icon'   => 'shield-check',
            'done'   => $funded,
        ];
        $delivery = $this->latest_delivery((int) $deal->deal_id);
        $delivered = in_array($st, ['delivered', 'released'], true) || $delivery;
        $events[] = [
            'key'    => 'delivered',
            'label'  => 'Delivery submitted',
            'detail' => $delivery && $delivery['note'] ? $delivery['note'] : 'Seller marked the deal as delivered.',
            'at'     => $delivery['submitted_at'] ?? ($delivered ? (string) $deal->updated_at : null),
            'icon'   => 'package-check',
            'done'   => (bool) $delivered,
        ];
        $events[] = [
            'key'    => 'released',
            'label'  => 'Payment released',
            'detail' => 'Buyer approved. Funds moved to the seller balance.',
            'at'     => $st === 'released' ? (string) $deal->updated_at : null,
            'icon'   => 'badge-check',
            'done'   => $st === 'released',
        ];
        if ($st === 'disputed') {
            $events[] = [
                'key'    => 'disputed',
                'label'  => 'Dispute opened',
                'detail' => 'Funds stay held until the dispute is resolved.',
                'at'     => (string) $deal->updated_at,
                'icon'   => 'gavel',
                'done'   => true,
            ];
        }
        if ($st === 'refunded') {
            $events[] = [
                'key'    => 'refunded',
                'label'  => 'Refunded to buyer',
                'detail' => 'Escrow was returned to the buyer.',
                'at'     => (string) $deal->updated_at,
                'icon'   => 'undo-2',
                'done'   => true,
            ];
        }
        return $events;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function accept_deal($deal_id, $user_id, $role, $invite_token = '') {
        $deal = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}deals WHERE deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal || $deal->status !== 'draft') {
            return new WP_Error('invalid_state', 'This deal cannot be accepted.', ['status' => 400]);
        }

        $stored_invite = isset($deal->invite_token) ? (string) $deal->invite_token : '';
        if ($stored_invite !== '') {
            if (!hash_equals($stored_invite, (string) $invite_token)) {
                return new WP_Error('forbidden', 'Invalid or missing invite link.', ['status' => 403]);
            }
        }

        $uid    = (int) $user_id;
        $seller = (int) $deal->seller_user_id;
        $buyer  = $deal->buyer_user_id ? (int) $deal->buyer_user_id : 0;
        $updates = ['updated_at' => gmdate('Y-m-d H:i:s')];

        if ($role === 'buyer') {
            if ($seller === $uid) {
                return new WP_Error('invalid_deal', 'You cannot join your own deal as the buyer.', ['status' => 400]);
            }
            if ($buyer > 0 && $buyer !== $uid) {
                return new WP_Error('invalid_deal', 'This deal already has a buyer.', ['status' => 400]);
            }
            $updates['buyer_user_id'] = $uid;
            $buyer = $uid;
        } elseif ($role === 'seller') {
            if ($buyer === $uid) {
                return new WP_Error('invalid_deal', 'You cannot join your own deal as the seller.', ['status' => 400]);
            }
            if ($seller > 0 && $seller !== $uid) {
                return new WP_Error('invalid_deal', 'This deal already has a seller.', ['status' => 400]);
            }
            $updates['seller_user_id'] = $uid;
            $seller = $uid;
        } else {
            return new WP_Error('invalid_deal', 'Choose buyer or seller.', ['status' => 400]);
        }

        if ($seller > 0 && $buyer > 0) {
            $updates['status'] = 'pending_funding';
        }

        $this->db->update('deals', $updates, ['deal_id' => (int) $deal_id]);
        $out = $this->get_deal_by_id($deal_id);
        $notify = $role === 'buyer' ? $seller : $buyer;
        if ($notify > 0) {
            $this->announce($out, 'deal_accepted', $notify, 'Deal accepted', 'The other party joined. Fund the deal to start.', 'info');
        }
        return $out;
    }

    /**
     * Cancel an open invite before the counterparty joins.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function cancel_deal($deal_id, $user_id) {
        $deal = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}deals WHERE deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        if ($deal->status !== 'draft') {
            return new WP_Error('invalid_state', 'This deal cannot be cancelled.', ['status' => 400]);
        }

        $uid    = (int) $user_id;
        $seller = (int) $deal->seller_user_id;
        $buyer  = $deal->buyer_user_id ? (int) $deal->buyer_user_id : 0;

        if ($seller > 0 && $buyer > 0) {
            return new WP_Error('invalid_state', 'Both parties have joined this deal.', ['status' => 400]);
        }
        if ($uid !== $seller && $uid !== $buyer) {
            return new WP_Error('forbidden', 'You cannot cancel this deal.', ['status' => 403]);
        }

        $this->db->update('deals', [
            'status'     => 'cancelled',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['deal_id' => (int) $deal_id]);

        return $this->get_deal_by_id($deal_id, $uid);
    }

    /**
     * Fund deal from balance or after x402 settlement id provided.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function fund_deal($deal_id, $buyer_user_id, $source, $settlement_id = null, $idempotency_key = '') {
        $deal = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}deals WHERE deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        if ((int) $deal->buyer_user_id !== (int) $buyer_user_id) {
            return new WP_Error('forbidden', 'Only the buyer can fund this deal.', ['status' => 403]);
        }
        if ($deal->status !== 'pending_funding') {
            return new WP_Error('invalid_state', 'This deal is not awaiting payment.', ['status' => 400]);
        }

        $amount = $this->economics_for_deal($deal)['deposit_micro'];
        $idem   = Growl_Money::sanitize_idempotency_key($idempotency_key) ?: 'deal_fund:' . $deal_id . ':' . $buyer_user_id;
        $ref_id = (string) $deal->public_slug;

        $this->db->begin_transaction();
        try {
            if ($source === 'balance') {
                $settlement_id = $this->payments->settle_from_balance(
                    $buyer_user_id,
                    $amount,
                    'deal_fund',
                    'deal',
                    $ref_id,
                    $idem
                );
                if (is_wp_error($settlement_id)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($settlement_id);
                }
            } elseif ($source === 'creator_wallet') {
                if (!class_exists('GROWL_Creator_Wallet', false)) {
                    require_once dirname(__DIR__) . '/class-creator-wallet.php';
                }
                $amount_usdc = (float) Growl_Money::to_usdc_string($amount);
                $debit = GROWL_Creator_Wallet::debit_paid($buyer_user_id, $amount_usdc, [
                    'entry_type' => 'deal_fund',
                    'purpose'    => 'deal_fund',
                    'deal_id'    => (int) $deal_id,
                    'ref_id'     => $ref_id,
                ]);
                if (is_wp_error($debit)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($debit);
                }

                $settlement_id = $this->payments->record_settlement([
                    'tx_hash'         => null,
                    'payer_wallet'    => '',
                    'amount_micro'    => $amount,
                    'purpose'         => 'deal_fund',
                    'ref_type'        => 'deal',
                    'ref_id'          => $ref_id,
                    'user_id'         => $buyer_user_id,
                    'source'          => 'creator_wallet',
                    'status'          => 'settled',
                    'idempotency_key' => $idem . ':settlement',
                ]);
                if (is_wp_error($settlement_id)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($settlement_id);
                }

                // Move paid Creator Wallet funds into EarnFi available, then lock like balance path.
                $credit = $this->balance->apply_ledger_entry([
                    'user_id'               => $buyer_user_id,
                    'delta_available_micro' => $amount,
                    'delta_escrow_micro'    => '0',
                    'entry_type'            => 'creator_wallet_transfer',
                    'purpose'               => 'deal_fund_creator_wallet',
                    'ref_type'              => 'deal',
                    'ref_id'                => $ref_id,
                    'settlement_id'         => (int) $settlement_id,
                    'idempotency_key'       => $idem . ':cw_credit',
                ], false);
                if (is_wp_error($credit)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($credit);
                }
            } elseif ($settlement_id) {
                $settlement_id = (int) $settlement_id;
            } else {
                $this->db->rollback();
                return new WP_Error('payment_required', 'Payment is required.', ['status' => 402]);
            }

            $lock = $this->balance->lock_escrow(
                $buyer_user_id,
                $amount,
                'deal_fund',
                'deal',
                $ref_id,
                $idem . ':lock',
                $settlement_id,
                false
            );
            if (is_wp_error($lock)) {
                $this->db->rollback();
                return Growl_X402_Payment_Service::public_error($lock);
            }

            $p = $this->db->table_prefix;
            $this->db->insert('escrow_ledger', [
                'ref_type'        => 'deal',
                'ref_id'          => $ref_id,
                'buyer_type'      => 'human',
                'buyer_ref'       => (string) $buyer_user_id,
                'seller_type'     => 'human',
                'seller_ref'      => (string) $deal->seller_user_id,
                'amount_micro'    => $amount,
                'status'          => 'locked',
                'settlement_id'   => $settlement_id,
                'idempotency_key' => $idem . ':escrow',
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            // Inspection clock starts on deliver, not fund.
            $this->db->update('deals', [
                'status'             => 'funded',
                'fund_settlement_id' => $settlement_id,
                'auto_release_at'    => null,
                'updated_at'         => gmdate('Y-m-d H:i:s'),
            ], ['deal_id' => (int) $deal_id]);

            $this->db->commit();
            $out = $this->get_deal_by_id($deal_id, $buyer_user_id);
            $this->announce($out, 'deal_funded', (int) $deal->seller_user_id, 'Deal funded', 'Payment is held. You can start the work.', 'success');
            return $out;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Growl_Deal_Service::fund_deal ' . $e->getMessage());
            return new WP_Error('fund_failed', 'Payment could not be completed.', ['status' => 500]);
        }
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function deliver($deal_id, $seller_user_id, $note = '', $file_url = '') {
        $deal = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}deals WHERE deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal || (int) $deal->seller_user_id !== (int) $seller_user_id) {
            return new WP_Error('forbidden', 'Only the seller can deliver.', ['status' => 403]);
        }
        if ($deal->status !== 'funded') {
            return new WP_Error('invalid_state', 'This deal is not ready for delivery.', ['status' => 400]);
        }

        $this->db->insert('deal_deliverables', [
            'deal_id'      => (int) $deal_id,
            'file_url'     => esc_url_raw((string) $file_url) ?: null,
            'note'         => sanitize_textarea_field((string) $note),
            'submitted_by' => (int) $seller_user_id,
            'submitted_at' => gmdate('Y-m-d H:i:s'),
        ]);

        // Start inspection clock on deliver.
        $this->db->update('deals', [
            'status'          => 'delivered',
            'auto_release_at' => $this->compute_auto_release_at($deal),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
        ], ['deal_id' => (int) $deal_id]);

        $out = $this->get_deal_by_id($deal_id);
        $this->announce($out, 'deal_delivered', (int) $deal->buyer_user_id, 'Work delivered', 'Review the work and release payment if it matches the scope.', 'info');
        return $out;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function release($deal_id, $buyer_user_id, $idempotency_key = '') {
        $deal = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}deals WHERE deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal || (int) $deal->buyer_user_id !== (int) $buyer_user_id) {
            return new WP_Error('forbidden', 'Only the buyer can release payment.', ['status' => 403]);
        }
        if (!in_array($deal->status, ['delivered', 'funded', 'disputed'], true)) {
            return new WP_Error('invalid_state', 'This deal cannot be released yet.', ['status' => 400]);
        }

        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key) ?: 'deal_release:' . $deal_id;
        $ref  = (string) $deal->public_slug;
        $employer_bps = isset($deal->employer_fee_bps) ? (int) $deal->employer_fee_bps : 0;

        $result = $this->balance->release_escrow_to_seller(
            (int) $deal->buyer_user_id,
            (int) $deal->seller_user_id,
            (string) $deal->amount_micro,
            (int) $deal->platform_fee_bps,
            'deal',
            $ref,
            $idem,
            $employer_bps
        );
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }

        $p = $this->db->table_prefix;
        $now = gmdate('Y-m-d H:i:s');
        $this->db->query(
            "UPDATE {$p}escrow_ledger SET status = 'released', updated_at = %s
             WHERE ref_type = 'deal' AND ref_id = %s AND status IN ('locked','disputed') LIMIT 1",
            [$now, $ref]
        );
        if ($deal->status === 'disputed') {
            $this->db->query(
                "UPDATE {$p}work_disputes SET status = 'closed', updated_at = %s
                 WHERE ref_type = 'deal' AND ref_id = %s AND status = 'open'",
                [$now, $ref]
            );
        }

        $this->db->update('deals', [
            'status'     => 'released',
            'updated_at' => $now,
        ], ['deal_id' => (int) $deal_id]);

        $this->create_receipt_stub($deal, $result);

        $out = $this->get_deal_by_id($deal_id, $buyer_user_id);
        $this->announce($out, 'deal_released', (int) $deal->seller_user_id, 'Payment released', 'Funds are in your EarnFi Balance.', 'success');
        if (class_exists('Growl_Work_Notify')) {
            Growl_Work_Notify::prompt_work_rating('deal', $ref, 'human', (string) $deal->buyer_user_id);
            Growl_Work_Notify::prompt_work_rating('deal', $ref, 'human', (string) $deal->seller_user_id);
        }
        if (class_exists('GROWL_Gamification') && (int) $deal->seller_user_id > 0) {
            $amt = class_exists('Growl_Money')
                ? (float) Growl_Money::to_display($deal->amount_micro)
                : 0.0;
            GROWL_Gamification::on_work_money_completion((int) $deal->seller_user_id, 'deal', $ref, $amt);
        }
        return $out;
    }

    /**
     * Open a work dispute on a funded or delivered deal.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function open_dispute($deal_id, $user_id, $reason) {
        $deal = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}deals WHERE deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        $uid = (int) $user_id;
        if ($uid !== (int) $deal->buyer_user_id && $uid !== (int) $deal->seller_user_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        if (!in_array($deal->status, ['funded', 'delivered'], true)) {
            return new WP_Error('invalid_state', 'This deal cannot be disputed.', ['status' => 400]);
        }
        $reason = sanitize_textarea_field((string) $reason);
        if ($reason === '') {
            return new WP_Error('invalid', 'A reason is required.', ['status' => 400]);
        }
        $econ = $this->economics_for_deal($deal);
        $ok = $this->db->insert('work_disputes', [
            'ref_type'        => 'deal',
            'ref_id'          => (string) $deal->public_slug,
            'opener_user_id'  => $uid,
            'reason'          => $reason,
            'status'          => 'open',
            'created_at'      => gmdate('Y-m-d H:i:s'),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            return new WP_Error('exists', 'A dispute is already open for this deal.', ['status' => 400]);
        }
        $this->balance->hold_dispute(
            (int) $deal->buyer_user_id,
            $econ['deposit_micro'],
            'deal',
            (string) $deal->public_slug,
            'deal_dispute:' . $deal_id
        );
        $this->db->update('deals', [
            'status'     => 'disputed',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['deal_id' => (int) $deal_id]);
        $p = $this->db->table_prefix;
        $this->db->query(
            "UPDATE {$p}escrow_ledger SET status = 'disputed', updated_at = %s
             WHERE ref_type = 'deal' AND ref_id = %s AND status = 'locked' LIMIT 1",
            [gmdate('Y-m-d H:i:s'), (string) $deal->public_slug]
        );
        $out = $this->get_deal_by_id($deal_id);
        $other = $uid === (int) $deal->buyer_user_id ? (int) $deal->seller_user_id : (int) $deal->buyer_user_id;
        $this->announce($out, 'deal_disputed', $other, 'Dispute opened', 'A dispute was opened on this deal.', 'warning');
        return $out;
    }

    /**
     * Refund a disputed deal to the buyer.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function refund($deal_id, $actor_user_id, $idempotency_key = '') {
        $deal = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}deals WHERE deal_id = %d LIMIT 1",
            [(int) $deal_id]
        );
        if (!$deal) {
            return new WP_Error('not_found', 'Deal not found.', ['status' => 404]);
        }
        if ((int) $actor_user_id !== (int) $deal->buyer_user_id) {
            return new WP_Error('forbidden', 'Only the buyer can refund this deal.', ['status' => 403]);
        }
        if ($deal->status !== 'disputed') {
            return new WP_Error('invalid_state', 'Refund is only available after a dispute is opened.', ['status' => 400]);
        }
        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key) ?: 'deal_refund:' . $deal_id;
        $ref  = (string) $deal->public_slug;
        $econ = $this->economics_for_deal($deal);
        $result = $this->balance->refund_escrow_to_buyer(
            (int) $deal->buyer_user_id,
            $econ['deposit_micro'],
            'deal',
            $ref,
            $idem,
            $deal->status === 'disputed'
        );
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }
        $p = $this->db->table_prefix;
        $this->db->query(
            "UPDATE {$p}escrow_ledger SET status = 'refunded', updated_at = %s
             WHERE ref_type = 'deal' AND ref_id = %s AND status IN ('locked','disputed') LIMIT 1",
            [gmdate('Y-m-d H:i:s'), $ref]
        );
        $this->db->query(
            "UPDATE {$p}work_disputes SET status = 'closed', updated_at = %s
             WHERE ref_type = 'deal' AND ref_id = %s AND status = 'open'",
            [gmdate('Y-m-d H:i:s'), $ref]
        );
        $this->db->update('deals', [
            'status'     => 'refunded',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['deal_id' => (int) $deal_id]);
        $out = $this->get_deal_by_id($deal_id, $actor_user_id);
        $other = (int) $actor_user_id === (int) $deal->buyer_user_id ? (int) $deal->seller_user_id : (int) $deal->buyer_user_id;
        $this->announce($out, 'deal_refunded', $other, 'Deal refunded', 'This deal was refunded.', 'info');
        return $out;
    }

    /**
     * @param object $deal
     * @param array<string,mixed> $release_result
     */
    private function create_receipt_stub($deal, $release_result) {
        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once __DIR__ . '/class-growl-work-receipt-service.php';
        }
        (new Growl_Work_Receipt_Service($this->db))->upsert_from_ref(
            'deal',
            (string) $deal->public_slug,
            [
                'fee_micro' => (string) ($release_result['fee_micro'] ?? '0'),
                'net_micro' => (string) ($release_result['net_micro'] ?? $deal->amount_micro),
            ]
        );
    }

    /**
     * List deals for user (single query, no N+1). Excludes legacy job-spawned deals.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_for_user($user_id, $role = 'all', $limit = 20, $offset = 0) {
        $user_id = (int) $user_id;
        $limit   = min(50, max(1, (int) $limit));
        $offset  = max(0, (int) $offset);
        $p       = $this->db->table_prefix;

        $where = '';
        $params = [];
        if ($role === 'buyer') {
            $where = 'WHERE d.buyer_user_id = %d';
            $params[] = $user_id;
        } elseif ($role === 'seller') {
            $where = 'WHERE d.seller_user_id = %d';
            $params[] = $user_id;
        } else {
            $where = 'WHERE (d.buyer_user_id = %d OR d.seller_user_id = %d)';
            $params[] = $user_id;
            $params[] = $user_id;
        }
        $params[] = $limit;
        $params[] = $offset;

        $rows = $this->db->get_results(
            "SELECT d.* FROM {$p}deals d
             LEFT JOIN {$p}job_listings jl ON jl.spawned_deal_id = d.deal_id
             {$where} AND jl.listing_id IS NULL
             ORDER BY d.updated_at DESC LIMIT %d OFFSET %d",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->format_deal($row, $user_id);
        }
        return $out;
    }

    /**
     * @param object $deal
     */
    private function compute_auto_release_at($deal) {
        if (!empty($deal->inspection_seconds) && (int) $deal->inspection_seconds > 0) {
            return gmdate('Y-m-d H:i:s', time() + (int) $deal->inspection_seconds);
        }
        if (!empty($deal->deadline_at) && strtotime($deal->deadline_at) > 0) {
            return gmdate('Y-m-d H:i:s', strtotime($deal->deadline_at . ' UTC') + 2 * DAY_IN_SECONDS);
        }
        return gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS);
    }

    /**
     * Release delivered deals past auto_release_at. One query for due rows, then existing release path.
     *
     * @return int Number released
     */
    public function auto_release_due($limit = 25) {
        $limit = max(1, min(50, (int) $limit));
        $rows = $this->db->get_results(
            "SELECT deal_id, buyer_user_id FROM {$this->db->table_prefix}deals
             WHERE status = 'delivered' AND auto_release_at IS NOT NULL AND auto_release_at <= UTC_TIMESTAMP()
             ORDER BY auto_release_at ASC LIMIT %d",
            [$limit]
        );
        $n = 0;
        foreach ($rows ?: [] as $row) {
            $res = $this->release((int) $row->deal_id, (int) $row->buyer_user_id, 'auto_release:' . $row->deal_id);
            if (!is_wp_error($res)) {
                $n++;
            }
        }
        return $n;
    }

    private function announce($deal, $event_key, $notify_user_id, $title, $message, $type = 'info') {
        if (!is_array($deal) || empty($deal['public_slug'])) {
            return;
        }
        $slug = (string) $deal['public_slug'];
        require_once __DIR__ . '/class-growl-work-event-service.php';
        (new Growl_Work_Event_Service($this->db))->record('deal', $slug, $event_key, 'system', '');
        require_once __DIR__ . '/class-growl-work-thread-service.php';
        $threads = new Growl_Work_Thread_Service($this->db);
        $tid = $threads->get_or_create('deal', $slug);
        if (!is_wp_error($tid)) {
            $threads->post($tid, 'system', 'system', $title, 'system');
        }
        if (class_exists('Growl_Work_Notify') && (int) $notify_user_id > 0) {
            Growl_Work_Notify::human((int) $notify_user_id, $title, $message, 'deal/' . $slug, $type);
        }
    }
}
