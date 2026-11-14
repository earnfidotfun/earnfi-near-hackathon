<?php
/**
 * OKX Onchain OS x402 facilitator client (PHP).
 *
 * Auth: OK-ACCESS-* HMAC-SHA256 — sign = Base64(HMAC-SHA256(secret, timestamp + METHOD + requestPath + body))
 * Endpoints: /api/v6/pay/x402/{supported,verify,settle}
 *
 * @see https://web3.okx.com/onchainos/dev-docs/payments/api-http-onetime
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Okx_Facilitator {

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function supported() {
        return self::request('GET', '/api/v6/pay/x402/supported', null);
    }

    /**
     * @param array<string,mixed> $payment_payload
     * @param array<string,mixed> $payment_requirements
     * @return array<string,mixed>|WP_Error
     */
    public static function verify(array $payment_payload, array $payment_requirements) {
        $body = [
            'x402Version'         => 2,
            'paymentPayload'      => $payment_payload,
            'paymentRequirements' => $payment_requirements,
        ];
        return self::request('POST', '/api/v6/pay/x402/verify', $body);
    }

    /**
     * @param array<string,mixed> $payment_payload
     * @param array<string,mixed> $payment_requirements
     * @return array<string,mixed>|WP_Error
     */
    public static function settle(array $payment_payload, array $payment_requirements, bool $sync_settle = true) {
        $body = [
            'x402Version'         => 2,
            'paymentPayload'      => $payment_payload,
            'paymentRequirements' => $payment_requirements,
            'syncSettle'          => $sync_settle,
        ];
        return self::request('POST', '/api/v6/pay/x402/settle', $body);
    }

    /**
     * Parse PAYMENT-SIGNATURE for OKX / EVM x402 (not Solana signed_tx).
     *
     * Accepts:
     * - base64(JSON) or raw JSON paymentPayload
     * - wrapper { paymentPayload, paymentRequirements? } / { payload, requirements }
     *
     * @return array{payment_payload:array,requirements:?array}|WP_Error
     */
    public static function parse_payment_signature_header(string $header_raw) {
        $raw = trim($header_raw);
        if ($raw === '') {
            return new WP_Error('missing_payment_signature', 'PAYMENT-SIGNATURE header is empty', ['status' => 402]);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $decoded = base64_decode($raw, true);
            if ($decoded !== false) {
                $json = json_decode($decoded, true);
            }
        }
        if (!is_array($json)) {
            return new WP_Error('invalid_payment_signature', 'PAYMENT-SIGNATURE is not valid JSON or base64 JSON', ['status' => 400]);
        }

        // Reject Solana payloads early — wrong rail.
        if (isset($json['signed_tx']) || isset($json['signedTx'])) {
            return new WP_Error(
                'wrong_payment_rail',
                'This endpoint is the OKX X Layer rail. Solana signed_tx belongs on /api/ai-agent/v1. Send an EVM x402 paymentPayload instead.',
                ['status' => 400, 'rail' => Earnfi_Payment_Rails::RAIL_OKX_XLAYER]
            );
        }

        $payload = null;
        $requirements = null;

        if (isset($json['paymentPayload']) && is_array($json['paymentPayload'])) {
            $payload = $json['paymentPayload'];
            if (isset($json['paymentRequirements']) && is_array($json['paymentRequirements'])) {
                $requirements = $json['paymentRequirements'];
            }
        } elseif (isset($json['payload']) && is_array($json['payload']) && (isset($json['accepted']) || isset($json['x402Version']))) {
            $payload = $json;
        } elseif (isset($json['x402Version']) && (isset($json['payload']) || isset($json['accepted']))) {
            $payload = $json;
        }

        if ($requirements === null) {
            foreach (['requirements', 'paymentRequirements', 'payment_requirements', 'accepted'] as $k) {
                if (isset($json[$k]) && is_array($json[$k]) && isset($json[$k]['network'])) {
                    $requirements = $json[$k];
                    break;
                }
            }
        }

        if (!is_array($payload)) {
            return new WP_Error(
                'invalid_payment_signature',
                'OKX PAYMENT-SIGNATURE must contain an x402 paymentPayload (EVM EIP-3009), not a Solana signed_tx',
                ['status' => 400]
            );
        }

        if (!isset($payload['x402Version'])) {
            $payload['x402Version'] = 2;
        }

        return [
            'payment_payload' => $payload,
            'requirements'    => $requirements,
        ];
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>|WP_Error
     */
    private static function request(string $method, string $request_path, $body) {
        if (!Earnfi_Okx_Xlayer_Rail::is_paid_ready()) {
            return new WP_Error(
                'okx_rail_not_configured',
                'OKX facilitator credentials are not configured',
                ['status' => 503, 'config' => Earnfi_Okx_Xlayer_Rail::public_config_status()]
            );
        }

        $c = Earnfi_Okx_Xlayer_Rail::credentials();
        $method = strtoupper($method);
        $body_str = ($body === null) ? '' : wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($body_str === false) {
            return new WP_Error('json_encode_failed', 'Failed to encode OKX request body', ['status' => 500]);
        }

        $timestamp = gmdate('Y-m-d\TH:i:s') . '.' . sprintf('%03d', (int) ((microtime(true) * 1000) % 1000)) . 'Z';

        $prehash = $timestamp . $method . $request_path . ($method === 'GET' ? '' : $body_str);
        $sign = base64_encode(hash_hmac('sha256', $prehash, $c['secret_key'], true));

        $url = $c['base_url'] . $request_path;
        $args = [
            'method'  => $method,
            'timeout' => 60,
            'headers' => [
                'Content-Type'         => 'application/json',
                'OK-ACCESS-KEY'        => $c['api_key'],
                'OK-ACCESS-SIGN'       => $sign,
                'OK-ACCESS-PASSPHRASE' => $c['passphrase'],
                'OK-ACCESS-TIMESTAMP'  => $timestamp,
            ],
        ];
        if ($method !== 'GET' && $body_str !== '') {
            $args['body'] = $body_str;
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            return new WP_Error('okx_facilitator_http', $res->get_error_message(), ['status' => 502]);
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return new WP_Error('okx_facilitator_bad_response', 'OKX facilitator returned non-JSON', [
                'status' => 502,
                'http'   => $code,
            ]);
        }

        $biz = isset($json['code']) ? (string) $json['code'] : '';
        if ($biz !== '' && $biz !== '0') {
            return new WP_Error(
                'okx_facilitator_error',
                (string) ($json['msg'] ?? 'OKX facilitator error'),
                [
                    'status'   => ($code >= 400 ? $code : 400),
                    'okx_code' => $biz,
                    'data'     => $json['data'] ?? null,
                ]
            );
        }

        $data = $json['data'] ?? $json;
        return is_array($data) ? $data : [ 'raw' => $data ];
    }
}
