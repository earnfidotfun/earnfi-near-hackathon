<?php
/**
 * FastNEAR x402 facilitator (NEAR mainnet exact / NEP-366).
 *
 * @see https://github.com/fastnear/x402-facilitator
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Fastnear_Facilitator {

    public const USDC_MAINNET = Earnfi_Near_X402_Facilitator::USDC_MAINNET;

    /**
     * @return array{api_key: string, base_url: string}
     */
    public static function credentials(): array {
        return Earnfi_Near_X402_Facilitator::credentials('fastnear');
    }

    public static function has_api_key(): bool {
        return Earnfi_Near_X402_Facilitator::has_api_key('fastnear');
    }

    /**
     * @param array<string, mixed> $payment_payload
     * @param array<string, mixed> $payment_requirements
     * @return array<string, mixed>|WP_Error
     */
    public static function verify(array $payment_payload, array $payment_requirements) {
        return Earnfi_Near_X402_Facilitator::verify('fastnear', $payment_payload, $payment_requirements);
    }

    /**
     * @param array<string, mixed> $payment_payload
     * @param array<string, mixed> $payment_requirements
     * @return array<string, mixed>|WP_Error
     */
    public static function settle(array $payment_payload, array $payment_requirements) {
        return Earnfi_Near_X402_Facilitator::settle('fastnear', $payment_payload, $payment_requirements);
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
            'fastnear',
            $requirements,
            $payment_payload_v2,
            $stored_402
        );
    }

    /**
     * @param array<string, mixed> $requirements
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    public static function near_payment_requirements(array $requirements, array $normalized): array {
        return Earnfi_Near_X402_Facilitator::near_payment_requirements($requirements, $normalized);
    }
}
