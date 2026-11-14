<?php
/**
 * Shared idempotency cache for Agent API and Work API paid creates.
 */
if (!defined('ABSPATH')) {
    exit;
}

class EarnFi_Idempotency {

    private const TTL_HOURS = 24;
    private const MAX_KEY_LEN = 64;

    /**
     * Resolve idempotency key from Idempotency-Key header or request param.
     */
    public static function resolve_key(WP_REST_Request $request): string {
        $header = $request->get_header('idempotency-key');
        if (!is_string($header)) {
            $header = $request->get_header('Idempotency-Key');
        }
        $raw = is_string($header) ? trim($header) : '';
        if ($raw === '') {
            $raw = sanitize_text_field((string) ($request->get_param('idempotency_key') ?? ''));
        }
        if ($raw === '') {
            return '';
        }
        return substr($raw, 0, self::MAX_KEY_LEN);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key, string $resource) {
        $key = trim($key);
        $resource = trim($resource);
        if ($key === '' || $resource === '') {
            return null;
        }
        $db = new GROWL_Database();
        $table = $db->table_prefix . 'earnfi_idempotency';
        $row = $db->get_row(
            "SELECT response_body FROM {$table} WHERE idempotency_key = %s AND resource = %s AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            [$key, $resource, self::TTL_HOURS]
        );
        if (!$row || empty($row->response_body)) {
            return null;
        }
        $data = json_decode($row->response_body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $response_data
     */
    public static function put(string $key, string $resource, array $response_data): void {
        $key = trim($key);
        $resource = trim($resource);
        if ($key === '' || $resource === '') {
            return;
        }
        $body = wp_json_encode($response_data);
        if (!is_string($body)) {
            return;
        }
        $db = new GROWL_Database();
        $existing = $db->get_row(
            "SELECT idempotency_key FROM {$db->table_prefix}earnfi_idempotency WHERE idempotency_key = %s AND resource = %s",
            [$key, $resource]
        );
        if ($existing) {
            $db->update('earnfi_idempotency', [
                'response_hash' => md5($body),
                'response_body' => $body,
                'created_at'    => current_time('mysql'),
            ], [
                'idempotency_key' => $key,
                'resource'        => $resource,
            ]);
            return;
        }
        $db->insert('earnfi_idempotency', [
            'idempotency_key' => $key,
            'resource'        => $resource,
            'response_hash'   => md5($body),
            'response_body'   => $body,
        ]);
    }
}
