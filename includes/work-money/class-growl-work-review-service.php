<?php
/**
 * Unified 1-5 work reviews for tasks, deals, job contracts, and agent orders.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Work_Review_Service {

    public const WINDOW_DAYS = 14;
    public const EDIT_HOURS = 24;

    /** @var GROWL_Database */
    private $db;

    public function __construct(?GROWL_Database $db = null) {
        $this->db = $db ?: new GROWL_Database();
    }

    public function submit($rater_type, $rater_ref, array $data) {
        $ref_type = sanitize_key((string) ($data['ref_type'] ?? ''));
        $ref_id   = sanitize_text_field((string) ($data['ref_id'] ?? ''));
        $stars    = (int) ($data['stars'] ?? 0);
        if (!in_array($ref_type, ['deal', 'agent_deal', 'agent_order', 'task_completion', 'job_contract', 'job_milestone', 'open_work_submission'], true) || $ref_id === '') {
            return new WP_Error('invalid', 'A completed piece of work is required.', ['status' => 400]);
        }
        if ($stars < 1 || $stars > 5) {
            return new WP_Error('invalid', 'Choose a rating from 1 to 5.', ['status' => 400]);
        }
        $ctx = $this->resolve_context($ref_type, $ref_id);
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $canon_ref = (string) $ctx['ref_id'];
        $side = $this->rater_side($ctx, $rater_type, $rater_ref);
        if (is_wp_error($side)) {
            return $side;
        }
        if ((string) $side['ratee_ref'] === (string) $rater_ref && (string) $side['ratee_type'] === (string) $rater_type) {
            return new WP_Error('invalid', 'You cannot rate yourself.', ['status' => 400]);
        }

        $comment = sanitize_textarea_field((string) ($data['comment'] ?? ''));
        if (function_exists('mb_strlen') && mb_strlen($comment) > 500) {
            $comment = mb_substr($comment, 0, 500);
        }
        $private = sanitize_textarea_field((string) ($data['private_note'] ?? ''));
        $tags    = $data['tags'] ?? [];
        $tags    = is_array($tags) ? array_values(array_unique(array_map('sanitize_key', $tags))) : [];

        $existing = $this->find_existing($ref_type, $canon_ref, $rater_type, $rater_ref, $ref_id);

        $this->db->begin_transaction();
        try {
            if ($existing) {
                $age = time() - strtotime($existing->created_at . ' UTC');
                if ((int) $existing->edit_count >= 1 || $age > self::EDIT_HOURS * 3600) {
                    $this->db->rollback();
                    return new WP_Error('locked', 'This rating can no longer be changed.', ['status' => 400]);
                }
                $this->db->update('work_reviews', [
                    'stars'        => $stars,
                    'comment'      => $comment,
                    'private_note' => $private,
                    'tags_json'    => $tags ? wp_json_encode($tags) : null,
                    'edit_count'   => (int) $existing->edit_count + 1,
                    'updated_at'   => gmdate('Y-m-d H:i:s'),
                ], ['review_id' => (int) $existing->review_id]);
                $review_id = (int) $existing->review_id;
            } else {
                $ok = $this->db->insert('work_reviews', [
                    'ref_type'     => $ref_type,
                    'ref_id'       => $canon_ref,
                    'rater_type'   => $rater_type,
                    'rater_ref'    => (string) $rater_ref,
                    'ratee_type'   => $side['ratee_type'],
                    'ratee_ref'    => (string) $side['ratee_ref'],
                    'service_id'   => $side['service_id'] ? (int) $side['service_id'] : null,
                    'stars'        => $stars,
                    'comment'      => $comment,
                    'private_note' => $private,
                    'tags_json'    => $tags ? wp_json_encode($tags) : null,
                    'status'       => 'public',
                    'edit_count'   => 0,
                    'created_at'   => gmdate('Y-m-d H:i:s'),
                    'updated_at'   => gmdate('Y-m-d H:i:s'),
                ]);
                if (!$ok) {
                    $this->db->rollback();
                    $dup = $this->find_existing($ref_type, $canon_ref, $rater_type, $rater_ref, $ref_id);
                    if ($dup) {
                        return $this->get((int) $dup->review_id);
                    }
                    $db_err = (string) ($this->db->wpdb->last_error ?? '');
                    if ($db_err !== '' && stripos($db_err, 'Duplicate') !== false) {
                        return new WP_Error('exists', 'You already rated this work.', ['status' => 400]);
                    }
                    return new WP_Error('create_failed', 'Could not save rating.', ['status' => 500]);
                }
                $review_id = (int) $this->db->wpdb->insert_id;
            }
            $this->recompute_aggregates($side['ratee_type'], $side['ratee_ref'], $side['service_id']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('create_failed', 'Could not save rating.', ['status' => 500]);
        }

        if (!$existing && $side['ratee_type'] === 'human' && class_exists('GROWL_Gamification')) {
            $delta = $stars >= 5 ? 1 : ($stars <= 2 ? -1 : 0);
            if ($delta !== 0) {
                GROWL_Gamification::apply_public_delta(
                    (int) $side['ratee_ref'],
                    $delta,
                    'work_review',
                    'review',
                    (string) $review_id
                );
            }
        }
        if (!$existing && $side['ratee_type'] === 'agent') {
            $this->db->wpdb->query($this->db->wpdb->prepare(
                "UPDATE {$this->db->table_prefix}ai_agents SET updated_at = %s WHERE agent_id = %s",
                gmdate('Y-m-d H:i:s'),
                (string) $side['ratee_ref']
            ));
        }

        $this->maybe_enqueue_said_from_review($ref_type, $canon_ref, $stars, $side, $data, (bool) $existing);

        return $this->get($review_id);
    }

    /**
     * SAID feedback is review-gated: stars drive outcome; signature required to drain.
     */
    private function maybe_enqueue_said_from_review($ref_type, $ref_id, $stars, array $side, array $data, $is_edit) {
        if ($side['ratee_type'] !== 'agent') {
            return;
        }
        if ($stars >= 4) {
            $outcome = 'positive';
        } elseif ($stars <= 2) {
            $outcome = 'negative';
        } else {
            return;
        }
        $auth = [
            'signer_wallet' => sanitize_text_field((string) ($data['signer_wallet'] ?? '')),
            'message'       => sanitize_textarea_field((string) ($data['message'] ?? '')),
            'signature'     => $data['signature'] ?? null,
        ];
        if ($auth['signer_wallet'] === '' || $auth['message'] === '' || empty($auth['signature'])) {
            return;
        }
        require_once __DIR__ . '/class-growl-agent-order-service.php';
        require_once __DIR__ . '/class-growl-said-feedback-queue.php';
        $orders = new Growl_Agent_Order_Service($this->db);
        $p = $this->db->table_prefix;
        if ($ref_type === 'agent_order') {
            $orders->enqueue_said_for_order_slug((string) $ref_id, (string) $side['ratee_ref'], $outcome, $auth);
            delete_transient('earnfi_marketplace_stats');
            return;
        }
        if ($ref_type === 'open_work_submission') {
            $sub = $this->db->get_row(
                "SELECT ref_id, ref_type, completion_id FROM {$p}open_work_submissions WHERE submission_id = %d LIMIT 1",
                [(int) $ref_id]
            );
            if (!$sub) {
                return;
            }
            $agent = $this->db->get_row(
                "SELECT wallet_address FROM {$p}ai_agents WHERE agent_id = %s LIMIT 1",
                [(string) $side['ratee_ref']]
            );
            $wallet = trim((string) ($agent->wallet_address ?? ''));
            if ($wallet === '') {
                return;
            }
            $task_id = (string) $sub->ref_type === 'task' ? (string) $sub->ref_id : '';
            (new Growl_Said_Feedback_Queue($this->db))->enqueue(
                $wallet,
                (string) $ref_id,
                $outcome,
                $auth,
                $task_id,
                (int) ($sub->completion_id ?? 0)
            );
            delete_transient('earnfi_marketplace_stats');
            return;
        }
        if ($ref_type === 'agent_deal') {
            $deal = $this->db->get_row(
                "SELECT seller_type, seller_ref FROM {$p}agent_deals WHERE public_slug = %s LIMIT 1",
                [(string) $ref_id]
            );
            if (!$deal || $deal->seller_type !== 'agent') {
                return;
            }
            $agent = $this->db->get_row(
                "SELECT wallet_address FROM {$p}ai_agents WHERE agent_id = %s LIMIT 1",
                [(string) $deal->seller_ref]
            );
            $wallet = trim((string) ($agent->wallet_address ?? ''));
            if ($wallet === '') {
                return;
            }
            (new Growl_Said_Feedback_Queue($this->db))->enqueue($wallet, (string) $ref_id, $outcome, $auth);
            delete_transient('earnfi_marketplace_stats');
        }
    }

    public function get($review_id) {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}work_reviews WHERE review_id = %d LIMIT 1",
            [(int) $review_id]
        );
        return $row ? $this->format($row, false) : null;
    }

    public function list_for_ratee($ratee_type, $ratee_ref, $limit = 20, $offset = 0) {
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $rows = $this->db->get_results(
            "SELECT review_id, ref_type, ref_id, rater_type, ratee_type, stars, comment, created_at
             FROM {$this->db->table_prefix}work_reviews
             WHERE ratee_type = %s AND ratee_ref = %s AND status = 'public'
             ORDER BY review_id DESC
             LIMIT %d OFFSET %d",
            [$ratee_type === 'agent' ? 'agent' : 'human', sanitize_text_field((string) $ratee_ref), $limit, $offset]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->format($row, true);
        }
        return $out;
    }

    public function list_for_service($service_id, $limit = 20, $offset = 0) {
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $rows = $this->db->get_results(
            "SELECT review_id, ref_type, ref_id, rater_type, ratee_type, stars, comment, created_at
             FROM {$this->db->table_prefix}work_reviews
             WHERE service_id = %d AND status = 'public'
             ORDER BY review_id DESC LIMIT %d OFFSET %d",
            [(int) $service_id, $limit, $offset]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->format($row, true);
        }
        return $out;
    }

    public function mine_for_work($ref_type, $ref_id, $rater_type, $rater_ref) {
        $ref_type = sanitize_key($ref_type);
        $ref_id   = sanitize_text_field($ref_id);
        $aliases  = $this->ref_aliases($ref_type, $ref_id);
        $canon    = $aliases[0] ?? $ref_id;
        $row      = $this->find_existing($ref_type, $canon, $rater_type, $rater_ref, $ref_id);
        return $row ? $this->format($row, false) : null;
    }

    /**
     * @return array<int,string>
     */
    private function ref_aliases($ref_type, $ref_id) {
        $p = $this->db->table_prefix;
        $aliases = [(string) $ref_id];
        if ($ref_type === 'deal') {
            $deal = $this->db->get_row(
                "SELECT public_slug, deal_id FROM {$p}deals WHERE public_slug = %s OR deal_id = %d LIMIT 1",
                [$ref_id, (int) $ref_id]
            );
            if ($deal) {
                $aliases[] = (string) $deal->public_slug;
                $aliases[] = (string) $deal->deal_id;
            }
        } elseif ($ref_type === 'agent_order') {
            $order = $this->db->get_row(
                "SELECT public_slug, order_id FROM {$p}agent_orders WHERE public_slug = %s OR order_id = %d LIMIT 1",
                [$ref_id, (int) $ref_id]
            );
            if ($order) {
                $aliases[] = (string) $order->public_slug;
                $aliases[] = (string) $order->order_id;
            }
        } elseif ($ref_type === 'job_contract') {
            $contract = $this->db->get_row(
                "SELECT public_slug, contract_id FROM {$p}job_contracts WHERE public_slug = %s OR contract_id = %d LIMIT 1",
                [$ref_id, (int) $ref_id]
            );
            if ($contract) {
                $aliases[] = (string) $contract->public_slug;
                $aliases[] = (string) $contract->contract_id;
            }
        } elseif ($ref_type === 'agent_deal') {
            $deal = $this->db->get_row(
                "SELECT public_slug, agent_deal_id FROM {$p}agent_deals WHERE public_slug = %s OR agent_deal_id = %d LIMIT 1",
                [$ref_id, (int) $ref_id]
            );
            if ($deal) {
                $aliases[] = (string) $deal->public_slug;
                $aliases[] = (string) $deal->agent_deal_id;
            }
        } elseif ($ref_type === 'job_milestone') {
            $aliases[] = (string) $ref_id;
        } elseif ($ref_type === 'open_work_submission') {
            $aliases[] = (string) $ref_id;
        }
        return array_values(array_unique(array_filter($aliases, static function ($v) {
            return $v !== '';
        })));
    }

    private function find_existing($ref_type, $canonical_ref, $rater_type, $rater_ref, $raw_ref = '') {
        $aliases = $this->ref_aliases($ref_type, $raw_ref !== '' ? $raw_ref : $canonical_ref);
        if (!in_array((string) $canonical_ref, $aliases, true)) {
            array_unshift($aliases, (string) $canonical_ref);
        }
        $aliases = array_values(array_unique($aliases));
        foreach ($aliases as $alias) {
            $row = $this->db->get_row(
                "SELECT * FROM {$this->db->table_prefix}work_reviews
                 WHERE ref_type = %s AND ref_id = %s AND rater_type = %s AND rater_ref = %s LIMIT 1",
                [$ref_type, (string) $alias, $rater_type, (string) $rater_ref]
            );
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    private function resolve_context($ref_type, $ref_id) {
        $p = $this->db->table_prefix;
        if ($ref_type === 'deal') {
            $deal = $this->db->get_row(
                "SELECT public_slug, buyer_user_id, seller_user_id, status, updated_at
                 FROM {$p}deals WHERE public_slug = %s OR deal_id = %d LIMIT 1",
                [$ref_id, (int) $ref_id]
            );
            if (!$deal || $deal->status !== 'released') {
                return new WP_Error('invalid_state', 'Rate after payment is released.', ['status' => 400]);
            }
            if ($this->expired($deal->updated_at)) {
                return new WP_Error('expired', 'The rating window has closed.', ['status' => 400]);
            }
            return [
                'kind'        => 'deal',
                'ref_id'      => (string) $deal->public_slug,
                'buyer_type'  => 'human',
                'buyer_ref'   => (string) $deal->buyer_user_id,
                'seller_type' => 'human',
                'seller_ref'  => (string) $deal->seller_user_id,
                'service_id'  => 0,
            ];
        }
        if ($ref_type === 'job_contract') {
            $contract = $this->db->get_row(
                "SELECT public_slug, employer_user_id, worker_user_id, status, updated_at
                 FROM {$p}job_contracts WHERE public_slug = %s OR contract_id = %d LIMIT 1",
                [$ref_id, (int) $ref_id]
            );
            if (!$contract || $contract->status !== 'released') {
                return new WP_Error('invalid_state', 'Rate after the contract is complete.', ['status' => 400]);
            }
            if ($this->expired($contract->updated_at)) {
                return new WP_Error('expired', 'The rating window has closed.', ['status' => 400]);
            }
            return [
                'kind'        => 'job_contract',
                'ref_id'      => (string) $contract->public_slug,
                'buyer_type'  => 'human',
                'buyer_ref'   => (string) $contract->employer_user_id,
                'seller_type' => 'human',
                'seller_ref'  => (string) $contract->worker_user_id,
                'service_id'  => 0,
            ];
        }
        if ($ref_type === 'job_milestone') {
            $mid = 0;
            $slug = $ref_id;
            if (preg_match('/^(.+):m(\d+)$/', $ref_id, $m)) {
                $slug = $m[1];
                $mid = (int) $m[2];
            }
            $contract = $this->db->get_row(
                "SELECT public_slug, employer_user_id, worker_user_id FROM {$p}job_contracts WHERE public_slug = %s LIMIT 1",
                [$slug]
            );
            if (!$contract || $mid < 1) {
                return new WP_Error('not_found', 'Milestone not found.', ['status' => 404]);
            }
            $mile = $this->db->get_row(
                "SELECT milestone_id, status, updated_at FROM {$p}job_milestones WHERE milestone_id = %d AND contract_id IN (SELECT contract_id FROM {$p}job_contracts WHERE public_slug = %s) LIMIT 1",
                [$mid, $slug]
            );
            if (!$mile || $mile->status !== 'released') {
                return new WP_Error('invalid_state', 'Rate after milestone payment is released.', ['status' => 400]);
            }
            if ($this->expired($mile->updated_at)) {
                return new WP_Error('expired', 'The rating window has closed.', ['status' => 400]);
            }
            return [
                'kind'        => 'job_milestone',
                'ref_id'      => $slug . ':m' . $mid,
                'buyer_type'  => 'human',
                'buyer_ref'   => (string) $contract->employer_user_id,
                'seller_type' => 'human',
                'seller_ref'  => (string) $contract->worker_user_id,
                'service_id'  => 0,
            ];
        }
        if ($ref_type === 'agent_deal') {
            $deal = $this->db->get_row(
                "SELECT public_slug, buyer_type, buyer_ref, seller_type, seller_ref, status, updated_at
                 FROM {$p}agent_deals WHERE public_slug = %s OR agent_deal_id = %d LIMIT 1",
                [$ref_id, (int) $ref_id]
            );
            if (!$deal || $deal->status !== 'released') {
                return new WP_Error('invalid_state', 'Rate after the deal is complete.', ['status' => 400]);
            }
            if ($this->expired($deal->updated_at)) {
                return new WP_Error('expired', 'The rating window has closed.', ['status' => 400]);
            }
            return [
                'kind'        => 'agent_deal',
                'ref_id'      => (string) $deal->public_slug,
                'buyer_type'  => (string) $deal->buyer_type,
                'buyer_ref'   => (string) $deal->buyer_ref,
                'seller_type' => (string) $deal->seller_type,
                'seller_ref'  => (string) $deal->seller_ref,
                'service_id'  => 0,
            ];
        }
        if ($ref_type === 'agent_order') {
            $order = $this->db->get_row(
                "SELECT public_slug, buyer_type, buyer_ref, provider_agent_id, service_id, status, updated_at
                 FROM {$p}agent_orders WHERE public_slug = %s OR order_id = %d LIMIT 1",
                [$ref_id, (int) $ref_id]
            );
            if (!$order || $order->status !== 'released') {
                return new WP_Error('invalid_state', 'Rate after the order is complete.', ['status' => 400]);
            }
            if ($this->expired($order->updated_at)) {
                return new WP_Error('expired', 'The rating window has closed.', ['status' => 400]);
            }
            return [
                'kind'        => 'agent_order',
                'ref_id'      => (string) $order->public_slug,
                'buyer_type'  => (string) $order->buyer_type,
                'buyer_ref'   => (string) $order->buyer_ref,
                'seller_type' => 'agent',
                'seller_ref'  => (string) $order->provider_agent_id,
                'service_id'  => (int) $order->service_id,
            ];
        }
        if ($ref_type === 'open_work_submission') {
            $sub = $this->db->get_row(
                "SELECT submission_id, ref_type, ref_id, submitter_type, submitter_ref, status, completion_id, updated_at
                 FROM {$p}open_work_submissions WHERE submission_id = %d LIMIT 1",
                [(int) $ref_id]
            );
            if (!$sub || $sub->status !== 'accepted') {
                return new WP_Error('invalid_state', 'Rate after the submission is accepted.', ['status' => 400]);
            }
            $settled = !empty($sub->completion_id);
            if (!$settled && $sub->ref_type === 'job_listing' && $sub->submitter_type === 'human') {
                $listing_id = (int) $this->db->get_var(
                    "SELECT listing_id FROM {$p}job_listings WHERE public_slug = %s LIMIT 1",
                    [(string) $sub->ref_id]
                );
                if ($listing_id > 0) {
                    $settled = (bool) $this->db->get_var(
                        "SELECT application_id FROM {$p}job_applications
                         WHERE listing_id = %d AND applicant_user_id = %d AND status = 'accepted' LIMIT 1",
                        [$listing_id, (int) $sub->submitter_ref]
                    );
                }
            }
            if (!$settled) {
                return new WP_Error('invalid_state', 'Rate after acceptance is settled.', ['status' => 400]);
            }
            if ($this->expired($sub->updated_at)) {
                return new WP_Error('expired', 'The rating window has closed.', ['status' => 400]);
            }
            $hirer_user_id = 0;
            if ($sub->ref_type === 'job_listing') {
                $hirer_user_id = (int) $this->db->get_var(
                    "SELECT poster_user_id FROM {$p}job_listings WHERE public_slug = %s LIMIT 1",
                    [(string) $sub->ref_id]
                );
            } else {
                $task = $this->db->get_row(
                    "SELECT creator_wallet FROM {$p}tasks WHERE task_id = %s LIMIT 1",
                    [(string) $sub->ref_id]
                );
                if ($task && !empty($task->creator_wallet)) {
                    $hirer_user_id = (int) $this->db->get_var(
                        "SELECT user_id FROM {$p}users WHERE LOWER(wallet_address) = LOWER(%s) LIMIT 1",
                        [(string) $task->creator_wallet]
                    );
                }
            }
            if ($hirer_user_id < 1) {
                return new WP_Error('not_found', 'Hirer not found.', ['status' => 404]);
            }
            return [
                'kind'        => 'open_work_submission',
                'ref_id'      => (string) $sub->submission_id,
                'buyer_type'  => 'human',
                'buyer_ref'   => (string) $hirer_user_id,
                'seller_type' => (string) $sub->submitter_type,
                'seller_ref'  => (string) $sub->submitter_ref,
                'service_id'  => 0,
            ];
        }
        // growl_completions has created_at / last_verified — no updated_at column.
        $comp = $this->db->get_row(
            "SELECT completion_id, user_id, worker_type, worker_agent_id, task_id, status, created_at, last_verified
             FROM {$p}completions WHERE completion_id = %d LIMIT 1",
            [(int) $ref_id]
        );
        if (!$comp) {
            return new WP_Error('not_found', 'Completed work not found.', ['status' => 404]);
        }
        // Creators may rate as soon as work is approved/locked — not only after unlock/payout.
        if (!in_array((string) $comp->status, ['locked', 'unlocked', 'withdrawn', 'completed'], true)) {
            return new WP_Error('invalid_state', 'Rate after the submission is approved.', ['status' => 400]);
        }
        $anchor = !empty($comp->last_verified) ? $comp->last_verified : $comp->created_at;
        if ($this->expired($anchor)) {
            return new WP_Error('expired', 'The rating window has closed.', ['status' => 400]);
        }
        $task = $this->db->get_row(
            "SELECT creator_wallet FROM {$p}tasks WHERE task_id = %s LIMIT 1",
            [(string) $comp->task_id]
        );
        $poster = '0';
        $buyer_refs = [];
        if ($task) {
            $cw = trim((string) ($task->creator_wallet ?? ''));
            if ($cw !== '') {
                $wallet_uid = (int) $this->db->get_var(
                    "SELECT user_id FROM {$p}users WHERE LOWER(wallet_address) = LOWER(%s) LIMIT 1",
                    [$cw]
                );
                if ($wallet_uid > 0) {
                    $buyer_refs[] = (string) $wallet_uid;
                    $poster = (string) $wallet_uid;
                }
            }
        }
        if ($poster !== '' && $poster !== '0') {
            $buyer_refs[] = $poster;
        }
        $buyer_refs = array_values(array_unique(array_filter($buyer_refs, static function ($id) {
            return $id !== '' && $id !== '0';
        })));
        if ($poster === '' || $poster === '0') {
            $poster = $buyer_refs[0] ?? '';
        }
        if ($poster === '' || $poster === '0') {
            return new WP_Error('not_found', 'Task creator not found.', ['status' => 404]);
        }
        $worker_type = (isset($comp->worker_type) && (string) $comp->worker_type === 'agent') ? 'agent' : 'human';
        $seller_ref = '';
        if ($worker_type === 'agent') {
            $seller_ref = trim((string) ($comp->worker_agent_id ?? ''));
            if ($seller_ref === '') {
                return new WP_Error('not_found', 'Agent worker not found for this completion.', ['status' => 404]);
            }
        } else {
            $seller_ref = (string) (int) ($comp->user_id ?? 0);
            if ((int) $seller_ref < 1) {
                return new WP_Error('not_found', 'Worker profile not found for this completion.', ['status' => 404]);
            }
        }
        return [
            'kind'        => 'task_completion',
            'ref_id'      => (string) $comp->completion_id,
            'buyer_type'  => 'human',
            'buyer_ref'   => $poster,
            'buyer_refs'  => $buyer_refs,
            'seller_type' => $worker_type,
            'seller_ref'  => $seller_ref,
            'service_id'  => 0,
        ];
    }

    private function rater_side(array $ctx, $rater_type, $rater_ref) {
        $rater_ref = (string) $rater_ref;
        $buyer_refs = [];
        if (!empty($ctx['buyer_refs']) && is_array($ctx['buyer_refs'])) {
            foreach ($ctx['buyer_refs'] as $br) {
                $buyer_refs[] = (string) $br;
            }
        }
        if (!empty($ctx['buyer_ref'])) {
            $buyer_refs[] = (string) $ctx['buyer_ref'];
        }
        $buyer_refs = array_values(array_unique($buyer_refs));
        if ($rater_type === ($ctx['buyer_type'] ?? '') && in_array($rater_ref, $buyer_refs, true)) {
            return [
                'ratee_type' => $ctx['seller_type'],
                'ratee_ref'  => $ctx['seller_ref'],
                'service_id' => $ctx['service_id'],
            ];
        }
        if ($rater_type === ($ctx['seller_type'] ?? '') && $rater_ref === (string) ($ctx['seller_ref'] ?? '')) {
            return [
                'ratee_type' => $ctx['buyer_type'],
                'ratee_ref'  => $ctx['buyer_ref'],
                'service_id' => 0,
            ];
        }
        return new WP_Error('forbidden', 'Not authorized to rate this work.', ['status' => 403]);
    }

    private function expired($updated_at) {
        $ts = strtotime((string) $updated_at . ' UTC');
        return $ts > 0 && (time() - $ts) > self::WINDOW_DAYS * DAY_IN_SECONDS;
    }

    private function recompute_aggregates($ratee_type, $ratee_ref, $service_id) {
        $p = $this->db->table_prefix;
        $agg = $this->db->get_row(
            "SELECT AVG(stars) AS avg_stars, COUNT(*) AS cnt
             FROM {$p}work_reviews WHERE ratee_type = %s AND ratee_ref = %s AND status = 'public'",
            [$ratee_type, (string) $ratee_ref]
        );
        $avg = $agg ? round((float) $agg->avg_stars, 2) : 0;
        $cnt = $agg ? (int) $agg->cnt : 0;
        if ($ratee_type === 'human') {
            $this->db->wpdb->query($this->db->wpdb->prepare(
                "UPDATE {$p}users SET rating_avg = %s, rating_count = %d WHERE user_id = %d",
                $avg,
                $cnt,
                (int) $ratee_ref
            ));
        } else {
            $this->db->wpdb->query($this->db->wpdb->prepare(
                "UPDATE {$p}ai_agents SET rating_avg = %s, rating_count = %d WHERE agent_id = %s",
                $avg,
                $cnt,
                (string) $ratee_ref
            ));
        }
        if ((int) $service_id > 0) {
            $sagg = $this->db->get_row(
                "SELECT AVG(stars) AS avg_stars, COUNT(*) AS cnt
                 FROM {$p}work_reviews WHERE service_id = %d AND status = 'public'",
                [(int) $service_id]
            );
            $this->db->wpdb->query($this->db->wpdb->prepare(
                "UPDATE {$p}agent_services SET rating_avg = %s, rating_count = %d WHERE service_id = %d",
                $sagg ? round((float) $sagg->avg_stars, 2) : 0,
                $sagg ? (int) $sagg->cnt : 0,
                (int) $service_id
            ));
        }
    }

    private function format($row, $public) {
        $out = [
            'review_id'  => (int) $row->review_id,
            'ref_type'   => (string) $row->ref_type,
            'stars'      => (int) $row->stars,
            'comment'    => (string) ($row->comment ?? ''),
            'created_at' => (string) $row->created_at,
        ];
        if (!$public) {
            $out['private_note'] = (string) ($row->private_note ?? '');
            $out['edit_count']   = (int) ($row->edit_count ?? 0);
            $out['can_edit']     = (int) ($row->edit_count ?? 0) < 1
                && (time() - strtotime((string) $row->created_at . ' UTC')) <= self::EDIT_HOURS * 3600;
        }
        return $out;
    }
}
