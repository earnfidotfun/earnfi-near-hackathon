<?php
/**
 * NEAR x402 facilitators (FastNEAR + Solvador) — shared verify/settle client.
 *
 * Active backend is selected in admin (`near_x402_facilitator`). Quote accepts include
 * `extra.facilitator` so settlement routes to the facilitator that issued the quote.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Near_X402_Facilitator {

    public const USDC_MAINNET = '17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1';

    /** @var string[] */
    public const FACILITATOR_IDS = ['fastnear', 'solvador'];

    public static function active_id(): string {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $id = sanitize_key((string) ($settings['near_x402_facilitator'] ?? 'fastnear'));
        return in_array($id, self::FACILITATOR_IDS, true) ? $id : 'fastnear';
    }

    public static function is_near_facilitator(string $facilitator_id): bool {
        return in_array(sanitize_key($facilitator_id), self::FACILITATOR_IDS, true);
    }

    /**
     * Facilitator for verify/settle — honor quoted accept, then admin active id for NEAR.
     *
     * @param array<string, mixed> $requirements
     */
    public static function resolve_facilitator_id(array $requirements): string {
        if (!empty($requirements['extra']['facilitator'])) {
            $f = sanitize_key((string) $requirements['extra']['facilitator']);
            if (self::is_near_facilitator($f)) {
                return $f;
            }
        }
        $network = (string) ($requirements['network'] ?? '');
        if (strpos($network, 'near:') === 0) {
            return self::active_id();
        }
        return Earnfi_X402_Payment_Method_Id::infer_facilitator_from_network($network);
    }

    /**
     * @return array{api_key: string, base_url: string}
     */
    public static function credentials(string $facilitator_id): array {
        $facilitator_id = sanitize_key($facilitator_id);
        if (!self::is_near_facilitator($facilitator_id)) {
            return ['api_key' => '', 'base_url' => ''];
        }
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $api_key = '';
        if ($facilitator_id === 'fastnear') {
            $api_key = self::setting_secret($settings, 'fastnear_x402_api_key', 'EARNFI_FASTNEAR_X402_API_KEY');
        } else {
            $api_key = self::setting_secret($settings, 'solvador_near_api_key', 'EARNFI_SOLVADOR_X402_API_KEY');
            if ($api_key === '') {
                $api_key = self::setting_secret($settings, 'solvador_x402_api_key', 'EARNFI_SOLVADOR_NEAR_API_KEY');
            }
        }
        $base = '';
        if (class_exists('Earnfi_X402_Facilitator_Registry', false)) {
            $known = Earnfi_X402_Facilitator_Registry::known_facilitators();
            if (!empty($known[$facilitator_id]['base_url'])) {
                $base = untrailingslashit((string) $known[$facilitator_id]['base_url']);
            }
        }
        return ['api_key' => $api_key, 'base_url' => $base];
    }

    public static function has_api_key(?string $facilitator_id = null): bool {
        $id = $facilitator_id ?? self::active_id();
        if (!self::is_near_facilitator($id)) {
            return false;
        }
        return trim(self::credentials($id)['api_key']) !== '';
    }

    public static function has_active_api_key(): bool {
        return self::has_api_key(self::active_id());
    }

    /**
     * @param array<string, mixed> $payment_payload
     * @param array<string, mixed> $payment_requirements
     * @return array<string, mixed>|WP_Error
     */
    public static function verify(string $facilitator_id, array $payment_payload, array $payment_requirements) {
        return self::post($facilitator_id, '/verify', [
            'x402Version'         => 2,
            'paymentPayload'      => $payment_payload,
            'paymentRequirements' => $payment_requirements,
        ]);
    }

    /**
     * @param array<string, mixed> $payment_payload
     * @param array<string, mixed> $payment_requirements
     * @return array<string, mixed>|WP_Error
     */
    public static function settle(string $facilitator_id, array $payment_payload, array $payment_requirements) {
        return self::post($facilitator_id, '/settle', [
            'x402Version'         => 2,
            'paymentPayload'      => $payment_payload,
            'paymentRequirements' => $payment_requirements,
        ]);
    }

    /**
     * @param array<string, mixed>|null $payment_payload_v2
     * @param array<string, mixed>|null $stored_402
     * @return array{paymentPayload: array<string, mixed>, paymentRequirements: array<string, mixed>}|WP_Error
     */
    public static function build_facilitator_request(
        string $facilitator_id,
        array $requirements,
        ?array $payment_payload_v2 = null,
        ?array $stored_402 = null
    ) {
        $facilitator_id = sanitize_key($facilitator_id);
        if (!self::is_near_facilitator($facilitator_id)) {
            return new WP_Error('near_facilitator_invalid', 'Invalid NEAR facilitator.', ['status' => 503]);
        }
        if (!self::has_api_key($facilitator_id)) {
            return new WP_Error(
                'near_facilitator_unconfigured',
                'NEAR payments are not configured for the selected facilitator.',
                ['status' => 503, 'facilitator' => $facilitator_id]
            );
        }

        $normalized = PayAI_Payments::normalize_requirements_v2_public($requirements);
        $req        = self::near_payment_requirements($requirements, $normalized);

        if (is_array($payment_payload_v2)
            && !empty($payment_payload_v2['accepted'])
            && !empty($payment_payload_v2['payload']['signedDelegateAction'])) {
            $payload = $payment_payload_v2;
            if (empty($payload['x402Version'])) {
                $payload['x402Version'] = 2;
            }
        } else {
            return new WP_Error(
                'invalid_near_payment',
                'NEAR PAYMENT-SIGNATURE must include x402 v2 payload with signedDelegateAction.',
                ['status' => 400]
            );
        }

        if ($stored_402 === null && class_exists('PayAI_X402_Quote_Store', false)) {
            $stored_402 = PayAI_X402_Quote_Store::resolve_stored_for_settlement($requirements);
        }
        if (is_array($stored_402)) {
            if (!empty($stored_402['resource']) && is_array($stored_402['resource'])) {
                $payload['resource'] = $stored_402['resource'];
            }
            if (class_exists('PayAI_X402_Quote_Store', false)
                && !PayAI_X402_Quote_Store::extensions_empty($stored_402['extensions'] ?? null)) {
                $payload['extensions'] = $stored_402['extensions'];
            }
        }

        $payload['accepted'] = self::near_payment_requirements(
            is_array($payload['accepted']) ? $payload['accepted'] : $requirements,
            $normalized
        );

        return [
            'paymentPayload'      => $payload,
            'paymentRequirements' => $req,
        ];
    }

    /**
     * @param array<string, mixed> $requirements
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    public static function near_payment_requirements(array $requirements, array $normalized): array {
        $amount = (string) ($normalized['amount'] ?? $requirements['amount'] ?? $requirements['maxAmountRequired'] ?? '0');
        $out    = [
            'scheme'            => (string) ($normalized['scheme'] ?? 'exact'),
            'network'           => (string) ($normalized['network'] ?? $requirements['network'] ?? 'near:mainnet'),
            'asset'             => (string) ($normalized['asset'] ?? $requirements['asset'] ?? self::USDC_MAINNET),
            'amount'            => $amount,
            'payTo'             => (string) ($normalized['payTo'] ?? $requirements['payTo'] ?? ''),
            'maxTimeoutSeconds' => (int) ($normalized['maxTimeoutSeconds'] ?? $requirements['maxTimeoutSeconds'] ?? 60),
        ];
        $extra = isset($requirements['extra']) && is_array($requirements['extra']) ? $requirements['extra'] : [];
        unset($extra['transferChecked'], $extra['tokenMint'], $extra['tokenDecimals'], $extra['tokenSymbol'], $extra['feePayer'], $extra['facilitator']);
        if (!empty($extra)) {
            $out['extra'] = $extra;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|WP_Error
     */
    private static function post(string $facilitator_id, string $endpoint, array $body) {
        $facilitator_id = sanitize_key($facilitator_id);
        $c              = self::credentials($facilitator_id);
        if ($c['api_key'] === '' || $c['base_url'] === '') {
            return new WP_Error(
                'near_facilitator_unconfigured',
                'NEAR facilitator API key is not configured.',
                ['status' => 503, 'facilitator' => $facilitator_id]
            );
        }
        $url = untrailingslashit($c['base_url']) . $endpoint;
        $res = wp_remote_post($url, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'X-API-Key'     => $c['api_key'],
                'Authorization' => 'Bearer ' . $c['api_key'],
            ],
            'body'    => wp_json_encode($body, JSON_UNESCAPED_SLASHES),
            'timeout' => 90,
        ]);
        if (is_wp_error($res)) {
            return $res;
        }
        $status = wp_remote_retrieve_response_code($res);
        $raw    = wp_remote_retrieve_body($res);
        $data   = json_decode($raw, true);
        if (!is_array($data)) {
            return new WP_Error(
                'near_facilitator_invalid_response',
                'Invalid response from NEAR payment facilitator.',
                ['status' => 502, 'facilitator' => $facilitator_id]
            );
        }
        if ($status < 200 || $status >= 300) {
            $message = $data['errorMessage'] ?? $data['errorReason'] ?? $data['error'] ?? $data['message'] ?? 'NEAR payment facilitator request failed';
            if (!is_string($message)) {
                $message = 'NEAR payment facilitator request failed';
            }
            return new WP_Error(
                'near_facilitator_http_error',
                $message,
                ['status' => $status, 'details' => $data, 'facilitator' => $facilitator_id]
            );
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function setting_secret(array $settings, string $option_key, string $env_key): string {
        $raw = isset($settings[$option_key]) ? trim((string) $settings[$option_key]) : '';
        if ($raw !== '') {
            try {
                if (class_exists('GROWL_Security')) {
                    $sec   = new GROWL_Security();
                    $maybe = $sec->decrypt($raw);
                    if (is_string($maybe) && $maybe !== '') {
                        return trim($maybe);
                    }
                }
            } catch (Throwable $e) {
                // plaintext fallback
            }
            return $raw;
        }
        if (defined($env_key)) {
            $v = constant($env_key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        $env = getenv($env_key);
        return is_string($env) ? trim($env) : '';
    }
}
