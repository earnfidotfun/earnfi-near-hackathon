<?php
/**
 * x402 capability discovery (config + rails; live /supported health in Phase 2+).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Capabilities {

    /**
     * @return array<string, mixed>
     */
    public static function agent_capabilities_payload(): array {
        $settings = get_option('growl_settings', []);
        $rails    = class_exists('Earnfi_Payment_Rails', false) ? Earnfi_Payment_Rails::catalog() : [];
        $fac_meta = class_exists('Earnfi_X402_Facilitator_Registry', false)
            ? Earnfi_X402_Facilitator_Registry::known_facilitators()
            : [];

        $networks = [];
        foreach ($rails['rails'] ?? [] as $rail) {
            if (($rail['status'] ?? '') !== 'active') {
                continue;
            }
            $networks[] = [
                'rail_id'  => (string) ($rail['id'] ?? ''),
                'network'  => (string) ($rail['network'] ?? ''),
                'protocol' => 'x402',
                'default'  => !empty($rail['default']),
            ];
        }

        $near_facilitator = class_exists('Earnfi_Near_X402_Facilitator', false)
            ? Earnfi_Near_X402_Facilitator::active_id()
            : 'fastnear';

        return [
            'x402Version'           => 2,
            'solana_first'          => true,
            'rails'                 => $rails,
            'networks'              => $networks,
            'facilitators'          => array_keys($fac_meta),
            'purposes'              => self::advertised_purposes(),
            'withdrawals_x402'      => false,
            'near_mainnet_pay'      => !empty($settings['near_x402_enabled']) && !empty($settings['near_x402_gate_b_ok']),
            'near_x402_facilitator' => $near_facilitator,
            'base_mainnet_pay'      => class_exists('Earnfi_X402_Base_Scheme_Adapter', false) && Earnfi_X402_Base_Scheme_Adapter::is_live_enabled(),
        ];
    }

    /**
     * Purposes exposed on growl/v2/x402 (excludes agent_payout inbound).
     *
     * @return string[]
     */
    public static function advertised_purposes(): array {
        $list = class_exists('Earnfi_X402_Purpose_Funding', false)
            ? Earnfi_X402_Purpose_Funding::APPROVED_INBOUND
            : [];
        $list[] = 'balance_deposit';
        return array_values(array_unique(array_filter($list)));
    }

    /**
     * Build accepts[] for a resource (Solana-first; extension networks gated).
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public static function eligible_accepts_for_descriptor(string $resource_url, ?string $example_amount = null) {
        $descriptor = PayAI_Flow::get_x402_descriptor($resource_url, $example_amount);
        if (is_wp_error($descriptor)) {
            return $descriptor;
        }
        $accepts = $descriptor['accepts'] ?? [];
        if (!is_array($accepts)) {
            $accepts = [];
        }
        // Solana-first ordering (already single accept today).
        if (class_exists('Earnfi_X402_Near_Scheme_Adapter', false)) {
            $accepts = Earnfi_X402_Near_Scheme_Adapter::merge_when_enabled($accepts, $resource_url, $example_amount);
        }
        if (class_exists('Earnfi_X402_Base_Scheme_Adapter', false)) {
            $accepts = Earnfi_X402_Base_Scheme_Adapter::merge_when_enabled($accepts, $resource_url, $example_amount);
        }
        usort($accepts, static function ($a, $b) {
            $na = (string) ($a['network'] ?? '');
            $nb = (string) ($b['network'] ?? '');
            if (strpos($na, 'solana:') === 0 && strpos($nb, 'solana:') !== 0) {
                return -1;
            }
            if (strpos($nb, 'solana:') === 0 && strpos($na, 'solana:') !== 0) {
                return 1;
            }
            return 0;
        });
        return $accepts;
    }

    /**
     * Optional request filter (e.g. ai-agent/near/v1 alias).
     *
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>
     */
    public static function filter_accepts_for_request(array $accepts, ?string $filter_network_prefix): array {
        $filter_network_prefix = trim((string) $filter_network_prefix);
        if ($filter_network_prefix === '') {
            return $accepts;
        }
        return array_values(array_filter($accepts, static function ($a) use ($filter_network_prefix) {
            $n = (string) ($a['network'] ?? '');
            return strpos($n, $filter_network_prefix) === 0;
        }));
    }

    /**
     * Read optional network filter from integrator request (e.g. ai-agent/near/v1 alias).
     */
    public static function accepts_filter_from_request($request): string {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            return '';
        }
        return trim((string) $request->get_header('X-Earnfi-Accepts-Filter'));
    }

    /**
     * Restrict accepts[] to a CAIP-2 prefix without bypassing rail enablement (filter only).
     *
     * @param array<int, array<string, mixed>> $accepts
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public static function filter_accepts_or_error(array $accepts, string $filter) {
        $filter = trim($filter);
        if ($filter === '') {
            return $accepts;
        }
        if (!self::is_known_accepts_filter($filter)) {
            return new WP_Error(
                'invalid_accepts_filter',
                'Unknown X-Earnfi-Accepts-Filter value. Use a CAIP-2 prefix such as near:mainnet, solana:, or eip155:8453.',
                ['status' => 400, 'filter' => $filter]
            );
        }
        $filtered = self::filter_accepts_for_request($accepts, $filter);
        if ($filtered === []) {
            return new WP_Error(
                'payment_network_unavailable',
                'No eligible payment options for the requested network. Enable the rail in EarnFi admin (treasury, facilitator credentials, Production sign-off, Live for users, Include in payment quotes) or omit X-Earnfi-Accepts-Filter.',
                ['status' => 503, 'filter' => $filter]
            );
        }
        return $filtered;
    }

    private static function is_known_accepts_filter(string $filter): bool {
        if ($filter === '') {
            return false;
        }
        $known = ['near:', 'solana:', 'eip155:'];
        foreach ($known as $prefix) {
            if (strpos($filter, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}
