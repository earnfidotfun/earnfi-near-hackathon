<?php
/**
 * Central account capability matrix (restriction / mute / future tiers).
 *
 * All product gates should call this instead of hardcoding is_restricted checks.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Growl_Money', false)) {
    require_once dirname(__DIR__) . '/money/class-growl-money.php';
}

class Growl_Account_Capability_Policy {

    public const CAP_COMPLETE_TASK     = 'complete_task';
    public const CAP_COMPLETE_JOB      = 'complete_job';
    public const CAP_ENTER_CONTEST     = 'enter_contest';
    public const CAP_EARN_TASK         = 'earn_task';
    public const CAP_EARN_JOB          = 'earn_job';
    public const CAP_EARN_CONTEST      = 'earn_contest';
    public const CAP_CREATE_LISTING    = 'create_listing';
    public const CAP_REFERRAL          = 'referral';
    public const CAP_EARN_REFERRAL     = 'earn_referral';
    public const CAP_WITHDRAW_AVAILABLE = 'withdraw_available';
    public const CAP_DEALS             = 'deals';
    public const CAP_STAKE             = 'stake';
    public const CAP_RECOVER_SOL       = 'recover_sol';
    public const CAP_P2P_TRANSFER      = 'p2p_transfer';
    public const CAP_VIEW_BALANCE      = 'view_balance';

    /** @var GROWL_Database */
    private $db;

    public function __construct(?GROWL_Database $db = null) {
        $this->db = $db ?: new GROWL_Database();
    }

    /**
     * Capabilities denied while the account is restricted.
     * Filter: growl_restricted_denied_capabilities
     *
     * @return string[]
     */
    public function denied_when_restricted() {
        $caps = [
            self::CAP_COMPLETE_TASK,
            self::CAP_COMPLETE_JOB,
            self::CAP_ENTER_CONTEST,
            self::CAP_EARN_TASK,
            self::CAP_EARN_JOB,
            self::CAP_EARN_CONTEST,
            self::CAP_P2P_TRANSFER,
        ];
        return array_values(array_unique(apply_filters('growl_restricted_denied_capabilities', $caps)));
    }

    /**
     * Capabilities explicitly allowed while restricted (documentation + UI).
     * Filter: growl_restricted_allowed_capabilities
     *
     * @return string[]
     */
    public function allowed_when_restricted() {
        $caps = [
            self::CAP_CREATE_LISTING,
            self::CAP_REFERRAL,
            self::CAP_EARN_REFERRAL,
            self::CAP_WITHDRAW_AVAILABLE,
            self::CAP_DEALS,
            self::CAP_STAKE,
            self::CAP_RECOVER_SOL,
            self::CAP_VIEW_BALANCE,
        ];
        return array_values(array_unique(apply_filters('growl_restricted_allowed_capabilities', $caps)));
    }

    /**
     * user_balance_credits.earning_type values treated as task/job/contest earnings.
     * Filter: growl_restricted_earn_credit_types
     *
     * @return string[]
     */
    public function restricted_earn_credit_types() {
        $types = [
            'unlocked_reward',
            'job_reward',
            'contest',
            'open_work_accept',
            'task_reward',
            'completion_reward',
        ];
        return array_values(array_unique(apply_filters('growl_restricted_earn_credit_types', $types)));
    }

    /**
     * balance_ledger.purpose values that credit task/job/contest earnings.
     * Filter: growl_restricted_earn_ledger_purposes
     *
     * @return string[]
     */
    public function restricted_earn_ledger_purposes() {
        $purposes = [
            'open_work_accept',
            'task_reward',
            'job_reward',
            'contest_prize',
            'unlocked_reward',
            'completion_reward',
        ];
        return array_values(array_unique(apply_filters('growl_restricted_earn_ledger_purposes', $purposes)));
    }

    public function is_restricted($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return false;
        }
        static $cache = [];
        if (array_key_exists($user_id, $cache)) {
            return $cache[$user_id];
        }
        $row = $this->db->get_row(
            "SELECT is_restricted FROM {$this->db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$user_id]
        );
        $cache[$user_id] = $row && !empty($row->is_restricted);
        return $cache[$user_id];
    }

    /**
     * Clear per-request restriction cache after admin flips the flag.
     */
    public function clear_cache($user_id = null) {
        // Static cache is request-scoped; re-instantiation is enough in most paths.
        // Kept for API symmetry.
    }

    /**
     * @return bool
     */
    public function can($user_id, $capability) {
        $capability = sanitize_key((string) $capability);
        if ($capability === '') {
            return false;
        }
        if (!$this->is_restricted($user_id)) {
            return (bool) apply_filters('growl_account_capability_can', true, $user_id, $capability, false);
        }
        $denied = $this->denied_when_restricted();
        $ok = !in_array($capability, $denied, true);
        return (bool) apply_filters('growl_account_capability_can', $ok, $user_id, $capability, true);
    }

    /**
     * @return true|WP_Error
     */
    public function assert($user_id, $capability) {
        if ($this->can($user_id, $capability)) {
            return true;
        }
        $messages = [
            self::CAP_COMPLETE_TASK => 'Your account is restricted and cannot complete tasks.',
            self::CAP_COMPLETE_JOB  => 'Your account is restricted and cannot take jobs.',
            self::CAP_ENTER_CONTEST => 'Your account is restricted and cannot enter contests.',
            self::CAP_EARN_TASK     => 'Your account is restricted and cannot earn from tasks.',
            self::CAP_EARN_JOB      => 'Your account is restricted and cannot earn from jobs.',
            self::CAP_EARN_CONTEST  => 'Your account is restricted and cannot earn from contests.',
            self::CAP_P2P_TRANSFER  => 'Your account is restricted and cannot send P2P transfers.',
        ];
        $msg = $messages[$capability] ?? 'Your account is restricted for this action.';
        return new WP_Error('account_restricted', $msg, ['status' => 403, 'capability' => $capability]);
    }

    /**
     * Copy for modal / notifications. Filter: growl_restriction_modal_copy
     *
     * @return array{title:string,intro:string,can:string[],cannot:string[]}
     */
    public function modal_copy() {
        $copy = [
            'title'  => 'Account restricted',
            'intro'  => 'Your account is restricted due to misconduct. You can still use the platform with limited features. If you have a complaint, please contact support.',
            'can'    => [
                'Log in and access the platform',
                'Create tasks, campaigns, jobs, and deals',
                'Use referrals (refer users and withdraw referral earnings)',
                'Use deals as buyer or seller and withdraw deal earnings',
                'Stake and use Recover SOL',
                'Withdraw non-restricted available balance',
            ],
            'cannot' => [
                'Complete or earn from tasks, jobs, or contests',
                'Withdraw task, job, or contest earnings (those are held)',
                'Send P2P balance transfers',
            ],
        ];
        return apply_filters('growl_restriction_modal_copy', $copy);
    }

    /**
     * Whether the full restriction modal should auto-open after login.
     */
    public function should_show_restriction_modal($user_id) {
        $user_id = (int) $user_id;
        if (!$this->is_restricted($user_id)) {
            return false;
        }
        $row = $this->db->get_row(
            "SELECT restricted_at, restriction_notice_seen_at FROM {$this->db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$user_id]
        );
        if (!$row) {
            return false;
        }
        $seen = $row->restriction_notice_seen_at ?? null;
        if ($seen === null || $seen === '' || $seen === '0000-00-00 00:00:00') {
            return true;
        }
        $restricted_at = $row->restricted_at ?? null;
        if ($restricted_at && strtotime((string) $restricted_at) > strtotime((string) $seen)) {
            return true;
        }
        return false;
    }

    /**
     * Mark the one-shot restriction modal as acknowledged.
     *
     * @return true|WP_Error
     */
    public function acknowledge_restriction_notice($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return new WP_Error('invalid_user', 'Invalid user.', ['status' => 400]);
        }
        $ok = $this->db->update(
            'users',
            ['restriction_notice_seen_at' => current_time('mysql')],
            ['user_id' => $user_id]
        );
        if ($ok === false) {
            return new WP_Error('db_error', 'Could not save notice acknowledgment.', ['status' => 500]);
        }
        return true;
    }

    /**
     * Apply restriction side-effects: flag + lock task/job/contest earnings into held.
     *
     * @return array{locked_micro:string,success:bool}|WP_Error
     */
    public function apply_restriction($user_id, $reason = '') {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return new WP_Error('invalid_user', 'Invalid user.', ['status' => 400]);
        }

        $updated = $this->db->query(
            "UPDATE {$this->db->table_prefix}users
             SET is_restricted = 1,
                 restricted_at = %s,
                 restriction_reason = %s,
                 restriction_notice_seen_at = NULL
             WHERE user_id = %d",
            [current_time('mysql'), sanitize_textarea_field((string) $reason), $user_id]
        );
        if ($updated === false) {
            return new WP_Error('db_error', 'Could not restrict user.', ['status' => 500]);
        }

        $locked = $this->lock_restricted_earnings($user_id);
        if (is_wp_error($locked)) {
            return $locked;
        }
        return [
            'success'      => true,
            'locked_micro' => $locked,
        ];
    }

    /**
     * Move task/job/contest earnings from available → escrow held.
     *
     * @return string|WP_Error locked micro amount
     */
    public function lock_restricted_earnings($user_id) {
        $user_id = (int) $user_id;
        if (!class_exists('Growl_Balance_Service', false)) {
            require_once __DIR__ . '/class-growl-balance-service.php';
        }
        $balance = new Growl_Balance_Service($this->db);
        // Repair from ledger without inventing legacy_sync gaps.
        $balance->repair_user_balance_from_ledger($user_id);

        $bal = $balance->get_user_balances_raw($user_id);
        if (!$bal) {
            return '0';
        }
        $available = (string) ($bal['available_micro'] ?? '0');
        if (!Growl_Money::is_positive($available)) {
            return '0';
        }

        $to_lock = $this->estimate_restricted_earn_micro($user_id);
        if (Growl_Money::cmp($to_lock, $available) > 0) {
            $to_lock = $available;
        }
        if (!Growl_Money::is_positive($to_lock)) {
            return '0';
        }

        $row = $this->db->get_row(
            "SELECT restricted_at FROM {$this->db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$user_id]
        );
        $stamp = $row && !empty($row->restricted_at) ? (string) $row->restricted_at : gmdate('Y-m-d H:i:s');
        $result = $balance->hold_restriction(
            $user_id,
            $to_lock,
            'restriction_hold:' . $user_id . ':' . md5($stamp),
            (string) $user_id
        );
        if (is_wp_error($result)) {
            return $result;
        }
        return $to_lock;
    }

    /**
     * Estimate how much of available balance is from tasks/jobs/contests.
     */
    public function estimate_restricted_earn_micro($user_id) {
        $user_id = (int) $user_id;
        $p = $this->db->table_prefix;
        $types = $this->restricted_earn_credit_types();
        $total = '0';

        if ($types) {
            $placeholders = implode(',', array_fill(0, count($types), '%s'));
            $params = array_merge([$user_id], $types);
            $sum = $this->db->get_var(
                "SELECT COALESCE(SUM(amount), 0) FROM {$p}user_balance_credits
                 WHERE user_id = %d AND earning_type IN ({$placeholders})",
                $params
            );
            $total = Growl_Money::add($total, Growl_Money::to_micro($sum ?? 0));
        }

        $purposes = $this->restricted_earn_ledger_purposes();
        if ($purposes) {
            $placeholders = implode(',', array_fill(0, count($purposes), '%s'));
            $params = array_merge([$user_id], $purposes);
            $sum = $this->db->get_var(
                "SELECT COALESCE(SUM(delta_available), 0) FROM {$p}balance_ledger
                 WHERE user_id = %d AND purpose IN ({$placeholders}) AND delta_available > 0",
                $params
            );
            $ledger_sum = (string) ($sum ?? '0');
            if (Growl_Money::cmp($ledger_sum, $total) > 0) {
                $total = $ledger_sum;
            }
        }

        // Completions unlocked/withdrawn as a floor if credits table is sparse.
        $comp = $this->db->get_var(
            "SELECT COALESCE(SUM(reward_amount), 0) FROM {$p}completions
             WHERE user_id = %d AND status IN ('unlocked','withdrawn')",
            [$user_id]
        );
        $comp_micro = Growl_Money::to_micro($comp ?? 0);
        if (Growl_Money::cmp($comp_micro, $total) > 0) {
            $total = $comp_micro;
        }

        return Growl_Money::is_positive($total) ? $total : '0';
    }

    /**
     * Payload helpers for API user objects.
     *
     * @return array<string,mixed>
     */
    public function user_restriction_payload($user_id) {
        $user_id = (int) $user_id;
        $restricted = $this->is_restricted($user_id);
        return [
            'is_restricted'                 => $restricted,
            'restriction_notice_pending'    => $restricted && $this->should_show_restriction_modal($user_id),
            'restriction_capabilities'      => [
                'allowed' => $this->allowed_when_restricted(),
                'denied'  => $this->denied_when_restricted(),
            ],
            'restriction_modal'             => $this->modal_copy(),
        ];
    }
}
