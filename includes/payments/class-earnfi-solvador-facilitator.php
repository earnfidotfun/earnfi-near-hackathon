<?php
/**
 * Solvador x402 facilitator (NEAR mainnet exact / NEP-366).
 *
 * @see https://docs.solvador.com/
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Solvador_Facilitator {

    public const USDC_MAINNET = Earnfi_Near_X402_Facilitator::USDC_MAINNET;

    /**
     * @return array{api_key: string, base_url: string}
     */
    public static function credentials(): array {
        return Earnfi_Near_X402_Facilitator::credentials('solvador');
    }

    public static function has_api_key(): bool {
        return Earnfi_Near_X402_Facilitator::has_api_key('solvador');
    }

    /**
     * @param array<string, mixed> $payment_payload
     * @param array<string, mixed> $payment_requirements
     * @return array<string, mixed>|WP_Error
     */
    public static function verify(array $payment_payload, array $payment_requirements) {
        return Earnfi_Near_X402_Facilitator::verify('solvador', $payment_payload, $payment_requirements);
    }

    /**
     * @param array<string, mixed> $payment_payload
     * @param array<string, mixed> $payment_requirements
     * @return array<string, mixed>|WP_Error
     */
    public static function settle(array $payment_payload, array $payment_requirements) {
        return Earnfi_Near_X402_Facilitator::settle('solvador', $payment_payload, $payment_requirements);
    }

    /**
     * @param array<string, mixed>|null $payment_payload_v2
     * @param array<string, mixed>|null $stored_402
     * @return array{paymentPayload: array<string, mixed>, paymentRequirements: array<string, mixed>}|WP_Error
     */
    public static function build_facilitator_request(
        array $requirements,
        ?array $payment_payload_v2 = null,
        ?array $stored_402 = null
    ) {
        return Earnfi_Near_X402_Facilitator::build_facilitator_request(
            'solvador',
            $requirements,
            $payment_payload_v2,
            $stored_402
        );
    }
}
