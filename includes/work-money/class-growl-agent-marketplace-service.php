<?php
/**
 * Agent + service marketplace queries. Aggregates only — no N+1.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Agent_Marketplace_Service {

    /** @var GROWL_Database */
    private $db;

    public function __construct(?GROWL_Database $db = null) {
        $this->db = $db ?: new GROWL_Database();
    }

    /**
     * Resolve a public avatar URL for marketplace list rows.
     *
     * @param object|array|null $row
     * @return string
     */
    private function resolve_agent_avatar_url($row) {
        if (!$row) {
            return class_exists('GROWL_Avatar_Service') ? GROWL_Avatar_Service::get_instance()->default_url() : '';
        }
        if (!class_exists('GROWL_Avatar_Service')) {
            return (string) (is_array($row) ? ($row['avatar_url'] ?? '') : ($row->avatar_url ?? ''));
        }
        return GROWL_Avatar_Service::get_instance()->fast_url_for_agent($row);
    }

    public function browse_agents($category = '', $q = '', $sort = 'popular', $limit = 20, $offset = 0, $said_verified = false, $min_rating = 0, $min_price_micro = '', $max_price_micro = '', $model = '', $delivery_type = '') {
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $p      = $this->db->table_prefix;
        $where  = "s.status = 'active'";
        $params = [];
        if ($category !== '' && $category !== 'all') {
            $where   .= ' AND s.category = %s';
            $params[] = sanitize_key($category);
        }
        $q = trim((string) $q);
        if ($q !== '') {
            $like     = '%' . $this->db->wpdb->esc_like($q) . '%';
            $where   .= ' AND (a.agent_name LIKE %s OR a.bio LIKE %s OR s.title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $min_rating = (float) $min_rating;
        if ($min_rating > 0) {
            $where   .= ' AND a.rating_avg >= %f';
            $params[] = $min_rating;
        }
        $delivery_type = sanitize_key((string) $delivery_type);
        if ($delivery_type !== '' && in_array($delivery_type, ['instant', 'protected'], true)) {
            $where   .= ' AND s.delivery_type = %s';
            $params[] = $delivery_type;
        }
        $model = trim((string) $model);
        if ($model !== '') {
            $model_like = '%' . $this->db->wpdb->esc_like($model) . '%';
            $where     .= ' AND (JSON_CONTAINS(a.models_json, %s) OR a.models_json LIKE %s OR s.models_json LIKE %s)';
            $params[]   = wp_json_encode($model);
            $params[]   = $model_like;
            $params[]   = $model_like;
        }
        $having_parts = [];
        $having_params = [];
        if ($min_price_micro !== '') {
            $min_micro = Growl_Money::to_micro($min_price_micro);
            if (Growl_Money::is_positive($min_micro)) {
                $having_parts[]  = 'MIN(s.price_micro) >= %s';
                $having_params[] = Growl_Money::assert_micro($min_micro);
            }
        }
        if ($max_price_micro !== '') {
            $max_micro = Growl_Money::to_micro($max_price_micro);
            if (Growl_Money::is_positive($max_micro)) {
                $having_parts[]  = 'MIN(s.price_micro) <= %s';
                $having_params[] = Growl_Money::assert_micro($max_micro);
            }
        }
        $having = $having_parts ? ' HAVING ' . implode(' AND ', $having_parts) : '';
        $order = 'a.completed_orders DESC, a.rating_avg DESC, a.updated_at DESC';
        if ($sort === 'newest') {
            $order = 'a.updated_at DESC';
        } elseif ($sort === 'price') {
            $order = 'min_price_micro ASC, a.rating_avg DESC';
        } elseif ($sort === 'rating') {
            $order = 'a.rating_avg DESC, a.rating_count DESC, a.completed_orders DESC';
        }
        $sql_limit  = $said_verified ? min(200, max($limit + $offset, $limit * 5)) : $limit;
        $sql_offset = $said_verified ? 0 : $offset;
        $params     = array_merge($params, $having_params);
        $params[] = $sql_limit;
        $params[] = $sql_offset;
        $rows = $this->db->get_results(
            "SELECT a.agent_id, a.agent_name, a.bio, a.avatar_url, a.wallet_address, a.website,
                    a.models_json, a.rating_avg, a.rating_count, a.completed_orders,
                    a.x_username, a.identity_provider, a.identity_reference, a.capabilities,
                    MIN(s.price_micro) AS min_price_micro,
                    COUNT(s.service_id) AS service_count,
                    GROUP_CONCAT(DISTINCT s.category ORDER BY s.category SEPARATOR ',') AS categories,
                    GROUP_CONCAT(DISTINCT s.title ORDER BY s.title SEPARATOR '\\n') AS service_titles
             FROM {$p}ai_agents a
             INNER JOIN {$p}agent_services s ON s.agent_id = a.agent_id AND s.status = 'active'
             WHERE {$where}
             GROUP BY a.agent_id{$having}
             ORDER BY {$order}
             LIMIT %d OFFSET %d",
            $params
        );
        $out = [];
        $wallets = [];
        $agent_rows_by_wallet = [];
        foreach ($rows ?: [] as $row) {
            $models = $row->models_json ? json_decode($row->models_json, true) : [];
            $skills = array_values(array_filter(array_map('trim', explode("\n", (string) $row->service_titles))));
            $wallet = (string) ($row->wallet_address ?? '');
            if ($wallet !== '') {
                $wallets[] = $wallet;
                $agent_rows_by_wallet[$wallet] = $row;
            }
            $identity_url = '';
            if ((string) ($row->identity_provider ?? '') === 'said' && $wallet !== '' && class_exists('EarnFi_Said_Identity')) {
                $identity_url = EarnFi_Said_Identity::public_profile_url($wallet);
            }
            $out[] = [
                'agent_id'         => (string) $row->agent_id,
                'agent_name'       => (string) $row->agent_name,
                'bio'              => (string) ($row->bio ?? ''),
                'avatar_url'       => $this->resolve_agent_avatar_url($row),
                'banner_url'       => '',
                'website'          => class_exists('EarnFi_Said_Identity')
                    ? EarnFi_Said_Identity::sanitize_agent_website((string) ($row->website ?? ''))
                    : (string) ($row->website ?? ''),
                'wallet_short'     => $wallet ? substr($wallet, 0, 4) . '…' : '',
                'wallet_address'   => $wallet,
                'x_username'       => trim((string) ($row->x_username ?? '')),
                'identity_provider'=> (string) ($row->identity_provider ?? ''),
                'identity_profile_url' => $identity_url,
                'models'           => is_array($models) ? $models : [],
                'model_count'      => is_array($models) ? count($models) : 0,
                'skills'           => $skills,
                'skill_count'      => count($skills),
                'service_count'    => (int) $row->service_count,
                'categories'       => array_values(array_filter(explode(',', (string) $row->categories))),
                'min_price_display'=> Growl_Money::to_display($row->min_price_micro),
                'rating_avg'       => round((float) $row->rating_avg, 2),
                'rating_count'     => (int) $row->rating_count,
                'completed_orders' => (int) $row->completed_orders,
                'said_verified'    => false,
                'said_reputation_score' => null,
            ];
        }
        $said_map = [];
        if ($wallets && class_exists('EarnFi_Said_Identity')) {
            $said_svc = EarnFi_Said_Identity::get_instance();
            $said_map = $said_svc->batch_verified_flags(array_values(array_unique($wallets)), $agent_rows_by_wallet);
        }
        $i = 0;
        foreach ($rows ?: [] as $row) {
            $w = (string) ($row->wallet_address ?? '');
            $verified = $w !== '' && !empty($said_map[$w]);
            if (!$verified && class_exists('EarnFi_Said_Identity')) {
                $said_svc = EarnFi_Said_Identity::get_instance();
                if (!$verified && $row) {
                    $verified = $said_svc->verified_from_agent_row($row);
                }
            }
            if (!$verified && (string) ($row->identity_provider ?? '') === 'said' && $w !== '') {
                $verified = true;
            }
            $out[$i]['said_verified'] = $verified;
            if ($w !== '' && class_exists('EarnFi_Said_Identity') && ($verified || (string) ($row->identity_provider ?? '') === 'said')) {
                $out[$i]['identity_profile_url'] = EarnFi_Said_Identity::public_profile_url($w);
            }
            if ($w !== '' && class_exists('EarnFi_Said_Identity')) {
                $said_data = EarnFi_Said_Identity::get_instance()->lookup_transient_only($w, $row);
                if (is_array($said_data) && !empty($said_data['reputation_score'])) {
                    $out[$i]['said_reputation_score'] = (int) $said_data['reputation_score'];
                }
            }
            $i++;
        }
        if ($said_verified) {
            $out = array_values(array_filter($out, static function ($a) {
                return !empty($a['said_verified']);
            }));
            $out = array_slice($out, $offset, $limit);
        }
        return $out;
    }

    public function agent_services($agent_id) {
        $agent_id = sanitize_text_field((string) $agent_id);
        $p = $this->db->table_prefix;
        $agent = $this->db->get_row(
            "SELECT agent_id, agent_name, bio, avatar_url, website, models_json, rating_avg, rating_count,
                    completed_orders, wallet_address, x_username, identity_provider, identity_reference, capabilities
             FROM {$p}ai_agents WHERE agent_id = %s LIMIT 1",
            [$agent_id]
        );
        if (!$agent) {
            return new WP_Error('not_found', 'Agent not found.', ['status' => 404]);
        }
        $rows = $this->db->get_results(
            "SELECT * FROM {$p}agent_services WHERE agent_id = %s AND status = 'active' ORDER BY updated_at DESC",
            [$agent_id]
        );
        $services = [];
        foreach ($rows ?: [] as $row) {
            $services[] = $this->format_service($row, $agent);
        }
        $models = $agent->models_json ? json_decode($agent->models_json, true) : [];
        $wallet = (string) ($agent->wallet_address ?? '');
        $said = false;
        $said_reputation = null;
        if ($wallet !== '' && class_exists('EarnFi_Said_Identity')) {
            $said_svc = EarnFi_Said_Identity::get_instance();
            $flags = $said_svc->batch_verified_flags([$wallet], [$wallet => $agent]);
            $said = !empty($flags[$wallet]);
            if (!$said) {
                $said = $said_svc->verified_from_agent_row($agent);
            }
            if (!$said && (string) ($agent->identity_provider ?? '') === 'said') {
                $said = true;
            }
            $said_data = $said_svc->lookup_transient_only($wallet, $agent);
            if (is_array($said_data) && !empty($said_data['reputation_score'])) {
                $said_reputation = (int) $said_data['reputation_score'];
            }
        }
        $skills = array_values(array_unique(array_filter(array_map(static function ($svc) {
            return trim((string) ($svc['title'] ?? ''));
        }, $services))));
        $min_price = null;
        foreach ($services as $svc) {
            $pd = (string) ($svc['price_display'] ?? '');
            if ($pd === '') {
                continue;
            }
            if ($min_price === null || (float) $pd < (float) $min_price) {
                $min_price = $pd;
            }
        }
        $identity_url = ((string) ($agent->identity_provider ?? '') === 'said' || $said) && $wallet !== '' && class_exists('EarnFi_Said_Identity')
            ? EarnFi_Said_Identity::public_profile_url($wallet)
            : '';
        return [
            'agent' => [
                'agent_id'         => (string) $agent->agent_id,
                'agent_name'       => (string) $agent->agent_name,
                'bio'              => (string) ($agent->bio ?? ''),
                'avatar_url'       => $this->resolve_agent_avatar_url($agent),
                'banner_url'       => '',
                'website'          => class_exists('EarnFi_Said_Identity')
                    ? EarnFi_Said_Identity::sanitize_agent_website((string) ($agent->website ?? ''))
                    : (string) ($agent->website ?? ''),
                'wallet_address'   => $wallet,
                'x_username'       => trim((string) ($agent->x_username ?? '')),
                'identity_provider'=> (string) ($agent->identity_provider ?? ''),
                'identity_profile_url' => $identity_url,
                'models'           => is_array($models) ? $models : [],
                'model_count'      => is_array($models) ? count($models) : 0,
                'skills'           => $skills,
                'skill_count'      => count($skills),
                'service_count'    => count($services),
                'min_price_display'=> $min_price ?? '0.00',
                'rating_avg'       => round((float) $agent->rating_avg, 2),
                'rating_count'     => (int) $agent->rating_count,
                'completed_orders' => (int) $agent->completed_orders,
                'said_verified'    => $said,
                'said_reputation_score' => $said_reputation,
            ],
            'services' => $services,
        ];
    }

    public function format_service($row, $agent = null) {
        $models = !empty($row->models_json) ? json_decode($row->models_json, true) : [];
        $deliverables = !empty($row->deliverables_json) ? json_decode($row->deliverables_json, true) : [];
        $caps = !empty($row->capabilities_json) ? json_decode($row->capabilities_json, true) : [];
        $sla = (int) ($row->sla_seconds ?? 86400);
        return [
            'service_id'         => (int) $row->service_id,
            'agent_id'           => (string) $row->agent_id,
            'agent_name'         => $agent ? (string) ($agent->agent_name ?? $agent->agent_name ?? '') : (string) ($row->agent_name ?? ''),
            'avatar_url'         => $agent ? $this->resolve_agent_avatar_url($agent) : $this->resolve_agent_avatar_url($row),
            'category'           => (string) $row->category,
            'title'              => (string) $row->title,
            'description'        => (string) ($row->description ?? ''),
            'price_display'      => Growl_Money::to_display($row->price_micro),
            'price_micro'        => (string) $row->price_micro,
            'delivery_type'      => (string) $row->delivery_type,
            'revision_allowance' => (int) ($row->revision_count ?? 2),
            'sla_seconds'        => $sla,
            'sla_label'          => $this->sla_label($sla),
            'models'             => is_array($models) ? $models : [],
            'deliverables'       => is_array($deliverables) ? $deliverables : [],
            'capabilities'       => is_array($caps) ? $caps : [],
            'rating_avg'         => round((float) ($row->rating_avg ?? 0), 2),
            'rating_count'       => (int) ($row->rating_count ?? 0),
            'completed_count'    => (int) ($row->completed_count ?? 0),
            'status'             => (string) ($row->status ?? 'active'),
            'input_schema'       => !empty($row->input_schema_json) ? json_decode($row->input_schema_json, true) : null,
            'output_schema'      => !empty($row->output_schema_json) ? json_decode($row->output_schema_json, true) : null,
        ];
    }

    public function sla_label($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 3600) {
            return '~' . max(1, (int) round($seconds / 60)) . 'm delivery';
        }
        if ($seconds < 86400) {
            return '~' . max(1, (int) round($seconds / 3600)) . 'h delivery';
        }
        return '~' . max(1, (int) round($seconds / 86400)) . 'd delivery';
    }

    public function refresh_agent_denorm($agent_id) {
        $p = $this->db->table_prefix;
        $rows = $this->db->get_results(
            "SELECT models_json, capabilities_json FROM {$p}agent_services WHERE agent_id = %s AND status = 'active'",
            [sanitize_text_field((string) $agent_id)]
        );
        $models = [];
        foreach ($rows ?: [] as $row) {
            $m = $row->models_json ? json_decode($row->models_json, true) : [];
            if (is_array($m)) {
                foreach ($m as $name) {
                    $name = sanitize_text_field((string) $name);
                    if ($name !== '') {
                        $models[$name] = true;
                    }
                }
            }
        }
        $this->db->update('ai_agents', [
            'models_json' => wp_json_encode(array_keys($models)),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ], ['agent_id' => sanitize_text_field((string) $agent_id)]);
    }

    /**
     * Public marketplace pulse stats (cached).
     *
     * @return array<string,mixed>
     */
    public function get_stats() {
        $cached = get_transient('earnfi_marketplace_stats');
        if (is_array($cached)) {
            return $cached;
        }
        $p = $this->db->table_prefix;
        $row = $this->db->get_row(
            "SELECT
                (SELECT COUNT(DISTINCT agent_id) FROM {$p}agent_services WHERE status = 'active') AS agents_listed,
                (SELECT COUNT(*) FROM {$p}agent_services WHERE status = 'active') AS services_live,
                (SELECT COALESCE(SUM(completed_orders), 0) FROM {$p}ai_agents) AS orders_completed,
                (SELECT COALESCE(SUM(amount_micro), 0) FROM {$p}escrow_ledger WHERE status = 'locked' AND ref_type = 'agent_order') AS usdc_held_micro"
        );
        $held = Growl_Money::to_display($row->usdc_held_micro ?? '0');
        $stats = [
            'agents_listed'    => (int) ($row->agents_listed ?? 0),
            'services_live'    => (int) ($row->services_live ?? 0),
            'orders_completed' => (int) ($row->orders_completed ?? 0),
            'usdc_held_display'=> $held,
            'updated_at'       => gmdate('c'),
        ];
        set_transient('earnfi_marketplace_stats', $stats, 60);
        return $stats;
    }

    /**
     * Agent leaderboard (single query + batch SAID).
     *
     * @return array{items:array<int,array<string,mixed>>,page:int,per_page:int}
     */
    public function leaderboard($period = 'all', $limit = 20, $offset = 0) {
        $cache_key = 'earnfi_leaderboard_' . sanitize_key($period) . '_' . (int) $limit . '_' . (int) $offset;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $limit  = min(50, max(1, (int) $limit));
        $offset = max(0, (int) $offset);
        $p = $this->db->table_prefix;
        $where = 'WHERE (completed_orders > 0 OR rating_count > 0)';
        if ($period === '30d') {
            $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
            $where = $this->db->wpdb->prepare(
                "WHERE agent_id IN (
                    SELECT DISTINCT provider_agent_id FROM {$p}agent_orders
                    WHERE status = 'released' AND updated_at >= %s
                )",
                $since
            );
        }
        $rows = $this->db->get_results(
            "SELECT agent_id, agent_name, avatar_url, wallet_address, x_username, identity_provider,
                    completed_orders, rating_avg, rating_count
             FROM {$p}ai_agents
             {$where}
             ORDER BY (completed_orders * 2 + rating_avg * rating_count) DESC, rating_avg DESC
             LIMIT %d OFFSET %d",
            [$limit, $offset]
        );
        $wallets = [];
        $agent_rows = [];
        foreach ($rows ?: [] as $row) {
            $w = (string) ($row->wallet_address ?? '');
            if ($w !== '') {
                $wallets[] = $w;
                $agent_rows[$w] = $row;
            }
        }
        $said_map = ($wallets && class_exists('EarnFi_Said_Identity'))
            ? EarnFi_Said_Identity::get_instance()->batch_verified_flags(array_values(array_unique($wallets)), $agent_rows)
            : [];
        $items = [];
        $rank = $offset + 1;
        foreach ($rows ?: [] as $row) {
            $w = (string) ($row->wallet_address ?? '');
            $said_verified = $w !== '' && !empty($said_map[$w]);
            $identity_url = $said_verified && class_exists('EarnFi_Said_Identity')
                ? EarnFi_Said_Identity::public_profile_url($w)
                : '';
            $items[] = [
                'rank'             => $rank++,
                'agent_id'         => (string) $row->agent_id,
                'agent_name'       => (string) $row->agent_name,
                'avatar_url'       => $this->resolve_agent_avatar_url($row),
                'banner_url'       => '',
                'wallet_address'   => $w,
                'x_username'       => trim((string) ($row->x_username ?? '')),
                'identity_profile_url' => $identity_url,
                'completed_orders' => (int) $row->completed_orders,
                'rating_avg'       => round((float) $row->rating_avg, 2),
                'rating_count'     => (int) $row->rating_count,
                'said_verified'    => $said_verified,
            ];
        }
        $out = [
            'items'    => $items,
            'page'     => (int) floor($offset / $limit) + 1,
            'per_page' => $limit,
            'period'   => $period === '30d' ? '30d' : 'all',
        ];
        set_transient($cache_key, $out, 120);
        return $out;
    }
}
