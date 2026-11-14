<?php
/**
 * Open Work Modes gallery: brief, sprint, pitch, prove, bid.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Open_Work_Service {

    /** @var GROWL_Database */
    private $db;

    /** @var string[] */
    private static $modes = ['brief', 'sprint', 'pitch', 'prove', 'bid'];

    public function __construct(?GROWL_Database $db = null) {
        $this->db = $db ?: new GROWL_Database();
    }

    /**
     * @return string[]
     */
    public static function modes() {
        return self::$modes;
    }

    /**
     * Unified gallery: tasks with work_mode + pitch job listings.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function list_gallery($work_mode = '', $execution_mode = '', $q = '', $page = 1, $per_page = 20) {
        $page     = max(1, (int) $page);
        $per_page = min(50, max(1, (int) $per_page));
        $offset   = ($page - 1) * $per_page;
        $p        = $this->db->table_prefix;

        $work_mode = sanitize_key((string) $work_mode);
        if ($work_mode !== '' && !in_array($work_mode, self::$modes, true)) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page];
        }

        $execution_mode = sanitize_key((string) $execution_mode);
        $include_pitch_listings = ($work_mode === '' || $work_mode === 'pitch');

        $task_where  = "t.work_mode IS NOT NULL AND t.status = 'active' AND t.completed_count < t.user_count";
        $task_params = [];
        if ($work_mode !== '') {
            $task_where   .= ' AND t.work_mode = %s';
            $task_params[] = $work_mode;
        }
        if (in_array($execution_mode, ['human', 'agent', 'hybrid'], true)) {
            $task_where   .= ' AND t.execution_mode = %s';
            $task_params[] = $execution_mode;
        }
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%' . $this->db->wpdb->esc_like($q) . '%';
            $task_where   .= ' AND (t.title LIKE %s OR t.task_id LIKE %s)';
            $task_params[] = $like;
            $task_params[] = $like;
        }

        $task_sql = "SELECT 'task' AS item_type, t.work_mode AS mode, COALESCE(NULLIF(t.title,''), t.action_type) AS title,
                            t.base_reward AS reward_raw, 'decimal' AS reward_kind, t.execution_mode,
                            t.task_id AS ref_id, t.created_at,
                            (SELECT COUNT(*) FROM {$p}open_work_submissions s
                             WHERE s.ref_type = 'task' AND s.ref_id = t.task_id) AS submission_count,
                            0 AS applicant_count
                     FROM {$p}tasks t
                     WHERE {$task_where}";

        $parts  = [$task_sql];
        $params = $task_params;

        if ($include_pitch_listings) {
            $list_where  = "l.status = 'open'
                            AND (SELECT COUNT(*) FROM {$p}job_applications a
                                 WHERE a.listing_id = l.listing_id AND a.status = 'accepted') < GREATEST(1, l.slots)";
            $list_params = [];
            if (in_array($execution_mode, ['human', 'agent', 'hybrid'], true)) {
                $list_where   .= ' AND l.execution_mode = %s';
                $list_params[] = $execution_mode;
            }
            if ($q !== '') {
                $like = '%' . $this->db->wpdb->esc_like($q) . '%';
                $list_where   .= ' AND (l.title LIKE %s OR l.scope_text LIKE %s)';
                $list_params[] = $like;
                $list_params[] = $like;
            }
            if ($work_mode === 'pitch') {
                // pitch filter: only listings (tasks already filtered above).
            } elseif ($work_mode === '') {
                // all modes: include open pitch listings alongside every task mode.
            }
            $list_sql = "SELECT 'pitch' AS item_type, 'pitch' AS mode, l.title,
                                l.budget_micro AS reward_raw, 'micro' AS reward_kind,
                                l.execution_mode, l.public_slug AS ref_id, l.created_at,
                                (SELECT COUNT(*) FROM {$p}open_work_submissions s
                                 WHERE s.ref_type = 'job_listing' AND s.ref_id = l.public_slug) AS submission_count,
                                (SELECT COUNT(*) FROM {$p}job_applications a
                                 WHERE a.listing_id = l.listing_id) AS applicant_count
                         FROM {$p}job_listings l
                         WHERE {$list_where}";
            $parts[] = $list_sql;
            $params  = array_merge($params, $list_params);
        }

        $union = implode(' UNION ALL ', $parts);
        $count_sql = "SELECT COUNT(*) FROM ({$union}) AS gallery";
        $total     = (int) $this->db->get_var($count_sql, $params);

        $list_params = array_merge($params, [$per_page, $offset]);
        $rows = $this->db->get_results(
            "SELECT * FROM ({$union}) AS gallery ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $list_params
        );

        $items = [];
        foreach ($rows ?: [] as $row) {
            $items[] = $this->format_gallery_item($row);
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    private function format_gallery_item($row) {
        $kind = (string) ($row->reward_kind ?? 'decimal');
        $raw  = (string) ($row->reward_raw ?? '0');
        if ($kind === 'micro') {
            $reward_display = Growl_Money::to_display($raw);
        } else {
            $reward_display = Growl_Money::to_display(Growl_Money::to_micro($raw));
        }
        return [
            'type'              => (string) ($row->item_type ?? 'task'),
            'mode'              => (string) ($row->mode ?? ''),
            'title'             => (string) ($row->title ?? ''),
            'reward_display'    => $reward_display,
            'execution_mode'    => (string) ($row->execution_mode ?? 'hybrid'),
            'ref_id'            => (string) ($row->ref_id ?? ''),
            'submission_count'  => (int) ($row->submission_count ?? 0),
            'applicant_count'   => (int) ($row->applicant_count ?? 0),
            'created_at'        => (string) ($row->created_at ?? ''),
        ];
    }

    /**
     * Detail by task_id or job listing public_slug.
     *
     * @return array<string,mixed>|null
     */
    public function get_detail($ref, $viewer_user_id = 0) {
        $ref = sanitize_text_field((string) $ref);
        if ($ref === '') {
            return null;
        }
        $p = $this->db->table_prefix;

        $task = $this->db->get_row(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM {$p}open_work_submissions s
                     WHERE s.ref_type = 'task' AND s.ref_id = t.task_id) AS submission_count
             FROM {$p}tasks t
             WHERE t.task_id = %s AND t.work_mode IS NOT NULL
             LIMIT 1",
            [$ref]
        );
        if ($task) {
            return $this->format_task_detail($task, (int) $viewer_user_id);
        }

        $listing = $this->db->get_row(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM {$p}open_work_submissions s
                     WHERE s.ref_type = 'job_listing' AND s.ref_id = l.public_slug) AS submission_count,
                    (SELECT COUNT(*) FROM {$p}job_applications a WHERE a.listing_id = l.listing_id) AS applicant_count
             FROM {$p}job_listings l
             WHERE l.public_slug = %s
             LIMIT 1",
            [$ref]
        );
        if ($listing) {
            return $this->format_listing_detail($listing, (int) $viewer_user_id);
        }

        return null;
    }

    /**
     * @param object $task
     * @return array<string,mixed>
     */
    private function format_task_detail($task, $viewer_user_id) {
        $p = $this->db->table_prefix;
        $hirer_user_id = $this->hirer_user_id_for_task($task);
        $detail = [
            'ref_type'         => 'task',
            'ref_id'           => (string) $task->task_id,
            'type'             => 'task',
            'mode'             => (string) $task->work_mode,
            'title'            => (string) ($task->title ?: $task->action_type),
            'status'           => (string) $task->status,
            'execution_mode'   => (string) ($task->execution_mode ?? 'hybrid'),
            'reward_display'   => Growl_Money::to_display(Growl_Money::to_micro($task->base_reward)),
            'reward_micro'     => Growl_Money::to_micro($task->base_reward),
            'slots'            => (int) $task->user_count,
            'slots_filled'     => (int) $task->completed_count,
            'slots_left'       => max(0, (int) $task->user_count - (int) $task->completed_count),
            'submission_count' => (int) ($task->submission_count ?? 0),
            'applicant_count'  => 0,
            'requirements'     => $task->requirements ? (string) $task->requirements : null,
            'custom_instructions' => $task->custom_instructions ? (string) $task->custom_instructions : null,
            'created_at'       => (string) ($task->created_at ?? ''),
            'hirer_user_id'    => $hirer_user_id,
            'viewer_role'      => $this->viewer_role_for_ref('task', (string) $task->task_id, $hirer_user_id, $viewer_user_id, ''),
        ];
        if ($viewer_user_id > 0) {
            $mine = $this->db->get_row(
                "SELECT submission_id, status, created_at FROM {$p}open_work_submissions
                 WHERE ref_type = 'task' AND ref_id = %s AND submitter_type = 'human' AND submitter_ref = %s
                 ORDER BY submission_id DESC LIMIT 1",
                [(string) $task->task_id, (string) $viewer_user_id]
            );
            if ($mine) {
                $detail['my_submission'] = [
                    'submission_id' => (int) $mine->submission_id,
                    'status'        => (string) $mine->status,
                    'created_at'    => (string) $mine->created_at,
                ];
            }
        }
        return $detail;
    }

    /**
     * @param object $listing
     * @return array<string,mixed>
     */
    private function format_listing_detail($listing, $viewer_user_id) {
        $positions = isset($listing->slots) ? max(1, (int) $listing->slots) : 1;
        $hired = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->db->table_prefix}job_applications
             WHERE listing_id = %d AND status = 'accepted'",
            [(int) $listing->listing_id]
        );
        $detail = [
            'ref_type'         => 'job_listing',
            'ref_id'           => (string) $listing->public_slug,
            'type'             => 'pitch',
            'mode'             => 'pitch',
            'title'            => (string) $listing->title,
            'status'           => (string) $listing->status,
            'execution_mode'   => (string) ($listing->execution_mode ?? 'hybrid'),
            'reward_display'   => Growl_Money::to_display((string) $listing->budget_micro),
            'reward_micro'     => (string) $listing->budget_micro,
            'scope_text'       => (string) ($listing->scope_text ?? ''),
            'slots'            => $positions,
            'slots_filled'     => $hired,
            'slots_left'       => max(0, $positions - $hired),
            'submission_count' => (int) ($listing->submission_count ?? 0),
            'applicant_count'  => (int) ($listing->applicant_count ?? 0),
            'deadline_at'      => !empty($listing->deadline_at) ? (string) $listing->deadline_at : null,
            'created_at'       => (string) ($listing->created_at ?? ''),
            'hirer_user_id'    => (int) $listing->poster_user_id,
            'viewer_role'      => $this->viewer_role_for_ref(
                'job_listing',
                (string) $listing->public_slug,
                (int) $listing->poster_user_id,
                $viewer_user_id,
                ''
            ),
        ];
        if ($viewer_user_id > 0) {
            $p = $this->db->table_prefix;
            $mine = $this->db->get_row(
                "SELECT submission_id, status, created_at FROM {$p}open_work_submissions
                 WHERE ref_type = 'job_listing' AND ref_id = %s AND submitter_type = 'human' AND submitter_ref = %s
                 ORDER BY submission_id DESC LIMIT 1",
                [(string) $listing->public_slug, (string) $viewer_user_id]
            );
            if ($mine) {
                $detail['my_submission'] = [
                    'submission_id' => (int) $mine->submission_id,
                    'status'        => (string) $mine->status,
                    'created_at'    => (string) $mine->created_at,
                ];
            }
        }
        return $detail;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function submit($ref_type, $ref_id, $submitter_type, $submitter_ref, array $payload, $bid_micro = null) {
        $ref_type = sanitize_key((string) $ref_type);
        $ref_id   = sanitize_text_field((string) $ref_id);
        $submitter_type = $submitter_type === 'agent' ? 'agent' : 'human';
        $submitter_ref  = sanitize_text_field((string) $submitter_ref);

        if (!in_array($ref_type, ['task', 'job_listing'], true) || $ref_id === '' || $submitter_ref === '') {
            return new WP_Error('invalid', 'Invalid submission target.', ['status' => 400]);
        }

        $work = $this->resolve_work($ref_type, $ref_id);
        if (is_wp_error($work)) {
            return $work;
        }

        $mode = (string) $work['mode'];
        if ($mode === 'bid') {
            $bid_micro = Growl_Money::assert_micro($bid_micro ?? ($payload['bid_micro'] ?? '0'));
            if (!Growl_Money::is_positive($bid_micro)) {
                return new WP_Error('bid_required', 'Bid amount is required for bid mode.', ['status' => 400]);
            }
        } else {
            $bid_micro = null;
        }

        if (!$work['open']) {
            return new WP_Error('closed', 'This work is no longer open.', ['status' => 400]);
        }

        if ($this->is_hirer($work, $submitter_type, $submitter_ref)) {
            return new WP_Error('invalid', 'You cannot submit to your own work.', ['status' => 400]);
        }

        $p = $this->db->table_prefix;
        $dup = $this->db->get_var(
            "SELECT submission_id FROM {$p}open_work_submissions
             WHERE ref_type = %s AND ref_id = %s AND submitter_type = %s AND submitter_ref = %s
               AND status IN ('pending','accepted')
             LIMIT 1",
            [$ref_type, $ref_id, $submitter_type, $submitter_ref]
        );
        if ($dup) {
            return new WP_Error('duplicate', 'You already have an active submission.', ['status' => 400]);
        }

        $now = gmdate('Y-m-d H:i:s');
        $ok  = $this->db->insert('open_work_submissions', [
            'ref_type'       => $ref_type,
            'ref_id'         => $ref_id,
            'submitter_type' => $submitter_type,
            'submitter_ref'  => $submitter_ref,
            'work_mode'      => $mode,
            'payload_json'   => wp_json_encode($payload),
            'bid_micro'      => $bid_micro,
            'status'         => 'pending',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        if (!$ok) {
            return new WP_Error('submit_failed', 'Could not save submission.', ['status' => 500]);
        }

        $submission_id = (int) $this->db->wpdb->insert_id;
        $hirer_user_id = (int) ($work['hirer_user_id'] ?? 0);
        if ($hirer_user_id > 0 && class_exists('Growl_Work_Notify')) {
            Growl_Work_Notify::human(
                $hirer_user_id,
                'New open-work submission',
                'Someone submitted to “' . ($work['title'] ?? 'your work') . '”.',
                'work/open/' . rawurlencode($ref_id),
                'info'
            );
        }

        return $this->format_submission($this->get_submission_row($submission_id));
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}|WP_Error
     */
    public function list_submissions($ref_type, $ref_id, $viewer_user_id, $page = 1, $per_page = 20) {
        $ref_type = sanitize_key((string) $ref_type);
        $ref_id   = sanitize_text_field((string) $ref_id);
        $viewer_user_id = (int) $viewer_user_id;

        $work = $this->resolve_work($ref_type, $ref_id);
        if (is_wp_error($work)) {
            return $work;
        }
        if ((int) ($work['hirer_user_id'] ?? 0) !== $viewer_user_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }

        $page     = max(1, (int) $page);
        $per_page = min(50, max(1, (int) $per_page));
        $offset   = ($page - 1) * $per_page;
        $p        = $this->db->table_prefix;

        $total = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$p}open_work_submissions WHERE ref_type = %s AND ref_id = %s",
            [$ref_type, $ref_id]
        );
        $rows = $this->db->get_results(
            "SELECT * FROM {$p}open_work_submissions
             WHERE ref_type = %s AND ref_id = %s
             ORDER BY submission_id DESC
             LIMIT %d OFFSET %d",
            [$ref_type, $ref_id, $per_page, $offset]
        );

        $items = $this->hydrate_submissions($rows ?: []);

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function accept_submission($submission_id, $hirer_user_id) {
        $submission_id = (int) $submission_id;
        $hirer_user_id = (int) $hirer_user_id;
        $p = $this->db->table_prefix;

        $sub  = null;
        $work = null;
        $settlement = null;
        $this->db->begin_transaction();
        try {
            $sub = $this->db->get_row_for_update(
                "SELECT * FROM {$p}open_work_submissions WHERE submission_id = %d LIMIT 1",
                [$submission_id]
            );
            if (!$sub || $sub->status !== 'pending') {
                $this->db->rollback();
                return new WP_Error('invalid', 'Submission not found or not pending.', ['status' => 404]);
            }

            $work = $this->resolve_work((string) $sub->ref_type, (string) $sub->ref_id);
            if (is_wp_error($work)) {
                $this->db->rollback();
                return $work;
            }
            if ((int) ($work['hirer_user_id'] ?? 0) !== $hirer_user_id) {
                $this->db->rollback();
                return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
            }

            $mode = (string) $sub->work_mode;
            if ($mode === 'sprint') {
                $accepted = $this->db->get_var(
                    "SELECT submission_id FROM {$p}open_work_submissions
                     WHERE ref_type = %s AND ref_id = %s AND status = 'accepted' LIMIT 1",
                    [(string) $sub->ref_type, (string) $sub->ref_id]
                );
                if ($accepted) {
                    $this->db->rollback();
                    return new WP_Error('race_lost', 'Another submission was already accepted for this sprint.', ['status' => 409]);
                }
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->db->update('open_work_submissions', [
                'status'     => 'accepted',
                'updated_at' => $now,
            ], ['submission_id' => $submission_id]);

            $this->db->query(
                $this->db->wpdb->prepare(
                    "UPDATE {$p}open_work_submissions SET status = 'rejected', updated_at = %s
                     WHERE ref_type = %s AND ref_id = %s AND submission_id != %d AND status = 'pending'",
                    $now,
                    (string) $sub->ref_type,
                    (string) $sub->ref_id,
                    $submission_id
                )
            );

            $settlement = $this->settle_acceptance($sub, $submission_id, $hirer_user_id);
            if (is_wp_error($settlement)) {
                $this->db->rollback();
                return $settlement;
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('accept_failed', 'Could not accept submission.', ['status' => 500]);
        }

        $row = $this->get_submission_row($submission_id);
        $hydrated = $this->hydrate_submissions($row ? [$row] : []);
        $formatted = $hydrated[0] ?? null;
        if (!$formatted) {
            return new WP_Error('accept_failed', 'Could not load submission.', ['status' => 500]);
        }

        if (class_exists('Growl_Work_Notify')) {
            $this->notify_submitter(
                (string) $sub->submitter_type,
                (string) $sub->submitter_ref,
                'Submission accepted',
                'Your submission to “' . ($work['title'] ?? 'open work') . '” was accepted.',
                'work/open/' . rawurlencode((string) $sub->ref_id),
                'success'
            );
            Growl_Work_Notify::human(
                $hirer_user_id,
                'Rate your worker',
                'Leave a rating for the accepted submission to “' . ($work['title'] ?? 'open work') . '”.',
                'work/open/' . rawurlencode((string) $sub->ref_id),
                'info'
            );
        }

        if (!empty($settlement) && is_array($settlement)) {
            $formatted['settlement'] = $settlement;
        }

        if (!class_exists('Growl_Work_Receipt_Service')) {
            require_once __DIR__ . '/class-growl-work-receipt-service.php';
        }
        (new Growl_Work_Receipt_Service($this->db))->upsert_from_ref(
            'open_work_submission',
            (string) $submission_id
        );
        $formatted = Growl_Work_Receipt_Service::attach_to_payload(
            $formatted,
            'open_work_submission',
            (string) $submission_id,
            'accepted'
        );

        return $formatted;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function reject_submission($submission_id, $hirer_user_id) {
        $submission_id = (int) $submission_id;
        $hirer_user_id = (int) $hirer_user_id;
        $p = $this->db->table_prefix;

        $sub = $this->db->get_row(
            "SELECT * FROM {$p}open_work_submissions WHERE submission_id = %d LIMIT 1",
            [$submission_id]
        );
        if (!$sub || $sub->status !== 'pending') {
            return new WP_Error('invalid', 'Submission not found or not pending.', ['status' => 404]);
        }

        $work = $this->resolve_work((string) $sub->ref_type, (string) $sub->ref_id);
        if (is_wp_error($work)) {
            return $work;
        }
        if ((int) ($work['hirer_user_id'] ?? 0) !== $hirer_user_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }

        $this->db->update('open_work_submissions', [
            'status'     => 'rejected',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['submission_id' => $submission_id]);

        $row = $this->get_submission_row($submission_id);
        $formatted = $this->format_submission($this->hydrate_submissions([$row])[0] ?? $this->format_submission($row));

        if (class_exists('Growl_Work_Notify')) {
            $this->notify_submitter(
                (string) $sub->submitter_type,
                (string) $sub->submitter_ref,
                'Submission declined',
                'Your submission to “' . ($work['title'] ?? 'open work') . '” was declined.',
                'work/open/' . rawurlencode((string) $sub->ref_id),
                'info'
            );
        }

        return $formatted;
    }

    /**
     * Resolve ref from public ref string (task_id or listing slug).
     *
     * @return array{ref_type:string,ref_id:string}|WP_Error
     */
    public function resolve_ref($ref) {
        $ref = sanitize_text_field((string) $ref);
        if ($ref === '') {
            return new WP_Error('invalid', 'Reference required.', ['status' => 400]);
        }
        $p = $this->db->table_prefix;
        $task = $this->db->get_var(
            "SELECT task_id FROM {$p}tasks WHERE task_id = %s AND work_mode IS NOT NULL LIMIT 1",
            [$ref]
        );
        if ($task) {
            return ['ref_type' => 'task', 'ref_id' => (string) $task];
        }
        $slug = $this->db->get_var(
            "SELECT public_slug FROM {$p}job_listings WHERE public_slug = %s LIMIT 1",
            [$ref]
        );
        if ($slug) {
            return ['ref_type' => 'job_listing', 'ref_id' => (string) $slug];
        }
        return new WP_Error('not_found', 'Open work not found.', ['status' => 404]);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function resolve_work($ref_type, $ref_id) {
        if ($ref_type === 'task') {
            $task = $this->db->get_row(
                "SELECT * FROM {$this->db->table_prefix}tasks WHERE task_id = %s AND work_mode IS NOT NULL LIMIT 1",
                [$ref_id]
            );
            if (!$task) {
                return new WP_Error('not_found', 'Task not found.', ['status' => 404]);
            }
            $slots_left = max(0, (int) $task->user_count - (int) $task->completed_count);
            return [
                'ref_type'      => 'task',
                'ref_id'        => (string) $task->task_id,
                'mode'          => (string) $task->work_mode,
                'title'         => (string) ($task->title ?: $task->action_type),
                'open'          => $task->status === 'active' && $slots_left > 0,
                'hirer_user_id' => $this->hirer_user_id_for_task($task),
            ];
        }

        if ($ref_type === 'job_listing') {
            $listing = $this->db->get_row(
                "SELECT * FROM {$this->db->table_prefix}job_listings WHERE public_slug = %s LIMIT 1",
                [$ref_id]
            );
            if (!$listing) {
                return new WP_Error('not_found', 'Listing not found.', ['status' => 404]);
            }
            $positions = isset($listing->slots) ? max(1, (int) $listing->slots) : 1;
            $hired = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->db->table_prefix}job_applications
                 WHERE listing_id = %d AND status = 'accepted'",
                [(int) $listing->listing_id]
            );
            return [
                'ref_type'      => 'job_listing',
                'ref_id'        => (string) $listing->public_slug,
                'mode'          => 'pitch',
                'title'         => (string) $listing->title,
                'open'          => $listing->status === 'open' && $hired < $positions,
                'hirer_user_id' => (int) $listing->poster_user_id,
            ];
        }

        return new WP_Error('invalid', 'Invalid reference type.', ['status' => 400]);
    }

    /**
     * @param object $task
     */
    private function hirer_user_id_for_task($task) {
        $wallet = trim((string) ($task->creator_wallet ?? ''));
        if ($wallet === '') {
            return 0;
        }
        $uid = $this->db->get_var(
            "SELECT user_id FROM {$this->db->table_prefix}users WHERE LOWER(wallet_address) = LOWER(%s) LIMIT 1",
            [$wallet]
        );
        return $uid ? (int) $uid : 0;
    }

    /**
     * @param array<string,mixed> $work
     */
    private function is_hirer($work, $submitter_type, $submitter_ref) {
        if ($submitter_type === 'human' && (int) $submitter_ref === (int) ($work['hirer_user_id'] ?? 0)) {
            return true;
        }
        return false;
    }

    private function viewer_role_for_ref($ref_type, $ref_id, $hirer_user_id, $viewer_user_id, $viewer_agent_id) {
        if ($viewer_user_id > 0 && $viewer_user_id === (int) $hirer_user_id) {
            return 'hirer';
        }
        $p = $this->db->table_prefix;
        if ($viewer_user_id > 0) {
            $mine = $this->db->get_var(
                "SELECT submission_id FROM {$p}open_work_submissions
                 WHERE ref_type = %s AND ref_id = %s AND submitter_type = 'human' AND submitter_ref = %s
                   AND status IN ('pending','accepted')
                 LIMIT 1",
                [$ref_type, $ref_id, (string) $viewer_user_id]
            );
            if ($mine) {
                return 'submitter';
            }
        }
        if ($viewer_agent_id !== '') {
            $mine = $this->db->get_var(
                "SELECT submission_id FROM {$p}open_work_submissions
                 WHERE ref_type = %s AND ref_id = %s AND submitter_type = 'agent' AND submitter_ref = %s
                   AND status IN ('pending','accepted')
                 LIMIT 1",
                [$ref_type, $ref_id, $viewer_agent_id]
            );
            if ($mine) {
                return 'submitter';
            }
        }
        return 'guest';
    }

    /**
     * @param object|null $row
     */
    private function get_submission_row($submission_id) {
        return $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}open_work_submissions WHERE submission_id = %d LIMIT 1",
            [(int) $submission_id]
        );
    }

    /**
     * @param array<int,object> $rows
     * @return array<int,array<string,mixed>>
     */
    private function hydrate_submissions(array $rows) {
        if (!$rows) {
            return [];
        }
        $human_ids = [];
        $agent_ids = [];
        foreach ($rows as $row) {
            if ($row->submitter_type === 'agent') {
                $agent_ids[] = (string) $row->submitter_ref;
            } else {
                $human_ids[] = (int) $row->submitter_ref;
            }
        }
        $human_ids = array_values(array_unique(array_filter($human_ids)));
        $agent_ids = array_values(array_unique(array_filter($agent_ids)));

        $human_names = [];
        if ($human_ids) {
            $p = $this->db->table_prefix;
            $ph  = implode(',', array_fill(0, count($human_ids), '%d'));
            $users = $this->db->get_results(
                "SELECT user_id, display_name, profile_slug, x_username FROM {$p}users WHERE user_id IN ({$ph})",
                $human_ids
            );
            foreach ($users ?: [] as $u) {
                $human_names[(int) $u->user_id] = $this->display_label($u);
            }
        }

        $agent_names = [];
        if ($agent_ids) {
            $p = $this->db->table_prefix;
            $ph  = implode(',', array_fill(0, count($agent_ids), '%s'));
            $agents = $this->db->get_results(
                "SELECT agent_id, agent_name, x_username FROM {$p}ai_agents WHERE agent_id IN ({$ph})",
                $agent_ids
            );
            foreach ($agents ?: [] as $a) {
                $label = trim((string) ($a->agent_name ?? ''));
                if ($label === '' && !empty($a->x_username)) {
                    $label = '@' . ltrim((string) $a->x_username, '@');
                }
                if ($label === '') {
                    $label = (string) $a->agent_id;
                }
                $agent_names[(string) $a->agent_id] = $label;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $item = $this->format_submission($row);
            if ($row->submitter_type === 'agent') {
                $item['submitter_name'] = $agent_names[(string) $row->submitter_ref] ?? (string) $row->submitter_ref;
            } else {
                $item['submitter_name'] = $human_names[(int) $row->submitter_ref] ?? 'EarnFi user';
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    private function format_submission($row) {
        $payload = [];
        if (!empty($row->payload_json)) {
            $decoded = json_decode((string) $row->payload_json, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        return [
            'submission_id'  => (int) $row->submission_id,
            'ref_type'       => (string) $row->ref_type,
            'ref_id'         => (string) $row->ref_id,
            'submitter_type' => (string) $row->submitter_type,
            'submitter_ref'  => (string) $row->submitter_ref,
            'work_mode'      => (string) $row->work_mode,
            'payload'        => $payload,
            'bid_micro'      => $row->bid_micro !== null ? (string) $row->bid_micro : null,
            'bid_display'    => $row->bid_micro !== null ? Growl_Money::to_display((string) $row->bid_micro) : null,
            'score'          => $row->score !== null ? (float) $row->score : null,
            'status'         => (string) $row->status,
            'completion_id'  => !empty($row->completion_id) ? (int) $row->completion_id : null,
            'settlement'     => $this->settlement_for_row($row),
            'hirer_user_id'  => $this->hirer_user_id_for_submission($row),
            'created_at'     => (string) ($row->created_at ?? ''),
            'updated_at'     => (string) ($row->updated_at ?? ''),
        ];
    }

    /**
     * @param object $user
     */
    private function display_label($user) {
        $slug = trim((string) ($user->profile_slug ?? ''));
        if ($slug !== '') {
            return '@' . ltrim($slug, '@');
        }
        $x = trim((string) ($user->x_username ?? ''));
        if ($x !== '') {
            return '@' . ltrim($x, '@');
        }
        $name = trim((string) ($user->display_name ?? ''));
        return $name !== '' ? $name : 'EarnFi user';
    }

    /**
     * @param object $sub
     * @return array<string,mixed>|WP_Error
     */
    private function settle_acceptance($sub, $submission_id, $hirer_user_id) {
        if ((string) $sub->ref_type === 'task') {
            return $this->settle_task_acceptance($sub, $submission_id);
        }
        if ((string) $sub->ref_type === 'job_listing') {
            return $this->settle_pitch_acceptance($sub, $submission_id, $hirer_user_id);
        }
        return new WP_Error('invalid', 'Cannot settle this submission.', ['status' => 400]);
    }

    /**
     * @param object $sub
     * @return array<string,mixed>|WP_Error
     */
    private function settle_task_acceptance($sub, $submission_id) {
        $submission_id = (int) $submission_id;
        $p = $this->db->table_prefix;

        if (!empty($sub->completion_id)) {
            return $this->settlement_snapshot_for_submission($sub, true);
        }

        $task_id = (string) $sub->ref_id;
        $task = $this->db->get_row_for_update(
            "SELECT * FROM {$p}tasks WHERE task_id = %s AND work_mode IS NOT NULL LIMIT 1",
            [$task_id]
        );
        if (!$task) {
            return new WP_Error('not_found', 'Task not found.', ['status' => 404]);
        }
        if ((int) $task->completed_count >= (int) $task->user_count) {
            return new WP_Error('slots_full', 'No slots remaining.', ['status' => 409]);
        }

        $mode = (string) $sub->work_mode;
        if ($mode === 'bid') {
            $reward_micro = Growl_Money::assert_micro($sub->bid_micro ?? '0');
            if (!Growl_Money::is_positive($reward_micro)) {
                return new WP_Error('bid_required', 'Bid amount is required for bid mode.', ['status' => 400]);
            }
            $reward_decimal = (float) Growl_Money::to_usdc_string($reward_micro);
        } else {
            $reward_micro = Growl_Money::to_micro($task->base_reward);
            $reward_decimal = (float) $task->base_reward;
        }

        $budget_after = (float) $task->budget_used + $reward_decimal;
        if ($budget_after > (float) $task->total_budget + 0.000001) {
            return new WP_Error('budget_exceeded', 'Task budget exceeded.', ['status' => 409]);
        }

        $idem_base = 'ows_settle_' . $submission_id;
        $submitter_type = (string) $sub->submitter_type;
        $submitter_ref  = (string) $sub->submitter_ref;
        $now = gmdate('Y-m-d H:i:s');

        $slot_ok = $this->db->query(
            "UPDATE {$p}tasks
             SET completed_count = completed_count + 1,
                 budget_used = budget_used + %f,
                 status = CASE WHEN completed_count + 1 >= user_count THEN 'completed' ELSE status END,
                 updated_at = %s
             WHERE task_id = %s
               AND completed_count < user_count
               AND budget_used + %f <= total_budget",
            [$reward_decimal, $now, $task_id, $reward_decimal]
        );
        if (!$slot_ok) {
            return new WP_Error('settle_failed', 'Could not reserve task slot or budget.', ['status' => 409]);
        }

        $rank = 'basic';
        if ($submitter_type === 'human') {
            $user = $this->db->get_row(
                "SELECT user_rank FROM {$p}users WHERE user_id = %d LIMIT 1",
                [(int) $submitter_ref]
            );
            if ($user && !empty($user->user_rank)) {
                $rank = (string) $user->user_rank;
            }
        }

        $completion_row = [
            'task_id'            => $task_id,
            'user_id'            => $submitter_type === 'human' ? (int) $submitter_ref : 0,
            'reward_amount'      => $reward_decimal,
            'rank_at_completion' => $rank,
            'status'             => 'unlocked',
            'verification_type'  => 'manual',
            'locked_until'       => $now,
            'worker_type'        => $submitter_type,
            'worker_agent_id'    => $submitter_type === 'agent' ? $submitter_ref : null,
            'created_at'         => $now,
        ];
        $ok = $this->db->insert('completions', $completion_row);
        if (!$ok) {
            return new WP_Error('settle_failed', 'Could not create completion.', ['status' => 500]);
        }
        $completion_id = (int) $this->db->wpdb->insert_id;

        if ($submitter_type === 'human') {
            if (!class_exists('Growl_Balance_Service', false)) {
                require_once __DIR__ . '/class-growl-balance-service.php';
            }
            $balance = new Growl_Balance_Service($this->db);
            $pay = $balance->apply_ledger_entry([
                'user_id'               => (int) $submitter_ref,
                'delta_available_micro' => $reward_micro,
                'entry_type'            => 'credit',
                'purpose'               => 'open_work_accept',
                'ref_type'              => 'open_work_submission',
                'ref_id'                => (string) $submission_id,
                'idempotency_key'       => $idem_base . ':human',
            ], false);
            if (is_wp_error($pay)) {
                return $pay;
            }
        } else {
            $exists = $this->db->get_var(
                "SELECT ledger_id FROM {$p}agent_balance_ledger WHERE idempotency_key = %s LIMIT 1",
                [$idem_base . ':agent']
            );
            if (!$exists) {
                $ins = $this->db->insert('agent_balance_ledger', [
                    'agent_id'        => $submitter_ref,
                    'amount_micro'    => $reward_micro,
                    'entry_type'      => 'credit',
                    'purpose'         => 'open_work_accept',
                    'ref_type'        => 'open_work_submission',
                    'ref_id'          => (string) $submission_id,
                    'idempotency_key' => $idem_base . ':agent',
                    'created_at'      => $now,
                ]);
                if (!$ins) {
                    return new WP_Error('settle_failed', 'Could not credit agent balance.', ['status' => 500]);
                }
            }
        }

        $this->db->update('open_work_submissions', [
            'completion_id' => $completion_id,
            'updated_at'    => $now,
        ], ['submission_id' => $submission_id]);

        $sub->completion_id = $completion_id;

        return [
            'type'            => 'task',
            'completion_id'   => $completion_id,
            'reward_micro'    => $reward_micro,
            'reward_display'  => Growl_Money::to_display($reward_micro),
            'already_settled' => false,
        ];
    }

    /**
     * @param object $sub
     * @return array<string,mixed>|WP_Error
     */
    private function settle_pitch_acceptance($sub, $submission_id, $hirer_user_id) {
        if ((string) $sub->ref_type !== 'job_listing') {
            return new WP_Error('invalid', 'Not a pitch listing submission.', ['status' => 400]);
        }
        $p = $this->db->table_prefix;
        $slug = (string) $sub->ref_id;
        $listing = $this->db->get_row(
            "SELECT listing_id, poster_user_id, title FROM {$p}job_listings WHERE public_slug = %s LIMIT 1",
            [$slug]
        );
        if (!$listing) {
            return new WP_Error('not_found', 'Listing not found.', ['status' => 404]);
        }
        if ((int) $listing->poster_user_id !== (int) $hirer_user_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }

        if ((string) $sub->submitter_type === 'agent') {
            return $this->settle_pitch_agent_acceptance($sub, $submission_id, $hirer_user_id, $listing);
        }

        $applicant_user_id = (int) $sub->submitter_ref;
        $app_id = (int) $this->db->get_var(
            "SELECT application_id FROM {$p}job_applications
             WHERE listing_id = %d AND applicant_user_id = %d
             ORDER BY application_id DESC LIMIT 1",
            [(int) $listing->listing_id, $applicant_user_id]
        );

        if (!class_exists('Growl_Job_Board_Service', false)) {
            require_once __DIR__ . '/class-growl-job-board-service.php';
        }
        $board = new Growl_Job_Board_Service($this->db);

        if ($app_id < 1) {
            $payload = [];
            if (!empty($sub->payload_json)) {
                $decoded = json_decode((string) $sub->payload_json, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $pitch = (string) ($payload['pitch'] ?? $payload['note'] ?? '');
            $applied = $board->apply((int) $listing->listing_id, $applicant_user_id, $pitch);
            if (is_wp_error($applied)) {
                return $applied;
            }
            $app_id = (int) ($applied['application_id'] ?? 0);
        }

        if ($app_id < 1) {
            return new WP_Error('settle_failed', 'Could not create application.', ['status' => 500]);
        }

        $app_status = (string) $this->db->get_var(
            "SELECT status FROM {$p}job_applications WHERE application_id = %d LIMIT 1",
            [$app_id]
        );
        if ($app_status === 'accepted') {
            $contract = $this->db->get_row(
                "SELECT contract_id, public_slug FROM {$p}job_contracts
                 WHERE listing_id = %d AND worker_user_id = %d
                 ORDER BY contract_id DESC LIMIT 1",
                [(int) $listing->listing_id, $applicant_user_id]
            );
            return [
                'type'            => 'pitch',
                'application_id'  => $app_id,
                'contract_id'     => $contract ? (int) $contract->contract_id : null,
                'contract_slug'   => $contract ? (string) $contract->public_slug : null,
                'already_settled' => true,
            ];
        }

        $accepted = $board->accept_application((int) $listing->listing_id, $app_id, (int) $hirer_user_id);
        if (is_wp_error($accepted)) {
            return $accepted;
        }
        $contract = is_array($accepted['contract'] ?? null) ? $accepted['contract'] : [];

        return [
            'type'            => 'pitch',
            'application_id'  => $app_id,
            'contract_id'     => isset($contract['contract_id']) ? (int) $contract['contract_id'] : null,
            'contract_slug'   => isset($contract['public_slug']) ? (string) $contract['public_slug'] : null,
            'already_settled' => false,
        ];
    }

    /**
     * Pitch acceptance for agent submitters creates a protected agent deal for funding.
     *
     * @param object $sub
     * @param object $listing
     * @return array<string,mixed>|WP_Error
     */
    private function settle_pitch_agent_acceptance($sub, $submission_id, $hirer_user_id, $listing) {
        $submission_id = (int) $submission_id;
        $agent_id      = sanitize_text_field((string) $sub->submitter_ref);
        if ($agent_id === '') {
            return new WP_Error('invalid', 'Agent submitter is required.', ['status' => 400]);
        }

        $payload = [];
        if (!empty($sub->payload_json)) {
            $decoded = json_decode((string) $sub->payload_json, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        $existing_slug = sanitize_text_field((string) ($payload['agent_deal_slug'] ?? ''));
        if ($existing_slug !== '') {
            return [
                'type'            => 'pitch_agent',
                'deal_slug'       => $existing_slug,
                'already_settled' => true,
            ];
        }

        if (!class_exists('Growl_Agent_Deal_Service', false)) {
            require_once __DIR__ . '/class-growl-agent-deal-service.php';
        }
        $deals = new Growl_Agent_Deal_Service($this->db);
        $created = $deals->create_draft('human', (string) $hirer_user_id, [
            'created_by_role' => 'buyer',
            'seller_type'     => 'agent',
            'seller_ref'      => $agent_id,
            'buyer_type'      => 'human',
            'buyer_ref'       => (string) $hirer_user_id,
            'title'           => (string) $listing->title,
            'scope_text'      => (string) ($listing->scope_text ?? ''),
            'amount_micro'    => (string) $listing->budget_micro,
            'kind'            => 'service',
        ]);
        if (is_wp_error($created)) {
            return $created;
        }

        $deal_slug = sanitize_text_field((string) ($created['public_slug'] ?? ''));
        if ($deal_slug === '') {
            return new WP_Error('settle_failed', 'Could not create agent deal.', ['status' => 500]);
        }

        $payload['agent_deal_slug'] = $deal_slug;
        $this->db->update('open_work_submissions', [
            'payload_json' => wp_json_encode($payload),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], ['submission_id' => $submission_id]);

        return [
            'type'            => 'pitch_agent',
            'deal_slug'       => $deal_slug,
            'deal_id'         => isset($created['deal_id']) ? (int) $created['deal_id'] : null,
            'already_settled' => false,
        ];
    }

    /**
     * @param object $sub
     * @return array<string,mixed>
     */
    private function settlement_snapshot_for_submission($sub, $already = false) {
        if (!empty($sub->completion_id)) {
            $reward_micro = null;
            $p = $this->db->table_prefix;
            $comp = $this->db->get_row(
                "SELECT reward_amount FROM {$p}completions WHERE completion_id = %d LIMIT 1",
                [(int) $sub->completion_id]
            );
            if ($comp) {
                $reward_micro = Growl_Money::to_micro($comp->reward_amount);
            }
            return array_filter([
                'type'            => 'task',
                'completion_id'   => (int) $sub->completion_id,
                'reward_micro'    => $reward_micro,
                'reward_display'  => $reward_micro !== null ? Growl_Money::to_display($reward_micro) : null,
                'already_settled' => $already,
            ], static function ($v) {
                return $v !== null;
            });
        }

        if ((string) $sub->ref_type === 'job_listing' && (string) $sub->submitter_type === 'human') {
            $p = $this->db->table_prefix;
            $listing_id = (int) $this->db->get_var(
                "SELECT listing_id FROM {$p}job_listings WHERE public_slug = %s LIMIT 1",
                [(string) $sub->ref_id]
            );
            if ($listing_id > 0) {
                $app = $this->db->get_row(
                    "SELECT application_id, status FROM {$p}job_applications
                     WHERE listing_id = %d AND applicant_user_id = %d
                     ORDER BY application_id DESC LIMIT 1",
                    [$listing_id, (int) $sub->submitter_ref]
                );
                if ($app && $app->status === 'accepted') {
                    $contract = $this->db->get_row(
                        "SELECT contract_id, public_slug FROM {$p}job_contracts
                         WHERE listing_id = %d AND worker_user_id = %d
                         ORDER BY contract_id DESC LIMIT 1",
                        [$listing_id, (int) $sub->submitter_ref]
                    );
                    return [
                        'type'            => 'pitch',
                        'application_id'  => (int) $app->application_id,
                        'contract_id'     => $contract ? (int) $contract->contract_id : null,
                        'contract_slug'   => $contract ? (string) $contract->public_slug : null,
                        'already_settled' => $already,
                    ];
                }
            }
        }

        if ((string) $sub->ref_type === 'job_listing' && (string) $sub->submitter_type === 'agent') {
            $payload = [];
            if (!empty($sub->payload_json)) {
                $decoded = json_decode((string) $sub->payload_json, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $deal_slug = sanitize_text_field((string) ($payload['agent_deal_slug'] ?? ''));
            if ($deal_slug !== '') {
                return [
                    'type'            => 'pitch_agent',
                    'deal_slug'       => $deal_slug,
                    'already_settled' => $already,
                ];
            }
        }

        return [];
    }

    /**
     * @param object $row
     * @return array<string,mixed>|null
     */
    private function settlement_for_row($row) {
        if ((string) ($row->status ?? '') !== 'accepted') {
            return null;
        }
        $snap = $this->settlement_snapshot_for_submission($row, true);
        return $snap ?: null;
    }

    /**
     * @param object $row
     */
    private function hirer_user_id_for_submission($row) {
        if ((string) $row->ref_type === 'job_listing') {
            $uid = $this->db->get_var(
                "SELECT poster_user_id FROM {$this->db->table_prefix}job_listings WHERE public_slug = %s LIMIT 1",
                [(string) $row->ref_id]
            );
            return $uid ? (int) $uid : 0;
        }
        if ((string) $row->ref_type === 'task') {
            $task = $this->db->get_row(
                "SELECT creator_wallet FROM {$this->db->table_prefix}tasks WHERE task_id = %s LIMIT 1",
                [(string) $row->ref_id]
            );
            return $task ? $this->hirer_user_id_for_task($task) : 0;
        }
        return 0;
    }

    private function notify_submitter($submitter_type, $submitter_ref, $title, $message, $path, $type = 'info') {
        if (!class_exists('Growl_Work_Notify')) {
            return;
        }
        if ($submitter_type === 'human' && (int) $submitter_ref > 0) {
            Growl_Work_Notify::human((int) $submitter_ref, $title, $message, $path, $type);
            return;
        }
        if ($submitter_type === 'agent' && $submitter_ref !== '') {
            $p = $this->db->table_prefix;
            $user = $this->db->get_row(
                "SELECT u.user_id FROM {$p}ai_agents a
                 INNER JOIN {$p}users u ON LOWER(u.wallet_address) = LOWER(a.wallet_address)
                 WHERE a.agent_id = %s LIMIT 1",
                [sanitize_text_field($submitter_ref)]
            );
            if ($user && (int) $user->user_id > 0) {
                Growl_Work_Notify::human((int) $user->user_id, $title, $message, $path, $type);
            }
        }
    }
}
