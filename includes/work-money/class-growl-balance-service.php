<?php
/**
 * EarnFi Balance ledger: internal accounting for evidenced funds.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Growl_Money', false)) {
    require_once dirname(__DIR__) . '/money/class-growl-money.php';
}

class Growl_Balance_Service {

    /** @var GROWL_Database */
    private $db;

    public function __construct(?GROWL_Database $db = null) {
        $this->db = $db ?: new GROWL_Database();
    }

    /**
     * Read balances without side effects (no repair/bootstrap on GET).
     *
     * @return array<string,string>|null
     */
    public function get_user_balances_raw($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return null;
        }
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT available_micro, escrow_held_micro, dispute_held_micro,
                    COALESCE(restriction_held_micro, 0) AS restriction_held_micro,
                    COALESCE(outbound_pending_micro, 0) AS outbound_pending_micro,
                    COALESCE(debt_owed_micro, 0) AS debt_owed_micro
             FROM {$p}users WHERE user_id = %d LIMIT 1",
            [$user_id]
        );
        if (!$row) {
            return null;
        }
        $avail = (string) ($row->available_micro ?? '0');
        $esc   = (string) ($row->escrow_held_micro ?? '0');
        $disp  = (string) ($row->dispute_held_micro ?? '0');
        $rest  = (string) ($row->restriction_held_micro ?? '0');
        $out   = (string) ($row->outbound_pending_micro ?? '0');
        $debt  = (string) ($row->debt_owed_micro ?? '0');
        $held  = Growl_Money::add(Growl_Money::add(Growl_Money::add($esc, $disp), $rest), $out);
        $total = Growl_Money::add($avail, $held);
        return [
            'available_micro'           => $avail,
            'escrow_held_micro'         => $esc,
            'dispute_held_micro'        => $disp,
            'restriction_held_micro'    => $rest,
            'outbound_pending_micro'    => $out,
            'debt_owed_micro'           => $debt,
            'total_micro'               => $total,
            'available_display'         => Growl_Money::to_display($avail),
            'escrow_held_display'       => Growl_Money::to_display($esc),
            'dispute_held_display'      => Growl_Money::to_display($disp),
            'restriction_held_display'  => Growl_Money::to_display($rest),
            'outbound_pending_display'  => Growl_Money::to_display($out),
            'debt_owed_display'         => Growl_Money::to_display($debt),
            'total_display'             => Growl_Money::to_display($total),
        ];
    }

    /**
     * Side-effect-free balance read. Use sync_user_balance_state() / ensure_ledger_authority() for repair.
     *
     * @return array<string,string>|null
     */
    public function get_user_balances($user_id) {
        return $this->get_user_balances_raw($user_id);
    }

    /**
     * Ensure the user has a ledger-backed balance without inventing ongoing legacy_sync rows.
     * Ops/cron/reconcile only — never call from GET handlers.
     */
    public function ensure_ledger_authority($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return false;
        }
        $p = $this->db->table_prefix;
        $ledger_count = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$p}balance_ledger WHERE user_id = %d",
            [$user_id]
        );
        if ($ledger_count === 0) {
            return $this->bootstrap_ledger_from_legacy_columns($user_id);
        }
        return $this->repair_user_balance_from_ledger($user_id);
    }

    /**
     * @param array<string,mixed> $entry
     * @param bool $manage_tx When false, caller owns the transaction.
     * @return true|WP_Error
     */
    public function apply_ledger_entry(array $entry, $manage_tx = true) {
        if ($manage_tx) {
            $this->db->begin_transaction();
        }
        $result = $this->apply_ledger_entry_inner($entry);
        if ($manage_tx) {
            if (is_wp_error($result)) {
                $this->db->rollback();
            } else {
                $this->db->commit();
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $entry
     * @return true|WP_Error
     */
    private function apply_ledger_entry_inner(array $entry) {
        $user_id = (int) ($entry['user_id'] ?? 0);
        $delta_avail = (string) ($entry['delta_available_micro'] ?? '0');
        $delta_esc   = (string) ($entry['delta_escrow_micro'] ?? '0');
        $delta_disp  = (string) ($entry['delta_dispute_micro'] ?? '0');
        $delta_rest  = (string) ($entry['delta_restriction_micro'] ?? '0');
        $delta_out   = (string) ($entry['delta_outbound_pending_micro'] ?? '0');
        $delta_debt  = (string) ($entry['delta_debt_micro'] ?? '0');
        $purpose     = sanitize_key((string) ($entry['purpose'] ?? ''));
        $ref_type    = sanitize_key((string) ($entry['ref_type'] ?? ''));
        $ref_id      = sanitize_text_field((string) ($entry['ref_id'] ?? ''));
        $idempotency = Growl_Money::sanitize_idempotency_key($entry['idempotency_key'] ?? '');

        if ($user_id < 1 || $purpose === '') {
            return new WP_Error('invalid_ledger', 'Invalid ledger entry.', ['status' => 400]);
        }

        if ($this->user_has_unresolved_drift($user_id)) {
            $this->repair_user_balance_from_ledger($user_id);
            if ($this->user_has_unresolved_drift($user_id)) {
                return new WP_Error('ledger_drift', 'This balance is under review. Try again later.', ['status' => 423]);
            }
        }

        $p = $this->db->table_prefix;
        if ($idempotency !== '') {
            $exists = $this->db->get_var(
                "SELECT ledger_id FROM {$p}balance_ledger WHERE idempotency_key = %s LIMIT 1",
                [$idempotency]
            );
            if ($exists) {
                return true;
            }
        }

        $user = $this->db->get_row_for_update(
            "SELECT available_micro, escrow_held_micro, dispute_held_micro,
                    COALESCE(restriction_held_micro, 0) AS restriction_held_micro,
                    COALESCE(outbound_pending_micro, 0) AS outbound_pending_micro,
                    COALESCE(debt_owed_micro, 0) AS debt_owed_micro
             FROM {$p}users WHERE user_id = %d LIMIT 1",
            [$user_id]
        );
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', ['status' => 404]);
        }

        $new_avail = Growl_Money::add($user->available_micro ?? '0', $delta_avail);
        $new_esc   = Growl_Money::add($user->escrow_held_micro ?? '0', $delta_esc);
        $new_disp  = Growl_Money::add($user->dispute_held_micro ?? '0', $delta_disp);
        $new_rest  = Growl_Money::add($user->restriction_held_micro ?? '0', $delta_rest);
        $new_out   = Growl_Money::add($user->outbound_pending_micro ?? '0', $delta_out);
        $new_debt  = Growl_Money::add($user->debt_owed_micro ?? '0', $delta_debt);

        if (Growl_Money::cmp($new_avail, '0') < 0
            || Growl_Money::cmp($new_esc, '0') < 0
            || Growl_Money::cmp($new_disp, '0') < 0
            || Growl_Money::cmp($new_rest, '0') < 0
            || Growl_Money::cmp($new_out, '0') < 0
            || Growl_Money::cmp($new_debt, '0') < 0) {
            return new WP_Error('insufficient_balance', 'Insufficient EarnFi Balance.', ['status' => 402]);
        }

        $updated = $this->db->query(
            "UPDATE {$p}users SET
                available_micro = %s,
                escrow_held_micro = %s,
                dispute_held_micro = %s,
                restriction_held_micro = %s,
                outbound_pending_micro = %s,
                debt_owed_micro = %s,
                usd_balance = %f,
                negative_balance = %f
             WHERE user_id = %d",
            [
                $new_avail,
                $new_esc,
                $new_disp,
                $new_rest,
                $new_out,
                $new_debt,
                (float) Growl_Money::to_usdc_string($new_avail),
                (float) Growl_Money::to_usdc_string($new_debt),
                $user_id,
            ]
        );
        if ($updated === false) {
            return new WP_Error('db_error', 'Balance update failed.', ['status' => 500]);
        }

        $amount_signed = Growl_Money::add(
            Growl_Money::add(Growl_Money::add($delta_avail, $delta_esc), $delta_disp),
            Growl_Money::add(Growl_Money::add($delta_rest, $delta_out), $delta_debt)
        );
        $insert_id = $this->db->insert('balance_ledger', [
            'user_id'                => $user_id,
            'amount_micro'           => $amount_signed,
            'delta_available'        => $delta_avail,
            'delta_escrow'           => $delta_esc,
            'delta_dispute'          => $delta_disp,
            'delta_restriction'      => $delta_rest,
            'delta_outbound_pending' => $delta_out,
            'delta_debt'             => $delta_debt,
            'entry_type'             => sanitize_key((string) ($entry['entry_type'] ?? 'adjustment')),
            'purpose'                => $purpose,
            'ref_type'               => $ref_type,
            'ref_id'                 => $ref_id,
            'settlement_id'          => isset($entry['settlement_id']) ? (int) $entry['settlement_id'] : null,
            'idempotency_key'        => $idempotency !== '' ? $idempotency : null,
            'created_at'             => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$insert_id) {
            // Concurrent cron: unique idempotency collision = already posted successfully.
            $db_err = '';
            if (isset($this->db->wpdb) && is_object($this->db->wpdb)) {
                $db_err = (string) ($this->db->wpdb->last_error ?? '');
            }
            $duplicate_idempotency = $idempotency !== ''
                && (
                    $this->ledger_idempotency_exists($idempotency)
                    || stripos($db_err, 'Duplicate') !== false
                    || stripos($db_err, 'uq_balance_idempotency') !== false
                );
            if ($duplicate_idempotency) {
                // This worker updated balances then lost the insert race — realign from ledger.
                $this->repair_user_balance_from_ledger($user_id);
                return true;
            }
            return new WP_Error('db_error', 'Ledger insert failed.', ['status' => 500]);
        }

        return true;
    }

    public function user_has_unresolved_drift($user_id) {
        $ids = get_option('growl_ledger_drift_user_ids', []);
        return is_array($ids) && in_array((int) $user_id, array_map('intval', $ids), true);
    }

    /**
     * Align user micro columns to the ledger (source of truth).
     * Does NOT create continuous legacy_sync / gap rows — use one-time bootstrap only.
     */
    public function sync_user_balance_state($user_id) {
        return $this->ensure_ledger_authority($user_id);
    }

    /**
     * Seed balance_ledger from legacy USD columns when no ledger exists yet.
     */
    private function bootstrap_ledger_from_legacy_columns($user_id) {
        $user_id = (int) $user_id;
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT usd_balance, locked_usd_balance, dispute_usd_balance, negative_balance FROM {$p}users WHERE user_id = %d LIMIT 1",
            [$user_id]
        );
        if (!$row) {
            return false;
        }

        $buckets = [
            ['field' => 'delta_available_micro', 'micro' => Growl_Money::to_micro($row->usd_balance ?? 0), 'suffix' => 'avail'],
            ['field' => 'delta_escrow_micro', 'micro' => Growl_Money::to_micro($row->locked_usd_balance ?? 0), 'suffix' => 'esc'],
            ['field' => 'delta_dispute_micro', 'micro' => Growl_Money::to_micro($row->dispute_usd_balance ?? 0), 'suffix' => 'disp'],
            ['field' => 'delta_debt_micro', 'micro' => Growl_Money::to_micro(abs((float) ($row->negative_balance ?? 0))), 'suffix' => 'debt'],
        ];

        $created = false;
        foreach ($buckets as $bucket) {
            if (!Growl_Money::is_positive($bucket['micro'])) {
                continue;
            }
            $idem = 'legacy_bootstrap:' . $user_id . ':' . $bucket['suffix'];
            $exists = $this->db->get_var(
                "SELECT ledger_id FROM {$p}balance_ledger WHERE idempotency_key = %s LIMIT 1",
                [$idem]
            );
            if ($exists) {
                continue;
            }
            $entry = [
                'user_id'                         => $user_id,
                'delta_available_micro'           => '0',
                'delta_escrow_micro'              => '0',
                'delta_dispute_micro'             => '0',
                'delta_restriction_micro'         => '0',
                'delta_outbound_pending_micro'    => '0',
                'delta_debt_micro'                => '0',
                'entry_type'                      => 'legacy_bootstrap',
                'purpose'                         => 'legacy_balance_bootstrap',
                'ref_type'                        => 'system',
                'ref_id'                          => 'legacy_usd',
                'idempotency_key'                 => $idem,
            ];
            $entry[$bucket['field']] = $bucket['micro'];
            $applied = $this->apply_ledger_entry($entry);
            if (is_wp_error($applied)) {
                return false;
            }
            $created = true;
        }

        if (!$created) {
            return true;
        }
        return $this->repair_user_balance_from_ledger($user_id);
    }

    /**
     * Re-sync user micro columns from balance_ledger sums and clear drift flag.
     */
    public function repair_user_balance_from_ledger($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return false;
        }
        $p = $this->db->table_prefix;
        $ledger_count = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$p}balance_ledger WHERE user_id = %d",
            [$user_id]
        );
        if ($ledger_count === 0) {
            return $this->bootstrap_ledger_from_legacy_columns($user_id);
        }
        $sums = $this->db->get_row(
            "SELECT COALESCE(SUM(delta_available), 0) AS avail,
                    COALESCE(SUM(delta_escrow), 0) AS esc,
                    COALESCE(SUM(delta_dispute), 0) AS disp,
                    COALESCE(SUM(delta_restriction), 0) AS rest,
                    COALESCE(SUM(delta_outbound_pending), 0) AS outb,
                    COALESCE(SUM(delta_debt), 0) AS debt
             FROM {$p}balance_ledger WHERE user_id = %d",
            [$user_id]
        );
        if (!$sums) {
            return false;
        }
        $avail = (string) $sums->avail;
        $esc   = (string) $sums->esc;
        $disp  = (string) $sums->disp;
        $rest  = (string) ($sums->rest ?? '0');
        $outb  = (string) ($sums->outb ?? '0');
        $debt  = (string) ($sums->debt ?? '0');
        $updated = $this->db->query(
            "UPDATE {$p}users SET
                available_micro = %s,
                escrow_held_micro = %s,
                dispute_held_micro = %s,
                restriction_held_micro = %s,
                outbound_pending_micro = %s,
                debt_owed_micro = %s,
                usd_balance = %f,
                negative_balance = %f
             WHERE user_id = %d",
            [
                $avail,
                $esc,
                $disp,
                $rest,
                $outb,
                $debt,
                (float) Growl_Money::to_usdc_string($avail),
                (float) Growl_Money::to_usdc_string($debt),
                $user_id,
            ]
        );
        if ($updated === false) {
            return false;
        }
        $ids = get_option('growl_ledger_drift_user_ids', []);
        if (is_array($ids)) {
            $ids = array_values(array_filter(array_map('intval', $ids), static function ($id) use ($user_id) {
                return $id !== $user_id;
            }));
            update_option('growl_ledger_drift_user_ids', $ids, false);
        }
        return true;
    }

    /**
     * Credit earnings debt-first, then to available or lock destination.
     * Always upserts one wallet_tx lifecycle row when $wtx is provided.
     *
     * @param array<string,mixed> $opts destination: available|locked|restriction; wallet_tx meta
     * @return true|WP_Error
     */
    public function credit_earn($user_id, $amount_micro, $purpose, $ref_type, $ref_id, $idempotency_key, array $opts = []) {
        $user_id = (int) $user_id;
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        if ($user_id < 1 || !Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid_earn', 'Invalid earn credit.', ['status' => 400]);
        }
        $this->sync_user_balance_state($user_id);
        $destination = sanitize_key((string) ($opts['destination'] ?? 'available'));
        if (!in_array($destination, ['available', 'locked', 'restriction'], true)) {
            $destination = 'available';
        }

        $this->db->begin_transaction();
        $bal = $this->get_user_balances_raw($user_id);
        $debt = (string) ($bal['debt_owed_micro'] ?? '0');
        $to_debt = '0';
        $remainder = $amount_micro;
        if (Growl_Money::is_positive($debt)) {
            if (Growl_Money::cmp($remainder, $debt) >= 0) {
                $to_debt = $debt;
                $remainder = Growl_Money::sub($remainder, $debt);
            } else {
                $to_debt = $remainder;
                $remainder = '0';
            }
        }

        $entry = [
            'user_id'               => $user_id,
            'delta_available_micro' => '0',
            'delta_escrow_micro'    => '0',
            'delta_restriction_micro' => '0',
            'delta_debt_micro'      => Growl_Money::is_positive($to_debt) ? Growl_Money::sub('0', $to_debt) : '0',
            'entry_type'            => sanitize_key((string) ($opts['entry_type'] ?? 'earn')),
            'purpose'               => sanitize_key((string) $purpose),
            'ref_type'              => sanitize_key((string) $ref_type),
            'ref_id'                => sanitize_text_field((string) $ref_id),
            'idempotency_key'       => $idempotency_key,
        ];
        if (Growl_Money::is_positive($remainder)) {
            if ($destination === 'locked') {
                $entry['delta_escrow_micro'] = $remainder;
            } elseif ($destination === 'restriction') {
                $entry['delta_restriction_micro'] = $remainder;
            } else {
                $entry['delta_available_micro'] = $remainder;
            }
        }

        $result = $this->apply_ledger_entry_inner($entry);
        if (is_wp_error($result)) {
            $this->db->rollback();
            return $result;
        }

        $wtx_status = 'succeeded';
        $wtx_bucket = 'available';
        $timeline_label = 'Credit applied';
        if ($destination === 'locked') {
            $wtx_status = 'locked';
            $wtx_bucket = 'escrow';
            $timeline_label = 'Earnings locked';
        } elseif ($destination === 'restriction') {
            $wtx_status = 'restricted';
            $wtx_bucket = 'restriction';
            $timeline_label = 'Earnings held under account restriction';
        }
        if (Growl_Money::is_positive($to_debt) && !Growl_Money::is_positive($remainder)) {
            $timeline_label = 'Credit applied to outstanding balance';
        }

        $wtx_err = $this->upsert_wallet_tx_lifecycle([
            'user_id'         => $user_id,
            'type'            => sanitize_key((string) ($opts['wtx_type'] ?? 'earn')),
            'status'          => $wtx_status,
            'amount_micro'    => $amount_micro,
            'title'           => (string) ($opts['title'] ?? 'Earnings'),
            'subtitle'        => (string) ($opts['subtitle'] ?? ''),
            'bucket'          => $wtx_bucket,
            'ref_type'        => sanitize_key((string) $ref_type),
            'ref_id'          => sanitize_text_field((string) $ref_id),
            'idempotency_key' => 'wtx:' . Growl_Money::sanitize_idempotency_key($idempotency_key),
            'timeline'        => [
                ['status' => 'pending', 'label' => 'Credit initiated', 'at' => current_time('mysql')],
                ['status' => $wtx_status, 'label' => $timeline_label, 'at' => current_time('mysql')],
            ],
        ]);
        if (is_wp_error($wtx_err)) {
            $this->db->rollback();
            return $wtx_err;
        }
        $this->db->commit();
        return true;
    }

    /**
     * Unlock locked earn into available, or into restriction if account is restricted.
     * If escrow is short but the earn already landed in available/restriction (e.g. reconcile
     * or instant credit), treat as successful no-op so completion can leave locked status.
     *
     * @return true|WP_Error
     */
    public function unlock_earn($user_id, $amount_micro, $purpose, $ref_type, $ref_id, $idempotency_key, array $opts = []) {
        $user_id = (int) $user_id;
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        if ($user_id < 1 || !Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid_unlock', 'Invalid unlock.', ['status' => 400]);
        }
        $to_restriction = !empty($opts['to_restriction']);
        $idempotency_key = Growl_Money::sanitize_idempotency_key($idempotency_key);

        // Already unlocked on the ledger — idempotent success.
        if ($idempotency_key !== '' && $this->ledger_idempotency_exists($idempotency_key)) {
            return true;
        }

        $this->db->begin_transaction();
        $bal = $this->get_user_balances_raw($user_id);
        $escrow = (string) ($bal['escrow_held_micro'] ?? '0');

        if (Growl_Money::cmp($escrow, $amount_micro) >= 0) {
            $entry = [
                'user_id'                 => $user_id,
                'delta_escrow_micro'      => Growl_Money::sub('0', $amount_micro),
                'delta_available_micro'   => $to_restriction ? '0' : $amount_micro,
                'delta_restriction_micro' => $to_restriction ? $amount_micro : '0',
                'entry_type'              => 'unlock',
                'purpose'                 => sanitize_key((string) $purpose),
                'ref_type'                => sanitize_key((string) $ref_type),
                'ref_id'                  => sanitize_text_field((string) $ref_id),
                'idempotency_key'         => $idempotency_key,
            ];
            $result = $this->apply_ledger_entry_inner($entry);
            if (is_wp_error($result)) {
                $this->db->rollback();
                return $result;
            }
        } elseif ($this->earn_already_spendable($user_id, $ref_type, $ref_id, $amount_micro, $to_restriction)) {
            // Funds never sat in escrow (credited straight to available/restriction). Record a
            // zero-bucket unlock marker so cron can clear locked status without double-moving.
            $marker = $this->apply_ledger_entry_inner([
                'user_id'         => $user_id,
                'entry_type'      => 'unlock',
                'purpose'         => sanitize_key((string) $purpose),
                'ref_type'        => sanitize_key((string) $ref_type),
                'ref_id'          => sanitize_text_field((string) $ref_id),
                'idempotency_key' => $idempotency_key,
                // Explicit zeros — amount_micro 0 journal row for audit trail only.
                'delta_available_micro' => '0',
                'delta_escrow_micro'    => '0',
            ]);
            if (is_wp_error($marker)) {
                $this->db->rollback();
                return $marker;
            }
        } else {
            $this->db->rollback();
            return new WP_Error('insufficient_balance', 'Insufficient EarnFi Balance.', ['status' => 402]);
        }

        $status = $to_restriction ? 'restricted' : 'succeeded';
        $label = $to_restriction ? 'Unlocked into restriction hold' : 'Funds unlocked';
        $this->touch_wallet_tx_by_ref($user_id, $ref_type, $ref_id, $status, $label, $to_restriction ? 'restriction' : 'available');
        $this->db->commit();
        return true;
    }

    /**
     * True when a prior earn for this ref already credited available (or restriction if targeting that).
     */
    private function earn_already_spendable($user_id, $ref_type, $ref_id, $amount_micro, $expect_restriction) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT COALESCE(SUM(delta_available), 0) AS avail,
                    COALESCE(SUM(delta_escrow), 0) AS esc,
                    COALESCE(SUM(delta_restriction), 0) AS rest
             FROM {$p}balance_ledger
             WHERE user_id = %d AND ref_type = %s AND ref_id = %s
               AND purpose IN (
                    'task_reward','contest_prize','reconcile_missing_earn','unlocked_reward',
                    'referral_commission','referral_leftover_bonus','referral_update_commission',
                    'recover_sol_referral'
               )",
            [(int) $user_id, sanitize_key((string) $ref_type), sanitize_text_field((string) $ref_id)]
        );
        if (!$row) {
            return false;
        }
        if ($expect_restriction) {
            return Growl_Money::cmp((string) $row->rest, $amount_micro) >= 0
                || Growl_Money::cmp((string) $row->avail, $amount_micro) >= 0;
        }
        // Already in available, or still in escrow (caller handles escrow path), or restriction.
        return Growl_Money::cmp((string) $row->avail, $amount_micro) >= 0
            || Growl_Money::cmp((string) $row->rest, $amount_micro) >= 0;
    }

    private function ledger_idempotency_exists($key) {
        $key = Growl_Money::sanitize_idempotency_key($key);
        if ($key === '') {
            return false;
        }
        $p = $this->db->table_prefix;
        $found = (int) $this->db->get_var(
            "SELECT ledger_id FROM {$p}balance_ledger WHERE idempotency_key = %s LIMIT 1",
            [$key]
        );
        return $found > 0;
    }

    /**
     * Hold available → restriction_held (account restriction).
     *
     * @return true|WP_Error
     */
    public function hold_restriction($user_id, $amount_micro, $idempotency_key, $ref_id = '') {
        $user_id = (int) $user_id;
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        if ($user_id < 1 || !Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid_restriction', 'Invalid restriction hold.', ['status' => 400]);
        }
        $this->db->begin_transaction();
        $result = $this->apply_ledger_entry_inner([
            'user_id'                 => $user_id,
            'delta_available_micro'   => Growl_Money::sub('0', $amount_micro),
            'delta_restriction_micro' => $amount_micro,
            'entry_type'              => 'restriction_hold',
            'purpose'                 => 'restriction_earn_hold',
            'ref_type'                => 'restriction',
            'ref_id'                  => $ref_id !== '' ? sanitize_text_field((string) $ref_id) : (string) $user_id,
            'idempotency_key'         => $idempotency_key,
        ]);
        if (is_wp_error($result)) {
            $this->db->rollback();
            return $result;
        }
        $this->upsert_wallet_tx_lifecycle([
            'user_id'         => $user_id,
            'type'            => 'restriction_hold',
            'status'          => 'restricted',
            'amount_micro'    => $amount_micro,
            'title'           => 'Restriction hold',
            'bucket'          => 'restriction',
            'ref_type'        => 'restriction',
            'ref_id'          => (string) $user_id,
            'idempotency_key' => 'wtx:' . Growl_Money::sanitize_idempotency_key($idempotency_key),
            'timeline'        => [
                ['status' => 'restricted', 'label' => 'Earnings held under account restriction', 'at' => current_time('mysql')],
            ],
        ]);
        $this->db->commit();
        return true;
    }

    /**
     * Begin outbound: available → outbound_pending.
     *
     * @return true|WP_Error
     */
    public function begin_outbound($user_id, $amount_micro, $purpose, $ref_type, $ref_id, $idempotency_key, array $wtx = [], $manage_tx = true) {
        $user_id = (int) $user_id;
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        $bal = $this->get_user_balances_raw($user_id);
        if ($bal && Growl_Money::is_positive($bal['debt_owed_micro'] ?? '0')) {
            return new WP_Error('debt_owed', 'Clear outstanding balance before withdrawing.', ['status' => 402]);
        }
        if ($manage_tx) {
            $this->db->begin_transaction();
        }
        $result = $this->apply_ledger_entry_inner([
            'user_id'                        => $user_id,
            'delta_available_micro'          => Growl_Money::sub('0', $amount_micro),
            'delta_outbound_pending_micro'   => $amount_micro,
            'entry_type'                     => 'outbound',
            'purpose'                        => sanitize_key((string) $purpose),
            'ref_type'                       => sanitize_key((string) $ref_type),
            'ref_id'                         => sanitize_text_field((string) $ref_id),
            'idempotency_key'                => $idempotency_key,
        ]);
        if (is_wp_error($result)) {
            if ($manage_tx) {
                $this->db->rollback();
            }
            return $result;
        }
        $wtx_status = sanitize_key((string) ($wtx['status'] ?? 'pending'));
        $this->upsert_wallet_tx_lifecycle([
            'user_id'         => $user_id,
            'type'            => sanitize_key((string) ($wtx['type'] ?? 'transfer_out')),
            'status'          => $wtx_status,
            'amount_micro'    => Growl_Money::sub('0', $amount_micro),
            'title'           => (string) ($wtx['title'] ?? 'Transfer out'),
            'subtitle'        => (string) ($wtx['subtitle'] ?? ''),
            'bucket'          => 'outbound_pending',
            'ref_type'        => sanitize_key((string) $ref_type),
            'ref_id'          => sanitize_text_field((string) $ref_id),
            'tx_hash'         => (string) ($wtx['tx_hash'] ?? ''),
            'network'         => (string) ($wtx['network'] ?? ''),
            'idempotency_key' => 'wtx:' . Growl_Money::sanitize_idempotency_key($idempotency_key),
            'timeline'        => [
                ['status' => $wtx_status, 'label' => 'Withdrawal initiated', 'at' => current_time('mysql')],
            ],
        ]);
        if ($manage_tx) {
            $this->db->commit();
        }
        return true;
    }

    /**
     * Settle outbound: clear outbound_pending (funds left the platform).
     *
     * @return true|WP_Error
     */
    public function settle_outbound($user_id, $amount_micro, $purpose, $ref_type, $ref_id, $idempotency_key, array $wtx = [], $manage_tx = true) {
        if ($manage_tx) {
            $this->db->begin_transaction();
        }
        $result = $this->apply_ledger_entry_inner([
            'user_id'                      => (int) $user_id,
            'delta_outbound_pending_micro' => Growl_Money::sub('0', Growl_Money::assert_micro($amount_micro)),
            'entry_type'                   => 'outbound_settle',
            'purpose'                      => sanitize_key((string) $purpose),
            'ref_type'                     => sanitize_key((string) $ref_type),
            'ref_id'                       => sanitize_text_field((string) $ref_id),
            'idempotency_key'              => $idempotency_key,
        ]);
        if (is_wp_error($result)) {
            if ($manage_tx) {
                $this->db->rollback();
            }
            return $result;
        }
        $this->touch_wallet_tx_by_ref(
            (int) $user_id,
            $ref_type,
            $ref_id,
            'succeeded',
            (string) ($wtx['label'] ?? 'Withdrawal completed'),
            'available',
            $wtx
        );
        if ($manage_tx) {
            $this->db->commit();
        }
        return true;
    }

    /**
     * Refund outbound: outbound_pending → available.
     *
     * @return true|WP_Error
     */
    public function refund_outbound($user_id, $amount_micro, $purpose, $ref_type, $ref_id, $idempotency_key, array $wtx = [], $manage_tx = true) {
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        if ($manage_tx) {
            $this->db->begin_transaction();
        }
        $result = $this->apply_ledger_entry_inner([
            'user_id'                      => (int) $user_id,
            'delta_outbound_pending_micro' => Growl_Money::sub('0', $amount_micro),
            'delta_available_micro'        => $amount_micro,
            'entry_type'                   => 'outbound_refund',
            'purpose'                      => sanitize_key((string) $purpose),
            'ref_type'                     => sanitize_key((string) $ref_type),
            'ref_id'                       => sanitize_text_field((string) $ref_id),
            'idempotency_key'              => $idempotency_key,
        ]);
        if (is_wp_error($result)) {
            if ($manage_tx) {
                $this->db->rollback();
            }
            return $result;
        }
        $this->touch_wallet_tx_by_ref(
            (int) $user_id,
            $ref_type,
            $ref_id,
            !empty($wtx['cancelled']) ? 'cancelled' : 'failed',
            (string) ($wtx['label'] ?? 'Withdrawal returned'),
            'available',
            $wtx
        );
        if ($manage_tx) {
            $this->db->commit();
        }
        return true;
    }

    /**
     * After on-chain success: settle outbound_pending when present (no-op for legacy available-only debits).
     *
     * @return true|WP_Error
     */
    public function settle_withdrawal_completion($user_id, $amount_micro, $withdrawal_id, $purpose = 'withdrawal', array $wtx = [], $manage_tx = true) {
        $withdrawal_id = (int) $withdrawal_id;
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        $bal = $this->get_user_balances_raw((int) $user_id);
        $pending = (string) ($bal['outbound_pending_micro'] ?? '0');
        if (!Growl_Money::is_positive($pending) || Growl_Money::cmp($pending, $amount_micro) < 0) {
            return true;
        }
        return $this->settle_outbound(
            (int) $user_id,
            $amount_micro,
            $purpose,
            'withdrawal',
            (string) $withdrawal_id,
            'withdraw_settle:' . $withdrawal_id,
            $wtx,
            $manage_tx
        );
    }

    /**
     * Debit available; overflow increases debt_owed (admin deduct / dispute penalty).
     *
     * @return true|WP_Error
     */
    public function apply_penalty_debit($user_id, $amount_micro, $purpose, $ref_type, $ref_id, $idempotency_key, array $opts = []) {
        $user_id = (int) $user_id;
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        if ($user_id < 1 || !Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid_penalty', 'Invalid penalty.', ['status' => 400]);
        }
        $this->sync_user_balance_state($user_id);
        $this->db->begin_transaction();
        $bal = $this->get_user_balances_raw($user_id);
        $avail = (string) ($bal['available_micro'] ?? '0');
        $from_avail = Growl_Money::cmp($avail, $amount_micro) >= 0 ? $amount_micro : $avail;
        $to_debt = Growl_Money::sub($amount_micro, $from_avail);

        $result = $this->apply_ledger_entry_inner([
            'user_id'               => $user_id,
            'delta_available_micro' => Growl_Money::is_positive($from_avail) ? Growl_Money::sub('0', $from_avail) : '0',
            'delta_debt_micro'      => Growl_Money::is_positive($to_debt) ? $to_debt : '0',
            'entry_type'            => sanitize_key((string) ($opts['entry_type'] ?? 'penalty')),
            'purpose'               => sanitize_key((string) $purpose),
            'ref_type'              => sanitize_key((string) $ref_type),
            'ref_id'                => sanitize_text_field((string) $ref_id),
            'idempotency_key'       => $idempotency_key,
        ]);
        if (is_wp_error($result)) {
            $this->db->rollback();
            return $result;
        }
        $status = !empty($opts['disputed']) ? 'disputed' : 'succeeded';
        $label = Growl_Money::is_positive($to_debt)
            ? 'Penalty applied · outstanding balance updated'
            : 'Penalty applied';
        $this->upsert_wallet_tx_lifecycle([
            'user_id'         => $user_id,
            'type'            => sanitize_key((string) ($opts['wtx_type'] ?? 'adjustment')),
            'status'          => $status,
            'amount_micro'    => Growl_Money::sub('0', $amount_micro),
            'title'           => (string) ($opts['title'] ?? 'Balance adjustment'),
            'subtitle'        => (string) ($opts['subtitle'] ?? ''),
            'bucket'          => 'available',
            'ref_type'        => sanitize_key((string) $ref_type),
            'ref_id'          => sanitize_text_field((string) $ref_id),
            'idempotency_key' => 'wtx:' . Growl_Money::sanitize_idempotency_key($idempotency_key),
            'timeline'        => [
                ['status' => 'pending', 'label' => 'Adjustment initiated', 'at' => current_time('mysql')],
                ['status' => $status, 'label' => $label, 'at' => current_time('mysql')],
            ],
        ]);
        $this->db->commit();
        return true;
    }

    /**
     * @param array<string,mixed> $data
     * @return int|WP_Error|true
     */
    private function upsert_wallet_tx_lifecycle(array $data) {
        if (!class_exists('Growl_Wallet_Tx_Service', false)) {
            require_once __DIR__ . '/class-growl-wallet-tx-service.php';
        }
        $svc = new Growl_Wallet_Tx_Service($this->db);
        $timeline = isset($data['timeline']) && is_array($data['timeline']) ? $data['timeline'] : [];
        unset($data['timeline']);
        if (!empty($timeline)) {
            $data['meta'] = array_merge(isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [], [
                'timeline' => $timeline,
            ]);
        }
        return $svc->upsert($data);
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function touch_wallet_tx_by_ref($user_id, $ref_type, $ref_id, $status, $label, $bucket = null, array $extra = []) {
        if (!class_exists('Growl_Wallet_Tx_Service', false)) {
            require_once __DIR__ . '/class-growl-wallet-tx-service.php';
        }
        $svc = new Growl_Wallet_Tx_Service($this->db);
        $tx_id = $svc->find_by_ref((int) $user_id, $ref_type, $ref_id);
        if (!$tx_id) {
            return;
        }
        $payload = [
            'status' => sanitize_key((string) $status),
            'append_timeline' => [
                'status' => sanitize_key((string) $status),
                'label'  => sanitize_text_field((string) $label),
                'at'     => current_time('mysql'),
            ],
        ];
        if ($bucket !== null) {
            $payload['bucket'] = sanitize_key((string) $bucket);
        }
        if (!empty($extra['tx_hash'])) {
            $payload['tx_hash'] = sanitize_text_field((string) $extra['tx_hash']);
        }
        if (!empty($extra['network'])) {
            $payload['network'] = sanitize_text_field((string) $extra['network']);
        }
        $svc->update($tx_id, $payload);
    }

    public function lock_escrow($buyer_id, $amount_micro, $purpose, $ref_type, $ref_id, $idempotency_key, $settlement_id = null, $manage_tx = true) {
        $this->sync_user_balance_state((int) $buyer_id);
        $amount_micro = (string) $amount_micro;
        if (!Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid_amount', 'Invalid amount.', ['status' => 400]);
        }
        if (!$settlement_id) {
            return new WP_Error('missing_settlement', 'Payment could not be verified.', ['status' => 400]);
        }
        $result = $this->apply_ledger_entry([
            'user_id'               => $buyer_id,
            'delta_available_micro' => Growl_Money::sub('0', $amount_micro),
            'delta_escrow_micro'    => $amount_micro,
            'entry_type'            => 'escrow_lock',
            'purpose'               => $purpose,
            'ref_type'              => $ref_type,
            'ref_id'                => $ref_id,
            'idempotency_key'       => $idempotency_key,
            'settlement_id'         => $settlement_id,
        ], $manage_tx);
        if (!is_wp_error($result)) {
            if (!class_exists('Growl_Wallet_Tx_Service')) {
                require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-wallet-tx-service.php';
            }
            $wtx = new Growl_Wallet_Tx_Service($this->db);
            $purpose_key = sanitize_key((string) $purpose);
            $type = 'escrow_hold';
            $title = 'Funds held';
            if (strpos($purpose_key, 'agent') !== false || (string) $ref_type === 'agent_order') {
                $type = 'agent_hire';
                $title = 'Agent hire';
            } elseif (strpos($purpose_key, 'deal') !== false || strpos((string) $ref_type, 'deal') !== false) {
                $type = 'deal';
                $title = 'Deal funded';
            }
            $wtx->upsert([
                'user_id'         => (int) $buyer_id,
                'type'            => $type,
                'status'          => 'processing',
                'amount_micro'    => Growl_Money::sub('0', $amount_micro),
                'title'           => $title,
                'subtitle'        => '',
                'bucket'          => 'escrow',
                'ref_type'        => sanitize_key((string) $ref_type),
                'ref_id'          => sanitize_text_field((string) $ref_id),
                'idempotency_key' => 'wtx:escrow:' . Growl_Money::sanitize_idempotency_key($idempotency_key),
                'created_at'      => current_time('mysql'),
                'meta'            => [
                    'timeline' => [[
                        'status' => 'processing',
                        'label'  => 'Payment held in escrow',
                        'at'     => current_time('mysql'),
                    ]],
                ],
            ]);
        }
        return $result;
    }

    /**
     * Release escrow using split-fee economics.
     * Buyer escrow unlocks deposit (gross + employer fee); seller receives net; platform gets both fees.
     *
     * @param int $employer_fee_bps Snapshot employer-side bps (0 = legacy gross-only lock)
     * @return array{fee_micro:string,net_micro:string,deposit_micro:string,employer_fee_micro:string,earner_fee_micro:string}|WP_Error
     */
    public function release_escrow_to_seller($buyer_id, $seller_id, $gross_micro, $fee_bps, $ref_type, $ref_id, $idempotency_key, $employer_fee_bps = 0) {
        $gross_micro = Growl_Money::assert_micro($gross_micro);
        $earner_fee  = Growl_Money::fee_micro($gross_micro, $fee_bps);
        $employer_fee = Growl_Money::fee_micro($gross_micro, (int) $employer_fee_bps);
        $deposit     = Growl_Money::add($gross_micro, $employer_fee);
        $net_micro   = Growl_Money::sub($gross_micro, $earner_fee);
        $fee_micro   = Growl_Money::add($employer_fee, $earner_fee);

        return $this->release_escrow_split(
            $buyer_id,
            $seller_id,
            $deposit,
            $net_micro,
            $fee_micro,
            $ref_type,
            $ref_id,
            $idempotency_key
        );
    }

    /**
     * Atomic escrow release with explicit deposit / net / platform micros (job contracts).
     *
     * @return array{fee_micro:string,net_micro:string,deposit_micro:string}|WP_Error
     */
    public function release_escrow_split($buyer_id, $seller_id, $deposit_micro, $net_micro, $platform_fee_micro, $ref_type, $ref_id, $idempotency_key) {
        $deposit_micro = Growl_Money::assert_micro($deposit_micro);
        $net_micro = Growl_Money::assert_micro($net_micro);
        $platform_fee_micro = Growl_Money::assert_micro($platform_fee_micro);

        $this->sync_user_balance_state((int) $buyer_id);

        $this->db->begin_transaction();
        try {
            $unlock = $this->apply_ledger_entry_inner([
                'user_id'               => $buyer_id,
                'delta_available_micro' => '0',
                'delta_escrow_micro'    => Growl_Money::sub('0', $deposit_micro),
                'entry_type'            => 'escrow_release_buyer',
                'purpose'               => 'escrow_release',
                'ref_type'              => $ref_type,
                'ref_id'                => $ref_id,
                'idempotency_key'       => $idempotency_key . ':buyer',
            ]);
            if (is_wp_error($unlock)) {
                $this->db->rollback();
                return $unlock;
            }

            if ((int) $seller_id > 0 && Growl_Money::is_positive($net_micro)) {
                $credit = $this->apply_ledger_entry_inner([
                    'user_id'               => $seller_id,
                    'delta_available_micro' => $net_micro,
                    'delta_escrow_micro'    => '0',
                    'entry_type'            => 'escrow_release_seller',
                    'purpose'               => 'escrow_release',
                    'ref_type'              => $ref_type,
                    'ref_id'                => $ref_id,
                    'idempotency_key'       => $idempotency_key . ':seller',
                ]);
                if (is_wp_error($credit)) {
                    $this->db->rollback();
                    return $credit;
                }
            }

            if (Growl_Money::is_positive($platform_fee_micro)) {
                $fee_credit = $this->credit_platform_fee_inner($platform_fee_micro, $ref_type, $ref_id, $idempotency_key . ':fee');
                if (is_wp_error($fee_credit)) {
                    $this->db->rollback();
                    return $fee_credit;
                }
            }

            $this->db->commit();
            if (!class_exists('Growl_Wallet_Tx_Service')) {
                require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-wallet-tx-service.php';
            }
            $wtx = new Growl_Wallet_Tx_Service($this->db);
            $ref_type_key = sanitize_key((string) $ref_type);
            $ref_id_key = sanitize_text_field((string) $ref_id);
            $buyer_tx = $wtx->find_by_ref((int) $buyer_id, $ref_type_key, $ref_id_key);
            if ($buyer_tx) {
                $keep_title = 'Agent hire';
                if (strpos($ref_type_key, 'deal') !== false && strpos($ref_type_key, 'agent_order') === false) {
                    $keep_title = 'Deal';
                }
                $wtx->update($buyer_tx, [
                    'status' => 'succeeded',
                    'title'  => $keep_title,
                    'bucket' => 'available',
                    'append_timeline' => [
                        'status' => 'succeeded',
                        'label'  => 'Completed · funds released',
                        'at'     => current_time('mysql'),
                    ],
                ]);
            }
            if ((int) $seller_id > 0 && Growl_Money::is_positive($net_micro)) {
                $wtx->upsert([
                    'user_id'         => (int) $seller_id,
                    'type'            => 'escrow_release',
                    'status'          => 'succeeded',
                    'amount_micro'    => $net_micro,
                    'title'           => 'Payout received',
                    'subtitle'        => '',
                    'bucket'          => 'available',
                    'ref_type'        => $ref_type_key,
                    'ref_id'          => $ref_id_key,
                    'idempotency_key' => 'wtx:release:' . Growl_Money::sanitize_idempotency_key($idempotency_key) . ':seller',
                    'created_at'      => current_time('mysql'),
                ]);
            }
            return [
                'fee_micro'     => $platform_fee_micro,
                'net_micro'     => $net_micro,
                'deposit_micro' => $deposit_micro,
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('release_failed', 'Release failed.', ['status' => 500]);
        }
    }

    /**
     * Credit platform take rate into treasury user (if configured) and always audit ledger.
     *
     * @return true|WP_Error
     */
    private function credit_platform_fee_inner($fee_micro, $ref_type, $ref_id, $idempotency_key) {
        $fee_micro = Growl_Money::assert_micro($fee_micro);
        if (!Growl_Money::is_positive($fee_micro)) {
            return true;
        }
        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key) ?: null;
        if ($idem !== null && $idem !== '') {
            $exists = $this->db->get_var(
                "SELECT fee_id FROM {$this->db->table_prefix}platform_fee_ledger WHERE idempotency_key = %s LIMIT 1",
                [$idem]
            );
            if ($exists) {
                // Already booked — still ensure distribution queue exists (idempotent).
                $this->enqueue_work_fee_for_distribution($fee_micro, $ref_type, $ref_id, $idempotency_key);
                return true;
            }
        }
        $this->db->insert('platform_fee_ledger', [
            'amount_micro'    => $fee_micro,
            'ref_type'        => sanitize_key((string) $ref_type),
            'ref_id'          => sanitize_text_field((string) $ref_id),
            'idempotency_key' => $idem,
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);

        // Same hourly on-chain distribution as job/contest platform fees.
        $this->enqueue_work_fee_for_distribution($fee_micro, $ref_type, $ref_id, $idempotency_key);

        $settings = get_option('growl_settings', []);
        $treasury_uid = (int) ($settings['work_fee_treasury_user_id'] ?? 0);
        if ($treasury_uid > 0) {
            return $this->apply_ledger_entry_inner([
                'user_id'               => $treasury_uid,
                'delta_available_micro' => $fee_micro,
                'delta_escrow_micro'    => '0',
                'entry_type'            => 'platform_fee',
                'purpose'               => 'platform_fee',
                'ref_type'              => $ref_type,
                'ref_id'                => $ref_id,
                'idempotency_key'       => $idempotency_key . ':treasury',
            ]);
        }
        return true;
    }

    /**
     * Insert a distribution-ready payments row so work-money fees share the hourly distributor.
     * Splits the fee using staking_vault / team_revenue settings (remainder → rewards wallet).
     */
    private function enqueue_work_fee_for_distribution($fee_micro, $ref_type, $ref_id, $idempotency_key) {
        $fee_micro = Growl_Money::assert_micro($fee_micro);
        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key);
        if ($idem === '') {
            $idem = 'work_fee:' . sanitize_key((string) $ref_type) . ':' . sanitize_text_field((string) $ref_id);
        }
        $idem = 'wf:' . substr($idem, 0, 60);
        $exists = $this->db->get_var(
            "SELECT payment_id FROM {$this->db->table_prefix}payments WHERE idempotency_key = %s LIMIT 1",
            [$idem]
        );
        if ($exists) {
            return;
        }

        $settings = get_option('growl_settings', []);
        $fee_usd = (float) Growl_Money::to_usdc_string($fee_micro);
        if ($fee_usd <= 0) {
            return;
        }
        $staking_pct = max(0, min(100, (float) ($settings['staking_vault'] ?? 50))) / 100.0;
        $team_pct = max(0, min(100, (float) ($settings['team_revenue'] ?? 20))) / 100.0;
        if ($staking_pct + $team_pct > 1) {
            $scale = 1 / ($staking_pct + $team_pct);
            $staking_pct *= $scale;
            $team_pct *= $scale;
        }
        $staking = round($fee_usd * $staking_pct, 6);
        $team = round($fee_usd * $team_pct, 6);
        $rewards = max(0, round($fee_usd - $staking - $team, 6));
        $to_atomic = static function ($usd) {
            return (string) (int) round(((float) $usd) * 1000000);
        };
        $hot = trim((string) ($settings['hot_wallet_address'] ?? $settings['reward_wallet_address'] ?? 'platform'));
        $this->db->insert('payments', [
            'idempotency_key'        => $idem,
            'from_wallet'            => mb_substr($hot !== '' ? $hot : 'platform', 0, 44),
            'to_wallet'              => 'distribution',
            'amount'                 => $fee_usd,
            'payment_asset'          => 'USDC',
            'principal_usdc_atomic'  => '0',
            'fee_usdc_atomic'        => $to_atomic($fee_usd),
            'fee_bps'                => 10000,
            'staking_usdc_atomic'    => $to_atomic($staking),
            'team_usdc_atomic'       => $to_atomic($team),
            'referral_usdc_atomic'   => '0',
            'bonus_usdc_atomic'      => '0',
            'rewards_usdc_atomic'    => $to_atomic($rewards),
            'distribution_ready'     => 1,
            'distribution_processed' => 0,
            'network'                => 'mainnet-beta',
            'status'                 => 'completed',
            'reference_id'           => mb_substr(sanitize_text_field((string) $ref_id), 0, 8),
            'reference_type'         => 'work_fee',
            'created_at'             => gmdate('Y-m-d H:i:s'),
            'completed_at'           => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Public wrapper for job contracts / agent releases that already unlocked escrow.
     *
     * @return true|WP_Error
     */
    public function credit_platform_fee($fee_micro, $ref_type, $ref_id, $idempotency_key) {
        $this->db->begin_transaction();
        $r = $this->credit_platform_fee_inner($fee_micro, $ref_type, $ref_id, $idempotency_key);
        if (is_wp_error($r)) {
            $this->db->rollback();
            return $r;
        }
        $this->db->commit();
        return true;
    }

    /**
     * Backfill undistributed platform_fee_ledger rows into payments for hourly distribution.
     */
    public function backfill_work_fees_into_distribution($limit = 100) {
        $limit = max(1, min(500, (int) $limit));
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT f.fee_id, f.amount_micro, f.ref_type, f.ref_id, f.idempotency_key
             FROM {$p}platform_fee_ledger f
             WHERE f.amount_micro > 0
               AND NOT EXISTS (
                    SELECT 1 FROM {$p}payments pay
                    WHERE pay.reference_type = 'work_fee'
                      AND (
                        (f.idempotency_key IS NOT NULL AND f.idempotency_key != '' AND pay.idempotency_key = CONCAT('wf:', LEFT(f.idempotency_key, 60)))
                        OR (pay.reference_id = LEFT(f.ref_id, 8) AND pay.created_at >= f.created_at - INTERVAL 1 DAY AND pay.created_at <= f.created_at + INTERVAL 1 DAY)
                      )
               )
             ORDER BY f.fee_id ASC
             LIMIT %d",
            [$limit]
        );
        $n = 0;
        foreach ($rows ?: [] as $row) {
            $idem = Growl_Money::sanitize_idempotency_key((string) ($row->idempotency_key ?? ''));
            if ($idem === '') {
                $idem = 'work_fee_bf:' . (int) $row->fee_id;
            }
            $this->enqueue_work_fee_for_distribution(
                $row->amount_micro,
                (string) $row->ref_type,
                (string) $row->ref_id,
                $idem
            );
            $n++;
        }
        return $n;
    }

    public function hold_dispute($buyer_id, $amount_micro, $ref_type, $ref_id, $idempotency_key) {
        $amount_micro = (string) $amount_micro;
        return $this->apply_ledger_entry([
            'user_id'               => $buyer_id,
            'delta_available_micro' => '0',
            'delta_escrow_micro'    => Growl_Money::sub('0', $amount_micro),
            'delta_dispute_micro'   => $amount_micro,
            'entry_type'            => 'dispute_hold',
            'purpose'               => 'deal_dispute',
            'ref_type'              => $ref_type,
            'ref_id'                => $ref_id,
            'idempotency_key'       => $idempotency_key,
        ]);
    }

    public function refund_escrow_to_buyer($buyer_id, $amount_micro, $ref_type, $ref_id, $idempotency_key, $from_dispute = false) {
        $amount_micro = (string) $amount_micro;
        $entry = [
            'user_id'               => $buyer_id,
            'delta_available_micro' => $amount_micro,
            'delta_escrow_micro'    => $from_dispute ? '0' : Growl_Money::sub('0', $amount_micro),
            'delta_dispute_micro'   => $from_dispute ? Growl_Money::sub('0', $amount_micro) : '0',
            'entry_type'            => 'escrow_refund',
            'purpose'               => 'deal_refund',
            'ref_type'              => $ref_type,
            'ref_id'                => $ref_id,
            'idempotency_key'       => $idempotency_key,
        ];
        return $this->apply_ledger_entry($entry);
    }

    /**
     * Compare cached user micro columns with ledger sums. Logs mismatches only.
     *
     * @return array{mismatches:int,checked:int}
     */
    public function reconcile_nightly($limit = 500) {
        $p = $this->db->table_prefix;
        $limit = max(1, min(2000, (int) $limit));
        $rows = $this->db->wpdb->get_results(
            $this->db->wpdb->prepare(
                "SELECT u.user_id, u.available_micro, u.escrow_held_micro, u.dispute_held_micro,
                        COALESCE(u.restriction_held_micro, 0) AS restriction_held_micro,
                        COALESCE(u.outbound_pending_micro, 0) AS outbound_pending_micro,
                        COALESCE(u.debt_owed_micro, 0) AS debt_owed_micro,
                        COALESCE(SUM(l.delta_available), 0) AS ledger_avail,
                        COALESCE(SUM(l.delta_escrow), 0) AS ledger_esc,
                        COALESCE(SUM(l.delta_dispute), 0) AS ledger_disp,
                        COALESCE(SUM(l.delta_restriction), 0) AS ledger_rest,
                        COALESCE(SUM(l.delta_outbound_pending), 0) AS ledger_out,
                        COALESCE(SUM(l.delta_debt), 0) AS ledger_debt
                 FROM {$p}users u
                 INNER JOIN (
                     SELECT user_id FROM {$p}balance_ledger
                     GROUP BY user_id
                     ORDER BY MAX(ledger_id) DESC
                     LIMIT %d
                 ) recent ON recent.user_id = u.user_id
                 LEFT JOIN {$p}balance_ledger l ON l.user_id = u.user_id
                 GROUP BY u.user_id, u.available_micro, u.escrow_held_micro, u.dispute_held_micro,
                          u.restriction_held_micro, u.outbound_pending_micro, u.debt_owed_micro",
                $limit
            ),
            ARRAY_A
        );
        $mismatches = 0;
        $drift_ids = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $disp_ok = !isset($row['ledger_disp']) || (string) ($row['dispute_held_micro'] ?? '0') === (string) $row['ledger_disp'];
                $rest_ok = !isset($row['ledger_rest']) || (string) ($row['restriction_held_micro'] ?? '0') === (string) $row['ledger_rest'];
                $out_ok = !isset($row['ledger_out']) || (string) ($row['outbound_pending_micro'] ?? '0') === (string) $row['ledger_out'];
                $debt_ok = !isset($row['ledger_debt']) || (string) ($row['debt_owed_micro'] ?? '0') === (string) $row['ledger_debt'];
                if ((string) $row['available_micro'] !== (string) $row['ledger_avail']
                    || (string) $row['escrow_held_micro'] !== (string) $row['ledger_esc']
                    || !$disp_ok
                    || !$rest_ok
                    || !$out_ok
                    || !$debt_ok) {
                    $mismatches++;
                    $drift_ids[] = (int) $row['user_id'];
                    error_log(sprintf(
                        'Growl_Balance_Service reconcile mismatch user_id=%d avail=%s ledger=%s escrow=%s ledger_esc=%s',
                        (int) $row['user_id'],
                        $row['available_micro'],
                        $row['ledger_avail'],
                        $row['escrow_held_micro'],
                        $row['ledger_esc']
                    ));
                }
            }
        }
        update_option('growl_ledger_drift_user_ids', $drift_ids, false);
        $this->db->query(
            "UPDATE {$p}payment_intents SET status = 'expired'
             WHERE status = 'quoted' AND expires_at < UTC_TIMESTAMP()"
        );
        return ['mismatches' => $mismatches, 'checked' => is_array($rows) ? count($rows) : 0];
    }

    /**
     * Peer-to-peer available-balance transfer.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function transfer_p2p($from_user_id, $to_user_id, $amount_micro, $idempotency_key = '', $note = '') {
        $from = (int) $from_user_id;
        $to   = (int) $to_user_id;
        $amount_micro = Growl_Money::assert_micro($amount_micro);

        if ($from < 1 || $to < 1) {
            return new WP_Error('invalid_user', 'Both users are required.', ['status' => 400]);
        }
        if ($from === $to) {
            return new WP_Error('invalid_user', 'You cannot send to yourself.', ['status' => 400]);
        }
        if (!Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid_amount', 'Amount must be greater than zero.', ['status' => 400]);
        }
        $settings = get_option('growl_settings', []);
        $min_internal = (float) ($settings['min_internal_withdrawal'] ?? 0.06);
        $amount_usd = (float) Growl_Money::to_usdc_string($amount_micro);
        if ($amount_usd + 1e-9 < $min_internal) {
            return new WP_Error(
                'min_internal_withdrawal',
                'Minimum internal transfer is $' . number_format($min_internal, 2) . ' USDC.',
                ['status' => 400]
            );
        }

        if (!class_exists('Growl_Account_Capability_Policy', false)) {
            require_once __DIR__ . '/class-growl-account-capability-policy.php';
        }
        $p2p_ok = (new Growl_Account_Capability_Policy($this->db))->assert(
            $from,
            Growl_Account_Capability_Policy::CAP_P2P_TRANSFER
        );
        if (is_wp_error($p2p_ok)) {
            return $p2p_ok;
        }

        $this->sync_user_balance_state($from);
        $this->sync_user_balance_state($to);

        $from_bal = $this->get_user_balances($from);
        if (!$from_bal) {
            return new WP_Error('user_not_found', 'Sender not found.', ['status' => 404]);
        }
        if (!$this->get_user_balances($to)) {
            return new WP_Error('user_not_found', 'Recipient not found.', ['status' => 404]);
        }
        if (Growl_Money::cmp($from_bal['available_micro'], $amount_micro) < 0) {
            return new WP_Error('insufficient_balance', 'Insufficient EarnFi Balance.', ['status' => 402]);
        }

        $base = Growl_Money::sanitize_idempotency_key($idempotency_key);
        if ($base === '') {
            $base = 'p2p:' . bin2hex(random_bytes(8));
        } else {
            $base = substr($base, 0, 120);
        }
        $ref_id = 'p2p_' . bin2hex(random_bytes(8));

        $this->db->begin_transaction();
        try {
            $debit = $this->apply_ledger_entry([
                'user_id'               => $from,
                'delta_available_micro' => Growl_Money::sub('0', $amount_micro),
                'entry_type'            => 'transfer',
                'purpose'               => 'p2p_send',
                'ref_type'              => 'p2p',
                'ref_id'                => $ref_id,
                'idempotency_key'       => $base . ':out',
            ], false);
            if (is_wp_error($debit)) {
                $this->db->rollback();
                return $debit;
            }
            $credit = $this->apply_ledger_entry([
                'user_id'               => $to,
                'delta_available_micro' => $amount_micro,
                'entry_type'            => 'transfer',
                'purpose'               => 'p2p_receive',
                'ref_type'              => 'p2p',
                'ref_id'                => $ref_id,
                'idempotency_key'       => $base . ':in',
            ], false);
            if (is_wp_error($credit)) {
                $this->db->rollback();
                return $credit;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('transfer_failed', 'Transfer could not be completed.', ['status' => 500]);
        }

        if (!class_exists('Growl_Wallet_Tx_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-wallet-tx-service.php';
        }
        $wtx = new Growl_Wallet_Tx_Service($this->db);
        $wtx->upsert([
            'user_id'         => $from,
            'type'            => 'transfer_out',
            'status'          => 'succeeded',
            'amount_micro'    => Growl_Money::sub('0', $amount_micro),
            'title'           => 'Transfer out',
            'subtitle'        => '',
            'bucket'          => 'available',
            'ref_type'        => 'p2p',
            'ref_id'          => $ref_id,
            'idempotency_key' => 'wtx:' . $base . ':out',
        ]);
        $wtx->upsert([
            'user_id'         => $to,
            'type'            => 'transfer_in',
            'status'          => 'succeeded',
            'amount_micro'    => $amount_micro,
            'title'           => 'Transfer in',
            'subtitle'        => '',
            'bucket'          => 'available',
            'ref_type'        => 'p2p',
            'ref_id'          => $ref_id,
            'idempotency_key' => 'wtx:' . $base . ':in',
        ]);

        return [
            'ok'             => true,
            'amount_micro'   => $amount_micro,
            'amount_display' => Growl_Money::to_display($amount_micro),
            'ref_id'         => $ref_id,
            'from'           => $this->get_user_balances($from),
            'to'             => $this->get_user_balances($to),
        ];
    }

    /**
     * Send USDC from EarnFi balance to an external Solana wallet.
     *
     * Creates a durable withdrawals row before debiting, then signs via wallet manager
     * with that withdrawal_id (never a ledger_id). Over max_auto or low platform liquidity
     * leaves the row pending for manual approval (same as legacy withdrawal flow).
     *
     * @return array<string,mixed>|WP_Error
     */
    public function send_external($user_id, $amount_micro, $destination_wallet, $idempotency_key = '') {
        $user_id = (int) $user_id;
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        $destination_wallet = trim((string) $destination_wallet);
        $settings = get_option('growl_settings', []);
        $min_withdrawal = (float) ($settings['min_withdrawal'] ?? 0.3);
        $max_withdrawal = (float) ($settings['max_withdrawal'] ?? 500);
        $max_auto_withdrawal = (float) ($settings['max_auto_withdrawal'] ?? 10);

        if (!Growl_Money::is_positive($amount_micro)) {
            return new WP_Error('invalid_amount', 'Enter an amount greater than zero.', ['status' => 400]);
        }
        $amount_usd = (float) Growl_Money::to_usdc_string($amount_micro);
        if ($amount_usd + 1e-9 < $min_withdrawal) {
            return new WP_Error(
                'min_withdrawal',
                'Minimum transfer is $' . number_format($min_withdrawal, 2) . ' USDC.',
                ['status' => 400]
            );
        }
        if ($amount_usd > $max_withdrawal + 1e-9) {
            return new WP_Error(
                'max_withdrawal',
                'Maximum transfer is $' . number_format($max_withdrawal, 2) . ' USDC.',
                ['status' => 400]
            );
        }
        if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $destination_wallet)) {
            return new WP_Error('invalid_wallet', 'Enter a valid Solana wallet address.', ['status' => 400]);
        }

        $policy = $this->assert_outbound_transfer_allowed($user_id);
        if (is_wp_error($policy)) {
            return $policy;
        }
        $debt_gate = $this->get_user_balances_raw($user_id);
        if ($debt_gate && Growl_Money::is_positive($debt_gate['debt_owed_micro'] ?? '0')) {
            return new WP_Error('debt_owed', 'Clear outstanding balance before withdrawing.', ['status' => 402]);
        }

        $this->sync_user_balance_state($user_id);

        if (!class_exists('GROWL_Wallet_Manager', false)) {
            require_once GROWL_PLUGIN_PATH . 'includes/class-wallet-manager.php';
        }
        $wallet_manager = new GROWL_Wallet_Manager();
        if (!$wallet_manager->validate_wallet_address($destination_wallet)) {
            return new WP_Error('invalid_wallet', 'Enter a valid Solana wallet address.', ['status' => 400]);
        }

        $bal = $this->get_user_balances($user_id);
        if (!$bal) {
            return new WP_Error('user_not_found', 'Account not found.', ['status' => 404]);
        }
        if (Growl_Money::cmp($bal['available_micro'], $amount_micro) < 0) {
            return new WP_Error('insufficient_balance', 'Insufficient balance.', ['status' => 402]);
        }

        if (!class_exists('GROWL_GasFeeService', false)) {
            require_once GROWL_PLUGIN_PATH . 'includes/class-gas-fee-service.php';
        }
        $gas_fee_service = new GROWL_GasFeeService();
        $gas_fee = $gas_fee_service->get_current_gas_fee()['gas_fee'];
        if ($gas_fee < 0.0000055) {
            $gas_fee = 0.0000055;
        }
        if (!class_exists('GROWL_Currency', false)) {
            require_once GROWL_PLUGIN_PATH . 'includes/class-currency.php';
        }
        $currency = new GROWL_Currency();
        $sol_price = $currency->get_sol_exchange_rate();
        $gas_fee_usd = $sol_price > 0 ? $gas_fee * $sol_price : 0;
        $net_amount = max(0, $amount_usd - $gas_fee_usd);
        if ($net_amount <= 0) {
            return new WP_Error('invalid_amount', 'Amount is too small after network fees.', ['status' => 400]);
        }

        $base = Growl_Money::sanitize_idempotency_key($idempotency_key);
        if ($base === '') {
            $base = 'send:' . bin2hex(random_bytes(8));
        }

        $reward_wallet = $settings['reward_wallet_address'] ?? '';
        $network = method_exists($wallet_manager, 'get_network') ? $wallet_manager->get_network() : 'mainnet-beta';
        $token_mint = $settings['usdc_mint_address'] ?? '';
        $token_decimals = (int) ($settings['usdc_decimals'] ?? 6);
        $gas_buffer = (float) ($settings['gas_fee_buffer'] ?? 0.00001);

        $requires_manual_approval = $amount_usd > $max_auto_withdrawal;
        $insufficient_funds = false;

        // Mirror legacy withdrawal: low platform liquidity → pending, do not fail the user.
        if (!$requires_manual_approval && $reward_wallet !== '' && $token_mint !== '') {
            $reward_token_balance = $wallet_manager->get_token_balance($reward_wallet, $token_mint, $token_decimals);
            $reward_sol_balance = $wallet_manager->get_wallet_balance($reward_wallet);
            if ($reward_token_balance < $amount_usd - 0.000001) {
                $requires_manual_approval = true;
                $insufficient_funds = true;
            }
            if ($reward_sol_balance < $gas_buffer - 0.000001) {
                $requires_manual_approval = true;
                $insufficient_funds = true;
            }
        }

        $status = $requires_manual_approval ? 'pending' : 'processing';
        $wtx_status = $requires_manual_approval ? 'pending' : 'processing';

        $this->db->begin_transaction();
        $withdrawal_id = $this->db->insert('withdrawals', [
            'user_id'                  => $user_id,
            'from_wallet'              => $reward_wallet ?: 'platform',
            'to_wallet'                => $destination_wallet,
            'amount'                   => $amount_usd,
            'gas_fee'                  => $gas_fee,
            'net_amount'               => $net_amount,
            'network'                  => $network,
            'status'                   => $status,
            'requires_manual_approval' => $requires_manual_approval ? 1 : 0,
            'failure_reason'           => $insufficient_funds ? 'insufficient_funds' : null,
            'admin_notes'              => 'outbound_send:' . $base,
            'created_at'               => current_time('mysql'),
        ]);
        if (!$withdrawal_id) {
            $this->db->rollback();
            return new WP_Error('db_error', 'Could not create outbound transfer record.', ['status' => 500]);
        }

        $debit = $this->begin_outbound(
            $user_id,
            $amount_micro,
            'wallet_send',
            'withdrawal',
            (string) (int) $withdrawal_id,
            $base,
            [
                'type'    => 'send_onchain',
                'title'   => 'Transfer out',
                'status'  => $wtx_status,
                'network' => $network,
            ],
            false
        );
        if (is_wp_error($debit)) {
            $this->db->rollback();
            return $debit;
        }
        $this->db->commit();

        if ($requires_manual_approval) {
            $msg = $insufficient_funds
                ? 'Transfer submitted. Processing will begin once funding is available.'
                : 'Transfer submitted for review. You will be notified when it completes.';
            return [
                'ok'                       => true,
                'pending'                  => true,
                'requires_manual_approval' => true,
                'insufficient_funds'       => $insufficient_funds,
                'message'                  => $msg,
                'amount_micro'             => $amount_micro,
                'amount_display'           => Growl_Money::to_display($amount_micro),
                'net_amount'               => $net_amount,
                'withdrawal_id'            => (int) $withdrawal_id,
                'balance'                  => $this->get_user_balances($user_id),
            ];
        }

        $result = $wallet_manager->process_withdrawal($user_id, $net_amount, $destination_wallet, (int) $withdrawal_id);

        if (empty($result['success'])) {
            $tx_hash = isset($result['transaction_hash']) ? trim((string) $result['transaction_hash']) : '';
            if ($tx_hash !== '') {
                $verify = $wallet_manager->verify_transaction_on_chain($tx_hash, [
                    'max_retries'     => 4,
                    'retry_delay_sec' => 2,
                    'context'         => 'wallet_send_after_signer_fail',
                ]);
                if (!empty($verify['success'])) {
                    $this->db->update('withdrawals', [
                        'status'           => 'completed',
                        'transaction_hash' => $tx_hash,
                        'completed_at'     => current_time('mysql'),
                    ], ['withdrawal_id' => (int) $withdrawal_id]);
                    if (!class_exists('Growl_Wallet_Tx_Service')) {
                        require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-wallet-tx-service.php';
                    }
                    $wtx = new Growl_Wallet_Tx_Service($this->db);
                    $tx_id = $wtx->find_by_ref($user_id, 'withdrawal', (string) (int) $withdrawal_id);
                    if ($tx_id) {
                        $wtx->update($tx_id, ['status' => 'succeeded', 'tx_hash' => $tx_hash, 'network' => $network]);
                    }
                    $this->settle_withdrawal_completion(
                        $user_id,
                        $amount_micro,
                        (int) $withdrawal_id,
                        'wallet_send',
                        ['label' => 'Withdrawal completed', 'tx_hash' => $tx_hash, 'network' => $network]
                    );
                    return [
                        'ok'               => true,
                        'amount_micro'     => $amount_micro,
                        'amount_display'   => Growl_Money::to_display($amount_micro),
                        'net_amount'       => $net_amount,
                        'transaction_hash' => $tx_hash,
                        'withdrawal_id'    => (int) $withdrawal_id,
                        'balance'          => $this->get_user_balances($user_id),
                    ];
                }
            }

            $err_code = isset($result['error_code'])
                ? (string) $result['error_code']
                : GROWL_Wallet_Manager::classify_withdrawal_error((string) ($result['error'] ?? ''));
            $internal = isset($result['error']) ? (string) $result['error'] : 'send_failed';

            // Liquidity shortfall after create → keep pending (do not refund / fail the user).
            if ($err_code === 'platform_liquidity' || !empty($result['requires_manual_approval'])) {
                error_log('WALLET_SEND_PENDING_LIQUIDITY: withdrawal_id=' . (int) $withdrawal_id . ' err=' . $internal);
                $this->db->update('withdrawals', [
                    'status'                   => 'pending',
                    'requires_manual_approval' => 1,
                    'failure_reason'           => 'insufficient_funds',
                    'admin_notes'              => 'outbound_send:' . $base . ' | ' . $internal,
                    'processed_at'             => current_time('mysql'),
                ], ['withdrawal_id' => (int) $withdrawal_id]);
                if (!class_exists('Growl_Wallet_Tx_Service')) {
                    require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-wallet-tx-service.php';
                }
                $wtx = new Growl_Wallet_Tx_Service($this->db);
                $tx_id = $wtx->find_by_ref($user_id, 'withdrawal', (string) (int) $withdrawal_id);
                if ($tx_id) {
                    $wtx->update($tx_id, ['status' => 'pending']);
                }
                return [
                    'ok'                       => true,
                    'pending'                  => true,
                    'requires_manual_approval' => true,
                    'insufficient_funds'       => true,
                    'message'                  => 'Transfer submitted. Processing will begin once funding is available.',
                    'amount_micro'             => $amount_micro,
                    'amount_display'           => Growl_Money::to_display($amount_micro),
                    'net_amount'               => $net_amount,
                    'withdrawal_id'            => (int) $withdrawal_id,
                    'balance'                  => $this->get_user_balances($user_id),
                ];
            }

            $this->db->begin_transaction();
            $this->db->update('withdrawals', [
                'status'         => 'failed',
                'failure_reason' => $internal,
                'processed_at'   => current_time('mysql'),
            ], ['withdrawal_id' => (int) $withdrawal_id]);
            $refund = $this->credit_withdrawal_refund(
                $user_id,
                $amount_micro,
                (int) $withdrawal_id,
                false,
                ['label' => 'Withdrawal returned']
            );
            if (is_wp_error($refund)) {
                $this->db->rollback();
                return $refund;
            }
            $this->db->commit();
            if (!class_exists('Growl_Wallet_Tx_Service')) {
                require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-wallet-tx-service.php';
            }
            $wtx = new Growl_Wallet_Tx_Service($this->db);
            $tx_id = $wtx->find_by_ref($user_id, 'withdrawal', (string) (int) $withdrawal_id);
            if ($tx_id) {
                $wtx->update($tx_id, ['status' => 'failed']);
            }
            $user_msg = isset($result['user_message']) && $result['user_message'] !== ''
                ? (string) $result['user_message']
                : GROWL_Wallet_Manager::user_message_for_withdrawal_error($err_code, $internal);
            error_log('WALLET_SEND_FAILED: withdrawal_id=' . (int) $withdrawal_id . ' code=' . $err_code . ' err=' . $internal);
            return new WP_Error('send_failed', $user_msg, [
                'status'     => 500,
                'error_code' => $err_code,
            ]);
        }

        $ok_hash = (string) ($result['transaction_hash'] ?? '');
        $this->db->update('withdrawals', [
            'status'           => 'completed',
            'transaction_hash' => $ok_hash !== '' ? $ok_hash : null,
            'completed_at'     => current_time('mysql'),
        ], ['withdrawal_id' => (int) $withdrawal_id]);
        if (!class_exists('Growl_Wallet_Tx_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-wallet-tx-service.php';
        }
        $wtx = new Growl_Wallet_Tx_Service($this->db);
        $tx_id = $wtx->find_by_ref($user_id, 'withdrawal', (string) (int) $withdrawal_id);
        if ($tx_id) {
            $wtx->update($tx_id, ['status' => 'succeeded', 'tx_hash' => $ok_hash, 'network' => $network]);
        }
        $this->settle_withdrawal_completion(
            $user_id,
            $amount_micro,
            (int) $withdrawal_id,
            'wallet_send',
            ['label' => 'Withdrawal completed', 'tx_hash' => $ok_hash, 'network' => $network]
        );

        return [
            'ok'               => true,
            'amount_micro'     => $amount_micro,
            'amount_display'   => Growl_Money::to_display($amount_micro),
            'net_amount'       => $net_amount,
            'transaction_hash' => $ok_hash,
            'withdrawal_id'    => (int) $withdrawal_id,
            'balance'          => $this->get_user_balances($user_id),
        ];
    }

    /**
     * Policy gate for outbound USDC (treasury send, external transfer).
     *
     * @return true|WP_Error
     */
    public function assert_outbound_transfer_allowed($user_id) {
        $user_id = (int) $user_id;
        $row = $this->db->get_row(
            "SELECT is_restricted, is_muted, mute_until FROM {$this->db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$user_id]
        );
        if (!$row) {
            return new WP_Error('user_not_found', 'Account not found.', ['status' => 404]);
        }
        if (!empty($row->is_muted) && strtotime((string) $row->mute_until) > time()) {
            return new WP_Error('user_muted', 'Your account is temporarily suspended from transfers.', ['status' => 403]);
        }
        // Restricted users may withdraw remaining available (referral/deals/etc after earn hold).
        // P2P is gated separately via Growl_Account_Capability_Policy::CAP_P2P_TRANSFER.
        if (!class_exists('Growl_Account_Capability_Policy', false)) {
            require_once __DIR__ . '/class-growl-account-capability-policy.php';
        }
        $policy = new Growl_Account_Capability_Policy($this->db);
        return $policy->assert($user_id, Growl_Account_Capability_Policy::CAP_WITHDRAW_AVAILABLE);
    }

    /**
     * Debit available balance when a legacy withdrawal row is created.
     *
     * @return true|WP_Error
     */
    public function debit_withdrawal($user_id, $amount_micro, $withdrawal_id, $manage_tx = false) {
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        $withdrawal_id = (int) $withdrawal_id;
        if ($withdrawal_id < 1) {
            return new WP_Error('invalid_withdrawal', 'Outbound debit requires a withdrawal id.', ['status' => 400]);
        }
        return $this->begin_outbound(
            (int) $user_id,
            $amount_micro,
            'withdrawal',
            'withdrawal',
            (string) $withdrawal_id,
            'withdraw:' . $withdrawal_id,
            [
                'type'   => 'transfer_out',
                'title'  => 'Transfer out',
                'status' => 'processing',
            ],
            $manage_tx
        );
    }

    /**
     * Ensure every user-facing outbound debit has a statusful wallet_transactions row.
     */
    private function record_outbound_wallet_tx($user_id, $amount_micro, $withdrawal_id, $status = 'processing', $tx_hash = '', $network = '', $subtitle = '') {
        if (!class_exists('Growl_Wallet_Tx_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-wallet-tx-service.php';
        }
        $wtx = new Growl_Wallet_Tx_Service($this->db);
        $notes = '';
        if ($subtitle === '') {
            $w = $this->db->get_row(
                "SELECT to_wallet, admin_notes, network FROM {$this->db->table_prefix}withdrawals WHERE withdrawal_id = %d LIMIT 1",
                [(int) $withdrawal_id]
            );
            if ($w) {
                $wallet = trim((string) ($w->to_wallet ?? ''));
                if (strlen($wallet) > 12) {
                    $subtitle = substr($wallet, 0, 6) . '…' . substr($wallet, -4);
                } else {
                    $subtitle = $wallet;
                }
                $notes = (string) ($w->admin_notes ?? '');
                if ($network === '') {
                    $network = (string) ($w->network ?? '');
                }
            }
        }
        $is_send = (stripos($notes, 'outbound_send') !== false || stripos($notes, 'wallet_send') !== false);
        $wtx->upsert([
            'user_id'         => (int) $user_id,
            'type'            => $is_send ? 'send_onchain' : 'transfer_out',
            'status'          => sanitize_key((string) $status),
            'amount_micro'    => Growl_Money::sub('0', Growl_Money::assert_micro($amount_micro)),
            'title'           => 'Transfer out',
            'subtitle'        => $subtitle,
            'bucket'          => 'available',
            'ref_type'        => 'withdrawal',
            'ref_id'          => (string) (int) $withdrawal_id,
            'tx_hash'         => $tx_hash,
            'network'         => $network,
            'idempotency_key' => 'wtx:withdrawal:' . (int) $withdrawal_id,
        ]);
    }

    /**
     * Restore available balance when a withdrawal is rejected or fails on-chain.
     *
     * @return true|WP_Error
     */
    public function credit_withdrawal_refund($user_id, $amount_micro, $withdrawal_id, $manage_tx = false, array $wtx = []) {
        $amount_micro = Growl_Money::assert_micro($amount_micro);
        $withdrawal_id = (int) $withdrawal_id;
        $bal = $this->get_user_balances_raw((int) $user_id);
        $pending = (string) ($bal['outbound_pending_micro'] ?? '0');
        if (Growl_Money::is_positive($pending) && Growl_Money::cmp($pending, $amount_micro) >= 0) {
            return $this->refund_outbound(
                (int) $user_id,
                $amount_micro,
                'withdrawal_refund',
                'withdrawal',
                (string) $withdrawal_id,
                'withdraw_refund:' . $withdrawal_id,
                array_merge(['label' => 'Withdrawal returned'], $wtx),
                $manage_tx
            );
        }
        return $this->apply_ledger_entry([
            'user_id'               => (int) $user_id,
            'delta_available_micro' => $amount_micro,
            'entry_type'            => 'adjustment',
            'purpose'               => 'withdrawal_refund',
            'ref_type'              => 'withdrawal',
            'ref_id'                => (string) $withdrawal_id,
            'idempotency_key'       => 'withdraw_refund:' . $withdrawal_id,
        ], $manage_tx);
    }
}
