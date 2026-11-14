<?php
/**
 * Canonical x402 paid resource URLs (well-known, PayAI resource.url, OpenAPI).
 *
 * Every entry in x402.txt should be a URL that returns HTTP 402 (descriptor or quote) without payment.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Paid_Resources {

    /** @var array<string, string> payment purpose → REST path segment after growl/v1/x402/ */
    public const GROWL_PROBE_SLUGS = [
        'creator_wallet_top_up' => 'creator-wallet/top-up',
        'job_create'            => 'job/create',
        'job_update'            => 'job/update',
        'contest_boost'         => 'contest/boost',
        'campaign_fund'         => 'campaign/fund',
        'job_listing_fee'       => 'listing/fee',
        'store_checkout'        => 'store/checkout',
        'direct_payment'        => 'payment/direct',
        'balance_deposit'       => 'balance/deposit',
        'agent_payout'          => 'agent/payout',
        'agent_order_fund'      => 'marketplace/order/fund',
        'agent_deal_fund'       => 'marketplace/deal/fund',
        'deal_fund'             => 'marketplace/deal/fund',
        'job_contract_fund'     => 'job-contract/fund',
    ];

    /**
     * Template URLs for x402.txt (app.earnfi.fun host; localized at serve time).
     *
     * @return array<int, string>
     */
    public static function well_known_resource_templates(): array {
        $base = 'https://app.earnfi.fun/api';
        $agent = $base . '/ai-agent/v1';
        $work  = $base . '/work/v1';
        $growl = $base . '/growl/v1';

        $human = ['ask', 'review', 'vote', 'test', 'research', 'verify', 'moderate', 'feedback'];
        $out   = [
            $agent . '/jobs/social',
            $agent . '/jobs/manual',
            $agent . '/jobs/contest',
            $agent . '/interrupt',
        ];
        foreach ($human as $type) {
            $out[] = $agent . '/human-actions/' . $type;
        }

        $out[] = $work . '/jobs/social';
        $out[] = $work . '/jobs/contest';

        foreach (self::GROWL_PROBE_SLUGS as $slug) {
            $out[] = $growl . '/x402/' . $slug;
            $out[] = $agent . '/x402/' . $slug;
        }

        $out[] = $agent . '/agents/orders/{id}/fund';
        $out[] = $agent . '/agents/deals/{id}/fund';
        $out[] = $growl . '/job-contracts/{id}/fund';
        $out[] = $growl . '/x402';

        return array_values(array_unique($out));
    }

    /**
     * Resolve live resource.url for PayAI verify/settle (no generic growl/v1/x402 fallback).
     *
     * @param array<string, mixed> $job_data
     */
    public static function resolve_url(string $purpose, array $job_data): string {
        $custom = isset($job_data['_x402_resource_url']) ? trim((string) $job_data['_x402_resource_url']) : '';
        if ($custom !== '') {
            $custom = function_exists('earnfi_x402_normalize_resource_url')
                ? earnfi_x402_normalize_resource_url($custom)
                : untrailingslashit(esc_url_raw($custom));
            return untrailingslashit(esc_url_raw($custom));
        }

        $purpose = sanitize_key($purpose);
        $canonical = static function (string $route): string {
            return function_exists('earnfi_x402_canonical_resource_url')
                ? earnfi_x402_canonical_resource_url($route)
                : untrailingslashit(rest_url($route));
        };

        if ($purpose === 'agent_order_fund') {
            $oid = (int) ($job_data['order_id'] ?? $job_data['reference_id'] ?? 0);
            if ($oid > 0) {
                return $canonical('ai-agent/v1/agents/orders/' . $oid . '/fund');
            }
            return self::intent_probe_url_for_slug('marketplace/order/fund');
        }
        if (in_array($purpose, ['agent_deal_fund', 'deal_fund'], true)) {
            $did = (int) ($job_data['deal_id'] ?? $job_data['agent_deal_id'] ?? $job_data['reference_id'] ?? 0);
            if ($did > 0) {
                return $canonical('ai-agent/v1/agents/deals/' . $did . '/fund');
            }
            return self::intent_probe_url_for_slug('marketplace/deal/fund');
        }
        if ($purpose === 'job_contract_fund') {
            $cid = (int) ($job_data['contract_id'] ?? $job_data['reference_id'] ?? 0);
            if ($cid > 0) {
                return $canonical('growl/v1/job-contracts/' . $cid . '/fund');
            }
            return self::intent_probe_url_for_slug('job-contract/fund');
        }

        return self::intent_probe_url_for_purpose($purpose);
    }

    /**
     * x402scan-registered URL for in-app wallet intents (mirrors growl/v1/x402/* probes).
     */
    public static function intent_probe_url_for_purpose(string $purpose): string {
        $purpose = sanitize_key($purpose);
        $slug = self::GROWL_PROBE_SLUGS[$purpose] ?? self::GROWL_PROBE_SLUGS['job_create'];
        return self::intent_probe_url_for_slug($slug);
    }

    public static function intent_probe_url_for_slug(string $slug, string $api_version = 'v1'): string {
        $slug = trim($slug, '/');
        $api_version = $api_version === 'v2' ? 'v2' : 'v1';
        $route = 'ai-agent/' . $api_version . '/x402/' . $slug;
        return function_exists('earnfi_x402_canonical_resource_url')
            ? earnfi_x402_canonical_resource_url($route)
            : untrailingslashit(rest_url($route));
    }

    /**
     * @return array<string, array{description: string, schema: string, example_output?: array<string, mixed>}>
     */
    public static function probe_definitions(): array {
        return [
            'creator-wallet/top-up' => [
                'purpose'     => 'creator_wallet_top_up',
                'description' => 'Top up EarnFi Creator Wallet paid balance with USDC on Solana. Unpaid GET/POST returns HTTP 402; after on-chain settlement, credit via wallet verify flow.',
                'schema'      => 'wallet_top_up',
                'example_output' => ['success' => true, 'balances' => ['paid' => '10.00']],
            ],
            'job/create' => [
                'purpose'     => 'job_create',
                'description' => 'Fund a new job or campaign from the EarnFi app (in-app listing). Unpaid call returns 402; retry with PAYMENT-SIGNATURE after USDC transfer.',
                'schema'      => 'growl_job',
                'example_output' => ['job_id' => 'EF1234', 'transaction_hash' => '…'],
            ],
            'job/update' => [
                'purpose'     => 'job_update',
                'description' => 'Add slots or funding to an existing EarnFi job (USDC).',
                'schema'      => 'growl_job',
            ],
            'contest/boost' => [
                'purpose'     => 'contest_boost',
                'description' => 'Boost or add prize pool funding to a contest on EarnFi.',
                'schema'      => 'growl_job',
            ],
            'campaign/fund' => [
                'purpose'     => 'campaign_fund',
                'description' => 'Fund a marketing campaign on EarnFi.',
                'schema'      => 'growl_job',
            ],
            'listing/fee' => [
                'purpose'     => 'job_listing_fee',
                'description' => 'Pay a job listing fee on EarnFi.',
                'schema'      => 'growl_job',
            ],
            'store/checkout' => [
                'purpose'     => 'store_checkout',
                'description' => 'Store checkout payment on EarnFi.',
                'schema'      => 'growl_job',
            ],
            'payment/direct' => [
                'purpose'     => 'direct_payment',
                'description' => 'Direct USDC payment on EarnFi (no platform fee on principal).',
                'schema'      => 'growl_job',
            ],
            'balance/deposit' => [
                'purpose'     => 'balance_deposit',
                'description' => 'Deposit USDC into EarnFi Balance via x402 (exactly-once ledger credit after settlement). Not the same as direct_payment.',
                'schema'      => 'growl_job',
                'example_output' => ['success' => true, 'balance_credited' => true],
            ],
            'agent/payout' => [
                'purpose'     => 'agent_payout',
                'description' => 'Agent payout settlement on EarnFi.',
                'schema'      => 'growl_job',
            ],
            'marketplace/order/fund' => [
                'purpose'     => 'agent_order_fund',
                'description' => 'x402 descriptor for marketplace agent order escrow. Settled payments use …/ai-agent/v1/agents/orders/{id}/fund for the specific order id.',
                'schema'      => 'commerce_fund',
            ],
            'marketplace/deal/fund' => [
                'purpose'     => 'agent_deal_fund',
                'description' => 'x402 descriptor for custom agent deal escrow. Settled payments use …/ai-agent/v1/agents/deals/{id}/fund for the specific deal id.',
                'schema'      => 'commerce_fund',
            ],
            'job-contract/fund' => [
                'purpose'     => 'job_contract_fund',
                'description' => 'x402 descriptor for job contract escrow. Settled payments use …/growl/v1/job-contracts/{id}/fund for the specific contract id.',
                'schema'      => 'commerce_fund',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema_for(string $schema_key): array {
        if (class_exists('Earnfi_Work_REST_API', false)) {
            $w = new Earnfi_Work_REST_API();
            if ($schema_key === 'growl_job') {
                return $w->get_growl_v1_x402_extensions_schema();
            }
            if ($schema_key === 'wallet_top_up') {
                return self::wallet_top_up_schema();
            }
            if ($schema_key === 'commerce_fund') {
                return self::commerce_fund_schema();
            }
        }
        return ['type' => 'object'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function wallet_top_up_schema(): array {
        return [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'type'       => 'object',
            'properties' => [
                'amount' => [
                    'type'        => 'number',
                    'description' => 'USDC amount to add to Creator Wallet paid balance.',
                    'minimum'     => 0.01,
                ],
                'payment_purpose' => [
                    'type' => 'string',
                    'const' => 'creator_wallet_top_up',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commerce_fund_schema(): array {
        return [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'type'       => 'object',
            'properties' => [
                'id' => [
                    'type'        => 'integer',
                    'description' => 'Order, deal, or contract id for the funded resource.',
                ],
            ],
        ];
    }

    /**
     * Section 19 inventory — v2 probe URLs mapped to purpose handlers (Phase 4 gate).
     *
     * @return array<int, array<string, string>>
     */
    public static function x402_inventory_matrix(): array {
        return self::v1_inventory_matrix();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function v1_inventory_matrix(): array {
        $rows = [];
        foreach (self::probe_definitions() as $slug => $def) {
            $purpose = sanitize_key((string) ($def['purpose'] ?? ''));
            if ($purpose === 'agent_payout') {
                continue;
            }
            $handler = 'probe';
            if ($purpose === 'balance_deposit') {
                $handler = 'Earnfi_X402_Balance_Deposit';
            } elseif (class_exists('Earnfi_X402_Purpose_Funding', false) && Earnfi_X402_Purpose_Funding::is_escrow_purpose($purpose)) {
                $handler = 'Earnfi_X402_Escrow_Funding';
            } elseif (class_exists('Earnfi_X402_Purpose_Funding', false) && Earnfi_X402_Purpose_Funding::is_job_purpose($purpose)) {
                $handler = 'Earnfi_Rail_Job_Funding';
            }
            $rows[] = [
                'purpose'      => $purpose,
                'probe_url'    => self::intent_probe_url_for_slug($slug, 'v1'),
                'growl_path'   => 'growl/v1/x402/' . $slug,
                'handler'      => $handler,
                'x402_inbound' => 'yes',
            ];
        }
        $agent_paid = [
            ['purpose' => 'agent_job_create', 'route' => 'ai-agent/v1/jobs/social|manual|contest', 'handler' => 'Earnfi_Rail_Job_Funding'],
            ['purpose' => 'human_action', 'route' => 'ai-agent/v1/human-actions/{type}', 'handler' => 'Earnfi_Rail_Job_Funding'],
            ['purpose' => 'interrupt', 'route' => 'ai-agent/v1/interrupt', 'handler' => 'Earnfi_Rail_Job_Funding'],
            ['purpose' => 'agent_order_fund', 'route' => 'ai-agent/v1/agents/orders/{id}/fund', 'handler' => 'Earnfi_X402_Escrow_Funding'],
            ['purpose' => 'agent_deal_fund', 'route' => 'ai-agent/v1/agents/deals/{id}/fund', 'handler' => 'Earnfi_X402_Escrow_Funding'],
            ['purpose' => 'job_contract_fund', 'route' => 'growl/v1/job-contracts/{id}/fund', 'handler' => 'Earnfi_X402_Escrow_Funding'],
        ];
        foreach ($agent_paid as $extra) {
            $rows[] = array_merge([
                'probe_url'    => '',
                'growl_path'   => '',
                'x402_inbound' => 'yes',
            ], $extra);
        }
        return $rows;
    }

    /** @deprecated Use x402_inventory_matrix() */
    public static function v2_inventory_matrix(): array {
        return self::x402_inventory_matrix();
    }
}
