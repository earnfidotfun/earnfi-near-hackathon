<?php
/**
 * Agent-facing HTTP 402 / integration guidance (keep aligned with public/js/utils/earnfi-agent-integrator-copy.js).
 */
final class Earnfi_Agent_Integrator_Copy {

    public static function http402_hint(): string {
        return 'Payment quote. Do not send PAYMENT-SIGNATURE on this call. Pick one accepts[] entry, sign it, retry this same request with PAYMENT-SIGNATURE.';
    }

    public static function http402_next_step(bool $include_agent_token_note = true): string {
        $step = 'Sign one accept from accepts[], set PAYMENT-SIGNATURE, retry identical request. On 200 save secret, status_url';
        if ($include_agent_token_note) {
            $step .= ', agent_token (if any)';
        }
        $step .= '; then poll with secret.';
        return $step;
    }

    public static function paid_create_quote_hint(): string {
        return self::http402_hint()
            . ' Agent-Token is optional; omit it to bind identity from the paying wallet. Optional agent_name sets the display name on first bind.';
    }

    public static function paid_create_quote_next_step(): string {
        return self::http402_next_step(true) . ' GET /catalog lists more actions.';
    }

    public static function paid_resource_402_hint(): string {
        return self::http402_hint();
    }

    public static function paid_resource_402_next_step(): string {
        return self::http402_next_step(true);
    }

    public static function mcp_402_next_step(): string {
        return 'Payment quote (HTTP 402). Do not send payment_signature on the quote call. Pick exactly one accepts[] entry whose network matches your signer (solana:…, eip155:8453, near:mainnet). Optional payment_network on the same tool filters the quote. Sign that accept only: Solana signed_tx, Base EVM x402, or NEAR signedDelegateAction. OKX tools use eip155:196 USDT0 paymentPayload (never a Solana signed_tx). Call this same MCP tool again with identical parameters plus payment_signature. agent_token is optional on first pay; store secret and agent_token from 200, then poll without payment. If payment_network_unavailable, omit the filter or switch network.';
    }

    public static function work_paid_create_quote_hint(): string {
        return self::http402_hint()
            . ' Work API: include Authorization: Bearer <Work API key> on the quote call and on the pay retry.';
    }

    public static function work_paid_create_quote_next_step(): string {
        return self::http402_next_step(false)
            . ' Keep the same JSON body (or query) and Bearer key. On 200 use suggested_job_data when the platform returns it.';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function merge_agent_402_quote_fields(array $payload): array {
        if (empty($payload['payment_required'])) {
            $payload['payment_required'] = true;
        }
        $payload['hint'] = isset($payload['hint']) && is_string($payload['hint']) && $payload['hint'] !== ''
            ? $payload['hint']
            : self::paid_create_quote_hint();
        $payload['next_step'] = isset($payload['next_step']) && is_string($payload['next_step']) && $payload['next_step'] !== ''
            ? $payload['next_step']
            : self::paid_create_quote_next_step();
        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function merge_work_402_quote_fields(array $payload): array {
        if (empty($payload['payment_required'])) {
            $payload['payment_required'] = true;
        }
        $payload['hint'] = self::work_paid_create_quote_hint();
        $payload['next_step'] = self::work_paid_create_quote_next_step();
        return $payload;
    }

    public static function catalog_discovery_hint(): string {
        return 'Free catalog. Paid creates return HTTP 402 first; read hint and next_step on every quote response.';
    }

    public static function catalog_discovery_next_step(): string {
        return 'Call a paid route without PAYMENT-SIGNATURE for a quote, or GET /x402/capabilities for networks. Agent-Token optional on first pay.';
    }

    /** OpenAPI field descriptions for hint / next_step on 402 bodies. */
    public static function openapi_hint_field_description(): string {
        return 'Human-readable quote context. On HTTP 402: payment quote — do not send PAYMENT-SIGNATURE yet; pick one accepts[] entry to sign.';
    }

    public static function openapi_next_step_field_description(): string {
        return 'Authoritative next action for autonomous agents. Sign one accept, set PAYMENT-SIGNATURE, retry the identical request; on 200 persist secret/status_url/agent_token then poll.';
    }
}
