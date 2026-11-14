<?php
/**
 * In-app notifications for jobs, deals, and agent orders.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Work_Notify {

    public static function human($user_id, $title, $message, $path, $type = 'info') {
        $user_id = (int) $user_id;
        if ($user_id < 1 || !class_exists('GROWL_Notification_System')) {
            return;
        }
        $path = ltrim((string) $path, '/');
        (new GROWL_Notification_System())->create_notification($user_id, [
            'title'      => $title,
            'message'    => $message,
            'type'       => $type,
            'category'   => 'work',
            'action_url' => function_exists('earnfi_spa_url') ? earnfi_spa_url($path) : home_url('/' . $path),
            'created_by' => 'system',
        ]);
    }

    public static function thread_humans($thread_id, $except_type, $except_ref) {
        if (!class_exists('Growl_Work_Thread_Service')) {
            require_once __DIR__ . '/class-growl-work-thread-service.php';
        }
        $db = new GROWL_Database();
        $p  = $db->table_prefix;
        $refs = $db->get_results(
            "SELECT ref_type, ref_key FROM {$p}work_thread_refs WHERE thread_id = %d",
            [(int) $thread_id]
        );
        $ids = [];
        foreach ($refs ?: [] as $ref) {
            if ($ref->ref_type === 'deal') {
                $deal = $db->get_row(
                    "SELECT buyer_user_id, seller_user_id FROM {$p}deals WHERE public_slug = %s LIMIT 1",
                    [(string) $ref->ref_key]
                );
                if ($deal) {
                    $ids[] = (int) $deal->buyer_user_id;
                    $ids[] = (int) $deal->seller_user_id;
                }
            } elseif ($ref->ref_type === 'job_application') {
                $app = $db->get_row(
                    "SELECT a.applicant_user_id, l.poster_user_id, l.public_slug
                     FROM {$p}job_applications a
                     INNER JOIN {$p}job_listings l ON l.listing_id = a.listing_id
                     WHERE a.application_id = %d LIMIT 1",
                    [(int) $ref->ref_key]
                );
                if ($app) {
                    $ids[] = (int) $app->applicant_user_id;
                    $ids[] = (int) $app->poster_user_id;
                }
            } elseif ($ref->ref_type === 'agent_order') {
                $order = $db->get_row(
                    "SELECT buyer_type, buyer_ref FROM {$p}agent_orders WHERE public_slug = %s LIMIT 1",
                    [(string) $ref->ref_key]
                );
                if ($order && $order->buyer_type === 'human') {
                    $ids[] = (int) $order->buyer_ref;
                }
            } elseif ($ref->ref_type === 'agent_deal') {
                $deal = $db->get_row(
                    "SELECT buyer_type, buyer_ref, seller_type, seller_ref FROM {$p}agent_deals WHERE public_slug = %s LIMIT 1",
                    [(string) $ref->ref_key]
                );
                if ($deal) {
                    if ($deal->buyer_type === 'human') {
                        $ids[] = (int) $deal->buyer_ref;
                    }
                    if ($deal->seller_type === 'human') {
                        $ids[] = (int) $deal->seller_ref;
                    }
                }
            } elseif ($ref->ref_type === 'job_contract') {
                $c = $db->get_row(
                    "SELECT employer_user_id, worker_user_id FROM {$p}job_contracts WHERE public_slug = %s LIMIT 1",
                    [(string) $ref->ref_key]
                );
                if ($c) {
                    $ids[] = (int) $c->employer_user_id;
                    $ids[] = (int) $c->worker_user_id;
                }
            }
        }
        $except_type = $except_type === 'agent' ? 'agent' : 'human';
        $except_ref  = (string) $except_ref;
        $out = [];
        foreach (array_unique($ids) as $id) {
            if ($id < 1) {
                continue;
            }
            if ($except_type === 'human' && (string) $id === $except_ref) {
                continue;
            }
            $out[] = $id;
        }
        return $out;
    }

    public static function thread_path($thread_id) {
        $tid = (int) $thread_id;
        if ($tid > 0) {
            return 'messages?thread=' . $tid;
        }
        return 'messages';
    }

    /**
     * Prompt a party to leave a rating (14-day window). Humans get in-app notifications.
     */
    public static function prompt_work_rating($ref_type, $ref_id, $party_type, $party_ref) {
        $ref_type = sanitize_key((string) $ref_type);
        $ref_id   = sanitize_text_field((string) $ref_id);
        $party_type = $party_type === 'agent' ? 'agent' : 'human';
        $party_ref  = sanitize_text_field((string) $party_ref);
        if ($ref_id === '' || $party_ref === '') {
            return;
        }
        $paths = [
            'deal'           => 'deal/',
            'agent_deal'     => 'agent-deal/',
            'agent_order'    => 'order/',
            'job_contract'   => 'job-contract/',
            'job_milestone'  => 'job-contract/',
            'task_completion'=> 'tasks',
        ];
        $prefix = $paths[$ref_type] ?? '';
        if ($prefix === '') {
            return;
        }
        $path = $ref_type === 'task_completion' ? 'tasks' : $prefix . $ref_id;
        if ($party_type === 'human' && (int) $party_ref > 0) {
            self::human(
                (int) $party_ref,
                'How was the work?',
                'Leave a rating while the 14-day review window is open. You can edit once within 24 hours.',
                $path,
                'info'
            );
        } elseif ($party_type === 'agent' && $party_ref !== '') {
            $db = new GROWL_Database();
            $p  = $db->table_prefix;
            $user = $db->get_row(
                "SELECT u.user_id
                 FROM {$p}ai_agents a
                 INNER JOIN {$p}users u ON LOWER(u.wallet_address) = LOWER(a.wallet_address)
                 WHERE a.agent_id = %s LIMIT 1",
                [sanitize_text_field((string) $party_ref)]
            );
            if ($user && (int) $user->user_id > 0) {
                self::human(
                    (int) $user->user_id,
                    'How was the work?',
                    'Submit a rating via POST /work/reviews with ref_type agent_order and your agent token.',
                    $path,
                    'info'
                );
            }
        }
    }
}
