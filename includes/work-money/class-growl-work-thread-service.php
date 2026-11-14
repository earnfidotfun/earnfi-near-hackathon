<?php
/**
 * Shared conversation threads for applications, deals, and agent orders.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Work_Thread_Service {

    /** @var GROWL_Database */
    private $db;

    public function __construct(?GROWL_Database $db = null) {
        $this->db = $db ?: new GROWL_Database();
    }

    public function get_or_create($ref_type, $ref_key) {
        $ref_type = sanitize_key((string) $ref_type);
        $ref_key  = sanitize_text_field((string) $ref_key);
        if ($ref_type === '' || $ref_key === '') {
            return new WP_Error('invalid', 'Thread reference is required.', ['status' => 400]);
        }
        $p = $this->db->table_prefix;
        $existing = $this->db->get_row(
            "SELECT thread_id FROM {$p}work_thread_refs WHERE ref_type = %s AND ref_key = %s LIMIT 1",
            [$ref_type, $ref_key]
        );
        if ($existing) {
            return (int) $existing->thread_id;
        }
        $this->db->begin_transaction();
        try {
            $again = $this->db->get_row(
                "SELECT thread_id FROM {$p}work_thread_refs WHERE ref_type = %s AND ref_key = %s LIMIT 1",
                [$ref_type, $ref_key]
            );
            if ($again) {
                $this->db->commit();
                return (int) $again->thread_id;
            }
            $this->db->insert('work_threads', [
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $thread_id = (int) $this->db->wpdb->insert_id;
            $ok = $this->db->insert('work_thread_refs', [
                'thread_id'  => $thread_id,
                'ref_type'   => $ref_type,
                'ref_key'    => $ref_key,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if (!$ok) {
                $this->db->rollback();
                $row = $this->db->get_row(
                    "SELECT thread_id FROM {$p}work_thread_refs WHERE ref_type = %s AND ref_key = %s LIMIT 1",
                    [$ref_type, $ref_key]
                );
                return $row ? (int) $row->thread_id : new WP_Error('create_failed', 'Could not open conversation.', ['status' => 500]);
            }
            $this->db->commit();
            return $thread_id;
        } catch (Throwable $e) {
            $this->db->rollback();
            return new WP_Error('create_failed', 'Could not open conversation.', ['status' => 500]);
        }
    }

    public function link($thread_id, $ref_type, $ref_key) {
        $ok = $this->db->insert('work_thread_refs', [
            'thread_id'  => (int) $thread_id,
            'ref_type'   => sanitize_key((string) $ref_type),
            'ref_key'    => sanitize_text_field((string) $ref_key),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            $row = $this->db->get_row(
                "SELECT thread_id FROM {$this->db->table_prefix}work_thread_refs WHERE ref_type = %s AND ref_key = %s LIMIT 1",
                [sanitize_key((string) $ref_type), sanitize_text_field((string) $ref_key)]
            );
            return $row ? (int) $row->thread_id : (int) $thread_id;
        }
        return (int) $thread_id;
    }

    /**
     * @return true|WP_Error
     */
    public function assert_can_access($thread_id, $actor_type, $actor_ref) {
        $auth = $this->authorize((int) $thread_id, $actor_type, $actor_ref);
        return $auth === true ? true : $auth;
    }

    public function post($thread_id, $sender_type, $sender_ref, $body, $kind = 'user', $meta = null, $notify = true) {
        $access = $this->assert_can_access($thread_id, $sender_type, $sender_ref);
        if (is_wp_error($access) && $kind !== 'system') {
            return $access;
        }
        $body = sanitize_textarea_field((string) $body);
        if ($body === '') {
            return new WP_Error('invalid', 'A message is required.', ['status' => 400]);
        }
        if (function_exists('mb_strlen') && mb_strlen($body) > 4000) {
            $body = mb_substr($body, 0, 4000);
        } elseif (strlen($body) > 4000) {
            $body = substr($body, 0, 4000);
        }
        $ok = $this->db->insert('work_messages', [
            'thread_id'   => (int) $thread_id,
            'sender_type' => in_array($sender_type, ['human', 'agent', 'system'], true) ? $sender_type : 'human',
            'sender_ref'  => sanitize_text_field((string) $sender_ref),
            'kind'        => $kind === 'system' ? 'system' : 'user',
            'body'        => $body,
            'meta_json'   => is_array($meta) ? wp_json_encode($meta) : null,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            return new WP_Error('create_failed', 'Could not send message.', ['status' => 500]);
        }
        $this->db->update('work_threads', ['updated_at' => gmdate('Y-m-d H:i:s')], ['thread_id' => (int) $thread_id]);
        $msg = $this->get_message((int) $this->db->wpdb->insert_id);
        if ($notify && $kind !== 'system' && class_exists('Growl_Work_Notify')) {
            $path = Growl_Work_Notify::thread_path($thread_id);
            $body_text = $this->notify_message_body((int) $thread_id);
            foreach (Growl_Work_Notify::thread_humans($thread_id, $sender_type, $sender_ref) as $uid) {
                Growl_Work_Notify::human(
                    $uid,
                    'New message',
                    $body_text,
                    $path,
                    'info'
                );
            }
        }
        return $msg;
    }

    public function list_messages($thread_id, $actor_type, $actor_ref, $after_id = 0, $limit = 50, $mark_read = true) {
        $access = $this->assert_can_access($thread_id, $actor_type, $actor_ref);
        if (is_wp_error($access)) {
            return $access;
        }
        $limit = min(100, max(1, (int) $limit));
        $after_id = max(0, (int) $after_id);
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT message_id, sender_type, sender_ref, kind, body, meta_json, created_at
             FROM {$p}work_messages
             WHERE thread_id = %d AND message_id > %d
             ORDER BY message_id ASC
             LIMIT %d",
            [(int) $thread_id, $after_id, $limit]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = $this->format_message($row);
        }
        if ($mark_read) {
            $this->mark_read($thread_id, $actor_type, $actor_ref);
        }
        return $out;
    }

    public function unread_count_for_human($thread_id, $user_id) {
        $thread_id = (int) $thread_id;
        $user_id = (int) $user_id;
        if ($thread_id < 1 || $user_id < 1) {
            return 0;
        }
        $p = $this->db->table_prefix;
        $last_read = (int) $this->db->get_var(
            "SELECT last_read_id FROM {$p}work_thread_reads
             WHERE thread_id = %d AND participant_type = 'human' AND participant_ref = %s LIMIT 1",
            [$thread_id, (string) $user_id]
        );
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$p}work_messages
             WHERE thread_id = %d AND message_id > %d
               AND NOT (sender_type = 'human' AND sender_ref = %s)",
            [$thread_id, $last_read, (string) $user_id]
        );
    }

    /**
     * Role labels for chat UI (You / Buyer / Seller / Applicant / etc.).
     *
     * @return array{self_label: string, peer_label: string}
     */
    public function chat_role_labels($thread_id, $viewer_user_id) {
        $viewer_user_id = (int) $viewer_user_id;
        if ($viewer_user_id < 1) {
            return ['self_label' => 'You', 'peer_label' => 'Them'];
        }
        $p = $this->db->table_prefix;
        $refs = $this->db->get_results(
            "SELECT ref_type, ref_key FROM {$p}work_thread_refs WHERE thread_id = %d",
            [(int) $thread_id]
        );
        foreach ((array) $refs as $ref) {
            $labels = $this->role_labels_for_ref((string) $ref->ref_type, (string) $ref->ref_key, $viewer_user_id);
            if ($labels) {
                return $labels;
            }
        }
        return ['self_label' => 'You', 'peer_label' => 'Them'];
    }

    /**
     * @return array{self_label: string, peer_label: string}|null
     */
    private function role_labels_for_ref($ref_type, $ref_key, $user_id) {
        $p = $this->db->table_prefix;
        if ($ref_type === 'deal') {
            $deal = $this->db->get_row(
                "SELECT buyer_user_id, seller_user_id FROM {$p}deals WHERE public_slug = %s OR deal_id = %d LIMIT 1",
                [$ref_key, (int) $ref_key]
            );
            if (!$deal) {
                return null;
            }
            if ((int) $deal->buyer_user_id === $user_id) {
                return ['self_label' => 'You', 'peer_label' => 'Seller'];
            }
            if ((int) $deal->seller_user_id === $user_id) {
                return ['self_label' => 'You', 'peer_label' => 'Buyer'];
            }
            return null;
        }
        if ($ref_type === 'job_application') {
            $app = $this->db->get_row(
                "SELECT a.applicant_user_id, l.poster_user_id
                 FROM {$p}job_applications a
                 INNER JOIN {$p}job_listings l ON l.listing_id = a.listing_id
                 WHERE a.application_id = %d LIMIT 1",
                [(int) $ref_key]
            );
            if (!$app) {
                return null;
            }
            if ((int) $app->poster_user_id === $user_id) {
                return ['self_label' => 'You', 'peer_label' => 'Applicant'];
            }
            if ((int) $app->applicant_user_id === $user_id) {
                return ['self_label' => 'You', 'peer_label' => 'Job creator'];
            }
            return null;
        }
        if ($ref_type === 'job_contract') {
            $contract = $this->db->get_row(
                "SELECT employer_user_id, worker_user_id FROM {$p}job_contracts
                 WHERE public_slug = %s OR contract_id = %d LIMIT 1",
                [$ref_key, (int) $ref_key]
            );
            if (!$contract) {
                return null;
            }
            if ((int) $contract->employer_user_id === $user_id) {
                return ['self_label' => 'You', 'peer_label' => 'Worker'];
            }
            if ((int) $contract->worker_user_id === $user_id) {
                return ['self_label' => 'You', 'peer_label' => 'Employer'];
            }
            return null;
        }
        if ($ref_type === 'agent_order') {
            return ['self_label' => 'You', 'peer_label' => 'Provider'];
        }
        if ($ref_type === 'agent_deal') {
            $deal = $this->db->get_row(
                "SELECT buyer_type, buyer_ref, seller_type, seller_ref FROM {$p}agent_deals WHERE public_slug = %s LIMIT 1",
                [$ref_key]
            );
            if (!$deal) {
                return null;
            }
            if ($deal->buyer_type === 'human' && (int) $deal->buyer_ref === $user_id) {
                $peer = $deal->seller_type === 'agent' ? 'Agent seller' : 'Seller';
                return ['self_label' => 'You', 'peer_label' => $peer];
            }
            if ($deal->seller_type === 'human' && (int) $deal->seller_ref === $user_id) {
                $peer = $deal->buyer_type === 'agent' ? 'Agent buyer' : 'Buyer';
                return ['self_label' => 'You', 'peer_label' => $peer];
            }
            return null;
        }
        return null;
    }

    private function notify_message_body($thread_id) {
        $p = $this->db->table_prefix;
        $ref = $this->db->get_row(
            "SELECT ref_type, ref_key FROM {$p}work_thread_refs WHERE thread_id = %d LIMIT 1",
            [(int) $thread_id]
        );
        if ($ref && $ref->ref_type === 'deal') {
            $title = $this->db->get_var(
                "SELECT title FROM {$p}deals WHERE public_slug = %s LIMIT 1",
                [(string) $ref->ref_key]
            );
            $title = trim((string) $title);
            if ($title !== '') {
                return sprintf('You have a new message on deal "%s".', $title);
            }
            return 'You have a new message on this deal.';
        }
        if ($ref && $ref->ref_type === 'job_application') {
            $title = $this->db->get_var(
                "SELECT l.title FROM {$p}job_applications a
                 INNER JOIN {$p}job_listings l ON l.listing_id = a.listing_id
                 WHERE a.application_id = %d LIMIT 1",
                [(int) $ref->ref_key]
            );
            $title = trim((string) $title);
            if ($title !== '') {
                return sprintf('You have a new message on job "%s".', $title);
            }
        }
        if ($ref && $ref->ref_type === 'job_contract') {
            $title = $this->db->get_var(
                "SELECT title FROM {$p}job_contracts WHERE public_slug = %s LIMIT 1",
                [(string) $ref->ref_key]
            );
            $title = trim((string) $title);
            if ($title !== '') {
                return sprintf('You have a new message on job contract "%s".', $title);
            }
        }
        if ($ref && $ref->ref_type === 'agent_deal') {
            $title = $this->db->get_var(
                "SELECT title FROM {$p}agent_deals WHERE public_slug = %s LIMIT 1",
                [(string) $ref->ref_key]
            );
            $title = trim((string) $title);
            if ($title !== '') {
                return sprintf('You have a new message on agent deal "%s".', $title);
            }
            return 'You have a new message on this agent deal.';
        }
        return 'You have a new message on this work item.';
    }

    public function mark_read($thread_id, $actor_type, $actor_ref) {
        if (!in_array($actor_type, ['human', 'agent'], true)) {
            return;
        }
        $last = (int) $this->db->get_var(
            "SELECT MAX(message_id) FROM {$this->db->table_prefix}work_messages WHERE thread_id = %d",
            [(int) $thread_id]
        );
        $this->db->wpdb->query($this->db->wpdb->prepare(
            "INSERT INTO {$this->db->table_prefix}work_thread_reads
             (thread_id, participant_type, participant_ref, last_read_id, updated_at)
             VALUES (%d, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE last_read_id = VALUES(last_read_id), updated_at = VALUES(updated_at)",
            (int) $thread_id,
            $actor_type,
            sanitize_text_field((string) $actor_ref),
            $last,
            gmdate('Y-m-d H:i:s')
        ));
    }

    public function get_message($message_id) {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->db->table_prefix}work_messages WHERE message_id = %d LIMIT 1",
            [(int) $message_id]
        );
        return $row ? $this->format_message($row) : null;
    }

    public function format_message($row) {
        return [
            'message_id'  => (int) $row->message_id,
            'sender_type' => (string) $row->sender_type,
            'sender_ref'  => (string) $row->sender_ref,
            'kind'        => (string) $row->kind,
            'body'        => (string) $row->body,
            'meta'        => $row->meta_json ? json_decode($row->meta_json, true) : null,
            'created_at'  => (string) $row->created_at,
        ];
    }

    /**
     * @return true|WP_Error
     */
    private function authorize($thread_id, $actor_type, $actor_ref) {
        $p = $this->db->table_prefix;
        $refs = $this->db->get_results(
            "SELECT ref_type, ref_key FROM {$p}work_thread_refs WHERE thread_id = %d",
            [(int) $thread_id]
        );
        if (!$refs) {
            return new WP_Error('not_found', 'Conversation not found.', ['status' => 404]);
        }
        $actor_type = $actor_type === 'agent' ? 'agent' : 'human';
        $actor_ref  = (string) $actor_ref;
        foreach ($refs as $ref) {
            if ($this->ref_allows($ref->ref_type, $ref->ref_key, $actor_type, $actor_ref)) {
                return true;
            }
        }
        return new WP_Error('forbidden', 'Not authorized.', ['status' => 403]);
    }

    private function ref_allows($ref_type, $ref_key, $actor_type, $actor_ref) {
        $p = $this->db->table_prefix;
        if ($ref_type === 'deal') {
            $deal = $this->db->get_row(
                "SELECT buyer_user_id, seller_user_id FROM {$p}deals WHERE public_slug = %s OR deal_id = %d LIMIT 1",
                [$ref_key, (int) $ref_key]
            );
            if (!$deal || $actor_type !== 'human') {
                return false;
            }
            return (string) $deal->buyer_user_id === $actor_ref || (string) $deal->seller_user_id === $actor_ref;
        }
        if ($ref_type === 'job_application') {
            $app = $this->db->get_row(
                "SELECT a.applicant_user_id, l.poster_user_id
                 FROM {$p}job_applications a
                 INNER JOIN {$p}job_listings l ON l.listing_id = a.listing_id
                 WHERE a.application_id = %d LIMIT 1",
                [(int) $ref_key]
            );
            if (!$app || $actor_type !== 'human') {
                return false;
            }
            return (string) $app->applicant_user_id === $actor_ref || (string) $app->poster_user_id === $actor_ref;
        }
        if ($ref_type === 'agent_order') {
            $order = $this->db->get_row(
                "SELECT buyer_type, buyer_ref, provider_agent_id FROM {$p}agent_orders
                 WHERE public_slug = %s OR order_id = %d LIMIT 1",
                [$ref_key, (int) $ref_key]
            );
            if (!$order) {
                return false;
            }
            if ($actor_type === 'agent' && (string) $order->provider_agent_id === $actor_ref) {
                return true;
            }
            return (string) $order->buyer_type === $actor_type && (string) $order->buyer_ref === $actor_ref;
        }
        if ($ref_type === 'job_contract') {
            $contract = $this->db->get_row(
                "SELECT employer_user_id, worker_user_id FROM {$p}job_contracts
                 WHERE public_slug = %s OR contract_id = %d LIMIT 1",
                [$ref_key, (int) $ref_key]
            );
            if (!$contract || $actor_type !== 'human') {
                return false;
            }
            return (string) $contract->employer_user_id === $actor_ref || (string) $contract->worker_user_id === $actor_ref;
        }
        return false;
    }

    /**
     * Inbox list for the signed-in user (deals, job applications, contracts, agent orders).
     *
     * @return array{items: array<int, array<string,mixed>>, pagination: array<string,int>}
     */
    public function list_for_user($user_id, $filter = '', $page = 1, $per_page = 40) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return ['items' => [], 'pagination' => ['page' => 1, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0]];
        }
        $filter = sanitize_key((string) $filter);
        $page = max(1, (int) $page);
        $per_page = min(60, max(1, (int) $per_page));
        $offset = ($page - 1) * $per_page;
        $p = $this->db->table_prefix;
        $uid = $user_id;
        $uid_s = (string) $user_id;

        $filter_sql = '';
        if ($filter === 'deal') {
            $filter_sql = " AND r.ref_type = 'deal' ";
        } elseif ($filter === 'job') {
            $filter_sql = " AND r.ref_type IN ('job_application','job_contract') ";
        } elseif ($filter === 'agent') {
            $filter_sql = " AND r.ref_type = 'agent_order' ";
        }

        $count_sql = "
            SELECT COUNT(DISTINCT wt.thread_id) FROM {$p}work_threads wt
            INNER JOIN {$p}work_thread_refs r ON r.thread_id = wt.thread_id
            LEFT JOIN {$p}deals d ON r.ref_type = 'deal' AND d.public_slug = r.ref_key
            LEFT JOIN {$p}job_applications ja ON r.ref_type = 'job_application' AND ja.application_id = CAST(r.ref_key AS UNSIGNED)
            LEFT JOIN {$p}job_listings jl ON ja.listing_id = jl.listing_id
            LEFT JOIN {$p}job_contracts jc ON r.ref_type = 'job_contract' AND jc.public_slug = r.ref_key
            LEFT JOIN {$p}agent_orders ao ON r.ref_type = 'agent_order' AND ao.public_slug = r.ref_key
            WHERE (
                (r.ref_type = 'deal' AND (d.buyer_user_id = {$uid} OR d.seller_user_id = {$uid}))
                OR (r.ref_type = 'job_application' AND (ja.applicant_user_id = {$uid} OR jl.poster_user_id = {$uid}))
                OR (r.ref_type = 'job_contract' AND (jc.employer_user_id = {$uid} OR jc.worker_user_id = {$uid}))
                OR (r.ref_type = 'agent_order' AND ao.buyer_type = 'human' AND ao.buyer_ref = '{$uid_s}')
            )
            {$filter_sql}
        ";
        $total = (int) $this->db->get_var($count_sql);

        $rows = $this->db->query(
            "SELECT DISTINCT wt.thread_id, wt.updated_at, r.ref_type, r.ref_key
             FROM {$p}work_threads wt
             INNER JOIN {$p}work_thread_refs r ON r.thread_id = wt.thread_id
             LEFT JOIN {$p}deals d ON r.ref_type = 'deal' AND d.public_slug = r.ref_key
             LEFT JOIN {$p}job_applications ja ON r.ref_type = 'job_application' AND ja.application_id = CAST(r.ref_key AS UNSIGNED)
             LEFT JOIN {$p}job_listings jl ON ja.listing_id = jl.listing_id
             LEFT JOIN {$p}job_contracts jc ON r.ref_type = 'job_contract' AND jc.public_slug = r.ref_key
             LEFT JOIN {$p}agent_orders ao ON r.ref_type = 'agent_order' AND ao.public_slug = r.ref_key
             WHERE (
                (r.ref_type = 'deal' AND (d.buyer_user_id = %d OR d.seller_user_id = %d))
                OR (r.ref_type = 'job_application' AND (ja.applicant_user_id = %d OR jl.poster_user_id = %d))
                OR (r.ref_type = 'job_contract' AND (jc.employer_user_id = %d OR jc.worker_user_id = %d))
                OR (r.ref_type = 'agent_order' AND ao.buyer_type = 'human' AND ao.buyer_ref = %s)
             )
             {$filter_sql}
             ORDER BY wt.updated_at DESC
             LIMIT %d OFFSET %d",
            [$uid, $uid, $uid, $uid, $uid, $uid, $uid_s, $per_page, $offset]
        );

        $items = [];
        foreach ((array) $rows as $row) {
            $items[] = $this->format_inbox_item($row, $user_id);
        }

        return [
            'items' => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
            ],
        ];
    }

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    private function format_inbox_item($row, $user_id) {
        $p = $this->db->table_prefix;
        $thread_id = (int) ($row->thread_id ?? 0);
        $ref_type = (string) ($row->ref_type ?? '');
        $ref_key = (string) ($row->ref_key ?? '');
        $context = $this->context_for_ref($ref_type, $ref_key, $user_id);

        $last = $this->db->get_row(
            "SELECT body, sender_type, sender_ref, created_at FROM {$p}work_messages
             WHERE thread_id = %d ORDER BY message_id DESC LIMIT 1",
            [$thread_id]
        );
        $last_read = (int) $this->db->get_var(
            "SELECT last_read_id FROM {$p}work_thread_reads
             WHERE thread_id = %d AND participant_type = 'human' AND participant_ref = %s LIMIT 1",
            [$thread_id, (string) $user_id]
        );
        $unread = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$p}work_messages
             WHERE thread_id = %d AND message_id > %d
               AND NOT (sender_type = 'human' AND sender_ref = %s)",
            [$thread_id, $last_read, (string) $user_id]
        );

        $preview = $last ? wp_trim_words((string) $last->body, 18, '…') : '';

        return [
            'thread_id'       => $thread_id,
            'ref_type'        => $ref_type,
            'ref_key'         => $ref_key,
            'kind'            => $context['kind'],
            'kind_label'      => $context['kind_label'],
            'title'           => $context['title'],
            'subtitle'        => $context['subtitle'],
            'status'          => $context['status'],
            'context_path'    => $context['path'],
            'counterpart'     => $context['counterpart'],
            'updated_at'      => (string) ($row->updated_at ?? ''),
            'last_message_at' => $last ? (string) $last->created_at : null,
            'last_preview'    => $preview,
            'unread_count'    => $unread,
        ];
    }

    /**
     * @return array{kind: string, kind_label: string, title: string, subtitle: string, status: string, path: string, counterpart: string}
     */
    private function context_for_ref($ref_type, $ref_key, $user_id) {
        $p = $this->db->table_prefix;
        $user_id = (int) $user_id;
        $default = [
            'kind'        => 'work',
            'kind_label'  => 'Work',
            'title'       => 'Conversation',
            'subtitle'    => '',
            'status'      => '',
            'path'        => 'messages',
            'counterpart' => '',
        ];
        if ($ref_type === 'deal') {
            $deal = $this->db->get_row(
                "SELECT title, status, buyer_user_id, seller_user_id, amount_micro FROM {$p}deals WHERE public_slug = %s LIMIT 1",
                [$ref_key]
            );
            if (!$deal) {
                return $default;
            }
            $other_id = (int) $deal->buyer_user_id === $user_id ? (int) $deal->seller_user_id : (int) $deal->buyer_user_id;
            return [
                'kind'        => 'deal',
                'kind_label'  => 'Deal',
                'title'       => (string) $deal->title,
                'subtitle'    => class_exists('Growl_Money') ? Growl_Money::to_display($deal->amount_micro) . ' USDC' : '',
                'status'      => (string) $deal->status,
                'path'        => 'deal/' . $ref_key,
                'counterpart' => $this->user_display($other_id),
            ];
        }
        if ($ref_type === 'job_application') {
            $app = $this->db->get_row(
                "SELECT a.applicant_user_id, a.status, l.title, l.public_slug, l.poster_user_id
                 FROM {$p}job_applications a
                 INNER JOIN {$p}job_listings l ON l.listing_id = a.listing_id
                 WHERE a.application_id = %d LIMIT 1",
                [(int) $ref_key]
            );
            if (!$app) {
                return $default;
            }
            $other_id = (int) $app->applicant_user_id === $user_id ? (int) $app->poster_user_id : (int) $app->applicant_user_id;
            $is_poster = (int) $app->poster_user_id === $user_id;
            return [
                'kind'        => 'job',
                'kind_label'  => 'Job application',
                'title'       => (string) $app->title,
                'subtitle'    => 'Application',
                'status'      => (string) $app->status,
                'path'        => $is_poster ? ('creator-hire/' . (string) $app->public_slug) : ('jobs/' . (string) $app->public_slug),
                'counterpart' => $this->user_display($other_id),
            ];
        }
        if ($ref_type === 'job_contract') {
            $c = $this->db->get_row(
                "SELECT title, status, employer_user_id, worker_user_id, amount_micro FROM {$p}job_contracts WHERE public_slug = %s LIMIT 1",
                [$ref_key]
            );
            if (!$c) {
                return $default;
            }
            $other_id = (int) $c->employer_user_id === $user_id ? (int) $c->worker_user_id : (int) $c->employer_user_id;
            return [
                'kind'        => 'job',
                'kind_label'  => 'Job contract',
                'title'       => (string) $c->title,
                'subtitle'    => class_exists('Growl_Money') ? Growl_Money::to_display($c->amount_micro) . ' USDC' : '',
                'status'      => (string) $c->status,
                'path'        => 'job-contract/' . $ref_key,
                'counterpart' => $this->user_display($other_id),
            ];
        }
        if ($ref_type === 'agent_order') {
            $order = $this->db->get_row(
                "SELECT status, buyer_ref, provider_agent_id FROM {$p}agent_orders WHERE public_slug = %s LIMIT 1",
                [$ref_key]
            );
            if (!$order) {
                return $default;
            }
            return [
                'kind'        => 'agent',
                'kind_label'  => 'Agent order',
                'title'       => 'Agent order',
                'subtitle'    => (string) ($order->provider_agent_id ?? ''),
                'status'      => (string) $order->status,
                'path'        => 'order/' . $ref_key,
                'counterpart' => 'Agent',
            ];
        }
        return $default;
    }

    private function user_display($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return 'Member';
        }
        $u = $this->db->get_row(
            "SELECT display_name, profile_slug, x_username FROM {$this->db->table_prefix}users WHERE user_id = %d LIMIT 1",
            [$user_id]
        );
        if (!$u) {
            return 'Member';
        }
        $name = trim((string) ($u->display_name ?? ''));
        if ($name !== '') {
            return $name;
        }
        if (!empty($u->profile_slug)) {
            return '@' . $u->profile_slug;
        }
        if (!empty($u->x_username)) {
            return '@' . $u->x_username;
        }
        return 'Member';
    }

    public function resolve($ref_type, $ref_key, $actor_type, $actor_ref) {
        $tid = $this->get_or_create($ref_type, $ref_key);
        if (is_wp_error($tid)) {
            return $tid;
        }
        $access = $this->assert_can_access($tid, $actor_type, $actor_ref);
        if (is_wp_error($access)) {
            return $access;
        }
        return ['thread_id' => (int) $tid];
    }
}
