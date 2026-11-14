<?php
/**
 * Multi-accept x402 quotes for priced Work / Agent creates (Solana-first + Base / NEAR when enabled).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Priced_Quote {

    public const DEFAULT_TIMEOUT_SECONDS = 60;

    /**
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public static function build_accepts(string $resource_url, string $atomic, ?string $resource_description = null, ?string $network_filter = null) {
        $atomic = preg_replace('/\D/', '', $atomic) ?? '';
        if ($atomic === '' || $atomic === '0') {
            return new WP_Error('invalid_amount', 'Payment amount is invalid.', ['status' => 400]);
        }
        if (!class_exists('Earnfi_X402_Capabilities', false)) {
            $descriptor = PayAI_Flow::get_x402_descriptor($resource_url, $atomic, $resource_description);
            if (is_wp_error($descriptor)) {
                return $descriptor;
            }
            $accepts = $descriptor['accepts'] ?? [];
            $accepts = is_array($accepts) ? $accepts : [];
        } else {
            $accepts = Earnfi_X402_Capabilities::eligible_accepts_for_descriptor($resource_url, $atomic);
            if (is_wp_error($accepts)) {
                return $accepts;
            }
        }
        $network_filter = trim((string) $network_filter);
        if ($network_filter !== '' && class_exists('Earnfi_X402_Capabilities', false)) {
            $filtered = Earnfi_X402_Capabilities::filter_accepts_or_error($accepts, $network_filter);
            if (is_wp_error($filtered)) {
                return $filtered;
            }
            $accepts = $filtered;
        }
        return self::stamp_atomic_on_accepts($accepts, $atomic);
    }

    /**
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>
     */
    public static function stamp_atomic_on_accepts(array $accepts, string $atomic, int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS): array {
        $atomic = preg_replace('/\D/', '', $atomic) ?? '';
        $out = [];
        foreach ($accepts as $accept) {
            if (!is_array($accept)) {
                continue;
            }
            $accept['amount'] = $atomic;
            $accept['maxAmountRequired'] = $atomic;
            $accept['maxTimeoutSeconds'] = max(60, min(300, (int) $timeout_seconds));
            $out[] = $accept;
        }
        return $out;
    }

    /**
     * @param array<string, scalar|null> $parts
     */
    public static function fingerprint(array $parts): string {
        $normalized = [];
        foreach ($parts as $k => $v) {
            if ($v === null) {
                continue;
            }
            $normalized[(string) $k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        }
        ksort($normalized, SORT_STRING);
        return hash('sha256', wp_json_encode($normalized, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $x402_body
     */
    public static function attach_fingerprint(array &$x402_body, string $fingerprint): void {
        $fingerprint = sanitize_text_field($fingerprint);
        if ($fingerprint === '') {
            return;
        }
        $x402_body['earnfi_quote_fingerprint'] = $fingerprint;
        if (empty($x402_body['accepts']) || !is_array($x402_body['accepts'])) {
            return;
        }
        foreach ($x402_body['accepts'] as $idx => $accept) {
            if (!is_array($accept)) {
                continue;
            }
            if (!isset($accept['extra']) || !is_array($accept['extra'])) {
                $accept['extra'] = [];
            }
            $accept['extra']['quoteFingerprint'] = $fingerprint;
            $x402_body['accepts'][$idx] = $accept;
        }
    }

    /**
     * Validate PAYMENT-SIGNATURE against stored multi-accept quote (network-aware).
     *
     * @param array<string, mixed> $parsed From PayAI_Flow::parse_payment_signature_header
     * @return true|WP_Error
     */
    public static function validate_paid_retry(array $parsed, string $expected_atomic = '', ?string $quote_fingerprint = null) {
        $requirements = isset($parsed['requirements']) && is_array($parsed['requirements'])
            ? $parsed['requirements']
            : [];
        if ($requirements === []) {
            return new WP_Error('invalid_params', 'Payment requirements are missing.', ['status' => 400]);
        }
        if (!isset($requirements['maxAmountRequired']) && isset($requirements['amount'])) {
            $requirements['maxAmountRequired'] = $requirements['amount'];
        }

        $stored = PayAI_X402_Quote_Store::resolve_stored_for_settlement($requirements);
        if ($quote_fingerprint !== null && $quote_fingerprint !== '' && is_array($stored)) {
            $stored_fp = sanitize_text_field((string) ($stored['earnfi_quote_fingerprint'] ?? ''));
            if ($stored_fp !== '' && !hash_equals($stored_fp, $quote_fingerprint)) {
                return new WP_Error(
                    'quote_mismatch',
                    'Payment does not match the original quote. Request a fresh quote without PAYMENT-SIGNATURE, pick one accepts[] entry, sign it, and retry.',
                    ['status' => 400]
                );
            }
        }

        if (!class_exists('Earnfi_X402_Payment_Method_Selector', false)) {
            $signed_atomic = (string) ($requirements['maxAmountRequired'] ?? $requirements['amount'] ?? '');
            if ($expected_atomic !== '' && $signed_atomic !== preg_replace('/\D/', '', $expected_atomic)) {
                return self::amount_mismatch_error();
            }
            return true;
        }

        $accept_count = is_array($stored) && !empty($stored['accepts']) && is_array($stored['accepts'])
            ? count($stored['accepts'])
            : 0;
        $selected = Earnfi_X402_Payment_Method_Selector::resolve($requirements, $stored);
        if (is_wp_error($selected)) {
            if ($accept_count <= 1 && $expected_atomic !== '') {
                $signed_atomic = (string) ($requirements['maxAmountRequired'] ?? $requirements['amount'] ?? '');
                if ($signed_atomic === preg_replace('/\D/', '', $expected_atomic)) {
                    return true;
                }
            }
            return $selected;
        }

        $accept = is_array($selected['accept'] ?? null) ? $selected['accept'] : [];
        $quoted_atomic = preg_replace('/\D/', '', (string) ($accept['maxAmountRequired'] ?? $accept['amount'] ?? '')) ?? '';
        $signed_atomic = preg_replace('/\D/', '', (string) ($requirements['maxAmountRequired'] ?? $requirements['amount'] ?? '')) ?? '';
        if ($quoted_atomic !== '' && $signed_atomic !== '' && $quoted_atomic !== $signed_atomic) {
            return self::amount_mismatch_error();
        }
        if ($expected_atomic !== '') {
            $expected = preg_replace('/\D/', '', $expected_atomic) ?? '';
            if ($expected !== '' && $quoted_atomic !== '' && $quoted_atomic !== $expected) {
                return new WP_Error(
                    'PAYMENT_AMOUNT_MISMATCH',
                    'Signed payment does not match the current price. Request a fresh quote without PAYMENT-SIGNATURE, pick one accepts[] entry matching your network, sign it, and retry.',
                    ['status' => 400]
                );
            }
        }
        return true;
    }

    /**
     * @return WP_Error
     */
    public static function amount_mismatch_error(): WP_Error {
        return new WP_Error(
            'PAYMENT_AMOUNT_MISMATCH',
            'Signed payment does not match this quote. Request a fresh quote without PAYMENT-SIGNATURE, pick one accepts[] entry matching your network, sign it, and retry.',
            ['status' => 400]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function resource_meta(string $resource_url, string $description): array {
        $resource = Earnfi_X402_Bazaar::resource_meta($resource_url, $description);
        $resource['mimeType'] = 'application/json';
        return $resource;
    }
}
