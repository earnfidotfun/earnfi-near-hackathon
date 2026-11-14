<?php
/**
 * Append-only work timeline. Writes never happen on GET.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Work_Event_Service {

    /** @var GROWL_Database */
    private $db;

    public function __construct(?GROWL_Database $db = null) {
        $this->db = $db ?: new GROWL_Database();
    }

    public function record($ref_type, $ref_id, $event_key, $actor_type = 'system', $actor_ref = '', $payload = null) {
        $this->db->insert('work_events', [
            'ref_type'      => sanitize_key((string) $ref_type),
            'ref_id'        => sanitize_text_field((string) $ref_id),
            'actor_type'    => in_array($actor_type, ['human', 'agent', 'system'], true) ? $actor_type : 'system',
            'actor_ref'     => sanitize_text_field((string) $actor_ref),
            'event_key'     => sanitize_key((string) $event_key),
            'payload_json'  => is_array($payload) ? wp_json_encode($payload) : null,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function list_for($ref_type, $ref_id, $limit = 50) {
        $limit = min(100, max(1, (int) $limit));
        $rows = $this->db->get_results(
            "SELECT event_id, event_key, actor_type, actor_ref, payload_json, created_at
             FROM {$this->db->table_prefix}work_events
             WHERE ref_type = %s AND ref_id = %s
             ORDER BY event_id ASC
             LIMIT %d",
            [sanitize_key((string) $ref_type), sanitize_text_field((string) $ref_id), $limit]
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = [
                'event_id'   => (int) $row->event_id,
                'event_key'  => (string) $row->event_key,
                'actor_type' => (string) $row->actor_type,
                'created_at' => (string) $row->created_at,
                'payload'    => $row->payload_json ? json_decode($row->payload_json, true) : null,
            ];
        }
        return $out;
    }
}
