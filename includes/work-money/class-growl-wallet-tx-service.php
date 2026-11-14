<?php
/**
 * User-facing wallet transactions (one row per money event + lifecycle status).
 * balance_ledger remains append-only accounting; this table powers /wallet-treasury.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Growl_Wallet_Tx_Service {
    /** @var GROWL_Database */
    private $db;

    public function __construct($database = null) {
        $this->db = $database instanceof GROWL_Database ? $database : new GROWL_Database();
    }

    /**
     * @param array<string,mixed> $data
     * @return int|WP_Error tx_id
     */
    public function upsert(array $data) {
        $user_id = (int) ($data['user_id'] ?? 0);
        $type = sanitize_key((string) ($data['type'] ?? ''));
        if ($user_id < 1 || $type === '') {
            return new WP_Error('invalid_wallet_tx', 'Invalid wallet transaction.', ['status' => 400]);
        }

        if (!class_exists('Growl_Money')) {
            require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
        }

        $idem = Growl_Money::sanitize_idempotency_key($data['idempotency_key'] ?? '');
        $p = $this->db->table_prefix;

        if ($idem !== '') {
            $existing = (int) $this->db->get_var(
                "SELECT tx_id FROM {$p}wallet_transactions WHERE idempotency_key = %s LIMIT 1",
                [$idem]
            );
            if ($existing > 0) {
                $this->update($existing, $data);
                return $existing;
            }
        }

        $amount_micro = Growl_Money::assert_micro($data['amount_micro'] ?? '0');
        $created_at = !empty($data['created_at']) ? sanitize_text_field((string) $data['created_at']) : current_time('mysql');
        $row = [
            'user_id'         => $user_id,
            'type'            => $type,
            'status'          => sanitize_key((string) ($data['status'] ?? 'succeeded')),
            'amount_micro'    => $amount_micro,
            'title'           => sanitize_text_field((string) ($data['title'] ?? $this->default_title($type))),
            'subtitle'        => sanitize_text_field((string) ($data['subtitle'] ?? '')),
            'bucket'          => sanitize_key((string) ($data['bucket'] ?? 'available')),
            'ref_type'        => sanitize_key((string) ($data['ref_type'] ?? '')),
            'ref_id'          => sanitize_text_field((string) ($data['ref_id'] ?? '')),
            'tx_hash'         => sanitize_text_field((string) ($data['tx_hash'] ?? '')),
            'network'         => sanitize_text_field((string) ($data['network'] ?? '')),
            'meta_json'       => isset($data['meta']) ? wp_json_encode($data['meta']) : null,
            'idempotency_key' => $idem !== '' ? $idem : null,
            'created_at'      => $created_at,
            'updated_at'      => current_time('mysql'),
        ];

        $tx_id = $this->db->insert('wallet_transactions', $row);
        if (!$tx_id) {
            return new WP_Error('db_error', 'Could not create wallet transaction.', ['status' => 500]);
        }
        return (int) $tx_id;
    }

    /**
     * @param array<string,mixed> $data
     * @return true|WP_Error
     */
    public function update($tx_id, array $data) {
        $tx_id = (int) $tx_id;
        if ($tx_id < 1) {
            return new WP_Error('invalid_wallet_tx', 'Invalid tx id.', ['status' => 400]);
        }
        if (!class_exists('Growl_Money')) {
            require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
        }

        $fields = [];
        if (isset($data['status'])) {
            $fields['status'] = sanitize_key((string) $data['status']);
        }
        if (isset($data['title'])) {
            $fields['title'] = sanitize_text_field((string) $data['title']);
        }
        if (isset($data['subtitle'])) {
            $fields['subtitle'] = sanitize_text_field((string) $data['subtitle']);
        }
        if (isset($data['tx_hash'])) {
            $fields['tx_hash'] = sanitize_text_field((string) $data['tx_hash']);
        }
        if (isset($data['network'])) {
            $fields['network'] = sanitize_text_field((string) $data['network']);
        }
        if (isset($data['amount_micro'])) {
            $fields['amount_micro'] = Growl_Money::assert_micro($data['amount_micro']);
        }
        if (isset($data['bucket'])) {
            $fields['bucket'] = sanitize_key((string) $data['bucket']);
        }
        if (isset($data['meta'])) {
            $fields['meta_json'] = wp_json_encode($data['meta']);
        }
        if (!empty($data['created_at'])) {
            $fields['created_at'] = sanitize_text_field((string) $data['created_at']);
        }
        if (isset($data['append_timeline']) && is_array($data['append_timeline'])) {
            $existing_meta = $this->db->get_var(
                "SELECT meta_json FROM {$this->db->table_prefix}wallet_transactions WHERE tx_id = %d LIMIT 1",
                [$tx_id]
            );
            $meta = $existing_meta ? json_decode((string) $existing_meta, true) : [];
            if (!is_array($meta)) {
                $meta = [];
            }
            $timeline = isset($meta['timeline']) && is_array($meta['timeline']) ? $meta['timeline'] : [];
            $timeline[] = $data['append_timeline'];
            $meta['timeline'] = $timeline;
            $fields['meta_json'] = wp_json_encode($meta);
        }
        if (empty($fields)) {
            return true;
        }
        $fields['updated_at'] = current_time('mysql');
        $ok = $this->db->update('wallet_transactions', $fields, ['tx_id' => $tx_id]);
        if ($ok === false) {
            return new WP_Error('db_error', 'Could not update wallet transaction.', ['status' => 500]);
        }
        return true;
    }

    /**
     * @return int|null
     */
    public function find_by_idempotency($key) {
        $key = Growl_Money::sanitize_idempotency_key($key);
        if ($key === '') {
            return null;
        }
        $id = (int) $this->db->get_var(
            "SELECT tx_id FROM {$this->db->table_prefix}wallet_transactions WHERE idempotency_key = %s LIMIT 1",
            [$key]
        );
        return $id > 0 ? $id : null;
    }

    /**
     * @return int|null
     */
    public function find_by_ref($user_id, $ref_type, $ref_id) {
        $id = (int) $this->db->get_var(
            "SELECT tx_id FROM {$this->db->table_prefix}wallet_transactions
             WHERE user_id = %d AND ref_type = %s AND ref_id = %s
             ORDER BY tx_id DESC LIMIT 1",
            [(int) $user_id, sanitize_key((string) $ref_type), sanitize_text_field((string) $ref_id)]
        );
        return $id > 0 ? $id : null;
    }

    public function default_title($type) {
        $map = [
            'transfer_out'    => 'Transfer out',
            'transfer_in'     => 'Transfer in',
            'send_onchain'    => 'Transfer out',
            'agent_hire'      => 'Agent hire',
            'deal'            => 'Deal',
            'escrow_hold'     => 'Funds held',
            'escrow_release'  => 'Funds released',
            'dispute_hold'    => 'Dispute hold',
            'dispute_release' => 'Dispute release',
            'fee'             => 'Platform fee',
            'adjustment'      => 'Balance adjustment',
            'deposit'         => 'Deposit',
        ];
        $type = sanitize_key((string) $type);
        return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * Project historical withdrawals / ledger into wallet_transactions for a user (idempotent).
     */
    public function backfill_user($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return;
        }
        if (!class_exists('Growl_Money')) {
            require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
        }
        $p = $this->db->table_prefix;

        $withdrawals = $this->db->get_results(
            "SELECT withdrawal_id, amount, status, to_wallet, transaction_hash, network, admin_notes, created_at, completed_at
             FROM {$p}withdrawals WHERE user_id = %d ORDER BY withdrawal_id ASC LIMIT 2000",
            [$user_id]
        );
        foreach ($withdrawals ?: [] as $w) {
            $wid = (int) $w->withdrawal_id;
            $status = $this->map_withdrawal_status((string) $w->status);
            $micro = Growl_Money::to_micro((float) $w->amount);
            $notes = (string) ($w->admin_notes ?? '');
            $is_send = (stripos($notes, 'outbound_send') !== false || stripos($notes, 'wallet_send') !== false);
            $wallet = trim((string) ($w->to_wallet ?? ''));
            $subtitle = '';
            if (strlen($wallet) > 12) {
                $subtitle = substr($wallet, 0, 6) . '…' . substr($wallet, -4);
            } elseif ($wallet !== '') {
                $subtitle = $wallet;
            }
            $created = (string) ($w->created_at ?? '');
            if ($created === '' && !empty($w->completed_at)) {
                $created = (string) $w->completed_at;
            }
            $this->upsert([
                'user_id'         => $user_id,
                'type'            => $is_send ? 'send_onchain' : 'transfer_out',
                'status'          => $status,
                'amount_micro'    => Growl_Money::sub('0', $micro),
                'title'           => 'Transfer out',
                'subtitle'        => $subtitle,
                'bucket'          => 'available',
                'ref_type'        => 'withdrawal',
                'ref_id'          => (string) $wid,
                'tx_hash'         => (string) ($w->transaction_hash ?? ''),
                'network'         => (string) ($w->network ?? ''),
                'idempotency_key' => 'wtx:withdrawal:' . $wid,
                'created_at'      => $created !== '' ? $created : current_time('mysql'),
                'meta'            => ['source' => 'backfill_withdrawal'],
            ]);
        }

        $legacy_skip = ['legacy_balance_bootstrap', 'legacy_balance_sync', 'legacy_bootstrap', 'legacy_sync'];
        $rows = $this->db->get_results(
            "SELECT ledger_id, amount_micro, delta_available, delta_escrow, delta_dispute, purpose, ref_type, ref_id, idempotency_key, created_at
             FROM {$p}balance_ledger
             WHERE user_id = %d
             ORDER BY ledger_id ASC
             LIMIT 5000",
            [$user_id]
        );
        foreach ($rows ?: [] as $row) {
            $purpose = (string) ($row->purpose ?? '');
            if (in_array($purpose, $legacy_skip, true) || strpos($purpose, 'legacy_') === 0) {
                continue;
            }
            if ($purpose === 'withdrawal_refund') {
                continue;
            }
            // Real withdrawals are covered by withdrawals backfill; orphan withdrawal legs still need a visible tx.
            if ($purpose === 'withdrawal') {
                $wid = ctype_digit(trim((string) ($row->ref_id ?? ''))) ? (int) $row->ref_id : 0;
                $own = $wid > 0 ? $this->db->get_var(
                    "SELECT withdrawal_id FROM {$p}withdrawals WHERE withdrawal_id = %d AND user_id = %d LIMIT 1",
                    [$wid, $user_id]
                ) : null;
                if ($own) {
                    continue;
                }
                $abs = (string) ($row->delta_available ?? '0');
                if (Growl_Money::cmp($abs, '0') >= 0) {
                    $abs = (string) ($row->amount_micro ?? '0');
                }
                if (Growl_Money::cmp($abs, '0') > 0) {
                    $abs = Growl_Money::sub('0', $abs);
                }
                $this->upsert([
                    'user_id'         => $user_id,
                    'type'            => 'transfer_out',
                    'status'          => 'failed',
                    'amount_micro'    => $abs,
                    'title'           => 'Transfer out',
                    'subtitle'        => 'Unauthorized debit',
                    'bucket'          => 'available',
                    'ref_type'        => 'balance_ledger',
                    'ref_id'          => (string) (int) $row->ledger_id,
                    'idempotency_key' => 'wtx:ledger:' . (int) $row->ledger_id,
                    'created_at'      => (string) ($row->created_at ?? current_time('mysql')),
                    'meta'            => [
                        'source'    => 'backfill_orphan_withdrawal',
                        'ledger_id' => (int) $row->ledger_id,
                        'purpose'   => $purpose,
                    ],
                ]);
                continue;
            }
            if ($purpose === 'wallet_send' && (string) ($row->ref_type ?? '') === 'withdrawal') {
                continue; // covered by withdrawals backfill
            }
            $mapped = $this->map_ledger_purpose($purpose, $row);
            if ($mapped === null) {
                continue;
            }
            $idem = 'wtx:ledger:' . (int) $row->ledger_id;
            $this->upsert(array_merge($mapped, [
                'user_id'         => $user_id,
                'idempotency_key' => $idem,
                'created_at'      => (string) ($row->created_at ?? current_time('mysql')),
                'meta'            => ['source' => 'backfill_ledger', 'ledger_id' => (int) $row->ledger_id, 'purpose' => $purpose],
            ]));
        }

        $this->repair_backfill_timestamps($user_id);
        $this->consolidate_agent_hire_rows($user_id);
    }

    /**
     * Fix wallet_transactions created_at that were incorrectly stamped with "now" during backfill.
     */
    public function repair_backfill_timestamps($user_id = 0) {
        $p = $this->db->table_prefix;
        $user_id = (int) $user_id;

        if ($user_id > 0) {
            $this->db->query(
                "UPDATE {$p}wallet_transactions t
                 INNER JOIN {$p}withdrawals w ON t.ref_type = 'withdrawal' AND t.ref_id = CAST(w.withdrawal_id AS CHAR) AND w.user_id = t.user_id
                 SET t.created_at = COALESCE(NULLIF(w.created_at, '0000-00-00 00:00:00'), w.completed_at, t.created_at)
                 WHERE t.user_id = %d AND t.idempotency_key LIKE 'wtx:withdrawal:%'",
                [$user_id]
            );
            $this->db->query(
                "UPDATE {$p}wallet_transactions t
                 INNER JOIN {$p}balance_ledger l ON t.idempotency_key = CONCAT('wtx:ledger:', l.ledger_id)
                 SET t.created_at = l.created_at
                 WHERE t.user_id = %d AND t.idempotency_key LIKE 'wtx:ledger:%'",
                [$user_id]
            );
            return;
        }

        $this->db->query(
            "UPDATE {$p}wallet_transactions t
             INNER JOIN {$p}withdrawals w ON t.ref_type = 'withdrawal' AND t.ref_id = CAST(w.withdrawal_id AS CHAR) AND w.user_id = t.user_id
             SET t.created_at = COALESCE(NULLIF(w.created_at, '0000-00-00 00:00:00'), w.completed_at, t.created_at)
             WHERE t.idempotency_key LIKE 'wtx:withdrawal:%'"
        );
        $this->db->query(
            "UPDATE {$p}wallet_transactions t
             INNER JOIN {$p}balance_ledger l ON t.idempotency_key = CONCAT('wtx:ledger:', l.ledger_id)
             SET t.created_at = l.created_at
             WHERE t.idempotency_key LIKE 'wtx:ledger:%'"
        );
    }

    /**
     * Collapse duplicate buyer Agent hire + Funds released rows into one lifecycle tx.
     */
    public function consolidate_agent_hire_rows($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return;
        }
        $p = $this->db->table_prefix;
        $hires = $this->db->get_results(
            "SELECT tx_id, ref_type, ref_id, status, meta_json, created_at, title, type
             FROM {$p}wallet_transactions
             WHERE user_id = %d AND type IN ('agent_hire','deal')
             ORDER BY tx_id ASC",
            [$user_id]
        );
        foreach ($hires ?: [] as $hire) {
            $ref_type = (string) $hire->ref_type;
            $ref_id = (string) $hire->ref_id;
            if ($ref_type === '' || $ref_id === '') {
                continue;
            }
            $dupes = $this->db->get_results(
                "SELECT tx_id FROM {$p}wallet_transactions
                 WHERE user_id = %d AND ref_type = %s AND ref_id = %s
                   AND type IN ('escrow_release','escrow_hold')
                   AND tx_id != %d",
                [$user_id, $ref_type, $ref_id, (int) $hire->tx_id]
            );
            $meta = $hire->meta_json ? json_decode((string) $hire->meta_json, true) : [];
            if (!is_array($meta)) {
                $meta = [];
            }
            $timeline = isset($meta['timeline']) && is_array($meta['timeline']) ? $meta['timeline'] : [];
            if (empty($timeline)) {
                $timeline[] = [
                    'status' => 'processing',
                    'label'  => 'Payment held in escrow',
                    'at'     => (string) $hire->created_at,
                ];
            }
            $released = false;
            foreach ($dupes ?: [] as $d) {
                $released = true;
                $this->db->query(
                    "DELETE FROM {$p}wallet_transactions WHERE tx_id = %d AND user_id = %d",
                    [(int) $d->tx_id, $user_id]
                );
            }
            // Also remove duplicate "Funds released" rows that reused agent_hire type with wrong title
            $wrong_title = $this->db->get_results(
                "SELECT tx_id FROM {$p}wallet_transactions
                 WHERE user_id = %d AND ref_type = %s AND ref_id = %s
                   AND tx_id != %d AND title = 'Funds released'",
                [$user_id, $ref_type, $ref_id, (int) $hire->tx_id]
            );
            foreach ($wrong_title ?: [] as $d) {
                $released = true;
                $this->db->query(
                    "DELETE FROM {$p}wallet_transactions WHERE tx_id = %d AND user_id = %d",
                    [(int) $d->tx_id, $user_id]
                );
            }
            if ($released) {
                $has_done = false;
                foreach ($timeline as $step) {
                    if (($step['status'] ?? '') === 'succeeded') {
                        $has_done = true;
                        break;
                    }
                }
                if (!$has_done) {
                    $timeline[] = [
                        'status' => 'succeeded',
                        'label'  => 'Order completed · funds released',
                        'at'     => current_time('mysql'),
                    ];
                }
                $meta['timeline'] = $timeline;
                $this->update((int) $hire->tx_id, [
                    'status' => 'succeeded',
                    'title'  => $hire->type === 'deal' ? 'Deal' : 'Agent hire',
                    'bucket' => 'available',
                    'meta'   => $meta,
                ]);
            } else {
                $meta['timeline'] = $timeline;
                $title = $hire->type === 'deal' ? 'Deal' : 'Agent hire';
                if ((string) $hire->title === 'Funds released') {
                    $this->update((int) $hire->tx_id, [
                        'title'  => $title,
                        'status' => 'succeeded',
                        'meta'   => $meta,
                    ]);
                } elseif (empty($hire->meta_json)) {
                    $this->update((int) $hire->tx_id, ['meta' => $meta]);
                }
            }
        }
    }

    /**
     * @param object $row
     * @return array<string,mixed>|null
     */
    private function map_ledger_purpose($purpose, $row) {
        if (!class_exists('Growl_Money')) {
            require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
        }
        $amount = (string) ($row->amount_micro ?? '0');
        if (Growl_Money::cmp($amount, '0') === 0) {
            $da = (string) ($row->delta_available ?? '0');
            $de = (string) ($row->delta_escrow ?? '0');
            $dd = (string) ($row->delta_dispute ?? '0');
            if (Growl_Money::cmp($da, '0') !== 0) {
                $amount = $da;
            } elseif (Growl_Money::cmp($de, '0') !== 0) {
                $amount = $de;
            } else {
                $amount = $dd;
            }
        }

        $ref_type = (string) ($row->ref_type ?? '');
        $ref_id = (string) ($row->ref_id ?? '');
        $subtitle = '';
        if ($purpose === 'wallet_send' || $purpose === 'wallet_send_reversal') {
            $w = trim($ref_id);
            if (strlen($w) > 12) {
                $subtitle = substr($w, 0, 6) . '…' . substr($w, -4);
            } else {
                $subtitle = $w;
            }
        }

        switch ($purpose) {
            case 'wallet_send':
                return [
                    'type' => 'send_onchain', 'status' => 'succeeded', 'amount_micro' => $amount,
                    'title' => 'Transfer out', 'subtitle' => $subtitle, 'bucket' => 'available',
                    'ref_type' => $ref_type ?: 'wallet_send', 'ref_id' => $ref_id,
                ];
            case 'wallet_send_reversal':
                return [
                    'type' => 'adjustment', 'status' => 'succeeded', 'amount_micro' => $amount,
                    'title' => 'Transfer out refund', 'subtitle' => $subtitle, 'bucket' => 'available',
                    'ref_type' => $ref_type ?: 'wallet_send', 'ref_id' => $ref_id,
                ];
            case 'p2p_send':
                return [
                    'type' => 'transfer_out', 'status' => 'succeeded', 'amount_micro' => $amount,
                    'title' => 'Transfer out', 'subtitle' => '', 'bucket' => 'available',
                    'ref_type' => $ref_type ?: 'p2p', 'ref_id' => $ref_id,
                ];
            case 'p2p_receive':
                return [
                    'type' => 'transfer_in', 'status' => 'succeeded', 'amount_micro' => $amount,
                    'title' => 'Transfer in', 'subtitle' => '', 'bucket' => 'available',
                    'ref_type' => $ref_type ?: 'p2p', 'ref_id' => $ref_id,
                ];
            case 'admin_balance_correction':
                return [
                    'type' => 'adjustment', 'status' => 'succeeded', 'amount_micro' => $amount,
                    'title' => 'Balance adjustment', 'subtitle' => 'Admin correction', 'bucket' => 'available',
                    'ref_type' => $ref_type, 'ref_id' => $ref_id,
                ];
            case 'deal_fund':
            case 'agent_deal_fund':
            case 'agent_order_fund':
                return [
                    'type' => strpos($purpose, 'agent') !== false ? 'agent_hire' : 'deal',
                    'status' => 'succeeded', 'amount_micro' => $amount,
                    'title' => strpos($purpose, 'agent') !== false ? 'Agent hire' : 'Deal funded',
                    'subtitle' => '', 'bucket' => 'escrow',
                    'ref_type' => $ref_type, 'ref_id' => $ref_id,
                ];
            case 'escrow_release':
                $da = (string) ($row->delta_available ?? '0');
                // Buyer unlock legs have zero available delta — skip (covered by agent_hire/deal lifecycle).
                if (Growl_Money::cmp($da, '0') <= 0) {
                    return null;
                }
                return [
                    'type' => 'escrow_release', 'status' => 'succeeded', 'amount_micro' => $amount,
                    'title' => 'Payout received', 'subtitle' => '', 'bucket' => 'available',
                    'ref_type' => $ref_type, 'ref_id' => $ref_id,
                ];
            case 'deal_refund':
                return [
                    'type' => 'deal', 'status' => 'cancelled', 'amount_micro' => $amount,
                    'title' => 'Deal refunded', 'subtitle' => '', 'bucket' => 'available',
                    'ref_type' => $ref_type, 'ref_id' => $ref_id,
                ];
            case 'deal_dispute':
                return [
                    'type' => 'dispute_hold', 'status' => 'processing', 'amount_micro' => $amount,
                    'title' => 'Dispute hold', 'subtitle' => '', 'bucket' => 'dispute',
                    'ref_type' => $ref_type, 'ref_id' => $ref_id,
                ];
            case 'deposit':
                return [
                    'type' => 'deposit', 'status' => 'succeeded', 'amount_micro' => $amount,
                    'title' => 'Deposit', 'subtitle' => '', 'bucket' => 'available',
                    'ref_type' => $ref_type, 'ref_id' => $ref_id,
                ];
            default:
                // Skip intermediate fee / lock-only noise that duplicates hire/deal rows when possible
                if (in_array($purpose, ['platform_fee', 'escrow_release'], true)) {
                    return null;
                }
                return null;
        }
    }

    private function map_withdrawal_status($status) {
        switch ($status) {
            case 'pending':
                return 'pending';
            case 'processing':
                return 'processing';
            case 'completed':
                return 'succeeded';
            case 'failed':
                return 'failed';
            default:
                return 'processing';
        }
    }

    /**
     * Format for /balances/activity response.
     *
     * @param object $row
     * @return array<string,mixed>
     */
    public function format_activity_row($row) {
        if (!class_exists('Growl_Money')) {
            require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
        }
        $amount = (string) ($row->amount_micro ?? '0');
        $status = (string) ($row->status ?? 'succeeded');
        $meta = [];
        if (!empty($row->meta_json)) {
            $decoded = json_decode((string) $row->meta_json, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $item = [
            'tx_id'        => (int) $row->tx_id,
            'type'         => (string) ($row->type ?? ''),
            'status'       => $status,
            'purpose'      => (string) ($row->type ?? ''),
            'entry_type'   => (string) ($row->type ?? ''),
            'display'      => Growl_Money::to_display($amount),
            'title'        => (string) ($row->title ?: $this->default_title($row->type ?? '')),
            'counterparty' => (string) ($row->subtitle ?? ''),
            'ref_type'     => (string) ($row->ref_type ?? ''),
            'ref_id'       => (string) ($row->ref_id ?? ''),
            'tx_hash'      => (string) ($row->tx_hash ?? ''),
            'network'      => (string) ($row->network ?? ''),
            'bucket'       => (string) ($row->bucket ?? 'available'),
            'created_at'   => (string) ($row->created_at ?? ''),
            'timeline'     => isset($meta['timeline']) && is_array($meta['timeline']) ? $meta['timeline'] : [],
        ];
        return $item;
    }
}
