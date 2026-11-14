<?php
/**
 * Unified Work Receipt V1 — one completion artifact for every hire type.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Work_Receipt_Service {

    const SCHEMA_VERSION = '1';

    const REF_TYPES = [
        'agent_order',
        'agent_deal',
        'deal',
        'human_action',
        'job_contract',
        'job_milestone',
        'open_work_submission',
        'work_order',
        'task_completion',
    ];

    const TERMINAL_STATUSES = ['released', 'completed', 'accepted', 'complete'];

    /** @var GROWL_Database */
    private $db;

    public function __construct(?GROWL_Database $db = null) {
        $this->db = $db ?: new GROWL_Database();
    }

    /**
     * Create or refresh a receipt row from a completed work reference.
     *
     * @param array<string,mixed> $context Optional overrides merged onto loaded context.
     * @return string|null receipt_id
     */
    public function upsert_from_ref(string $ref_type, string $ref_id, array $context = []) {
        $ref_type = sanitize_key($ref_type);
        $ref_id   = sanitize_text_field($ref_id);
        if ($ref_type === '' || $ref_id === '') {
            return null;
        }

        $loaded = $this->load_context($ref_type, $ref_id);
        if ($loaded === null) {
            return null;
        }
        $ctx = array_merge($loaded, $context);

        $p = $this->db->table_prefix;
        $existing = $this->db->get_var(
            "SELECT receipt_id FROM {$p}work_receipts WHERE ref_type = %s AND ref_id = %s LIMIT 1",
            [$ref_type, $ref_id]
        );

        $receipt_id = $existing ? (string) $existing : ('rcpt_' . bin2hex(random_bytes(8)));
        $meta       = $this->build_meta($ref_type, $ref_id, $ctx);
        $completed  = (string) ($ctx['completed_at'] ?? gmdate('Y-m-d H:i:s'));
        $status     = sanitize_key((string) ($ctx['status'] ?? 'released'));

        $row = [
            'receipt_id'        => $receipt_id,
            'ref_type'          => $ref_type,
            'ref_id'            => $ref_id,
            'payer_type'        => (string) ($ctx['payer_type'] ?? 'human'),
            'payer_ref'         => (string) ($ctx['payer_ref'] ?? ''),
            'payee_type'        => (string) ($ctx['payee_type'] ?? 'human'),
            'payee_ref'         => (string) ($ctx['payee_ref'] ?? ''),
            'worker_type'       => (string) ($ctx['worker_type'] ?? 'human'),
            'amount_micro'      => (string) ($ctx['amount_micro'] ?? '0'),
            'fee_micro'         => (string) ($ctx['fee_micro'] ?? '0'),
            'net_micro'         => (string) ($ctx['net_micro'] ?? ($ctx['amount_micro'] ?? '0')),
            'deliverable_hash'  => !empty($ctx['deliverable_hash']) ? (string) $ctx['deliverable_hash'] : null,
            'settlement_id'     => !empty($ctx['settlement_id']) ? (int) $ctx['settlement_id'] : null,
            'status'            => $status !== '' ? $status : 'released',
            'completed_at'      => $completed,
            'receipt_meta_json' => wp_json_encode($meta),
            'created_at'        => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing) {
            unset($row['created_at']);
            $this->db->update('work_receipts', $row, ['receipt_id' => $receipt_id]);
        } else {
            $this->db->insert('work_receipts', $row);
        }

        return $receipt_id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get_v1_by_receipt_id(string $receipt_id, bool $include_verified = false) {
        $row = $this->get_row_by_receipt_id($receipt_id);
        if (!$row) {
            return null;
        }
        return $this->build_v1($row, $include_verified);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get_v1_by_ref(string $ref_type, string $ref_id, bool $include_verified = false) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT * FROM {$p}work_receipts WHERE ref_type = %s AND ref_id = %s LIMIT 1",
            [sanitize_key($ref_type), sanitize_text_field($ref_id)]
        );
        if (!$row) {
            $id = $this->upsert_from_ref($ref_type, $ref_id);
            if (!$id) {
                return null;
            }
            $row = $this->get_row_by_receipt_id($id);
        }
        if (!$row) {
            return null;
        }
        return $this->build_v1($row, $include_verified);
    }

    /**
     * @return array{work_receipt: array<string,mixed>, verified: bool}|null
     */
    public function verify(string $receipt_id) {
        $v1 = $this->get_v1_by_receipt_id($receipt_id, true);
        if (!$v1) {
            return null;
        }
        return [
            'work_receipt' => $v1,
            'verified'     => !empty($v1['verified']),
        ];
    }

    /**
     * Attach work_receipt to a guidance envelope when work is terminal.
     *
     * @param array<string,mixed> $envelope
     * @param array<string,mixed> $entity
     * @return array<string,mixed>
     */
    public static function attach_to_envelope(array $envelope, string $entity_type, array $entity): array {
        $map = [
            'agent_order'          => ['agent_order', 'public_slug'],
            'agent_deal'           => ['agent_deal', 'public_slug'],
            'open_work_submission' => ['open_work_submission', 'submission_id'],
        ];
        if (!isset($map[$entity_type])) {
            return $envelope;
        }
        $status = sanitize_key((string) ($entity['status'] ?? $envelope['status'] ?? ''));
        if (!in_array($status, self::TERMINAL_STATUSES, true)) {
            return $envelope;
        }
        [$ref_type, $field] = $map[$entity_type];
        $ref_id = (string) ($entity[$field] ?? '');
        if ($ref_id === '') {
            return $envelope;
        }
        $svc = new self();
        $svc->upsert_from_ref($ref_type, $ref_id);
        $receipt = $svc->get_v1_by_ref($ref_type, $ref_id);
        if ($receipt) {
            $envelope['work_receipt'] = $receipt;
        }
        return $envelope;
    }

    /**
     * Attach work_receipt to a flat API response array.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function attach_to_payload(array $payload, string $ref_type, string $ref_id, string $status = ''): array {
        if ($status !== '' && !in_array(sanitize_key($status), self::TERMINAL_STATUSES, true)) {
            return $payload;
        }
        $svc = new self();
        $svc->upsert_from_ref($ref_type, $ref_id);
        $receipt = $svc->get_v1_by_ref($ref_type, $ref_id);
        if ($receipt) {
            $payload['work_receipt'] = $receipt;
        }
        return $payload;
    }

    /**
     * @return object|null
     */
    private function get_row_by_receipt_id(string $receipt_id) {
        $p = $this->db->table_prefix;
        return $this->db->get_row(
            "SELECT r.*, s.tx_hash, s.status AS settlement_status, s.amount_micro AS settlement_amount,
                    s.network_caip2, s.facilitator_id, s.settlement_state, s.payment_method_id, s.network AS settlement_network
             FROM {$p}work_receipts r
             LEFT JOIN {$p}x402_settlements s ON s.settlement_id = r.settlement_id
             WHERE r.receipt_id = %s LIMIT 1",
            [sanitize_text_field($receipt_id)]
        );
    }

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    private function build_v1($row, bool $include_verified = false): array {
        $meta = $this->decode_json($row->receipt_meta_json ?? '{}', []);
        $ref_type = (string) $row->ref_type;
        $ref_id   = (string) $row->ref_id;
        $base     = untrailingslashit(rest_url('ai-agent/v1'));

        $verified = false;
        if ($include_verified) {
            $verified = !empty($row->settlement_id)
                && (string) ($row->settlement_status ?? '') === 'settled'
                && (string) $row->amount_micro === (string) ($row->settlement_amount ?? '');
            if (!$verified && (int) ($row->amount_micro ?? 0) === 0) {
                $verified = true;
            }
        }

        $v1 = [
            'schema_version' => self::SCHEMA_VERSION,
            'receipt_id'     => (string) $row->receipt_id,
            'ref_type'       => $ref_type,
            'ref_id'         => $ref_id,
            'status'         => (string) ($row->status ?? 'released'),
            'completed_at'   => !empty($row->completed_at)
                ? gmdate('c', strtotime($row->completed_at . ' UTC'))
                : gmdate('c', strtotime($row->created_at . ' UTC')),
            'work'           => is_array($meta['work'] ?? null) ? $meta['work'] : [],
            'parties'        => [
                'payer'  => ['type' => (string) $row->payer_type, 'ref' => (string) $row->payer_ref],
                'payee'  => ['type' => (string) $row->payee_type, 'ref' => (string) $row->payee_ref],
                'worker' => ['type' => (string) $row->worker_type, 'ref' => (string) $row->payee_ref],
            ],
            'payment'        => [
                'amount_micro'  => (string) $row->amount_micro,
                'fee_micro'     => (string) ($row->fee_micro ?? '0'),
                'net_micro'     => (string) ($row->net_micro ?? $row->amount_micro),
                'currency'      => 'USDC',
                'protocol'      => !empty($row->settlement_id) ? 'x402' : 'balance',
                'settlement_id' => $row->settlement_id ? (int) $row->settlement_id : null,
                'tx_hash'       => !empty($row->tx_hash) ? (string) $row->tx_hash : null,
                'network_caip2' => !empty($row->network_caip2)
                    ? (string) $row->network_caip2
                    : (!empty($row->settlement_network) ? (string) $row->settlement_network : null),
                'facilitator_id'   => !empty($row->facilitator_id) ? (string) $row->facilitator_id : null,
                'settlement_state' => !empty($row->settlement_state) ? (string) $row->settlement_state : null,
                'payment_method_id'=> !empty($row->payment_method_id) ? (string) $row->payment_method_id : null,
            ],
            'links'          => [
                'self'   => $base . '/receipts/' . rawurlencode((string) $row->receipt_id),
                'verify' => $base . '/receipts/' . rawurlencode((string) $row->receipt_id) . '/verify',
                'lookup' => $base . '/work/receipts?ref_type=' . rawurlencode($ref_type) . '&ref_id=' . rawurlencode($ref_id),
            ],
        ];

        if ($include_verified) {
            $v1['verified'] = $verified;
        }

        return $v1;
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function build_meta(string $ref_type, string $ref_id, array $ctx): array {
        return [
            'work' => [
                'kind'       => (string) ($ctx['work_kind'] ?? $ref_type),
                'title'      => (string) ($ctx['title'] ?? ''),
                'spec'       => is_array($ctx['spec'] ?? null) ? $ctx['spec'] : [],
                'acceptance' => is_array($ctx['acceptance'] ?? null) ? $ctx['acceptance'] : [],
                'evidence'   => is_array($ctx['evidence'] ?? null) ? $ctx['evidence'] : [],
                'dispute'    => is_array($ctx['dispute'] ?? null) ? $ctx['dispute'] : [],
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_context(string $ref_type, string $ref_id) {
        switch ($ref_type) {
            case 'agent_order':
                return $this->load_agent_order($ref_id);
            case 'agent_deal':
                return $this->load_agent_deal($ref_id);
            case 'deal':
                return $this->load_deal($ref_id);
            case 'human_action':
                return $this->load_human_action($ref_id);
            case 'job_contract':
                return $this->load_job_contract($ref_id);
            case 'job_milestone':
                return $this->load_job_milestone($ref_id);
            case 'open_work_submission':
                return $this->load_open_work_submission($ref_id);
            case 'work_order':
                return $this->load_work_order($ref_id);
            case 'task_completion':
                return $this->load_task_completion($ref_id);
            default:
                return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_agent_order(string $slug) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT o.*, s.title AS service_title, s.inspection_seconds, s.output_schema_json
             FROM {$p}agent_orders o
             LEFT JOIN {$p}agent_services s ON s.service_id = o.service_id
             WHERE o.public_slug = %s LIMIT 1",
            [sanitize_text_field($slug)]
        );
        if (!$row || (string) $row->status !== 'released') {
            return null;
        }

        $output = $row->output_json ? json_decode((string) $row->output_json, true) : null;
        $input  = $row->input_json ? json_decode((string) $row->input_json, true) : null;
        $hash   = $row->output_json ? hash('sha256', (string) $row->output_json) : null;

        if (!class_exists('Growl_Work_Fee_Engine')) {
            require_once __DIR__ . '/class-growl-work-fee-engine.php';
        }
        $split = Growl_Work_Fee_Engine::split_for_gross($row->amount_micro, 'agent_orders');

        return [
            'payer_type'       => (string) $row->buyer_type,
            'payer_ref'        => (string) $row->buyer_ref,
            'payee_type'       => 'agent',
            'payee_ref'        => (string) $row->provider_agent_id,
            'worker_type'      => 'agent',
            'amount_micro'     => (string) $row->amount_micro,
            'fee_micro'        => (string) ($split['platform_fee_micro'] ?? '0'),
            'net_micro'        => (string) ($split['net_to_earner_micro'] ?? $row->amount_micro),
            'settlement_id'    => $row->fund_settlement_id ? (int) $row->fund_settlement_id : null,
            'deliverable_hash' => $hash,
            'status'           => 'released',
            'completed_at'     => (string) ($row->updated_at ?? gmdate('Y-m-d H:i:s')),
            'work_kind'        => 'agent_order',
            'title'            => (string) ($row->service_title ?? ''),
            'spec'             => [
                'input'          => $input,
                'output_schema'  => !empty($row->output_schema_json) ? json_decode((string) $row->output_schema_json, true) : null,
                'delivery_type'  => (string) ($row->delivery_type ?? 'protected'),
            ],
            'acceptance'       => [
                'mode'        => (string) ($row->delivery_type ?? 'protected') === 'instant' ? 'auto_release' : 'buyer_release',
                'criteria'    => 'Buyer releases payment after reviewing delivery.',
                'verified_by' => 'buyer',
            ],
            'evidence'         => [
                'hash'   => $hash,
                'output' => $output,
            ],
            'dispute'          => [
                'window_seconds'  => (int) ($row->inspection_seconds ?? 0) ?: null,
                'auto_release_at' => null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_agent_deal(string $slug) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT * FROM {$p}agent_deals WHERE public_slug = %s LIMIT 1",
            [sanitize_text_field($slug)]
        );
        if (!$row || (string) $row->status !== 'released') {
            return null;
        }

        $payload = trim((string) ($row->deliverable_text ?? ''));
        $hash    = $payload !== '' ? hash('sha256', $payload) : null;

        if (!class_exists('Growl_Work_Fee_Engine')) {
            require_once __DIR__ . '/class-growl-work-fee-engine.php';
        }
        $split = Growl_Work_Fee_Engine::split_for_gross($row->amount_micro, 'deals');

        return [
            'payer_type'       => (string) $row->buyer_type,
            'payer_ref'        => (string) $row->buyer_ref,
            'payee_type'       => (string) $row->seller_type,
            'payee_ref'        => (string) $row->seller_ref,
            'worker_type'      => (string) $row->seller_type,
            'amount_micro'     => (string) $row->amount_micro,
            'fee_micro'        => (string) ($split['platform_fee_micro'] ?? '0'),
            'net_micro'        => (string) ($split['net_to_earner_micro'] ?? $row->amount_micro),
            'settlement_id'    => $row->fund_settlement_id ? (int) $row->fund_settlement_id : null,
            'deliverable_hash' => $hash,
            'status'           => 'released',
            'completed_at'     => (string) ($row->updated_at ?? gmdate('Y-m-d H:i:s')),
            'work_kind'        => 'agent_deal',
            'title'            => (string) ($row->title ?? ''),
            'spec'             => [
                'scope' => (string) ($row->scope ?? ''),
            ],
            'acceptance'       => [
                'mode'        => 'buyer_release',
                'criteria'    => 'Buyer releases escrow after delivery review.',
                'verified_by' => 'buyer',
            ],
            'evidence'         => [
                'hash'    => $hash,
                'payload' => $payload !== '' ? $payload : null,
            ],
            'dispute'          => [
                'window_seconds'  => (int) ($row->inspection_seconds ?? 0) ?: null,
                'auto_release_at' => !empty($row->auto_release_at) ? gmdate('c', strtotime($row->auto_release_at . ' UTC')) : null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_deal(string $slug) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT * FROM {$p}deals WHERE public_slug = %s LIMIT 1",
            [sanitize_text_field($slug)]
        );
        if (!$row || (string) $row->status !== 'released') {
            return null;
        }

        $del = $this->db->get_row(
            "SELECT note, file_url FROM {$p}deal_deliverables WHERE deal_id = %d ORDER BY deliverable_id DESC LIMIT 1",
            [(int) $row->deal_id]
        );
        $payload = wp_json_encode([
            'deal' => $slug,
            'note' => $del->note ?? '',
            'file' => $del->file_url ?? '',
        ]);
        $hash = hash('sha256', (string) $payload);

        return [
            'payer_type'       => 'human',
            'payer_ref'        => (string) $row->buyer_user_id,
            'payee_type'       => 'human',
            'payee_ref'        => (string) $row->seller_user_id,
            'worker_type'      => 'human',
            'amount_micro'     => (string) $row->amount_micro,
            'fee_micro'        => '0',
            'net_micro'        => (string) $row->amount_micro,
            'settlement_id'    => $row->fund_settlement_id ? (int) $row->fund_settlement_id : null,
            'deliverable_hash' => $hash,
            'status'           => 'released',
            'completed_at'     => (string) ($row->updated_at ?? gmdate('Y-m-d H:i:s')),
            'work_kind'        => 'deal',
            'title'            => (string) ($row->title ?? ''),
            'spec'             => [
                'scope' => (string) ($row->description ?? ''),
            ],
            'acceptance'       => [
                'mode'        => 'buyer_release',
                'criteria'    => 'Buyer releases payment when deliverable matches scope.',
                'verified_by' => 'buyer',
            ],
            'evidence'         => [
                'hash' => $hash,
                'url'  => $del && !empty($del->file_url) ? (string) $del->file_url : null,
                'note' => $del ? (string) ($del->note ?? '') : '',
            ],
            'dispute'          => [
                'window_seconds' => (int) ($row->inspection_seconds ?? 0) ?: null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_human_action(string $action_id) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT * FROM {$p}human_actions WHERE action_id = %s LIMIT 1",
            [sanitize_text_field($action_id)]
        );
        if (!$row || (string) $row->status !== 'completed') {
            return null;
        }

        $results = $this->db->get_results(
            "SELECT output_json, evidence_json, review_status FROM {$p}human_action_results
             WHERE action_id = %s ORDER BY result_id ASC",
            [(string) $action_id]
        );
        $outputs = [];
        $evidence_urls = [];
        foreach ($results ?: [] as $r) {
            if (!empty($r->output_json)) {
                $decoded = json_decode((string) $r->output_json, true);
                if ($decoded !== null) {
                    $outputs[] = $decoded;
                }
            }
            if (!empty($r->evidence_json)) {
                $ev = json_decode((string) $r->evidence_json, true);
                if (is_array($ev)) {
                    foreach ($ev as $item) {
                        if (is_array($item) && !empty($item['url'])) {
                            $evidence_urls[] = (string) $item['url'];
                        } elseif (is_string($item) && filter_var($item, FILTER_VALIDATE_URL)) {
                            $evidence_urls[] = $item;
                        }
                    }
                }
            }
        }

        $amount_micro = '0';
        $settlement_id = null;
        if (!empty($row->task_id)) {
            $task = $this->db->get_row(
                "SELECT base_reward, user_count FROM {$p}tasks WHERE task_id = %s LIMIT 1",
                [(string) $row->task_id]
            );
            if ($task && class_exists('Growl_Money')) {
                $total = (float) $task->base_reward * max(1, (int) $task->user_count);
                $amount_micro = Growl_Money::to_micro($total);
            }
        }
        if ($amount_micro === '0' && !empty($row->reward_usdc) && class_exists('Growl_Money')) {
            $slots = max(1, (int) $row->filled_slots ?: (int) $row->slots);
            $amount_micro = Growl_Money::to_micro((float) $row->reward_usdc * $slots);
        }

        $blob = wp_json_encode(['action_id' => $action_id, 'results' => $outputs]);
        $hash = hash('sha256', (string) $blob);

        return [
            'payer_type'       => 'agent',
            'payer_ref'        => (string) $row->agent_id,
            'payee_type'       => 'human',
            'payee_ref'        => 'pool',
            'worker_type'      => 'human',
            'amount_micro'     => $amount_micro,
            'fee_micro'        => '0',
            'net_micro'        => $amount_micro,
            'settlement_id'    => $settlement_id,
            'deliverable_hash' => $hash,
            'status'           => 'completed',
            'completed_at'     => !empty($row->completed_at) ? (string) $row->completed_at : (string) $row->updated_at,
            'work_kind'        => 'human_action:' . (string) $row->kind,
            'title'            => substr((string) $row->prompt, 0, 120),
            'spec'             => [
                'prompt'        => (string) $row->prompt,
                'input'         => $this->decode_json($row->input_json ?? '{}', []),
                'kind'          => (string) $row->kind,
                'slots'         => (int) $row->slots,
                'aggregation'   => (string) $row->aggregation,
            ],
            'acceptance'       => [
                'mode'        => (string) $row->verification_mode,
                'criteria'    => 'Results aggregated per ' . (string) $row->aggregation . ' rule.',
                'verified_by' => (string) $row->verification_mode,
            ],
            'evidence'         => [
                'hash'  => $hash,
                'urls'  => array_values(array_unique($evidence_urls)),
                'output' => $outputs,
            ],
            'dispute'          => [
                'window_seconds' => null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_job_contract(string $slug) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT * FROM {$p}job_contracts WHERE public_slug = %s LIMIT 1",
            [sanitize_text_field($slug)]
        );
        if (!$row || (string) $row->status !== 'released') {
            return null;
        }

        return [
            'payer_type'       => 'human',
            'payer_ref'        => (string) $row->employer_user_id,
            'payee_type'       => 'human',
            'payee_ref'        => (string) $row->worker_user_id,
            'worker_type'      => 'human',
            'amount_micro'     => (string) $row->amount_micro,
            'fee_micro'        => (string) ($row->earner_fee_micro ?? '0'),
            'net_micro'        => class_exists('Growl_Money')
                ? Growl_Money::sub((string) $row->amount_micro, (string) ($row->earner_fee_micro ?? '0'))
                : (string) $row->amount_micro,
            'settlement_id'    => null,
            'deliverable_hash' => null,
            'status'           => 'released',
            'completed_at'     => (string) ($row->updated_at ?? gmdate('Y-m-d H:i:s')),
            'work_kind'        => 'job_contract',
            'title'            => (string) ($row->title ?? ''),
            'spec'             => [
                'scope' => (string) ($row->description ?? ''),
            ],
            'acceptance'       => [
                'mode'        => 'employer_release',
                'criteria'    => 'Employer releases after delivery review.',
                'verified_by' => 'employer',
            ],
            'evidence'         => [],
            'dispute'          => [],
        ];
    }

    /**
     * ref_id format: {contract_slug}:m{milestone_id}
     *
     * @return array<string,mixed>|null
     */
    private function load_job_milestone(string $ref_id) {
        if (!preg_match('/^([a-z0-9]+):m(\d+)$/', $ref_id, $m)) {
            return null;
        }
        $slug = $m[1];
        $mid  = (int) $m[2];
        $p    = $this->db->table_prefix;

        $c = $this->db->get_row(
            "SELECT * FROM {$p}job_contracts WHERE public_slug = %s LIMIT 1",
            [$slug]
        );
        $mile = $this->db->get_row(
            "SELECT * FROM {$p}job_milestones WHERE milestone_id = %d LIMIT 1",
            [$mid]
        );
        if (!$c || !$mile || (string) $mile->status !== 'released') {
            return null;
        }

        if (!class_exists('Growl_Work_Fee_Engine')) {
            require_once __DIR__ . '/class-growl-work-fee-engine.php';
        }
        $split = Growl_Work_Fee_Engine::split_for_gross($mile->amount_micro, 'jobs');

        return [
            'payer_type'       => 'human',
            'payer_ref'        => (string) $c->employer_user_id,
            'payee_type'       => 'human',
            'payee_ref'        => (string) $c->worker_user_id,
            'worker_type'      => 'human',
            'amount_micro'     => (string) $mile->amount_micro,
            'fee_micro'        => (string) ($split['platform_fee_micro'] ?? '0'),
            'net_micro'        => (string) ($split['net_to_earner_micro'] ?? $mile->amount_micro),
            'status'           => 'released',
            'completed_at'     => (string) ($mile->updated_at ?? gmdate('Y-m-d H:i:s')),
            'work_kind'        => 'job_milestone',
            'title'            => (string) ($mile->title ?? $c->title ?? ''),
            'spec'             => [
                'contract_slug' => $slug,
                'milestone_id'  => $mid,
            ],
            'acceptance'       => [
                'mode'        => 'employer_release',
                'criteria'    => 'Employer releases milestone after delivery review.',
                'verified_by' => 'employer',
            ],
            'evidence'         => [],
            'dispute'          => [],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_open_work_submission(string $submission_id) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT * FROM {$p}open_work_submissions WHERE submission_id = %d LIMIT 1",
            [(int) $submission_id]
        );
        if (!$row || (string) $row->status !== 'accepted') {
            return null;
        }

        $payload = $this->decode_json($row->payload_json ?? '{}', []);
        $hash    = hash('sha256', wp_json_encode($payload));
        $amount  = $row->bid_micro !== null ? (string) $row->bid_micro : '0';

        if ($amount === '0' && (string) $row->ref_type === 'task') {
            $task = $this->db->get_row(
                "SELECT base_reward FROM {$p}tasks WHERE task_id = %s LIMIT 1",
                [(string) $row->ref_id]
            );
            if ($task && class_exists('Growl_Money')) {
                $amount = Growl_Money::to_micro((float) $task->base_reward);
            }
        }

        $evidence_url = null;
        if (!empty($payload['url']) && is_string($payload['url'])) {
            $evidence_url = $payload['url'];
        }

        $hirer_id = '0';
        if ((string) $row->ref_type === 'task') {
            $task = $this->db->get_row(
                "SELECT creator_id FROM {$p}tasks WHERE task_id = %s LIMIT 1",
                [(string) $row->ref_id]
            );
            $hirer_id = $task ? (string) $task->creator_id : '0';
        } elseif ((string) $row->ref_type === 'job_listing') {
            $listing = $this->db->get_row(
                "SELECT poster_user_id FROM {$p}job_listings WHERE listing_id = %d LIMIT 1",
                [(int) $row->ref_id]
            );
            $hirer_id = $listing ? (string) $listing->poster_user_id : '0';
        }

        return [
            'payer_type'       => 'human',
            'payer_ref'        => $hirer_id,
            'payee_type'       => (string) $row->submitter_type,
            'payee_ref'        => (string) $row->submitter_ref,
            'worker_type'      => (string) $row->submitter_type,
            'amount_micro'     => $amount,
            'fee_micro'        => '0',
            'net_micro'        => $amount,
            'deliverable_hash' => $hash,
            'status'           => 'accepted',
            'completed_at'     => (string) ($row->updated_at ?? gmdate('Y-m-d H:i:s')),
            'work_kind'        => 'open_work:' . (string) $row->work_mode,
            'title'            => (string) $row->work_mode . ' submission',
            'spec'             => [
                'work_mode' => (string) $row->work_mode,
                'ref_type'  => (string) $row->ref_type,
                'ref_id'    => (string) $row->ref_id,
                'payload'   => $payload,
            ],
            'acceptance'       => [
                'mode'        => 'hirer_accept',
                'criteria'    => 'Hirer accepted the submission.',
                'verified_by' => 'hirer',
            ],
            'evidence'         => [
                'hash' => $hash,
                'url'  => $evidence_url,
                'output' => $payload,
            ],
            'dispute'          => [],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_work_order(string $slug) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT * FROM {$p}work_orders WHERE public_slug = %s LIMIT 1",
            [sanitize_text_field($slug)]
        );
        if (!$row || (string) $row->status !== 'complete') {
            return null;
        }

        return [
            'payer_type'    => 'human',
            'payer_ref'     => (string) $row->buyer_user_id,
            'payee_type'    => 'human',
            'payee_ref'     => (string) $row->buyer_user_id,
            'worker_type'   => 'hybrid',
            'amount_micro'  => (string) $row->budget_micro,
            'fee_micro'     => '0',
            'net_micro'     => (string) $row->budget_micro,
            'settlement_id' => $row->fund_settlement_id ? (int) $row->fund_settlement_id : null,
            'status'        => 'completed',
            'completed_at'  => (string) ($row->updated_at ?? gmdate('Y-m-d H:i:s')),
            'work_kind'     => 'work_order',
            'title'         => (string) ($row->title ?? ''),
            'spec'          => [],
            'acceptance'    => ['mode' => 'auto_complete', 'criteria' => 'All child jobs terminal.'],
            'evidence'      => [],
            'dispute'       => [],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_task_completion(string $task_id) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT task_id, status, base_reward, user_count, title, work_mode, updated_at, creator_wallet
             FROM {$p}tasks WHERE task_id = %s LIMIT 1",
            [sanitize_text_field($task_id)]
        );
        if (!$row || (string) $row->status !== 'completed') {
            return null;
        }

        $amount = class_exists('Growl_Money')
            ? Growl_Money::to_micro((float) $row->base_reward * max(1, (int) $row->user_count))
            : '0';

        return [
            'payer_type'   => 'human',
            'payer_ref'    => (string) ($row->creator_wallet ?? '0'),
            'payee_type'   => 'human',
            'payee_ref'    => 'workers',
            'worker_type'  => 'human',
            'amount_micro' => $amount,
            'fee_micro'    => '0',
            'net_micro'    => $amount,
            'status'       => 'completed',
            'completed_at' => (string) ($row->updated_at ?? gmdate('Y-m-d H:i:s')),
            'work_kind'    => 'task_completion',
            'title'        => (string) ($row->title ?? ''),
            'spec'         => [
                'work_mode' => (string) ($row->work_mode ?? ''),
            ],
            'acceptance'   => ['mode' => 'slot_fill', 'criteria' => 'All slots completed.'],
            'evidence'     => [],
            'dispute'      => [],
        ];
    }

    /**
     * @param mixed $json
     * @param mixed $fallback
     * @return mixed
     */
    private function decode_json($json, $fallback) {
        $decoded = json_decode((string) $json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
    }
}
