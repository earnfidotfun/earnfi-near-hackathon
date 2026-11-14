<?php
/**
 * EarnFi Agent API — multi-rail payment registry.
 *
 * Rails are bound by URL surface (never by sniffing the client):
 *   - solana-usdc-x402  → /api/ai-agent/v1/*          (default, unchanged)
 *   - okx-xlayer-usdt0  → /api/ai-agent/okx/v1/*      (OKX.AI / X Layer)
 *
 * Discovery: GET /api/ai-agent/v1/rails and GET /api/ai-agent/okx/v1/rails
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Payment_Rails {

    public const RAIL_SOLANA_USDC = 'solana-usdc-x402';
    public const RAIL_OKX_XLAYER  = 'okx-xlayer-usdt0';

    /**
     * @return array{default_rail:string,rails:array<int,array<string,mixed>>,docs:array<string,string>}
     */
    public static function catalog(): array {
        $home = untrailingslashit(home_url('/'));
        $solana = self::solana_rail_card($home);
        $okx    = self::okx_rail_card($home);

        return [
            'name'         => 'EarnFi Payment Rails',
            'description'  => 'Human Execution API payments are rail-scoped. Call the matching base URL; do not mix Solana PAYMENT-SIGNATURE payloads with the OKX surface (or vice versa).',
            'default_rail' => self::RAIL_SOLANA_USDC,
            'selection'    => [
                'rule'    => 'url_binding',
                'details' => 'The request path selects the rail. Explicit ?payment_rail= is accepted only as documentation on discovery endpoints; paid creates ignore cross-rail credentials.',
            ],
            'rails'        => [ $solana, $okx ],
            'docs'         => [
                'skill'   => $home . '/skill.md',
                'openapi' => $home . '/openapi-x402.json',
                'mcp'     => $home . '/mcp',
                'okx_asp' => $home . '/api/ai-agent/okx/v1',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_rail(string $rail_id): ?array {
        foreach (self::catalog()['rails'] as $rail) {
            if (($rail['id'] ?? '') === $rail_id) {
                return $rail;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function solana_rail_card(string $home): array {
        $base    = $home . '/api/ai-agent/v1';
        $network = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';
        if (class_exists('PayAI_Flow')) {
            $descriptor = PayAI_Flow::get_x402_descriptor($base . '/jobs/social');
            if (!is_wp_error($descriptor) && !empty($descriptor['accepts'][0]['network'])) {
                $network = (string) $descriptor['accepts'][0]['network'];
            }
        }

        $settings = get_option('growl_settings', []);
        $asset    = (string) ($settings['usdc_mint_address'] ?? 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v');

        return [
            'id'              => self::RAIL_SOLANA_USDC,
            'status'          => 'active',
            'default'         => true,
            'label'           => 'Solana USDC (x402 exact / SVM)',
            'protocol'        => 'x402',
            'x402_version'    => 2,
            'scheme'          => 'exact',
            'network'         => $network,
            'asset'           => [
                'symbol'   => 'USDC',
                'type'     => 'spl-token',
                'address'  => $asset,
                'decimals' => intval($settings['usdc_decimals'] ?? 6),
            ],
            'payment_proof'   => [
                'header' => 'PAYMENT-SIGNATURE',
                'shape'  => 'base64(JSON|{signed_tx,requirements}) — Solana wire transaction (SVM)',
            ],
            'facilitator'     => 'payai',
            'api_base'        => $base,
            'paid_creates'    => [
                $base . '/jobs/social',
                $base . '/jobs/manual',
                $base . '/jobs/contest',
                $base . '/interrupt',
                $base . '/actions',
            ],
            'discovery'       => [
                'catalog' => $base . '/catalog',
                'x402'    => $base . '/x402',
                'rails'   => $base . '/rails',
            ],
            'mcp'             => $home . '/mcp',
            'notes'           => 'Canonical Agent API. Existing Solana clients and @earn-fi/agent-client use this rail exclusively.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function okx_rail_card(string $home): array {
        $base   = $home . '/api/ai-agent/okx/v1';
        $ready  = Earnfi_Okx_Xlayer_Rail::is_paid_ready();
        $status = $ready ? 'active' : 'discovery_free';

        return [
            'id'              => self::RAIL_OKX_XLAYER,
            'status'          => $status,
            'default'         => false,
            'label'           => 'OKX X Layer USDT0 (x402 exact / EVM)',
            'protocol'        => 'x402',
            'x402_version'    => 2,
            'scheme'          => 'exact',
            'network'         => Earnfi_Okx_Xlayer_Rail::NETWORK,
            'asset'           => [
                'symbol'   => 'USDT0',
                'type'     => 'erc20-eip3009',
                'address'  => Earnfi_Okx_Xlayer_Rail::ASSET_USDT0,
                'decimals' => 6,
                'eip712'   => [ 'name' => 'USD₮0', 'version' => '1' ],
            ],
            'payment_proof'   => [
                'header' => 'PAYMENT-SIGNATURE',
                'shape'  => 'x402 v2 paymentPayload (EIP-3009 authorization) — EVM; never a Solana signed_tx',
            ],
            'facilitator'     => 'okx',
            'api_base'        => $base,
            'paid_creates'    => $ready
                ? [
                    $base . '/jobs/social',
                    $base . '/jobs/manual',
                    $base . '/jobs/contest',
                    $base . '/interrupt',
                    $base . '/actions',
                    $base . '/actions/ask',
                ]
                : [],
            'discovery'       => [
                'info'    => $base,
                'catalog' => $base . '/catalog',
                'health'  => $base . '/health',
                'x402'    => $base . '/x402',
                'rails'   => $base . '/rails',
            ],
            'marketplace'     => [
                'okx_ai_a2mcp' => true,
                'listing_hint' => 'Register A2MCP with free endpoint ' . $base . '/catalog (HTTP 200). Enable paid settlement with OKX facilitator credentials when ready.',
            ],
            'config'          => Earnfi_Okx_Xlayer_Rail::public_config_status(),
            'notes'           => 'Isolated surface for OKX.AI / X Layer. Does not alter Solana /api/ai-agent/v1 behavior.',
        ];
    }
}
