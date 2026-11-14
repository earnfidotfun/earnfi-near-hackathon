<?php
/**
 * Jobs board: listings, applications, spawn deal on accept.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Job_Board_Service {

    /** @var GROWL_Database */
    private $db;

    /** @var Growl_Deal_Service */
    private $deals;

    public function __construct(?GROWL_Database $db = null) {
        $this->db    = $db ?: new GROWL_Database();
        $this->deals = new Growl_Deal_Service($this->db);
    }

    private function slug() {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        do {
            $s = '';
            for ($i = 0; $i < 12; $i++) {
                $s .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while ($this->db->get_var("SELECT listing_id FROM {$this->db->table_prefix}job_listings WHERE public_slug = %s LIMIT 1", [$s]));
        return $s;
    }

    public function create_listing($user_id, array $data) {
        $title  = sanitize_text_field((string) ($data['title'] ?? ''));
        $scope  = sanitize_textarea_field((string) ($data['scope_text'] ?? ''));
        $amount = Growl_Money::to_micro($data['amount'] ?? $data['budget_micro'] ?? '0');
        if ($title === '' || !Growl_Money::is_positive($amount)) {
            return new WP_Error('invalid_listing', 'Title and budget are required.', ['status' => 400]);
        }
        $category = substr(sanitize_key((string) ($data['category'] ?? '')), 0, 40);
        // Open positions (legacy key: slots). Default 1 — optional on create.
        $slots = (int) ($data['positions'] ?? $data['slots'] ?? 1);
        if ($slots < 1) {
            $slots = 1;
        }
        if ($slots > 50) {
            $slots = 50;
        }
        $deadline = null;
        $raw_deadline = trim((string) ($data['deadline_at'] ?? ''));
        if ($raw_deadline !== '') {
            $ts = strtotime($raw_deadline);
            if ($ts) {
                $deadline = gmdate('Y-m-d H:i:s', $ts);
            }
        }
        require_once __DIR__ . '/class-growl-job-contract-service.php';
        $structure = Growl_Job_Contract_Service::normalize_structure($data['payment_structure'] ?? 'single');
        $milestones_json = null;
        if (!empty($data['milestones']) && is_array($data['milestones'])) {
            $milestones_json = wp_json_encode(array_values($data['milestones']));
        } elseif (!empty($data['milestones_json']) && is_string($data['milestones_json'])) {
            $decoded = json_decode($data['milestones_json'], true);
            if (is_array($decoded)) {
                $milestones_json = wp_json_encode(array_values($decoded));
            }
        }

        $row = [
            'public_slug'       => $this->slug(),
            'poster_user_id'    => (int) $user_id,
            'title'             => mb_substr($title, 0, 200),
            'scope_text'        => $scope,
            'budget_micro'      => $amount,
            'payment_structure' => $structure,
            'status'            => 'open',
            'created_at'        => gmdate('Y-m-d H:i:s'),
            'updated_at'        => gmdate('Y-m-d H:i:s'),
        ];
        if ($category !== '') {
            $row['category'] = $category;
        }
        if ($deadline !== null) {
            $row['deadline_at'] = $deadline;
        }
        $row['slots'] = $slots;
        if ($milestones_json !== null) {
            $row['milestones_json'] = $milestones_json;
        }
        $ok = $this->db->insert('job_listings', $row);
        if (!$ok) {
            unset($row['category'], $row['deadline_at'], $row['slots'], $row['payment_structure'], $row['milestones_json']);
            $ok = $this->db->insert('job_listings', $row);
        }
        if (!$ok) {
            return new WP_Error('create_failed', 'Could not create listing.', ['status' => 500]);
        }
        return $this->get_listing((int) $this->db->wpdb->insert_id);
    }

    /**
     * Update a creator-owned hire listing with lifecycle-aware field locks.
     *
     * Commercial terms are editable only before the first application. Once
     * candidates have applied, copy/deadline may change and positions may only
     * increase. expected_updated_at prevents stale browser/agent overwrites.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function update_listing($listing_id, $poster_user_id, array $data) {
        $listing_id = (int) $listing_id;
        $poster_user_id = (int) $poster_user_id;
        $expected = trim((string) ($data['expected_updated_at'] ?? ''));
        if ($expected === '') {
            return new WP_Error('version_required', 'Refresh the listing before saving changes.', ['status' => 428]);
        }

        $p = $this->db->table_prefix;
        $this->db->begin_transaction();
        try {
            $row = $this->db->get_row_for_update(
                "SELECT * FROM {$p}job_listings WHERE listing_id = %d LIMIT 1",
                [$listing_id]
            );
            if (!$row) {
                $this->db->rollback();
                return new WP_Error('not_found', 'Listing not found.', ['status' => 404]);
            }
            if ((int) $row->poster_user_id !== $poster_user_id) {
                $this->db->rollback();
                return new WP_Error('forbidden', 'You are not the creator of this listing.', ['status' => 403]);
            }
            if ((string) $row->updated_at !== $expected) {
                $this->db->rollback();
                return new WP_Error('listing_conflict', 'This listing changed in another session. Refresh and review the latest version.', [
                    'status' => 409,
                    'current_updated_at' => (string) $row->updated_at,
                ]);
            }
            if ((string) $row->status === 'closed') {
                $this->db->rollback();
                return new WP_Error('listing_closed', 'Closed listings cannot be edited.', ['status' => 400]);
            }

            $application_count = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$p}job_applications WHERE listing_id = %d",
                [$listing_id]
            );
            $hired_count = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$p}job_applications WHERE listing_id = %d AND status = 'accepted'",
                [$listing_id]
            );
            $commercial_locked = $application_count > 0 || $hired_count > 0;
            $changes = [];

            foreach (['title', 'scope_text', 'category'] as $field) {
                if (!array_key_exists($field, $data)) {
                    continue;
                }
                if ($field === 'title') {
                    $value = mb_substr(sanitize_text_field((string) $data[$field]), 0, 200);
                    if ($value === '') {
                        throw new Exception('Title cannot be empty');
                    }
                } elseif ($field === 'scope_text') {
                    $value = mb_substr(sanitize_textarea_field((string) $data[$field]), 0, 20000);
                    if ($value === '') {
                        throw new Exception('Scope cannot be empty');
                    }
                } else {
                    $value = mb_substr(sanitize_key((string) $data[$field]), 0, 40);
                }
                $changes[$field] = $value;
            }

            if (array_key_exists('deadline_at', $data)) {
                $raw = trim((string) $data['deadline_at']);
                if ($raw === '') {
                    $changes['deadline_at'] = null;
                } else {
                    $ts = strtotime($raw);
                    if (!$ts || $ts <= time()) {
                        throw new Exception('Deadline must be a valid future date');
                    }
                    $changes['deadline_at'] = gmdate('Y-m-d H:i:s', $ts);
                }
            }

            if (array_key_exists('positions', $data) || array_key_exists('slots', $data)) {
                $slots = (int) ($data['positions'] ?? $data['slots']);
                if ($slots < 1 || $slots > 50) {
                    throw new Exception('Positions must be between 1 and 50');
                }
                if ($commercial_locked && $slots < (int) $row->slots) {
                    throw new Exception('Positions can only be increased after applications have been received');
                }
                if ($slots < $hired_count) {
                    throw new Exception('Positions cannot be lower than the number already hired');
                }
                $changes['slots'] = $slots;
                if ($slots > $hired_count && (string) $row->status === 'accepted') {
                    $changes['status'] = 'open';
                }
            }

            $commercial_fields = ['amount', 'budget_micro', 'payment_structure', 'milestones', 'milestones_json', 'execution_mode'];
            foreach ($commercial_fields as $field) {
                if ($commercial_locked && array_key_exists($field, $data)) {
                    throw new Exception('Budget, payment structure, milestones, and execution mode lock after the first application');
                }
            }

            if (!$commercial_locked) {
                if (array_key_exists('amount', $data) || array_key_exists('budget_micro', $data)) {
                    $budget = Growl_Money::to_micro($data['amount'] ?? $data['budget_micro']);
                    if (!Growl_Money::is_positive($budget)) {
                        throw new Exception('Budget must be greater than zero');
                    }
                    $changes['budget_micro'] = $budget;
                }
                if (array_key_exists('payment_structure', $data)) {
                    require_once __DIR__ . '/class-growl-job-contract-service.php';
                    $changes['payment_structure'] = Growl_Job_Contract_Service::normalize_structure($data['payment_structure']);
                    if (in_array($changes['payment_structure'], ['single', 'split'], true) && !array_key_exists('milestones', $data) && !array_key_exists('milestones_json', $data)) {
                        $changes['milestones_json'] = null;
                    }
                }
                $resulting_structure = (string) ($changes['payment_structure'] ?? $row->payment_structure ?? 'single');
                if (
                    $resulting_structure === 'milestone'
                    && (array_key_exists('amount', $data) || array_key_exists('budget_micro', $data) || array_key_exists('payment_structure', $data))
                    && !array_key_exists('milestones', $data)
                    && !array_key_exists('milestones_json', $data)
                ) {
                    throw new Exception('Include milestones whenever changing a milestone-based budget or payment structure');
                }
                if (array_key_exists('milestones', $data) || array_key_exists('milestones_json', $data)) {
                    $milestones = $data['milestones'] ?? $data['milestones_json'];
                    if (is_string($milestones)) {
                        $milestones = json_decode($milestones, true);
                    }
                    if (!is_array($milestones)) {
                        throw new Exception('Milestones must be a list');
                    }
                    $structure = (string) ($changes['payment_structure'] ?? $row->payment_structure ?? 'single');
                    $budget = (string) ($changes['budget_micro'] ?? $row->budget_micro);
                    $clean_milestones = [];
                    $sum = '0';
                    foreach (array_values($milestones) as $index => $milestone) {
                        if (!is_array($milestone)) {
                            throw new Exception('Each milestone must include a title and amount');
                        }
                        $title = mb_substr(sanitize_text_field((string) ($milestone['title'] ?? '')), 0, 120);
                        $amount = Growl_Money::resolve_amount_micro($milestone);
                        if (!Growl_Money::is_positive($amount)) {
                            throw new Exception('Each milestone needs a positive amount');
                        }
                        $sum = Growl_Money::add($sum, $amount);
                        $clean_milestones[] = [
                            'title' => $title !== '' ? $title : 'Milestone ' . ($index + 1),
                            'amount_micro' => $amount,
                        ];
                    }
                    if ($structure === 'milestone') {
                        if (empty($clean_milestones) || Growl_Money::cmp($sum, $budget) !== 0) {
                            throw new Exception('Milestone amounts must add up to the listing budget');
                        }
                    } elseif ($structure === 'initial_then_rest') {
                        if (empty($clean_milestones) || Growl_Money::cmp($clean_milestones[0]['amount_micro'], $budget) >= 0) {
                            throw new Exception('The initial payment must be positive and leave a remaining balance');
                        }
                    }
                    $changes['milestones_json'] = wp_json_encode($clean_milestones);
                }
                if (array_key_exists('execution_mode', $data)) {
                    $mode = sanitize_key((string) $data['execution_mode']);
                    if (!in_array($mode, ['human', 'agent', 'hybrid'], true)) {
                        throw new Exception('Execution mode must be human, agent, or hybrid');
                    }
                    $changes['execution_mode'] = $mode;
                }
            }

            if (empty($changes)) {
                throw new Exception('No editable fields were provided');
            }
            $next_updated_at = gmdate('Y-m-d H:i:s');
            if (strtotime($next_updated_at . ' UTC') <= strtotime((string) $row->updated_at . ' UTC')) {
                $next_updated_at = gmdate('Y-m-d H:i:s', strtotime((string) $row->updated_at . ' UTC') + 1);
            }
            $changes['updated_at'] = $next_updated_at;

            $updated = $this->db->update('job_listings', $changes, [
                'listing_id' => $listing_id,
                'poster_user_id' => $poster_user_id,
                'updated_at' => $expected,
            ]);
            if ($updated === false || $updated === 0) {
                $this->db->rollback();
                return new WP_Error('listing_conflict', 'This listing changed before your update was saved. Refresh and try again.', ['status' => 409]);
            }
            $this->db->commit();
            return $this->get_listing($listing_id, $poster_user_id);
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('invalid_listing_update', $e->getMessage(), ['status' => 400]);
        }
    }

    public function get_listing($listing_id, $viewer_user_id = 0) {
        $row = $this->db->get_row(
            "SELECT l.*, d.public_slug AS spawned_deal_slug, c.public_slug AS spawned_contract_slug,
                    u.display_name AS poster_name, u.profile_slug AS poster_handle, u.x_username AS poster_x
             FROM {$this->db->table_prefix}job_listings l
             LEFT JOIN {$this->db->table_prefix}deals d ON d.deal_id = l.spawned_deal_id
             LEFT JOIN {$this->db->table_prefix}job_contracts c ON c.contract_id = l.spawned_contract_id
             LEFT JOIN {$this->db->table_prefix}users u ON u.user_id = l.poster_user_id
             WHERE l.listing_id = %d LIMIT 1",
            [(int) $listing_id]
        );
        return $row ? $this->format($row, (int) $viewer_user_id, true) : null;
    }

    public function get_by_slug($slug, $viewer_user_id = 0) {
        $row = $this->db->get_row(
            "SELECT l.*, d.public_slug AS spawned_deal_slug, c.public_slug AS spawned_contract_slug,
                    u.display_name AS poster_name, u.profile_slug AS poster_handle, u.x_username AS poster_x
             FROM {$this->db->table_prefix}job_listings l
             LEFT JOIN {$this->db->table_prefix}deals d ON d.deal_id = l.spawned_deal_id
             LEFT JOIN {$this->db->table_prefix}job_contracts c ON c.contract_id = l.spawned_contract_id
             LEFT JOIN {$this->db->table_prefix}users u ON u.user_id = l.poster_user_id
             WHERE l.public_slug = %s LIMIT 1",
            [sanitize_text_field((string) $slug)]
        );
        return $row ? $this->format($row, (int) $viewer_user_id, true) : null;
    }

    public function list_open($limit = 20, $offset = 0, $q = '', $category = '', $execution_mode = '') {
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $p      = $this->db->table_prefix;
        $where  = "l.status = 'open'";
        $params = [];
        $q      = trim((string) $q);
        if ($q !== '') {
            $like    = '%' . $this->db->wpdb->esc_like($q) . '%';
            $where  .= ' AND (l.title LIKE %s OR l.scope_text LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        $category = substr(sanitize_key((string) $category), 0, 40);
        if ($category !== '') {
            $where   .= ' AND l.category = %s';
            $params[] = $category;
        }
        $execution_mode = sanitize_key((string) $execution_mode);
        if (in_array($execution_mode, ['human', 'agent', 'hybrid'], true)) {
            $where   .= ' AND l.execution_mode = %s';
            $params[] = $execution_mode;
        }
        $params[] = $limit;
        $params[] = $offset;
        $rows   = $this->db->get_results(
            "SELECT l.*, u.display_name AS poster_name, u.profile_slug AS poster_handle
             FROM {$p}job_listings l
             LEFT JOIN {$p}users u ON u.user_id = l.poster_user_id
             WHERE {$where}
             ORDER BY l.created_at DESC
             LIMIT %d OFFSET %d",
            $params
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $item = $this->format($row);
            $item['poster_name']   = (string) ($row->poster_name ?? '');
            $item['poster_handle'] = (string) ($row->poster_handle ?? '');
            $out[] = $item;
        }
        return $out;
    }

    public function apply($listing_id, $user_id, $pitch = '') {
        if (!class_exists('Growl_Account_Capability_Policy', false)) {
            require_once __DIR__ . '/class-growl-account-capability-policy.php';
        }
        $cap = (new Growl_Account_Capability_Policy($this->db))->assert(
            (int) $user_id,
            Growl_Account_Capability_Policy::CAP_COMPLETE_JOB
        );
        if (is_wp_error($cap)) {
            return $cap;
        }
        $listing = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}job_listings WHERE listing_id = %d LIMIT 1",
            [(int) $listing_id]
        );
        if (!$listing || $listing->status !== 'open') {
            return new WP_Error('closed', 'This listing is not open.', ['status' => 400]);
        }
        if ((int) $listing->poster_user_id === (int) $user_id) {
            return new WP_Error('invalid', 'You cannot apply to your own listing.', ['status' => 400]);
        }
        $ok = $this->db->insert('job_applications', [
            'listing_id'         => (int) $listing_id,
            'applicant_user_id'  => (int) $user_id,
            'pitch'              => sanitize_textarea_field((string) $pitch),
            'status'             => 'pending',
            'created_at'         => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            return new WP_Error('apply_failed', 'You already applied, or the application could not be saved.', ['status' => 400]);
        }
        $application_id = (int) $this->db->wpdb->insert_id;
        require_once __DIR__ . '/class-growl-work-thread-service.php';
        $threads = new Growl_Work_Thread_Service($this->db);
        $tid = $threads->get_or_create('job_application', (string) $application_id);
        if (!is_wp_error($tid) && trim((string) $pitch) !== '') {
            $threads->post($tid, 'human', (string) $user_id, sanitize_textarea_field((string) $pitch), 'user', null, false);
        }
        if (class_exists('Growl_Work_Notify')) {
            Growl_Work_Notify::human(
                (int) $listing->poster_user_id,
                'New application',
                'Someone applied to “' . $listing->title . '”. Review applicants in Creator Hub.',
                'jobs/' . $listing->public_slug,
                'info'
            );
        }
        return ['application_id' => $application_id, 'status' => 'pending', 'thread_id' => is_wp_error($tid) ? null : $tid];
    }

    /**
     * Poster applications for one listing. Single JOIN, no N+1.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_applications($listing_id, $poster_user_id) {
        $p = $this->db->table_prefix;
        $listing = $this->db->get_row(
            "SELECT listing_id, poster_user_id FROM {$p}job_listings WHERE listing_id = %d LIMIT 1",
            [(int) $listing_id]
        );
        if (!$listing || (int) $listing->poster_user_id !== (int) $poster_user_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        $rows = $this->db->get_results(
            "SELECT a.application_id, a.listing_id, a.applicant_user_id, a.pitch, a.status, a.created_at,
                    a.is_shortlisted,
                    u.display_name AS applicant_name, u.profile_slug AS applicant_handle, u.x_username AS applicant_x,
                    r.thread_id
             FROM {$p}job_applications a
             LEFT JOIN {$p}users u ON u.user_id = a.applicant_user_id
             LEFT JOIN {$p}work_thread_refs r ON r.ref_type = 'job_application' AND r.ref_key = CAST(a.application_id AS CHAR)
             WHERE a.listing_id = %d
             ORDER BY a.is_shortlisted DESC, a.created_at ASC",
            [(int) $listing_id]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->format_application($row, true);
        }
        return $out;
    }

    /**
     * Public applicant roster: identity + time only (no pitch / threads).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_applicants_public($listing_id) {
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT a.application_id, a.applicant_user_id, a.status, a.created_at,
                    u.display_name AS applicant_name, u.profile_slug AS applicant_handle, u.x_username AS applicant_x
             FROM {$p}job_applications a
             LEFT JOIN {$p}users u ON u.user_id = a.applicant_user_id
             WHERE a.listing_id = %d
             ORDER BY a.created_at ASC",
            [(int) $listing_id]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->format_application($row, false);
        }
        return $out;
    }

    /**
     * Prefer display name, then EarnFi handle (profile_slug), then X username.
     */
    private function applicant_display_label($row) {
        $name = trim((string) ($row->applicant_name ?? ''));
        if ($name !== '') {
            return $name;
        }
        $handle = trim((string) ($row->applicant_handle ?? ''));
        if ($handle !== '') {
            return '@' . ltrim($handle, '@');
        }
        $x = trim((string) ($row->applicant_x ?? ''));
        if ($x !== '') {
            return '@' . ltrim($x, '@');
        }
        return 'Earner';
    }

    /**
     * @param object $row
     * @param bool   $include_private pitch + thread for poster only
     * @return array<string,mixed>
     */
    private function format_application($row, $include_private = true) {
        $label = $this->applicant_display_label($row);
        $handle = trim((string) ($row->applicant_handle ?? ''));
        $x = trim((string) ($row->applicant_x ?? ''));
        $out = [
            'application_id'      => (int) $row->application_id,
            'applicant_user_id'   => (int) ($row->applicant_user_id ?? 0),
            'applicant_name'      => trim((string) ($row->applicant_name ?? '')),
            'applicant_handle'    => $handle,
            'applicant_x'         => $x,
            'applicant_label'     => $label,
            'status'              => (string) ($row->status ?? 'pending'),
            'created_at'          => (string) ($row->created_at ?? ''),
            'created_at_label'    => !empty($row->created_at)
                ? gmdate('M j, Y · g:i A', strtotime((string) $row->created_at . ' UTC')) . ' UTC'
                : '',
        ];
        if ($include_private) {
            $out['pitch']          = (string) ($row->pitch ?? '');
            $out['is_shortlisted'] = !empty($row->is_shortlisted) ? 1 : 0;
            $out['thread_id']      = !empty($row->thread_id) ? (int) $row->thread_id : null;
        }
        return $out;
    }

    /**
     * Poster shortlists (or un-shortlists) an application.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function shortlist_application($listing_id, $application_id, $poster_user_id, $shortlisted = true) {
        $p = $this->db->table_prefix;
        $listing = $this->db->get_row(
            "SELECT listing_id, poster_user_id FROM {$p}job_listings WHERE listing_id = %d LIMIT 1",
            [(int) $listing_id]
        );
        if (!$listing || (int) $listing->poster_user_id !== (int) $poster_user_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        $app = $this->db->get_row(
            "SELECT * FROM {$p}job_applications WHERE application_id = %d AND listing_id = %d LIMIT 1",
            [(int) $application_id, (int) $listing_id]
        );
        if (!$app) {
            return new WP_Error('not_found', 'Application not found.', ['status' => 404]);
        }
        $flag = $shortlisted ? 1 : 0;
        $ok = $this->db->update(
            'job_applications',
            ['is_shortlisted' => $flag],
            ['application_id' => (int) $application_id]
        );
        if ($ok === false) {
            return new WP_Error('update_failed', 'Could not update shortlist.', ['status' => 500]);
        }
        $row = $this->db->get_row(
            "SELECT a.application_id, a.listing_id, a.applicant_user_id, a.pitch, a.status, a.created_at,
                    a.is_shortlisted,
                    u.display_name AS applicant_name, u.profile_slug AS applicant_handle, u.x_username AS applicant_x,
                    r.thread_id
             FROM {$p}job_applications a
             LEFT JOIN {$p}users u ON u.user_id = a.applicant_user_id
             LEFT JOIN {$p}work_thread_refs r ON r.ref_type = 'job_application' AND r.ref_key = CAST(a.application_id AS CHAR)
             WHERE a.application_id = %d LIMIT 1",
            [(int) $application_id]
        );
        return $row ? $this->format_application($row, true) : [
            'application_id' => (int) $application_id,
            'is_shortlisted' => $flag,
            'status'         => (string) $app->status,
        ];
    }

    public function my_application($listing_id, $user_id) {
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT a.application_id, a.pitch, a.status, a.created_at, r.thread_id
             FROM {$p}job_applications a
             LEFT JOIN {$p}work_thread_refs r ON r.ref_type = 'job_application' AND r.ref_key = CAST(a.application_id AS CHAR)
             WHERE a.listing_id = %d AND a.applicant_user_id = %d
             LIMIT 1",
            [(int) $listing_id, (int) $user_id]
        );
        if (!$row) {
            return null;
        }
        return [
            'application_id' => (int) $row->application_id,
            'pitch'          => (string) ($row->pitch ?? ''),
            'status'         => (string) $row->status,
            'created_at'     => (string) $row->created_at,
            'thread_id'      => $row->thread_id ? (int) $row->thread_id : null,
        ];
    }

    /**
     * @param array<string,mixed> $overrides amount, payment_structure, milestones
     * @return array<string,mixed>|WP_Error
     */
    public function accept_application($listing_id, $application_id, $poster_user_id, array $overrides = []) {
        $p = $this->db->table_prefix;
        $listing = $this->db->get_row_for_update(
            "SELECT * FROM {$p}job_listings WHERE listing_id = %d LIMIT 1",
            [(int) $listing_id]
        );
        if (!$listing || (int) $listing->poster_user_id !== (int) $poster_user_id) {
            return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
        }
        if ($listing->status !== 'open') {
            return new WP_Error('closed', 'This listing is no longer open.', ['status' => 400]);
        }
        $app = $this->db->get_row(
            "SELECT * FROM {$p}job_applications WHERE application_id = %d AND listing_id = %d LIMIT 1",
            [(int) $application_id, (int) $listing_id]
        );
        if (!$app || $app->status !== 'pending') {
            return new WP_Error('invalid', 'Application not found.', ['status' => 404]);
        }

        require_once __DIR__ . '/class-growl-job-contract-service.php';
        $contracts = new Growl_Job_Contract_Service($this->db);
        $contract = $contracts->create_from_hire($listing, $app, $poster_user_id, $overrides);
        if (is_wp_error($contract)) {
            return $contract;
        }

        $this->db->update('job_applications', ['status' => 'accepted'], ['application_id' => (int) $application_id]);

        $positions = isset($listing->slots) ? max(1, (int) $listing->slots) : 1;
        $hired = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$p}job_applications WHERE listing_id = %d AND status = 'accepted'",
            [(int) $listing_id]
        );
        $filled = $hired >= $positions;

        if ($filled) {
            $this->db->query(
                "UPDATE {$p}job_applications SET status = 'rejected' WHERE listing_id = %d AND status = 'pending'",
                [(int) $listing_id]
            );
        }

        $listing_update = [
            'accepted_application_id' => (int) $application_id,
            'updated_at'              => gmdate('Y-m-d H:i:s'),
            'spawned_contract_id'     => (int) $contract['contract_id'],
        ];
        // Close listing only when all open positions are filled.
        $listing_update['status'] = $filled ? 'accepted' : 'open';
        $this->db->update('job_listings', $listing_update, ['listing_id' => (int) $listing_id]);

        require_once __DIR__ . '/class-growl-work-thread-service.php';
        $threads = new Growl_Work_Thread_Service($this->db);
        $tid = $threads->get_or_create('job_application', (string) $application_id);
        if (!is_wp_error($tid)) {
            $threads->link($tid, 'job_contract', (string) $contract['public_slug']);
            $threads->post($tid, 'system', 'system', 'Hired. Fund the job contract to start protected work.', 'system');
        }

        if (class_exists('Growl_Work_Notify')) {
            Growl_Work_Notify::human(
                (int) $app->applicant_user_id,
                'You were hired',
                'The employer hired you for “' . $listing->title . '”. Open the contract. Work starts after they confirm protected payment.',
                'job-contract/' . $contract['public_slug'],
                'success'
            );
        }

        $gross = Growl_Money::assert_micro($contract['amount_micro'] ?? $listing->budget_micro);
        return [
            'listing'     => $this->get_listing($listing_id),
            'contract'    => $contract,
            'fee_preview' => Growl_Work_Fee_Engine::split_for_gross($gross, 'jobs'),
        ];
    }

    public function list_mine($user_id, $limit = 20, $offset = 0) {
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $p      = $this->db->table_prefix;
        $rows   = $this->db->get_results(
            "SELECT l.*, d.public_slug AS spawned_deal_slug, c.public_slug AS spawned_contract_slug,
                    (SELECT COUNT(*) FROM {$p}job_applications a WHERE a.listing_id = l.listing_id) AS application_count,
                    (SELECT COUNT(*) FROM {$p}job_applications a2 WHERE a2.listing_id = l.listing_id AND a2.status = 'accepted') AS hired_count
             FROM {$p}job_listings l
             LEFT JOIN {$p}deals d ON d.deal_id = l.spawned_deal_id
             LEFT JOIN {$p}job_contracts c ON c.contract_id = l.spawned_contract_id
             WHERE l.poster_user_id = %d
             ORDER BY l.listing_id DESC
             LIMIT %d OFFSET %d",
            [(int) $user_id, $limit, $offset]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $item = $this->format($row, (int) $user_id);
            $item['application_count'] = (int) ($row->application_count ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    public function format($row, $viewer_user_id = 0, $include_applicants = false) {
        $deadline = !empty($row->deadline_at) ? (string) $row->deadline_at : null;
        $structure = 'single';
        if (!empty($row->payment_structure)) {
            require_once __DIR__ . '/class-growl-job-contract-service.php';
            $structure = Growl_Job_Contract_Service::normalize_structure($row->payment_structure);
        }
        $milestones = [];
        if (!empty($row->milestones_json)) {
            $decoded = json_decode((string) $row->milestones_json, true);
            if (is_array($decoded)) {
                $milestones = $decoded;
            }
        }
        $positions = isset($row->slots) ? max(1, (int) $row->slots) : 1;
        $hired = 0;
        if (isset($row->hired_count)) {
            $hired = max(0, (int) $row->hired_count);
        } elseif ($include_applicants) {
            $p = $this->db->table_prefix;
            $hired = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$p}job_applications WHERE listing_id = %d AND status = 'accepted'",
                [(int) $row->listing_id]
            );
        }
        $open_positions = max(0, $positions - $hired);
        $is_poster = $viewer_user_id > 0 && (int) $row->poster_user_id === (int) $viewer_user_id;

        $poster_name = isset($row->poster_name) ? trim((string) $row->poster_name) : '';
        $poster_handle = isset($row->poster_handle) ? trim((string) $row->poster_handle) : '';
        $poster_x = isset($row->poster_x) ? trim((string) $row->poster_x) : '';
        $poster_label = $poster_name !== ''
            ? $poster_name
            : ($poster_handle !== '' ? '@' . ltrim($poster_handle, '@') : ($poster_x !== '' ? '@' . ltrim($poster_x, '@') : ''));

        $out = [
            'listing_id'     => (int) $row->listing_id,
            'public_slug'    => (string) $row->public_slug,
            'poster_user_id' => (int) $row->poster_user_id,
            'poster_name'    => $poster_name,
            'poster_handle'  => $poster_handle,
            'poster_label'   => $poster_label,
            'title'          => (string) $row->title,
            'scope_text'     => (string) $row->scope_text,
            'category'       => isset($row->category) ? (string) $row->category : '',
            'budget_micro'   => (string) $row->budget_micro,
            'budget_display' => Growl_Money::to_display($row->budget_micro),
            'payment_structure' => $structure,
            'milestones'        => $milestones,
            'deadline_at'    => $deadline,
            'deadline_label' => $deadline ? gmdate('M j, Y', strtotime($deadline . ' UTC')) : 'Flexible',
            'slots'          => $positions,
            'positions'      => $positions,
            'positions_filled' => $hired,
            'open_positions' => $open_positions,
            'status'         => (string) $row->status,
            'execution_mode' => isset($row->execution_mode) ? (string) $row->execution_mode : 'hybrid',
            'spawned_deal_id'       => $row->spawned_deal_id ? (int) $row->spawned_deal_id : null,
            'spawned_deal_slug'     => !empty($row->spawned_deal_slug) ? (string) $row->spawned_deal_slug : null,
            'spawned_contract_id'   => !empty($row->spawned_contract_id) ? (int) $row->spawned_contract_id : null,
            'spawned_contract_slug' => !empty($row->spawned_contract_slug) ? (string) $row->spawned_contract_slug : null,
            'created_at'            => (string) $row->created_at,
            'updated_at'            => (string) $row->updated_at,
        ];
        $application_count = isset($row->application_count)
            ? max(0, (int) $row->application_count)
            : ($include_applicants
                ? (int) $this->db->get_var(
                    "SELECT COUNT(*) FROM {$this->db->table_prefix}job_applications WHERE listing_id = %d",
                    [(int) $row->listing_id]
                )
                : 0);
        if ($is_poster) {
            $commercial_locked = $application_count > 0 || $hired > 0;
            $out['application_count'] = $application_count;
            $out['edit_policy'] = [
                'commercial_locked' => $commercial_locked,
                'editable_fields' => $commercial_locked
                    ? ['title', 'scope_text', 'category', 'deadline_at', 'positions']
                    : ['title', 'scope_text', 'category', 'budget_micro', 'payment_structure', 'milestones', 'deadline_at', 'positions', 'execution_mode'],
                'locked_fields' => $commercial_locked
                    ? ['budget_micro', 'payment_structure', 'milestones', 'execution_mode']
                    : [],
                'positions_may_only_increase' => $commercial_locked,
            ];
        }
        if ($include_applicants) {
            $out['applicants'] = $this->list_applicants_public((int) $row->listing_id);
        }
        if ($is_poster) {
            $out['is_poster'] = true;
        }
        return $out;
    }
}
