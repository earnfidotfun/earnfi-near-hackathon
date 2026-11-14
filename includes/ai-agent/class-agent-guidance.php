<?php
/**
 * Agent Guidance Protocol — pure O(1) state machine for next_actions envelopes.
 * No database queries inside this class.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Agent_Guidance {

    /**
     * Wrap an entity response with guidance metadata.
     *
     * @param string $entity_type agent_order|agent_deal|job_application|open_work|open_work_submission
     * @param array  $entity      Normalized entity array (must include status, ids, roles).
     * @param string $viewer_type human|agent|guest
     * @param string $viewer_ref  user_id or agent_id or empty
     * @return array
     */
    public static function envelope(string $entity_type, array $entity, string $viewer_type = 'guest', string $viewer_ref = ''): array {
        $status = sanitize_key((string) ($entity['status'] ?? ''));
        $viewer_type = in_array($viewer_type, ['human', 'agent', 'guest'], true) ? $viewer_type : 'guest';
        $role = self::viewer_role($entity_type, $entity, $viewer_type, $viewer_ref);
        $actions = self::actions_for($entity_type, $entity, $status, $role);
        $hint = self::hint_for($entity_type, $status, $role, $entity);
        $next_step = self::next_step_for($entity_type, $entity, $actions);

        $key = $entity_type === 'agent_deal' ? 'deal' : ($entity_type === 'agent_order' ? 'order' : ($entity_type === 'open_work' ? 'open_work' : ($entity_type === 'open_work_submission' ? 'submission' : 'application')));

        return [
            $key              => $entity,
            'status'          => $status,
            'viewer_role'     => $role,
            'next_actions'    => $actions,
            'hint'            => $hint,
            'next_step'       => $next_step,
        ];
    }

    /**
     * @return string buyer|provider|poster|applicant|guest|party
     */
    private static function viewer_role(string $entity_type, array $entity, string $viewer_type, string $viewer_ref): string {
        if ($viewer_type === 'guest' || $viewer_ref === '') {
            return 'guest';
        }
        if ($entity_type === 'agent_order') {
            $buyer_type = (string) ($entity['buyer_type'] ?? '');
            $buyer_ref = (string) ($entity['buyer_ref'] ?? '');
            $provider = (string) ($entity['provider_agent_id'] ?? '');
            if ($viewer_type === $buyer_type && $viewer_ref === $buyer_ref) {
                return 'buyer';
            }
            if ($viewer_type === 'agent' && $viewer_ref === $provider) {
                return 'provider';
            }
            return 'guest';
        }
        if ($entity_type === 'agent_deal') {
            $buyer_type = (string) ($entity['buyer_type'] ?? '');
            $buyer_ref = (string) ($entity['buyer_ref'] ?? '');
            $seller_type = (string) ($entity['seller_type'] ?? '');
            $seller_ref = (string) ($entity['seller_ref'] ?? '');
            if ($viewer_type === $buyer_type && $viewer_ref === $buyer_ref) {
                return 'buyer';
            }
            if ($viewer_type === $seller_type && $viewer_ref === $seller_ref) {
                return 'seller';
            }
            return 'guest';
        }
        if ($entity_type === 'open_work') {
            $hirer = (int) ($entity['hirer_user_id'] ?? 0);
            if ($viewer_type === 'human' && (int) $viewer_ref === $hirer) {
                return 'hirer';
            }
            $role = (string) ($entity['viewer_role'] ?? 'guest');
            if ($role === 'submitter') {
                return 'submitter';
            }
            return 'guest';
        }
        if ($entity_type === 'open_work_submission') {
            $hirer = (int) ($entity['hirer_user_id'] ?? 0);
            if ($viewer_type === 'human' && (int) $viewer_ref === $hirer) {
                return 'hirer';
            }
            $submitter_type = (string) ($entity['submitter_type'] ?? '');
            $submitter_ref  = (string) ($entity['submitter_ref'] ?? '');
            if ($viewer_type === $submitter_type && $viewer_ref === $submitter_ref) {
                return 'submitter';
            }
            return 'guest';
        }
        return 'party';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function actions_for(string $entity_type, array $entity, string $status, string $role): array {
        if ($entity_type === 'agent_order') {
            return self::order_actions($entity, $status, $role);
        }
        if ($entity_type === 'agent_deal') {
            return self::deal_actions($entity, $status, $role);
        }
        if ($entity_type === 'open_work') {
            return self::open_work_actions($entity, $status, $role);
        }
        if ($entity_type === 'open_work_submission') {
            return self::open_work_submission_actions($entity, $status, $role);
        }
        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function order_actions(array $order, string $status, string $role): array {
        $id = (int) ($order['order_id'] ?? 0);
        if ($id < 1) {
            return [];
        }
        $base = '/agents/orders/' . $id;
        $delivery = (string) ($order['delivery_type'] ?? 'protected');
        $revs = (int) ($order['revision_count'] ?? 0);
        $allow = (int) ($order['revision_allowance'] ?? 2);
        $actions = [];

        if ($status === 'pending_funding' && $role === 'buyer') {
            $actions[] = self::action('fund_order', 'Fund payment', 'POST', $base . '/fund', ['agent_token'], ['settlement_id', 'PAYMENT-SIGNATURE', 'source']);
        }
        if ($status === 'funded' && $role === 'provider') {
            $actions[] = self::action('deliver_order', 'Deliver work', 'POST', $base . '/deliver', ['agent_token'], ['output']);
        }
        if ($status === 'delivered' && $role === 'buyer' && $delivery === 'protected') {
            $actions[] = self::action('release_order', 'Release payment', 'POST', $base . '/release', ['agent_token'], ['Idempotency-Key']);
            if ($revs < $allow) {
                $actions[] = self::action('revise_order', 'Request revision', 'POST', $base . '/revision', ['agent_token'], ['note']);
            }
            $actions[] = self::action('dispute_order', 'Open dispute', 'POST', $base . '/dispute', ['agent_token'], ['reason']);
        }
        if ($status === 'released') {
            $slug = (string) ($order['public_slug'] ?? '');
            if ($slug !== '' && in_array($role, ['buyer', 'provider'], true)) {
                $actions[] = self::action('submit_review', 'Submit rating', 'POST', '/work/reviews', ['agent_token'], ['ref_type', 'ref_id', 'stars', 'comment', 'signer_wallet', 'signature', 'message']);
            }
        }
        if ($status === 'disputed') {
            $actions[] = self::action('open_thread', 'View messages', 'GET', '/work/threads', ['agent_token'], ['ref_type', 'ref_key']);
        }
        return $actions;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function open_work_actions(array $work, string $status, string $role): array {
        $ref = rawurlencode((string) ($work['ref_id'] ?? ''));
        if ($ref === '') {
            return [];
        }
        $mode = sanitize_key((string) ($work['mode'] ?? ''));
        $actions = [];
        $open = in_array($status, ['active', 'open'], true);
        if ($open && $role === 'guest') {
            $optional = ['payload', 'note', 'url'];
            if ($mode === 'bid') {
                $optional[] = 'bid_micro';
            }
            $actions[] = self::action('submit_open_work', 'Submit work', 'POST', '/work/open/' . $ref . '/submit', ['agent_token'], $optional);
        }
        if ($open && $role === 'submitter') {
            $actions[] = self::action('view_open_work', 'View submission', 'GET', '/work/open/' . $ref, ['agent_token'], []);
        }
        if ($role === 'hirer') {
            $actions[] = self::action('review_submissions', 'Review submissions', 'GET', '/work/open/' . $ref . '/submissions', ['agent_token'], ['page', 'per_page']);
            if (in_array($mode, ['prove', 'brief', 'pitch', 'bid'], true)) {
                $actions[] = self::action('accept_submission', 'Accept a submission', 'POST', '/work/open/submissions/{id}/accept', ['agent_token'], []);
                $actions[] = self::action('reject_submission', 'Reject a submission', 'POST', '/work/open/submissions/{id}/reject', ['agent_token'], []);
            }
            if ($mode === 'sprint') {
                $actions[] = self::action('accept_submission', 'Accept first submission', 'POST', '/work/open/submissions/{id}/accept', ['agent_token'], []);
            }
        }
        return $actions;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function open_work_submission_actions(array $submission, string $status, string $role): array {
        $actions = [];
        $sid = (int) ($submission['submission_id'] ?? 0);
        if ($role === 'hirer' && $status === 'accepted' && $sid > 0) {
            $actions[] = self::action(
                'submit_review',
                'Submit rating',
                'POST',
                '/work/reviews',
                ['agent_token'],
                ['ref_type', 'ref_id', 'stars', 'comment', 'signer_wallet', 'signature', 'message']
            );
        }
        if ($role === 'submitter' && $status === 'accepted') {
            $ref = rawurlencode((string) ($submission['ref_id'] ?? ''));
            if ($ref !== '') {
                $actions[] = self::action('view_open_work', 'View work', 'GET', '/work/open/' . $ref, ['agent_token'], []);
            }
        }
        return $actions;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function deal_actions(array $deal, string $status, string $role): array {
        $id = (int) ($deal['agent_deal_id'] ?? $deal['deal_id'] ?? 0);
        if ($id < 1) {
            return [];
        }
        $base = '/agents/deals/' . $id;
        $actions = [];

        if (in_array($status, ['draft', 'pending_funding'], true) && in_array($role, ['buyer', 'seller', 'guest'], true)) {
            $actions[] = self::action('accept_deal', 'Accept deal', 'POST', $base . '/accept', ['agent_token'], ['role', 'invite_token']);
        }
        if ($status === 'pending_funding' && $role === 'buyer') {
            $actions[] = self::action('fund_deal', 'Fund deal', 'POST', $base . '/fund', ['agent_token'], ['settlement_id', 'PAYMENT-SIGNATURE']);
        }
        if ($status === 'funded' && $role === 'seller') {
            $actions[] = self::action('deliver_deal', 'Deliver work', 'POST', $base . '/deliver', ['agent_token'], ['note', 'payload']);
        }
        if ($status === 'delivered' && $role === 'buyer') {
            $actions[] = self::action('release_deal', 'Release payment', 'POST', $base . '/release', ['agent_token'], ['Idempotency-Key']);
        }
        if ($status === 'released') {
            $slug = (string) ($deal['public_slug'] ?? '');
            if ($slug !== '' && in_array($role, ['buyer', 'seller'], true)) {
                $actions[] = self::action('submit_review', 'Submit rating', 'POST', '/work/reviews', ['agent_token'], ['ref_type', 'ref_id', 'stars', 'comment', 'signer_wallet', 'signature', 'message']);
            }
        }
        return $actions;
    }

    /**
     * @param array<int,string> $requires
     * @param array<int,string> $optional
     */
    private static function action(string $id, string $label, string $method, string $path, array $requires = [], array $optional = []): array {
        return [
            'id'        => $id,
            'label'     => $label,
            'method'    => strtoupper($method),
            'path'      => $path,
            'requires'  => array_values($requires),
            'optional'  => array_values($optional),
        ];
    }

    private static function hint_for(string $entity_type, string $status, string $role, array $entity = []): string {
        if ($entity_type === 'agent_order') {
            $map = [
                'pending_funding' => 'Fund this order to start work.',
                'funded'          => $role === 'provider' ? 'Deliver output before the SLA deadline.' : 'Waiting for the provider to deliver.',
                'delivered'       => $role === 'buyer' ? 'Review the delivery and release payment if it matches the brief.' : 'Waiting for the buyer to release payment.',
                'released'        => 'Order complete. Leave a rating while the 14-day window is open.',
                'disputed'        => 'A dispute is open. Use the thread to resolve.',
                'refunded'        => 'This order was refunded.',
            ];
            return $map[$status] ?? '';
        }
        if ($entity_type === 'agent_deal') {
            $map = [
                'draft'           => 'Accept roles and fund when ready.',
                'pending_funding' => 'Fund the deal to lock payment.',
                'funded'          => $role === 'seller' ? 'Deliver the agreed work.' : 'Waiting for delivery.',
                'delivered'       => $role === 'buyer' ? 'Release payment when satisfied.' : 'Waiting for release.',
                'released'        => 'Deal complete. Leave a rating.',
            ];
            return $map[$status] ?? '';
        }
        if ($entity_type === 'open_work') {
            $mode = sanitize_key((string) ($entity['mode'] ?? ''));
            if ($mode === 'sprint') {
                return $role === 'hirer' ? 'First accepted submission wins — act quickly.' : 'Sprint mode: first accepted submission wins.';
            }
            if ($mode === 'bid') {
                return $role === 'hirer' ? 'Compare bids and accept the best offer.' : 'Include bid_micro with your submission.';
            }
            if ($mode === 'prove') {
                return $role === 'hirer' ? 'Review proof submissions and accept the best.' : 'Submit proof of completed work.';
            }
            if ($mode === 'pitch') {
                return $role === 'hirer' ? 'Review pitches and submissions.' : 'Send a pitch before slots fill.';
            }
            if ($role === 'submitter' && !empty($entity['my_submission']['status']) && (string) $entity['my_submission']['status'] === 'accepted') {
                return 'Submission accepted. Leave a rating for the hirer while the 14-day window is open.';
            }
            $map = [
                'active' => $role === 'hirer' ? 'Review incoming submissions.' : 'Submit your work before slots fill.',
                'open'   => $role === 'hirer' ? 'Review pitches and submissions.' : 'Send a pitch or submission.',
            ];
            return $map[$status] ?? '';
        }
        if ($entity_type === 'open_work_submission') {
            $map = [
                'pending'  => 'Waiting for the hirer to review.',
                'accepted' => $role === 'submitter'
                    ? 'Submission accepted. Leave a rating while the 14-day window is open.'
                    : ($role === 'hirer' ? 'Submission accepted. Leave a rating for the worker.' : 'Your submission was accepted.'),
                'rejected' => 'This submission was declined.',
            ];
            return $map[$status] ?? '';
        }
        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     */
    private static function next_step_for(string $entity_type, array $entity, array $actions): string {
        if (!$actions) {
            $status = (string) ($entity['status'] ?? '');
            if ($status === 'released') {
                return 'POST /work/reviews with ref_type agent_' . ($entity_type === 'agent_deal' ? 'deal' : 'order') . ' and ref_id slug.';
            }
            return 'No further actions for your role at this status.';
        }
        $first = $actions[0];
        $req = !empty($first['requires']) ? ' (requires ' . implode(', ', $first['requires']) . ')' : '';
        return $first['method'] . ' ' . $first['path'] . $req;
    }

    /**
     * Build REST response from entity or WP_Error.
     *
     * @param string               $entity_type
     * @param array|WP_Error|null  $entity
     * @param string               $viewer_type
     * @param string               $viewer_ref
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_response(string $entity_type, $entity, string $viewer_type = 'guest', string $viewer_ref = '') {
        if (is_wp_error($entity)) {
            return $entity;
        }
        if (!is_array($entity)) {
            return new WP_Error('invalid', 'Invalid entity.', ['status' => 500]);
        }
        $envelope = self::envelope($entity_type, $entity, $viewer_type, $viewer_ref);
        if (class_exists('Growl_Work_Receipt_Service')) {
            $envelope = Growl_Work_Receipt_Service::attach_to_envelope($envelope, $entity_type, $entity);
        }
        return rest_ensure_response($envelope);
    }
}
