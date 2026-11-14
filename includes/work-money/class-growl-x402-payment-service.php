<?php
/**
 * x402 payment abstraction. Facilitator is an implementation detail (never user-facing).
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

class Growl_X402_Payment_Service {

    /** Work-money payment purposes (also extend PayAI_Flow allowed list). */
    public const PURPOSES = [
        'deal_fund',
        'agent_deal_fund',
        'agent_order_fund',
        'job_contract_fund',
        'campaign_fund',
        'job_listing_fee',
        'store_checkout',
        'agent_payout',
        'job_create',
        'job_update',
        'contest_boost',
        'creator_wallet_top_up',
        'direct_payment',
        'balance_deposit',
    ];

    /** @var GROWL_Database */
    private $db;

    /** @var Growl_Balance_Service */
    private $balance;

    public function __construct(?GROWL_Database $db = null) {
        $this->db      = $db ?: new GROWL_Database();
        $this->balance = new Growl_Balance_Service($this->db);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function probe($resource_url, $amount_micro, $user_id, array $metadata = []) {
        $amount_micro = (string) $amount_micro;
        if (!Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid_amount', 'Invalid payment amount.', ['status' => 400]);
        }

        $balances = $this->balance->get_user_balances((int) $user_id);
        $purpose = sanitize_key((string) ($metadata['purpose'] ?? 'direct_payment'));
        $intent_id = $this->create_quote_intent(
            (int) $user_id,
            $amount_micro,
            $purpose,
            (string) $resource_url,
            isset($metadata['idempotency_key']) ? (string) $metadata['idempotency_key'] : ''
        );

        if ($balances && Growl_Money::cmp($balances['available_micro'], $amount_micro) >= 0) {
            return [
                'can_use_balance' => true,
                'amount_micro'    => $amount_micro,
                'amount_display'  => Growl_Money::to_display($amount_micro),
                'available_micro' => $balances['available_micro'],
                'intent_id'       => $intent_id,
            ];
        }

        if (!class_exists('PayAI_Flow')) {
            return new WP_Error('payment_unavailable', 'Payments are not available right now.', ['status' => 503]);
        }

        $amount_usdc = Growl_Money::to_usdc_string($amount_micro);
        $descriptor  = PayAI_Flow::get_x402_descriptor(
            $resource_url,
            $amount_micro,
            isset($metadata['description']) ? (string) $metadata['description'] : null
        );
        if (is_wp_error($descriptor)) {
            return new WP_Error('payment_unavailable', 'Payment could not be prepared.', ['status' => 503]);
        }

        return [
            'can_use_balance' => false,
            'amount_micro'    => $amount_micro,
            'amount_display'  => Growl_Money::to_display($amount_micro),
            'intent_id'       => $intent_id,
            'x402'            => [
                'x402Version' => 2,
                'resource'    => $descriptor['resource'],
                'accepts'     => $descriptor['accepts'],
            ],
        ];
    }

    /**
     * 120s single-use quote. Returns intent_id or empty string on failure.
     */
    public function create_quote_intent($user_id, $amount_micro, $purpose, $resource_url, $idempotency_key = '') {
        $intent_id = wp_generate_uuid4();
        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key);
        if ($idem !== '') {
            $existing = $this->db->get_var(
                "SELECT intent_id FROM {$this->db->table_prefix}payment_intents
                 WHERE idempotency_key = %s AND status = 'quoted' AND expires_at > UTC_TIMESTAMP() LIMIT 1",
                [$idem]
            );
            if ($existing) {
                return (string) $existing;
            }
        }
        $ok = $this->db->insert('payment_intents', [
            'intent_id'        => $intent_id,
            'user_id'          => (int) $user_id > 0 ? (int) $user_id : null,
            'purpose'          => $purpose !== '' ? $purpose : 'direct_payment',
            'amount_micro'     => (string) $amount_micro,
            'resource_url'     => $resource_url !== '' ? esc_url_raw($resource_url) : null,
            'status'           => 'quoted',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 120),
            'idempotency_key'  => $idem !== '' ? $idem : null,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]);
        return $ok ? $intent_id : '';
    }

    /**
     * Consume a quoted intent (single-use, 120s TTL). Empty intent_id is allowed for legacy callers.
     *
     * @return true|WP_Error
     */
    public function consume_quote_intent($intent_id, $amount_micro) {
        $intent_id = sanitize_text_field((string) $intent_id);
        if ($intent_id === '') {
            return true;
        }
        $p = $this->db->table_prefix;
        $row = $this->db->get_row_for_update(
            "SELECT * FROM {$p}payment_intents WHERE intent_id = %s LIMIT 1",
            [$intent_id]
        );
        if (!$row) {
            return new WP_Error('invalid_intent', 'This quote expired. Request a new payment.', ['status' => 400]);
        }
        if ($row->status !== 'quoted') {
            return new WP_Error('intent_used', 'This quote was already used.', ['status' => 400]);
        }
        if (strtotime($row->expires_at . ' UTC') < time()) {
            $this->db->update('payment_intents', ['status' => 'expired'], ['intent_id' => $intent_id]);
            return new WP_Error('intent_expired', 'This quote expired. Request a new payment.', ['status' => 400]);
        }
        if (Growl_Money::is_positive($amount_micro) && Growl_Money::cmp($row->amount_micro, $amount_micro) !== 0) {
            return new WP_Error('intent_amount', 'Payment does not match this quote.', ['status' => 400]);
        }
        $this->db->update('payment_intents', ['status' => 'consumed'], ['intent_id' => $intent_id]);
        return true;
    }

    /**
     * Alias matching the plan name.
     */
    public function settle_from_signature($user_id, $signed_tx, array $requirements, array $metadata = []) {
        return $this->settle_wallet_payment($user_id, $signed_tx, $requirements, $metadata);
    }

    /**
     * Log a treasury outbound. On-chain SPL send is operator-configured; evidence is always written.
     *
     * @return int|WP_Error settlement_id
     */
    public function release_to_payee($payee_wallet, $amount_micro, $purpose, $ref_type, $ref_id, $tx_hash = '') {
        return $this->record_settlement([
            'tx_hash'      => $tx_hash,
            'payee_wallet' => $payee_wallet,
            'amount_micro' => $amount_micro,
            'purpose'      => $purpose,
            'ref_type'     => $ref_type,
            'ref_id'       => $ref_id,
            'source'       => 'treasury',
            'status'       => $tx_hash !== '' ? 'settled' : 'pending_payout',
        ]);
    }

    /**
     * Record settlement evidence (Layer A).
     *
     * @param array<string,mixed> $data
     * @return int|WP_Error settlement_id
     */
    public function record_settlement(array $data) {
        $tx_hash = sanitize_text_field((string) ($data['tx_hash'] ?? ''));
        $purpose = sanitize_key((string) ($data['purpose'] ?? ''));
        $amount  = (string) ($data['amount_micro'] ?? '0');
        $payer   = sanitize_text_field((string) ($data['payer_wallet'] ?? ''));
        $ref_type = sanitize_key((string) ($data['ref_type'] ?? ''));
        $ref_id   = sanitize_text_field((string) ($data['ref_id'] ?? ''));
        $idem     = Growl_Money::sanitize_idempotency_key($data['idempotency_key'] ?? '');

        if ($purpose === '' || !Growl_Money::is_positive($amount)) {
            return new WP_Error('invalid_settlement', 'Invalid settlement.', ['status' => 400]);
        }

        $prefix = $this->db->table_prefix;
        if ($idem !== '') {
            $existing = $this->db->get_var(
                "SELECT settlement_id FROM {$prefix}x402_settlements WHERE idempotency_key = %s LIMIT 1",
                [$idem]
            );
            if ($existing) {
                return (int) $existing;
            }
        }
        if ($tx_hash !== '') {
            $existing_tx = $this->db->get_var(
                "SELECT settlement_id FROM {$prefix}x402_settlements WHERE tx_hash = %s AND purpose = %s LIMIT 1",
                [$tx_hash, $purpose]
            );
            if ($existing_tx) {
                return (int) $existing_tx;
            }
        }

        $network_caip2 = sanitize_text_field((string) ($data['network_caip2'] ?? $data['network'] ?? 'solana'));
        $settlement_state = sanitize_key((string) ($data['settlement_state'] ?? $data['status'] ?? 'settled'));
        $insert_row = [
            'tx_hash'              => $tx_hash !== '' ? $tx_hash : null,
            'payer_wallet'         => $payer,
            'payee_wallet'         => isset($data['payee_wallet']) ? sanitize_text_field((string) $data['payee_wallet']) : null,
            'amount_micro'         => $amount,
            'network'              => sanitize_text_field((string) ($data['network'] ?? 'solana')),
            'asset'                => sanitize_text_field((string) ($data['asset'] ?? 'USDC')),
            'x402_resource'        => isset($data['x402_resource']) ? esc_url_raw((string) $data['x402_resource']) : null,
            'purpose'              => $purpose,
            'ref_type'             => $ref_type,
            'ref_id'               => $ref_id,
            'user_id'              => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'agent_id'             => isset($data['agent_id']) ? sanitize_text_field((string) $data['agent_id']) : null,
            'facilitator_id'       => isset($data['facilitator_id']) ? sanitize_text_field((string) $data['facilitator_id']) : null,
            'status'               => sanitize_key((string) ($data['status'] ?? 'settled')),
            'source'               => sanitize_key((string) ($data['source'] ?? 'x402')),
            'idempotency_key'      => $idem !== '' ? $idem : null,
            'created_at'           => gmdate('Y-m-d H:i:s'),
        ];
        $insert_row['network_caip2'] = $network_caip2;
        $insert_row['settlement_state'] = $settlement_state;
        if (!empty($data['payment_method_id'])) {
            $insert_row['payment_method_id'] = sanitize_text_field((string) $data['payment_method_id']);
        }
        $ok = $this->db->insert('x402_settlements', $insert_row);

        if (!$ok) {
            return new WP_Error('settlement_failed', 'Payment could not be recorded.', ['status' => 500]);
        }

        return (int) $this->db->wpdb->insert_id;
    }

    /**
     * Settle from EarnFi Balance (prior evidenced funds).
     */
    public function settle_from_balance($user_id, $amount_micro, $purpose, $ref_type, $ref_id, $idempotency_key) {
        $settlement_id = $this->record_settlement([
            'tx_hash'         => null,
            'payer_wallet'    => '',
            'amount_micro'    => $amount_micro,
            'purpose'         => $purpose,
            'ref_type'        => $ref_type,
            'ref_id'          => $ref_id,
            'user_id'         => $user_id,
            'source'          => 'balance',
            'status'          => 'settled',
            'idempotency_key' => $idempotency_key . ':settlement',
        ]);
        if (is_wp_error($settlement_id)) {
            return $settlement_id;
        }
        return (int) $settlement_id;
    }

    /**
     * Debit Creator Wallet paid balance, credit EarnFi available, record settlement.
     * Caller must then lock_escrow (same as balance path). Runs inside caller's transaction.
     *
     * @return int|WP_Error settlement_id
     */
    public function settle_from_creator_wallet($user_id, $amount_micro, $purpose, $ref_type, $ref_id, $idempotency_key) {
        if (!class_exists('GROWL_Creator_Wallet', false)) {
            require_once dirname(__DIR__) . '/class-creator-wallet.php';
        }
        $amount_usdc = (float) Growl_Money::to_usdc_string($amount_micro);
        $debit = GROWL_Creator_Wallet::debit_paid((int) $user_id, $amount_usdc, [
            'entry_type' => sanitize_key((string) $purpose),
            'purpose'    => sanitize_key((string) $purpose),
            'ref_type'   => sanitize_key((string) $ref_type),
            'ref_id'     => sanitize_text_field((string) $ref_id),
        ]);
        if (is_wp_error($debit)) {
            return $debit;
        }

        $settlement_id = $this->record_settlement([
            'tx_hash'         => null,
            'payer_wallet'    => '',
            'amount_micro'    => $amount_micro,
            'purpose'         => $purpose,
            'ref_type'        => $ref_type,
            'ref_id'          => $ref_id,
            'user_id'         => (int) $user_id,
            'source'          => 'creator_wallet',
            'status'          => 'settled',
            'idempotency_key' => $idempotency_key . ':settlement',
        ]);
        if (is_wp_error($settlement_id)) {
            return $settlement_id;
        }

        $credit = $this->balance->apply_ledger_entry([
            'user_id'               => (int) $user_id,
            'delta_available_micro' => (string) $amount_micro,
            'delta_escrow_micro'    => '0',
            'entry_type'            => 'creator_wallet_transfer',
            'purpose'               => sanitize_key((string) $purpose) . '_creator_wallet',
            'ref_type'              => $ref_type,
            'ref_id'                => $ref_id,
            'settlement_id'         => (int) $settlement_id,
            'idempotency_key'       => $idempotency_key . ':cw_credit',
        ], false);
        if (is_wp_error($credit)) {
            return $credit;
        }

        return (int) $settlement_id;
    }

    /**
     * Verify and settle an on-chain payment, then record settlement evidence.
     *
     * @param array<string,mixed> $requirements
     * @param array<string,mixed> $metadata
     * @return int|WP_Error settlement_id
     */
    public function settle_wallet_payment($user_id, $signed_tx, array $requirements, array $metadata = []) {
        if ($signed_tx === '' || empty($requirements)) {
            return new WP_Error('invalid_params', 'Payment could not be verified.', ['status' => 400]);
        }
        if (!isset($requirements['maxAmountRequired']) && isset($requirements['amount'])) {
            $requirements['maxAmountRequired'] = $requirements['amount'];
        }
        if (!class_exists('PayAI_Payments')) {
            return new WP_Error('payment_unavailable', 'Payment could not be processed. Try again.', ['status' => 503]);
        }

        $payload_v2 = null;
        if (!empty($metadata['payment_payload_v2']) && is_array($metadata['payment_payload_v2'])) {
            $payload_v2 = $metadata['payment_payload_v2'];
        }
        $stored = PayAI_X402_Quote_Store::resolve_stored_for_settlement($requirements);

        $verification = PayAI_Payments::verify($signed_tx, $requirements, $payload_v2, $stored);
        if (isset($verification['error'])) {
            return new WP_Error('verification_failed', 'Payment could not be verified.', ['status' => 400]);
        }
        if (isset($verification['isValid']) && $verification['isValid'] === false) {
            return new WP_Error('verification_failed', 'Payment could not be verified.', ['status' => 400]);
        }

        $settlement = PayAI_Payments::settle($signed_tx, $requirements, $payload_v2, $stored);
        if (isset($settlement['error'])) {
            return new WP_Error('settlement_failed', 'Payment could not be verified.', ['status' => 400]);
        }

        $tx_hash = '';
        if (is_array($settlement)) {
            $tx_hash = sanitize_text_field((string) ($settlement['transaction'] ?? $settlement['txHash'] ?? $settlement['signature'] ?? ''));
        }

        $purpose = sanitize_key((string) ($metadata['purpose'] ?? 'deal_fund'));
        $amount  = (string) ($metadata['amount_micro'] ?? '0');
        $idem    = Growl_Money::sanitize_idempotency_key($metadata['idempotency_key'] ?? '');
        $intent  = $this->consume_quote_intent($metadata['intent_id'] ?? '', $amount);
        if (is_wp_error($intent)) {
            return $intent;
        }

        $ref_type = sanitize_key((string) ($metadata['ref_type'] ?? ''));
        $ref_id   = sanitize_text_field((string) ($metadata['ref_id'] ?? ''));
        $settlement_id = $this->record_settlement([
            'tx_hash'         => $tx_hash,
            'payer_wallet'    => sanitize_text_field((string) ($metadata['payer_wallet'] ?? '')),
            'amount_micro'    => $amount,
            'purpose'         => $purpose,
            'ref_type'        => $ref_type,
            'ref_id'          => $ref_id,
            'user_id'         => (int) $user_id,
            'source'          => 'x402',
            'status'          => 'settled',
            'idempotency_key' => $idem !== '' ? $idem : null,
        ]);
        if (is_wp_error($settlement_id)) {
            return $settlement_id;
        }

        // Evidenced on-chain funds enter EarnFi Balance first. Callers then lock into held.
        if ((int) $user_id > 0 && Growl_Money::is_positive($amount)) {
            $credit_idem = ($idem !== '' ? $idem : ('x402_credit:' . (int) $settlement_id)) . ':credit';
            $credit = $this->balance->apply_ledger_entry([
                'user_id'               => (int) $user_id,
                'delta_available_micro' => $amount,
                'delta_escrow_micro'    => '0',
                'entry_type'            => 'x402_credit',
                'purpose'               => $purpose !== '' ? $purpose : 'wallet_fund',
                'ref_type'              => $ref_type !== '' ? $ref_type : 'x402',
                'ref_id'                => $ref_id !== '' ? $ref_id : (string) $settlement_id,
                'settlement_id'         => (int) $settlement_id,
                'idempotency_key'       => $credit_idem,
            ], true);
            if (is_wp_error($credit)) {
                return self::public_error($credit);
            }
        }

        return (int) $settlement_id;
    }

    /**
     * Sanitize payment errors for external clients.
     */
    public static function public_error(WP_Error $err) {
        $code = $err->get_error_code();
        $public_codes = [
            'insufficient_balance' => 'Insufficient EarnFi Balance.',
            'insufficient_wallet'  => 'Insufficient Creator Wallet paid balance.',
            'invalid_amount'       => 'Invalid payment amount.',
            'payment_unavailable'  => 'Payment could not be processed. Try again.',
            'invalid_settlement'   => 'Payment could not be verified.',
            'missing_settlement'   => 'Payment could not be verified.',
            'intent_expired'       => 'This quote expired. Request a new payment.',
            'intent_used'          => 'This quote was already used.',
            'invalid_intent'       => 'This quote expired. Request a new payment.',
            'ledger_drift'         => 'This balance is under review. Try again later.',
        ];
        $message = $public_codes[$code] ?? 'Payment could not be verified.';
        return new WP_Error($code, $message, ['status' => (int) ($err->get_error_data()['status'] ?? 400)]);
    }
}
