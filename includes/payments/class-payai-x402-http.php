<?php
/**
 * x402 HTTP response helpers (PAYMENT-REQUIRED / PAYMENT-RESPONSE).
 */
if (!defined('ABSPATH')) {
    exit;
}

class PayAI_X402_Http {

    /**
     * @param array<string, mixed> $settlement PayAI settle response or subset
     */
    public static function settlement_header_value(array $settlement, ?string $network_caip2 = null): string {
        $network = $network_caip2;
        if ($network === null || $network === '') {
            $network = (string) ($settlement['network'] ?? 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp');
        }
        $tx = (string) (
            $settlement['transaction']
            ?? $settlement['transactionId']
            ?? $settlement['txSignature']
            ?? $settlement['signature']
            ?? ''
        );
        $body = [
            'x402Version' => 2,
            'success'     => !isset($settlement['success']) || $settlement['success'] !== false,
            'transaction' => $tx,
            'network'     => $network,
        ];
        $payer = $settlement['payer'] ?? $settlement['payerAddress'] ?? null;
        if (is_string($payer) && $payer !== '') {
            $body['payer'] = $payer;
        }
        if (!empty($settlement['errorReason'])) {
            $body['errorReason'] = (string) $settlement['errorReason'];
        }
        return base64_encode(wp_json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $x402_body
     */
    public static function payment_required_header_value(array $x402_body): string {
        return base64_encode(wp_json_encode($x402_body, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $settlement
     */
    public static function attach_payment_response(WP_REST_Response $response, array $settlement, ?string $network_caip2 = null): WP_REST_Response {
        $b64 = self::settlement_header_value($settlement, $network_caip2);
        $response->header('Payment-Response', $b64);
        $response->header('PAYMENT-RESPONSE', $b64);
        return $response;
    }

    public static function send_payment_required_headers(array $x402_body): void {
        if (headers_sent()) {
            return;
        }
        $b64 = self::payment_required_header_value($x402_body);
        header('Payment-Required: ' . $b64);
        header('PAYMENT-REQUIRED: ' . $b64);
    }
}
