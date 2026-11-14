<?php
/**
 * Persists x402 v2 402 bodies so PayAI verify/settle can echo resource + bazaar extensions.
 */
if (!defined('ABSPATH')) {
    exit;
}

class PayAI_X402_Quote_Store {

    private const TRANSIENT_PREFIX = 'earnfi_x402_quote_';
    private const DEFAULT_TTL = 180;
    private const LOG_RING = 'earnfi_x402_bazaar_log';

    /**
     * @param array<string, mixed> $requirements
     */
    public static function resolve_key(array $requirements, ?string $intent_id = null): string {
        $intent_id = $intent_id !== null ? trim($intent_id) : '';
        if ($intent_id === '') {
            $extra = isset($requirements['extra']) && is_array($requirements['extra'])
                ? $requirements['extra']
                : [];
            $intent_id = sanitize_text_field((string) ($extra['intentId'] ?? ''));
        }
        if ($intent_id !== '') {
            return 'intent:' . $intent_id;
        }
        $canonical = self::canonicalize([
            'scheme'  => $requirements['scheme'] ?? '',
            'network' => $requirements['network'] ?? '',
            'amount'  => (string) ($requirements['amount'] ?? $requirements['maxAmountRequired'] ?? ''),
            'asset'   => $requirements['asset'] ?? '',
            'payTo'   => $requirements['payTo'] ?? '',
            'extra'   => isset($requirements['extra']) && is_array($requirements['extra'])
                ? self::canonicalize($requirements['extra'])
                : [],
        ]);
        return 'req:' . hash('sha256', wp_json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $x402_body Full 402 JSON (resource, accepts, extensions, …)
     */
    public static function save(string $quote_key, array $x402_body, int $ttl_seconds = self::DEFAULT_TTL): void {
        if ($quote_key === '') {
            return;
        }
        $payload = [
            'saved_at' => time(),
            'body'     => $x402_body,
        ];
        set_transient(self::TRANSIENT_PREFIX . md5($quote_key), $payload, max(60, min(600, $ttl_seconds)));

        if (strpos($quote_key, 'intent:') === 0) {
            $intent_id = substr($quote_key, 7);
            if ($intent_id !== '' && class_exists('GROWL_Database', false)) {
                $db = new GROWL_Database();
                $db->ensure_payai_funding_tables();
                $json = wp_json_encode($x402_body, JSON_UNESCAPED_SLASHES);
                $db->wpdb->update(
                    $db->table_prefix . 'funding_intents',
                    ['quote_json' => $json, 'updated_at' => current_time('mysql', true)],
                    ['intent_id' => $intent_id]
                );
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function load(string $quote_key): ?array {
        if ($quote_key === '') {
            return null;
        }
        $cached = get_transient(self::TRANSIENT_PREFIX . md5($quote_key));
        if (is_array($cached) && !empty($cached['body']) && is_array($cached['body'])) {
            return self::normalize_stored_body($cached['body']);
        }
        if (strpos($quote_key, 'intent:') === 0) {
            $intent_id = substr($quote_key, 7);
            if ($intent_id !== '' && class_exists('GROWL_Database', false)) {
                $db = new GROWL_Database();
                $row = $db->get_row(
                    "SELECT quote_json FROM {$db->table_prefix}funding_intents WHERE intent_id = %s LIMIT 1",
                    [$intent_id]
                );
                if ($row && !empty($row->quote_json)) {
                    $decoded = json_decode((string) $row->quote_json, true);
                    if (is_array($decoded) && (!empty($decoded['accepts']) || !empty($decoded['resource']))) {
                        return self::normalize_stored_body($decoded);
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function normalize_stored_body(array $body): array {
        if (!empty($body['resource']['url']) && function_exists('earnfi_x402_normalize_resource_url')) {
            $body['resource']['url'] = earnfi_x402_normalize_resource_url((string) $body['resource']['url']);
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $requirements
     * @return array<string, mixed>|null
     */
    public static function load_for_requirements(array $requirements): ?array {
        return self::load(self::resolve_key($requirements));
    }

    /**
     * Load or rebuild the full x402 v2 body for PayAI /verify and /settle (resource + bazaar required).
     *
     * @param array<string, mixed> $requirements
     * @return array<string, mixed>|null
     */
    public static function resolve_stored_for_settlement(array $requirements): ?array {
        if (!is_array($requirements)) {
            return null;
        }
        $body = self::load_for_requirements($requirements);
        if ($body === null) {
            $extra = isset($requirements['extra']) && is_array($requirements['extra']) ? $requirements['extra'] : [];
            $intent_id = sanitize_text_field((string) ($extra['intentId'] ?? ''));
            if ($intent_id !== '') {
                $body = self::load('intent:' . $intent_id);
            }
        }
        if ($body === null) {
            $extra = isset($requirements['extra']) && is_array($requirements['extra']) ? $requirements['extra'] : [];
            $fp = sanitize_text_field((string) ($extra['quoteFingerprint'] ?? ''));
            if ($fp !== '') {
                $body = self::load('fp:' . $fp);
            }
        }
        if ($body === null) {
            $extra = isset($requirements['extra']) && is_array($requirements['extra']) ? $requirements['extra'] : [];
            if (!empty($extra['intentId'])) {
                $body = self::synthesize_intent_x402_body($requirements);
            }
        }
        if (!is_array($body)) {
            return null;
        }
        return self::ensure_bazaar_extensions($body, $requirements);
    }

    /**
     * @param array<string, mixed> $requirements
     * @return array<string, mixed>
     */
    public static function synthesize_intent_x402_body(array $requirements): array {
        $extra = isset($requirements['extra']) && is_array($requirements['extra']) ? $requirements['extra'] : [];
        $purpose = sanitize_key((string) ($extra['purpose'] ?? 'job_create'));
        $url = class_exists('Earnfi_X402_Paid_Resources', false)
            ? Earnfi_X402_Paid_Resources::intent_probe_url_for_purpose($purpose)
            : '';
        if ($url === '') {
            $url = function_exists('earnfi_x402_canonical_resource_url')
                ? earnfi_x402_canonical_resource_url('ai-agent/v1/x402/job/create')
                : '';
        }
        $resource = Earnfi_X402_Bazaar::resource_meta($url, 'EarnFi funding (x402 intent)');
        $accept = $requirements;
        if (!isset($accept['amount']) && isset($accept['maxAmountRequired'])) {
            $accept['amount'] = (string) $accept['maxAmountRequired'];
        }
        $schema = ['type' => 'object'];
        if (class_exists('Earnfi_Work_REST_API', false)) {
            $schema = (new Earnfi_Work_REST_API())->get_growl_v1_x402_extensions_schema();
        }
        $extensions = Earnfi_X402_Bazaar::extensions(
            'POST',
            $schema,
            ['ok' => true, 'paid' => true, 'transaction_hash' => '…']
        );
        return Earnfi_X402_Bazaar::payment_required_body($resource, [$accept], $extensions);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $requirements
     * @return array<string, mixed>
     */
    public static function ensure_bazaar_extensions(array $body, array $requirements): array {
        $body = self::normalize_stored_body($body);
        if (empty($body['resource']['url']) && class_exists('Earnfi_X402_Paid_Resources', false)) {
            $extra = isset($requirements['extra']) && is_array($requirements['extra']) ? $requirements['extra'] : [];
            $purpose = sanitize_key((string) ($extra['purpose'] ?? 'job_create'));
            $url = Earnfi_X402_Paid_Resources::intent_probe_url_for_purpose($purpose);
            $body['resource'] = Earnfi_X402_Bazaar::resource_meta($url, 'EarnFi funding (x402)');
        }
        if (empty($body['accepts']) || !is_array($body['accepts'])) {
            $accept = $requirements;
            if (!isset($accept['amount']) && isset($accept['maxAmountRequired'])) {
                $accept['amount'] = (string) $accept['maxAmountRequired'];
            }
            $body['accepts'] = [$accept];
        }
        if (self::extensions_empty($body['extensions'] ?? null)) {
            $schema = ['type' => 'object'];
            if (class_exists('Earnfi_Work_REST_API', false)) {
                $schema = (new Earnfi_Work_REST_API())->get_growl_v1_x402_extensions_schema();
            }
            $body['extensions'] = Earnfi_X402_Bazaar::extensions(
                'POST',
                $schema,
                ['ok' => true, 'paid' => true, 'transaction_hash' => '…']
            );
        }
        if (empty($body['x402Version'])) {
            $body['x402Version'] = 2;
        }
        return $body;
    }

    /**
     * Build facilitator request bodies for /verify and /settle.
     *
     * @param array<string, mixed>      $requirements
     * @param array<string, mixed>|null $stored_402
     * @param array<string, mixed>|null $payment_payload_v2 Client-supplied full v2 payload
     * @return array{paymentPayload: array<string, mixed>, paymentRequirements: array<string, mixed>}
     */
    public static function build_facilitator_request(
        string $signed_tx,
        array $requirements,
        ?array $stored_402 = null,
        ?array $payment_payload_v2 = null
    ): array {
        if ($stored_402 === null) {
            $stored_402 = self::resolve_stored_for_settlement($requirements);
        } else {
            $stored_402 = self::ensure_bazaar_extensions($stored_402, $requirements);
        }

        $normalized = PayAI_Payments::normalize_requirements_v2_public($requirements);
        $accepted = self::accepted_from_requirements($requirements, $normalized, $stored_402);

        if (is_array($payment_payload_v2) && !empty($payment_payload_v2['payload']['transaction'])) {
            $payload = $payment_payload_v2;
            $payload['payload']['transaction'] = $signed_tx;
            if (empty($payload['x402Version'])) {
                $payload['x402Version'] = 2;
            }
            if (empty($payload['accepted'])) {
                $payload['accepted'] = $accepted;
            }
            if ($stored_402 !== null) {
                if (empty($payload['resource']) && !empty($stored_402['resource'])) {
                    $payload['resource'] = $stored_402['resource'];
                }
                if (self::extensions_empty($payload['extensions'] ?? null) && !self::extensions_empty($stored_402['extensions'] ?? null)) {
                    $payload['extensions'] = $stored_402['extensions'];
                }
            }
        } elseif (is_array($payment_payload_v2)
            && !empty($payment_payload_v2['payload']['authorization'])
            && !empty($payment_payload_v2['payload']['signature'])) {
            $payload = $payment_payload_v2;
            if (empty($payload['x402Version'])) {
                $payload['x402Version'] = 2;
            }
            if (empty($payload['accepted'])) {
                $payload['accepted'] = $accepted;
            }
            if ($stored_402 !== null) {
                if (empty($payload['resource']) && !empty($stored_402['resource'])) {
                    $payload['resource'] = $stored_402['resource'];
                }
                if (self::extensions_empty($payload['extensions'] ?? null) && !self::extensions_empty($stored_402['extensions'] ?? null)) {
                    $payload['extensions'] = $stored_402['extensions'];
                }
            }
        } else {
            $extensions = new stdClass();
            if ($stored_402 !== null && !self::extensions_empty($stored_402['extensions'] ?? null)) {
                $extensions = $stored_402['extensions'];
            }
            $payload = [
                'x402Version' => 2,
                'accepted'    => $accepted,
                'payload'     => ['transaction' => $signed_tx],
                'extensions'  => $extensions,
            ];
            if ($stored_402 !== null && !empty($stored_402['resource']) && is_array($stored_402['resource'])) {
                $payload['resource'] = $stored_402['resource'];
            }
        }

        if (is_array($stored_402)) {
            if (!self::extensions_empty($stored_402['extensions'] ?? null)) {
                $payload['extensions'] = $stored_402['extensions'];
            }
            if (!empty($stored_402['resource']) && is_array($stored_402['resource'])) {
                $payload['resource'] = $stored_402['resource'];
            }
        }

        if (!empty($payload['resource']['url']) && function_exists('earnfi_x402_normalize_resource_url')) {
            $payload['resource']['url'] = earnfi_x402_normalize_resource_url((string) $payload['resource']['url']);
        }

        return [
            'paymentPayload'       => $payload,
            'paymentRequirements'  => $normalized,
        ];
    }

    /**
     * @param array<string, mixed> $requirements
     * @param array<string, mixed> $normalized
     * @param array<string, mixed>|null $stored_402
     * @return array<string, mixed>
     */
    private static function accepted_from_requirements(array $requirements, array $normalized, ?array $stored_402): array {
        $accept = null;
        if ($stored_402 !== null && !empty($stored_402['accepts']) && is_array($stored_402['accepts'])) {
            $indices = self::find_matching_accept_indices($requirements, $stored_402['accepts']);
            if (count($indices) === 1 && isset($stored_402['accepts'][$indices[0]])) {
                $accept = $stored_402['accepts'][$indices[0]];
            } elseif (count($indices) === 0 && count($stored_402['accepts']) === 1 && !empty($stored_402['accepts'][0]) && is_array($stored_402['accepts'][0])) {
                // Backward compatibility: single-accept quotes when client omits fields present only on stored accept.
                $accept = $stored_402['accepts'][0];
            }
        }
        if ($accept === null) {
            $accept = $requirements;
        }
        $merged = array_merge($accept, [
            'scheme'  => $normalized['scheme'] ?? ($accept['scheme'] ?? 'exact'),
            'network' => $normalized['network'] ?? ($accept['network'] ?? ''),
            'amount'  => $normalized['amount'] ?? ($accept['amount'] ?? '0'),
            'asset'   => $normalized['asset'] ?? ($accept['asset'] ?? ''),
            'payTo'   => $normalized['payTo'] ?? ($accept['payTo'] ?? ''),
            'maxTimeoutSeconds' => $normalized['maxTimeoutSeconds'] ?? ($accept['maxTimeoutSeconds'] ?? 60),
        ]);
        if (isset($requirements['maxAmountRequired'])) {
            $merged['maxAmountRequired'] = (string) $requirements['maxAmountRequired'];
        }
        if (isset($normalized['extra']) && is_array($normalized['extra'])) {
            $merged['extra'] = array_merge(
                isset($accept['extra']) && is_array($accept['extra']) ? $accept['extra'] : [],
                $normalized['extra']
            );
        }
        return $merged;
    }

    /**
     * @param mixed $extensions
     */
    public static function extensions_empty($extensions): bool {
        if ($extensions === null) {
            return true;
        }
        if ($extensions instanceof stdClass) {
            return count(get_object_vars($extensions)) === 0;
        }
        return !is_array($extensions) || count($extensions) === 0;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function canonicalize($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $item) {
            $value[$k] = self::canonicalize($item);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $facilitator_response
     */
    public static function log_bazaar_header(array $facilitator_response, string $context): void {
        $ext = $facilitator_response['_extension_responses'] ?? null;
        if (!is_array($ext)) {
            return;
        }
        $entry = [
            'at'      => gmdate('c'),
            'context' => $context,
            'bazaar'  => $ext['bazaar'] ?? $ext,
        ];
        $ring = get_transient(self::LOG_RING);
        if (!is_array($ring)) {
            $ring = [];
        }
        array_unshift($ring, $entry);
        $ring = array_slice($ring, 0, 50);
        set_transient(self::LOG_RING, $ring, DAY_IN_SECONDS);
        if (!empty($entry['bazaar']['status']) && $entry['bazaar']['status'] === 'rejected') {
            error_log('PayAI Bazaar rejected: ' . wp_json_encode($entry['bazaar']));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent_bazaar_log(): array {
        $ring = get_transient(self::LOG_RING);
        return is_array($ring) ? $ring : [];
    }

    /**
     * @param array<string, mixed> $requirements Signed requirement / accepted fields.
     * @param array<int, array<string, mixed>> $accepts
     * @return int[] matching indices
     */
    public static function find_matching_accept_indices(array $requirements, array $accepts): array {
        $matches = [];
        foreach ($accepts as $idx => $accept) {
            if (!is_array($accept)) {
                continue;
            }
            if (self::requirements_match_accept($requirements, $accept)) {
                $matches[] = (int) $idx;
            }
        }
        return $matches;
    }

    /**
     * Canonical match for scheme, network, asset, payTo, amount (atomic string).
     *
     * @param array<string, mixed> $requirements
     * @param array<string, mixed> $accept
     */
    public static function requirements_match_accept(array $requirements, array $accept): bool {
        $fields = ['scheme', 'network', 'asset', 'payTo'];
        foreach ($fields as $field) {
            $a = isset($accept[$field]) ? (string) $accept[$field] : '';
            $r = isset($requirements[$field]) ? (string) $requirements[$field] : '';
            if ($a === '' || $r === '') {
                continue;
            }
            if ($a !== $r) {
                return false;
            }
        }
        $amount_a = (string) ($accept['amount'] ?? $accept['maxAmountRequired'] ?? '');
        $amount_r = (string) ($requirements['amount'] ?? $requirements['maxAmountRequired'] ?? '');
        if ($amount_a !== '' && $amount_r !== '' && $amount_a !== $amount_r) {
            return false;
        }
        $intent_a = '';
        $intent_r = '';
        if (!empty($accept['extra']['intentId'])) {
            $intent_a = (string) $accept['extra']['intentId'];
        }
        if (!empty($requirements['extra']['intentId'])) {
            $intent_r = (string) $requirements['extra']['intentId'];
        }
        if ($intent_a !== '' && $intent_r !== '' && $intent_a !== $intent_r) {
            return false;
        }
        return true;
    }

    /**
     * Persist 402 and return quote key for callers.
     *
     * @param array<string, mixed> $x402_body
     */
    public static function persist_402_response(array $x402_body, ?array $accept_override = null, int $ttl = self::DEFAULT_TTL): string {
        if ($accept_override !== null) {
            $x402_body['accepts'] = [$accept_override];
        }
        $accepts = $x402_body['accepts'] ?? [];
        if (!is_array($accepts) || $accepts === []) {
            return '';
        }
        $primary_key = '';
        foreach ($accepts as $accept) {
            if (!is_array($accept)) {
                continue;
            }
            $key = self::resolve_key($accept);
            if ($key === '') {
                continue;
            }
            self::save($key, $x402_body, $ttl);
            if ($primary_key === '') {
                $primary_key = $key;
            }
        }
        $fp = sanitize_text_field((string) ($x402_body['earnfi_quote_fingerprint'] ?? ''));
        if ($fp !== '') {
            self::save('fp:' . $fp, $x402_body, $ttl);
        }
        return $primary_key;
    }
}
