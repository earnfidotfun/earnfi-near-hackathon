<?php
/**
 * Facilitator metadata (server-side). Client hints in accept.extra are not trusted for routing.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Facilitator_Registry {

    /**
     * @return array<string, array{base_url: string, rail_id: string, schemes: string[]}>
     */
    public static function known_facilitators(): array {
        return [
            'payai' => [
                'base_url' => 'https://facilitator.payai.network',
                'rail_id'  => Earnfi_Payment_Rails::RAIL_SOLANA_USDC,
                'schemes'  => ['exact'],
            ],
            'okx_xlayer' => [
                'base_url' => 'https://web3.okx.com',
                'rail_id'  => Earnfi_Payment_Rails::RAIL_OKX_XLAYER,
                'schemes'  => ['exact'],
            ],
            'fastnear' => [
                'base_url' => 'https://x402.mikedotexe.com',
                'rail_id'  => 'near-usdc-x402',
                'schemes'  => ['exact'],
            ],
            'solvador' => [
                'base_url' => 'https://api.solvador.com',
                'rail_id'  => 'near-usdc-x402',
                'schemes'  => ['exact'],
            ],
        ];
    }

    public static function facilitator_for_network(string $network_caip2): string {
        return Earnfi_X402_Payment_Method_Id::infer_facilitator_from_network($network_caip2);
    }
}
