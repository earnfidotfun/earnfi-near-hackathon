<?php
/**
 * Job contracts — professional hire protected payment (separate from Deals UI).
 *
 * Payment structures:
 * - single: fund full deposit → deliver → release (no milestone rows).
 * - split / initial_then_rest / milestone: create job_milestones rows.
 *   Progressive model: fund() locks the NEXT pending milestone only;
 *   worker delivers that milestone; employer releases it; repeat until all released.
 *   Contract stays pending_funding between milestone rounds (deposit = next milestone).
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Growl_Money', false)) {
    require_once dirname(__DIR__) . '/money/class-growl-money.php';
}
if (!class_exists('Growl_Work_Fee_Engine', false)) {
    require_once __DIR__ . '/class-growl-work-fee-engine.php';
}

class Growl_Job_Contract_Service {

    public const STRUCTURES = ['single', 'milestone', 'split', 'initial_then_rest'];

    /** @var GROWL_Database */
    private $db;
    /** @var Growl_Balance_Service */
    private $balance;
    /** @var Growl_X402_Payment_Service */
    private $payments;

    public function __construct(?GROWL_Database $db = null) {
        $this->db = $db ?: new GROWL_Database();
        $this->balance = new Growl_Balance_Service($this->db);
        $this->payments = new Growl_X402_Payment_Service($this->db);
    }

    public static function normalize_structure($raw) {
        $s = sanitize_key((string) $raw);
        return in_array($s, self::STRUCTURES, true) ? $s : 'single';
    }

    private function generate_slug() {
        for ($i = 0; $i < 8; $i++) {
            $slug = substr(bin2hex(random_bytes(8)), 0, 12);
            $exists = $this->db->get_var(
                "SELECT contract_id FROM {$this->db->table_prefix}job_contracts WHERE public_slug = %s LIMIT 1",
                [$slug]
            );
            if (!$exists) {
                return $slug;
            }
        }
        return substr(bin2hex(random_bytes(10)), 0, 16);
    }

    /**
     * Create a pending_funding contract when hiring an applicant.
     *
     * @param object               $listing
     * @param object               $application
     * @param int                  $employer_user_id
     * @param array<string,mixed>  $overrides amount (USDC), payment_structure, milestones[{title,amount|percent}]
     * @return array<string,mixed>|WP_Error
     */
    public function create_from_hire($listing, $application, $employer_user_id, array $overrides = []) {
        $gross = Growl_Money::resolve_amount_micro([
            'amount'       => $overrides['amount'] ?? null,
            'amount_micro' => $overrides['amount_micro'] ?? null,
            'budget_micro' => $listing->budget_micro ?? null,
        ]);
        if (!Growl_Money::is_positive($gross)) {
            $gross = Growl_Money::assert_micro($listing->budget_micro);
        }
        if (!Growl_Money::is_positive($gross)) {
            return new WP_Error('invalid_amount', 'A positive budget is required.', ['status' => 400]);
        }

        $structure = self::normalize_structure(
            $overrides['payment_structure']
                ?? ($listing->payment_structure ?? 'single')
        );

        $milestones_spec = $overrides['milestones'] ?? null;
        if (!is_array($milestones_spec) && !empty($listing->milestones_json)) {
            $decoded = json_decode((string) $listing->milestones_json, true);
            if (is_array($decoded)) {
                $milestones_spec = $decoded;
            }
        }

        $built = $this->build_milestone_rows($structure, $gross, is_array($milestones_spec) ? $milestones_spec : []);
        if (is_wp_error($built)) {
            return $built;
        }

        $full_split = Growl_Work_Fee_Engine::split_for_gross($gross, 'jobs');
        // Progressive structures: deposit = first milestone only (pay-after-first still funds m1 first).
        $deposit_gross = $structure === 'single' || empty($built)
            ? $gross
            : (string) $built[0]['amount_micro'];
        $deposit_split = Growl_Work_Fee_Engine::split_for_gross($deposit_gross, 'jobs');

        $slug = $this->generate_slug();
        $row = [
            'public_slug'         => $slug,
            'listing_id'          => (int) $listing->listing_id,
            'application_id'      => (int) $application->application_id,
            'employer_user_id'    => (int) $employer_user_id,
            'worker_user_id'      => (int) $application->applicant_user_id,
            'title'               => mb_substr((string) $listing->title, 0, 200),
            'scope_text'          => (string) ($listing->scope_text ?? ''),
            'amount_micro'        => $gross,
            'employer_fee_micro'  => $full_split['employer_fee_micro'],
            'earner_fee_micro'    => $full_split['earner_fee_micro'],
            'deposit_micro'       => $deposit_split['deposit_micro'],
            'currency'            => 'USDC',
            'payment_structure'   => $structure,
            'status'              => 'pending_funding',
            'created_at'          => gmdate('Y-m-d H:i:s'),
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ];

        if (!$this->db->insert('job_contracts', $row)) {
            unset($row['payment_structure']);
            if (!$this->db->insert('job_contracts', $row)) {
                return new WP_Error('create_failed', 'Could not create job contract.', ['status' => 500]);
            }
        }
        $id = (int) $this->db->wpdb->insert_id;

        if ($structure !== 'single' && !empty($built)) {
            foreach ($built as $m) {
                $this->db->insert('job_milestones', [
                    'contract_id'  => $id,
                    'title'        => $m['title'],
                    'amount_micro' => $m['amount_micro'],
                    'sort_order'   => $m['sort_order'],
                    'status'       => 'pending',
                    'created_at'   => gmdate('Y-m-d H:i:s'),
                    'updated_at'   => gmdate('Y-m-d H:i:s'),
                ]);
            }
        }

        require_once __DIR__ . '/class-growl-work-thread-service.php';
        (new Growl_Work_Thread_Service($this->db))->get_or_create('job_contract', $slug);

        return $this->get_by_id($id);
    }

    /**
     * @param string               $structure
     * @param string               $gross_micro
     * @param array<int,mixed>     $spec
     * @return array<int,array{title:string,amount_micro:string,sort_order:int}>|WP_Error
     */
    private function build_milestone_rows($structure, $gross_micro, array $spec) {
        $gross = Growl_Money::assert_micro($gross_micro);
        if ($structure === 'single') {
            return [];
        }

        $from_spec = $this->resolve_milestone_amounts($gross, $spec);
        if (is_wp_error($from_spec)) {
            return $from_spec;
        }

        if ($structure === 'split') {
            if (count($from_spec) === 2) {
                return [
                    ['title' => $from_spec[0]['title'] ?: 'Start payment', 'amount_micro' => $from_spec[0]['amount_micro'], 'sort_order' => 0],
                    ['title' => $from_spec[1]['title'] ?: 'Final delivery', 'amount_micro' => $from_spec[1]['amount_micro'], 'sort_order' => 1],
                ];
            }
            $half = $this->split_even($gross, 2);
            return [
                ['title' => 'Start payment', 'amount_micro' => $half[0], 'sort_order' => 0],
                ['title' => 'Final delivery', 'amount_micro' => $half[1], 'sort_order' => 1],
            ];
        }

        if ($structure === 'initial_then_rest') {
            if (count($from_spec) >= 2) {
                $initial = $from_spec[0]['amount_micro'];
                $rest = Growl_Money::sub($gross, $initial);
                if (!Growl_Money::is_positive($rest)) {
                    return new WP_Error('invalid_milestones', 'Initial amount must leave a remaining balance.', ['status' => 400]);
                }
                return [
                    ['title' => $from_spec[0]['title'] ?: 'Start', 'amount_micro' => $initial, 'sort_order' => 0],
                    ['title' => $from_spec[1]['title'] ?: 'Remaining', 'amount_micro' => $rest, 'sort_order' => 1],
                ];
            }
            if (count($from_spec) === 1) {
                $initial = $from_spec[0]['amount_micro'];
                $rest = Growl_Money::sub($gross, $initial);
                if (!Growl_Money::is_positive($rest) || !Growl_Money::is_positive($initial)) {
                    return new WP_Error('invalid_milestones', 'Initial and remaining must both be positive.', ['status' => 400]);
                }
                return [
                    ['title' => $from_spec[0]['title'] ?: 'Start', 'amount_micro' => $initial, 'sort_order' => 0],
                    ['title' => 'Remaining', 'amount_micro' => $rest, 'sort_order' => 1],
                ];
            }
            // Default 30% start / 70% rest
            $initial = Growl_Money::fee_micro($gross, 3000);
            if (!Growl_Money::is_positive($initial)) {
                $initial = '1';
            }
            $rest = Growl_Money::sub($gross, $initial);
            return [
                ['title' => 'Start', 'amount_micro' => $initial, 'sort_order' => 0],
                ['title' => 'Remaining', 'amount_micro' => $rest, 'sort_order' => 1],
            ];
        }

        // milestone: N custom (must sum to gross)
        if (count($from_spec) < 1) {
            return new WP_Error('invalid_milestones', 'Add at least one milestone.', ['status' => 400]);
        }
        $sum = '0';
        $rows = [];
        foreach ($from_spec as $i => $m) {
            if (!Growl_Money::is_positive($m['amount_micro'])) {
                return new WP_Error('invalid_milestones', 'Each milestone needs a positive amount.', ['status' => 400]);
            }
            $sum = Growl_Money::add($sum, $m['amount_micro']);
            $rows[] = [
                'title'        => $m['title'] !== '' ? $m['title'] : ('Milestone ' . ($i + 1)),
                'amount_micro' => $m['amount_micro'],
                'sort_order'   => $i,
            ];
        }
        if (Growl_Money::cmp($sum, $gross) !== 0) {
            return new WP_Error('invalid_milestones', 'Milestone amounts must sum to the contract budget.', ['status' => 400]);
        }
        return $rows;
    }

    /**
     * @param string           $gross_micro
     * @param array<int,mixed> $spec
     * @return array<int,array{title:string,amount_micro:string}>|WP_Error
     */
    private function resolve_milestone_amounts($gross_micro, array $spec) {
        $out = [];
        $percent_mode = false;
        foreach ($spec as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = sanitize_text_field((string) ($item['title'] ?? ''));
            if (isset($item['percent']) && $item['percent'] !== '' && $item['percent'] !== null) {
                $percent_mode = true;
                $pct = (float) $item['percent'];
                if ($pct <= 0 || $pct > 100) {
                    return new WP_Error('invalid_milestones', 'Milestone percent must be between 0 and 100.', ['status' => 400]);
                }
                $bps = (int) round($pct * 100);
                $out[] = ['title' => $title, 'amount_micro' => Growl_Money::fee_micro($gross_micro, $bps), '_pct' => $pct];
                continue;
            }
            $amt = Growl_Money::resolve_amount_micro($item);
            if (!Growl_Money::is_positive($amt) && isset($item['amount_usdc'])) {
                $amt = Growl_Money::to_micro($item['amount_usdc']);
            }
            $out[] = ['title' => $title, 'amount_micro' => $amt];
        }
        if ($percent_mode && !empty($out)) {
            // Fix rounding so last milestone absorbs remainder
            $sum = '0';
            $n = count($out);
            for ($i = 0; $i < $n - 1; $i++) {
                $sum = Growl_Money::add($sum, $out[$i]['amount_micro']);
            }
            $out[$n - 1]['amount_micro'] = Growl_Money::sub($gross_micro, $sum);
            if (!Growl_Money::is_positive($out[$n - 1]['amount_micro'])) {
                return new WP_Error('invalid_milestones', 'Percents must leave a positive final milestone.', ['status' => 400]);
            }
        }
        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function split_even($gross_micro, $parts) {
        $parts = max(2, (int) $parts);
        $gross = Growl_Money::assert_micro($gross_micro);
        if (function_exists('bcdiv')) {
            $each = bcdiv($gross, (string) $parts, 0);
        } else {
            $each = (string) (int) floor(((int) $gross) / $parts);
        }
        $out = [];
        $sum = '0';
        for ($i = 0; $i < $parts - 1; $i++) {
            $out[] = $each;
            $sum = Growl_Money::add($sum, $each);
        }
        $out[] = Growl_Money::sub($gross, $sum);
        return $out;
    }

    public function get_by_slug($slug) {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}job_contracts WHERE public_slug = %s LIMIT 1",
            [sanitize_text_field((string) $slug)]
        );
        return $row ? $this->format($row) : null;
    }

    public function get_by_id($contract_id) {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}job_contracts WHERE contract_id = %d LIMIT 1",
            [(int) $contract_id]
        );
        return $row ? $this->format($row) : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list_milestones($contract_id) {
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->db->table_prefix}job_milestones
             WHERE contract_id = %d ORDER BY sort_order ASC, milestone_id ASC",
            [(int) $contract_id]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->format_milestone($row);
        }
        return $out;
    }

    private function format_milestone($row) {
        return [
            'milestone_id'    => (int) $row->milestone_id,
            'contract_id'     => (int) $row->contract_id,
            'title'           => (string) $row->title,
            'amount_micro'    => (string) $row->amount_micro,
            'amount_display'  => Growl_Money::to_display($row->amount_micro),
            'sort_order'      => (int) $row->sort_order,
            'status'          => (string) $row->status,
            'funded_at'       => !empty($row->funded_at) ? (string) $row->funded_at : null,
            'delivered_at'    => !empty($row->delivered_at) ? (string) $row->delivered_at : null,
            'created_at'      => (string) ($row->created_at ?? ''),
            'updated_at'      => (string) ($row->updated_at ?? ''),
        ];
    }

    /**
     * Next actionable milestone for progressive payment.
     *
     * @return object|null
     */
    private function current_milestone_row($contract_id, $prefer_status = null) {
        $p = $this->db->table_prefix;
        if ($prefer_status) {
            $row = $this->db->get_row(
                "SELECT * FROM {$p}job_milestones WHERE contract_id = %d AND status = %s
                 ORDER BY sort_order ASC, milestone_id ASC LIMIT 1",
                [(int) $contract_id, sanitize_key((string) $prefer_status)]
            );
            if ($row) {
                return $row;
            }
        }
        // Prefer in-progress, else next pending
        foreach (['funded', 'delivered', 'pending'] as $st) {
            $row = $this->db->get_row(
                "SELECT * FROM {$p}job_milestones WHERE contract_id = %d AND status = %s
                 ORDER BY sort_order ASC, milestone_id ASC LIMIT 1",
                [(int) $contract_id, $st]
            );
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    private function uses_milestones($contract_row) {
        $structure = self::normalize_structure($contract_row->payment_structure ?? 'single');
        return $structure !== 'single';
    }

    private function escrow_ref_for_milestone($contract_slug, $milestone_id) {
        return substr((string) $contract_slug . ':m' . (int) $milestone_id, 0, 64);
    }

    public function format($row) {
        $structure = self::normalize_structure($row->payment_structure ?? 'single');
        $milestones = $structure === 'single' ? [] : $this->list_milestones((int) $row->contract_id);
        $current = null;
        foreach ($milestones as $m) {
            if (in_array($m['status'], ['pending', 'funded', 'delivered'], true)) {
                $current = $m;
                break;
            }
        }
        return [
            'contract_id'            => (int) $row->contract_id,
            'public_slug'            => (string) $row->public_slug,
            'url'                    => home_url('/job-contract/' . $row->public_slug),
            'listing_id'             => (int) $row->listing_id,
            'application_id'         => (int) $row->application_id,
            'employer_user_id'       => (int) $row->employer_user_id,
            'worker_user_id'         => (int) $row->worker_user_id,
            'title'                  => (string) $row->title,
            'scope_text'             => (string) ($row->scope_text ?? ''),
            'amount_micro'           => (string) $row->amount_micro,
            'amount_display'         => Growl_Money::to_display($row->amount_micro),
            'employer_fee_micro'     => (string) $row->employer_fee_micro,
            'employer_fee_display'   => Growl_Money::to_display($row->employer_fee_micro),
            'earner_fee_micro'       => (string) $row->earner_fee_micro,
            'earner_fee_display'     => Growl_Money::to_display($row->earner_fee_micro),
            'deposit_micro'          => (string) $row->deposit_micro,
            'deposit_display'        => Growl_Money::to_display($row->deposit_micro),
            'net_to_worker_display'  => Growl_Money::to_display(Growl_Money::sub($row->amount_micro, $row->earner_fee_micro)),
            'currency'               => (string) $row->currency,
            'payment_structure'      => $structure,
            'status'                 => (string) $row->status,
            'milestones'             => $milestones,
            'current_milestone'      => $current,
            'created_at'             => (string) $row->created_at,
            'updated_at'             => (string) $row->updated_at,
        ];
    }

    /**
     * Fund full deposit (single) or the next pending milestone (progressive).
     *
     * @return array<string,mixed>|WP_Error
     */
    public function fund($contract_id, $employer_user_id, $source, $settlement_id = null, $idempotency_key = '') {
        $c = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}job_contracts WHERE contract_id = %d LIMIT 1",
            [(int) $contract_id]
        );
        if (!$c) {
            return new WP_Error('not_found', 'Contract not found.', ['status' => 404]);
        }
        if ((int) $c->employer_user_id !== (int) $employer_user_id) {
            return new WP_Error('forbidden', 'Only the employer can fund this contract.', ['status' => 403]);
        }
        if ($c->status !== 'pending_funding') {
            return new WP_Error('invalid_state', 'This contract is not awaiting payment.', ['status' => 400]);
        }

        if ($this->uses_milestones($c)) {
            return $this->fund_next_milestone_internal($c, $employer_user_id, $source, $settlement_id, $idempotency_key);
        }

        $deposit = (string) $c->deposit_micro;
        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key) ?: 'job_contract_fund:' . $contract_id;
        $ref = (string) $c->public_slug;

        $this->db->begin_transaction();
        try {
            if ($source === 'balance') {
                $settlement_id = $this->payments->settle_from_balance(
                    $employer_user_id,
                    $deposit,
                    'job_contract_fund',
                    'job_contract',
                    $ref,
                    $idem
                );
                if (is_wp_error($settlement_id)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($settlement_id);
                }
            } elseif ($source === 'creator_wallet') {
                $settlement_id = $this->payments->settle_from_creator_wallet(
                    $employer_user_id,
                    $deposit,
                    'job_contract_fund',
                    'job_contract',
                    $ref,
                    $idem
                );
                if (is_wp_error($settlement_id)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($settlement_id);
                }
            } elseif ($settlement_id) {
                $settlement_id = (int) $settlement_id;
            } else {
                $this->db->rollback();
                return new WP_Error('payment_required', 'Payment is required.', ['status' => 402]);
            }

            $lock = $this->balance->lock_escrow(
                $employer_user_id,
                $deposit,
                'job_contract_fund',
                'job_contract',
                $ref,
                $idem . ':lock',
                $settlement_id,
                false
            );
            if (is_wp_error($lock)) {
                $this->db->rollback();
                return Growl_X402_Payment_Service::public_error($lock);
            }

            $this->db->insert('escrow_ledger', [
                'ref_type'        => 'job_contract',
                'ref_id'          => $ref,
                'buyer_type'      => 'human',
                'buyer_ref'       => (string) $employer_user_id,
                'seller_type'     => 'human',
                'seller_ref'      => (string) $c->worker_user_id,
                'amount_micro'    => $deposit,
                'status'          => 'locked',
                'settlement_id'   => $settlement_id,
                'idempotency_key' => $idem . ':escrow',
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            $this->db->update('job_contracts', [
                'status'             => 'funded',
                'fund_settlement_id' => $settlement_id,
                'updated_at'         => gmdate('Y-m-d H:i:s'),
            ], ['contract_id' => (int) $contract_id]);

            $this->db->commit();
            $out = $this->get_by_id($contract_id);
            if (class_exists('Growl_Work_Notify')) {
                Growl_Work_Notify::human(
                    (int) $c->worker_user_id,
                    'Contract funded',
                    'Payment is held. You can start work on “' . $c->title . '”.',
                    'job-contract/' . $ref,
                    'success'
                );
            }
            return $out;
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('fund_failed', 'Payment could not be completed.', ['status' => 500]);
        }
    }

    /**
     * @param object $c
     * @return array<string,mixed>|WP_Error
     */
    private function fund_next_milestone_internal($c, $employer_user_id, $source, $settlement_id, $idempotency_key) {
        $m = $this->current_milestone_row((int) $c->contract_id, 'pending');
        if (!$m || $m->status !== 'pending') {
            return new WP_Error('invalid_state', 'No pending milestone to fund.', ['status' => 400]);
        }

        $split = Growl_Work_Fee_Engine::split_for_gross($m->amount_micro, 'jobs');
        $deposit = (string) $split['deposit_micro'];
        $mid = (int) $m->milestone_id;
        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key) ?: ('job_contract_fund:' . $c->contract_id . ':m' . $mid);
        $ref = $this->escrow_ref_for_milestone($c->public_slug, $mid);

        $this->db->begin_transaction();
        try {
            if ($source === 'balance') {
                $settlement_id = $this->payments->settle_from_balance(
                    $employer_user_id,
                    $deposit,
                    'job_contract_fund',
                    'job_contract',
                    $ref,
                    $idem
                );
                if (is_wp_error($settlement_id)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($settlement_id);
                }
            } elseif ($source === 'creator_wallet') {
                $settlement_id = $this->payments->settle_from_creator_wallet(
                    $employer_user_id,
                    $deposit,
                    'job_contract_fund',
                    'job_contract',
                    $ref,
                    $idem
                );
                if (is_wp_error($settlement_id)) {
                    $this->db->rollback();
                    return Growl_X402_Payment_Service::public_error($settlement_id);
                }
            } elseif ($settlement_id) {
                $settlement_id = (int) $settlement_id;
            } else {
                $this->db->rollback();
                return new WP_Error('payment_required', 'Payment is required.', ['status' => 402]);
            }

            $lock = $this->balance->lock_escrow(
                $employer_user_id,
                $deposit,
                'job_contract_fund',
                'job_contract',
                $ref,
                $idem . ':lock',
                $settlement_id,
                false
            );
            if (is_wp_error($lock)) {
                $this->db->rollback();
                return Growl_X402_Payment_Service::public_error($lock);
            }

            $this->db->insert('escrow_ledger', [
                'ref_type'        => 'job_contract',
                'ref_id'          => $ref,
                'buyer_type'      => 'human',
                'buyer_ref'       => (string) $employer_user_id,
                'seller_type'     => 'human',
                'seller_ref'      => (string) $c->worker_user_id,
                'amount_micro'    => $deposit,
                'status'          => 'locked',
                'settlement_id'   => $settlement_id,
                'idempotency_key' => $idem . ':escrow',
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            $now = gmdate('Y-m-d H:i:s');
            $this->db->update('job_milestones', [
                'status'     => 'funded',
                'funded_at'  => $now,
                'updated_at' => $now,
            ], ['milestone_id' => $mid]);

            $this->db->update('job_contracts', [
                'status'             => 'funded',
                'deposit_micro'      => $deposit,
                'fund_settlement_id' => $settlement_id,
                'updated_at'         => $now,
            ], ['contract_id' => (int) $c->contract_id]);

            $this->db->commit();
            $out = $this->get_by_id((int) $c->contract_id);
            if (class_exists('Growl_Work_Notify')) {
                Growl_Work_Notify::human(
                    (int) $c->worker_user_id,
                    'Milestone funded',
                    'Payment for “' . $m->title . '” is held. You can start that milestone.',
                    'job-contract/' . $c->public_slug,
                    'success'
                );
            }
            return $out;
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('fund_failed', 'Payment could not be completed.', ['status' => 500]);
        }
    }

    public function deliver($contract_id, $worker_user_id, $note = '') {
        $c = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}job_contracts WHERE contract_id = %d LIMIT 1",
            [(int) $contract_id]
        );
        if (!$c || (int) $c->worker_user_id !== (int) $worker_user_id) {
            return new WP_Error('forbidden', 'Only the worker can deliver.', ['status' => 403]);
        }
        if ($this->uses_milestones($c)) {
            $m = $this->current_milestone_row((int) $contract_id, 'funded');
            if (!$m) {
                return new WP_Error('invalid_state', 'No funded milestone to deliver.', ['status' => 400]);
            }
            return $this->deliver_milestone((int) $contract_id, (int) $m->milestone_id, $worker_user_id, $note);
        }
        if ($c->status !== 'funded') {
            return new WP_Error('invalid_state', 'Contract is not ready for delivery.', ['status' => 400]);
        }
        $note = sanitize_textarea_field((string) $note);
        if ($note === '') {
            return new WP_Error('invalid', 'Add a delivery note.', ['status' => 400]);
        }
        require_once __DIR__ . '/class-growl-work-thread-service.php';
        $threads = new Growl_Work_Thread_Service($this->db);
        $tid = $threads->get_or_create('job_contract', (string) $c->public_slug);
        if (!is_wp_error($tid)) {
            $threads->post($tid, 'system', 'system', 'Delivery submitted: ' . $note, 'system');
        }
        $this->db->update('job_contracts', [
            'status'     => 'delivered',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['contract_id' => (int) $contract_id]);
        if (class_exists('Growl_Work_Notify')) {
            Growl_Work_Notify::human(
                (int) $c->employer_user_id,
                'Work delivered',
                'Review delivery on “' . $c->title . '” and release payment if it matches.',
                'job-contract/' . $c->public_slug,
                'info'
            );
        }
        return $this->get_by_id($contract_id);
    }

    public function deliver_milestone($contract_id, $milestone_id, $worker_user_id, $note = '') {
        $c = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}job_contracts WHERE contract_id = %d LIMIT 1",
            [(int) $contract_id]
        );
        if (!$c || (int) $c->worker_user_id !== (int) $worker_user_id) {
            return new WP_Error('forbidden', 'Only the worker can deliver.', ['status' => 403]);
        }
        if (!$this->uses_milestones($c)) {
            return $this->deliver($contract_id, $worker_user_id, $note);
        }
        $m = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}job_milestones WHERE milestone_id = %d AND contract_id = %d LIMIT 1",
            [(int) $milestone_id, (int) $contract_id]
        );
        if (!$m || $m->status !== 'funded') {
            return new WP_Error('invalid_state', 'Milestone is not ready for delivery.', ['status' => 400]);
        }
        if ($c->status !== 'funded') {
            return new WP_Error('invalid_state', 'Contract is not ready for delivery.', ['status' => 400]);
        }
        $note = sanitize_textarea_field((string) $note);
        if ($note === '') {
            return new WP_Error('invalid', 'Add a delivery note.', ['status' => 400]);
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->update('job_milestones', [
            'status'       => 'delivered',
            'delivered_at' => $now,
            'updated_at'   => $now,
        ], ['milestone_id' => (int) $milestone_id]);
        $this->db->update('job_contracts', [
            'status'     => 'delivered',
            'updated_at' => $now,
        ], ['contract_id' => (int) $contract_id]);

        require_once __DIR__ . '/class-growl-work-thread-service.php';
        $threads = new Growl_Work_Thread_Service($this->db);
        $tid = $threads->get_or_create('job_contract', (string) $c->public_slug);
        if (!is_wp_error($tid)) {
            $threads->post($tid, 'system', 'system', 'Milestone delivered (“' . $m->title . '”): ' . $note, 'system');
        }
        if (class_exists('Growl_Work_Notify')) {
            Growl_Work_Notify::human(
                (int) $c->employer_user_id,
                'Milestone delivered',
                'Review “' . $m->title . '” on “' . $c->title . '” and release that payment if it matches.',
                'job-contract/' . $c->public_slug,
                'info'
            );
        }
        return $this->get_by_id($contract_id);
    }

    public function release($contract_id, $employer_user_id, $idempotency_key = '') {
        $c = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}job_contracts WHERE contract_id = %d LIMIT 1",
            [(int) $contract_id]
        );
        if (!$c || (int) $c->employer_user_id !== (int) $employer_user_id) {
            return new WP_Error('forbidden', 'Only the employer can release payment.', ['status' => 403]);
        }
        if ($this->uses_milestones($c)) {
            $m = $this->current_milestone_row((int) $contract_id, 'delivered');
            if (!$m) {
                return new WP_Error('invalid_state', 'No delivered milestone to release.', ['status' => 400]);
            }
            return $this->release_milestone((int) $contract_id, (int) $m->milestone_id, $employer_user_id, $idempotency_key);
        }
        if ($c->status !== 'delivered') {
            return new WP_Error('invalid_state', 'Release only after delivery.', ['status' => 400]);
        }

        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key) ?: 'job_contract_release:' . $contract_id;
        $deposit = (string) $c->deposit_micro;
        $gross = (string) $c->amount_micro;
        $earner_fee = (string) $c->earner_fee_micro;
        $employer_fee = (string) $c->employer_fee_micro;
        $net = Growl_Money::sub($gross, $earner_fee);
        $platform = Growl_Money::add($employer_fee, $earner_fee);
        $ref = (string) $c->public_slug;

        $result = $this->balance->release_escrow_split(
            (int) $c->employer_user_id,
            (int) $c->worker_user_id,
            $deposit,
            $net,
            $platform,
            'job_contract',
            $ref,
            $idem
        );
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }

        $p = $this->db->table_prefix;
        $this->db->query(
            "UPDATE {$p}escrow_ledger SET status = 'released', updated_at = %s
             WHERE ref_type = 'job_contract' AND ref_id = %s AND status = 'locked' LIMIT 1",
            [gmdate('Y-m-d H:i:s'), $ref]
        );
        $this->db->update('job_contracts', [
            'status'     => 'released',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['contract_id' => (int) $contract_id]);

        if (class_exists('Growl_Work_Notify')) {
            Growl_Work_Notify::human(
                (int) $c->worker_user_id,
                'Payment released',
                'Funds for “' . $c->title . '” were released to your balance.',
                'job-contract/' . $ref,
                'success'
            );
            Growl_Work_Notify::prompt_work_rating('job_contract', $ref, 'human', (string) $c->employer_user_id);
            Growl_Work_Notify::prompt_work_rating('job_contract', $ref, 'human', (string) $c->worker_user_id);
        }
        if (class_exists('GROWL_Gamification') && (int) $c->worker_user_id > 0) {
            $amt = class_exists('Growl_Money')
                ? (float) Growl_Money::to_display($deposit)
                : 0.0;
            GROWL_Gamification::on_work_money_completion((int) $c->worker_user_id, 'job_contract', $ref, $amt);
        }
        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once __DIR__ . '/class-growl-work-receipt-service.php';
        }
        (new Growl_Work_Receipt_Service($this->db))->upsert_from_ref('job_contract', $ref);
        return $this->get_by_id($contract_id);
    }

    public function release_milestone($contract_id, $milestone_id, $employer_user_id, $idempotency_key = '') {
        $c = $this->db->get_row_for_update(
            "SELECT * FROM {$this->db->table_prefix}job_contracts WHERE contract_id = %d LIMIT 1",
            [(int) $contract_id]
        );
        if (!$c || (int) $c->employer_user_id !== (int) $employer_user_id) {
            return new WP_Error('forbidden', 'Only the employer can release payment.', ['status' => 403]);
        }
        if (!$this->uses_milestones($c)) {
            return $this->release($contract_id, $employer_user_id, $idempotency_key);
        }
        $m = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}job_milestones WHERE milestone_id = %d AND contract_id = %d LIMIT 1",
            [(int) $milestone_id, (int) $contract_id]
        );
        if (!$m || $m->status !== 'delivered') {
            return new WP_Error('invalid_state', 'Release only after milestone delivery.', ['status' => 400]);
        }
        if ($c->status !== 'delivered') {
            return new WP_Error('invalid_state', 'Release only after delivery.', ['status' => 400]);
        }

        $split = Growl_Work_Fee_Engine::split_for_gross($m->amount_micro, 'jobs');
        $deposit = (string) $split['deposit_micro'];
        $net = (string) $split['net_to_earner_micro'];
        $platform = (string) $split['platform_fee_micro'];
        $mid = (int) $m->milestone_id;
        $idem = Growl_Money::sanitize_idempotency_key($idempotency_key) ?: ('job_contract_release:' . $contract_id . ':m' . $mid);
        $ref = $this->escrow_ref_for_milestone($c->public_slug, $mid);

        $result = $this->balance->release_escrow_split(
            (int) $c->employer_user_id,
            (int) $c->worker_user_id,
            $deposit,
            $net,
            $platform,
            'job_contract',
            $ref,
            $idem
        );
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }

        $now = gmdate('Y-m-d H:i:s');
        $p = $this->db->table_prefix;
        $this->db->query(
            "UPDATE {$p}escrow_ledger SET status = 'released', updated_at = %s
             WHERE ref_type = 'job_contract' AND ref_id = %s AND status = 'locked' LIMIT 1",
            [$now, $ref]
        );
        $this->db->update('job_milestones', [
            'status'     => 'released',
            'updated_at' => $now,
        ], ['milestone_id' => $mid]);

        if (class_exists('Growl_Work_Notify')) {
            $mref = $c->public_slug . ':m' . $mid;
            Growl_Work_Notify::prompt_work_rating('job_milestone', $mref, 'human', (string) $c->employer_user_id);
            Growl_Work_Notify::prompt_work_rating('job_milestone', $mref, 'human', (string) $c->worker_user_id);
        }

        $pending = $this->db->get_var(
            "SELECT COUNT(*) FROM {$p}job_milestones WHERE contract_id = %d AND status != 'released'",
            [(int) $contract_id]
        );
        if ((int) $pending === 0) {
            $this->db->update('job_contracts', [
                'status'     => 'released',
                'updated_at' => $now,
            ], ['contract_id' => (int) $contract_id]);
            if (class_exists('Growl_Work_Notify')) {
                Growl_Work_Notify::human(
                    (int) $c->worker_user_id,
                    'Payment released',
                    'All milestones for “' . $c->title . '” are complete. Funds are in your balance.',
                    'job-contract/' . $c->public_slug,
                    'success'
                );
            }
        } else {
            $next = $this->current_milestone_row((int) $contract_id, 'pending');
            $next_deposit = $c->deposit_micro;
            if ($next) {
                $next_split = Growl_Work_Fee_Engine::split_for_gross($next->amount_micro, 'jobs');
                $next_deposit = $next_split['deposit_micro'];
            }
            $this->db->update('job_contracts', [
                'status'        => 'pending_funding',
                'deposit_micro' => $next_deposit,
                'updated_at'    => $now,
            ], ['contract_id' => (int) $contract_id]);
            if (class_exists('Growl_Work_Notify')) {
                Growl_Work_Notify::human(
                    (int) $c->worker_user_id,
                    'Milestone released',
                    '“' . $m->title . '” was released. Waiting for the next held payment.',
                    'job-contract/' . $c->public_slug,
                    'success'
                );
                Growl_Work_Notify::human(
                    (int) $c->employer_user_id,
                    'Fund next milestone',
                    'Release done for “' . $m->title . '”. Fund the next milestone to continue.',
                    'job-contract/' . $c->public_slug,
                    'info'
                );
            }
        }

        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once __DIR__ . '/class-growl-work-receipt-service.php';
        }
        $mref = $c->public_slug . ':m' . $mid;
        (new Growl_Work_Receipt_Service($this->db))->upsert_from_ref('job_milestone', $mref);
        if ((int) $pending === 0) {
            (new Growl_Work_Receipt_Service($this->db))->upsert_from_ref('job_contract', (string) $c->public_slug);
        }

        return $this->get_by_id($contract_id);
    }
}
