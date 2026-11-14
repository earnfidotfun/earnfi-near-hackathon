<?php
/**
 * Match client-signed requirements to exactly one stored quote accept (server-authoritative).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Payment_Method_Selector {

    /**
     * @param array<string, mixed> $requirements From PAYMENT-SIGNATURE (requirements or accepted).
     * @param array<string, mixed>|null $stored_402 Full persisted 402 body.
     * @return array{accept: array<string,mixed>, index: int, payment_method_id: string, facilitator_id: string}|WP_Error
     */
    public static function resolve(array $requirements, ?array $stored_402) {
        if ($stored_402 === null || empty($stored_402['accepts']) || !is_array($stored_402['accepts'])) {
            if (!empty($requirements)) {
                $fac = class_exists('Earnfi_Near_X402_Facilitator', false)
                    ? Earnfi_Near_X402_Facilitator::resolve_facilitator_id($requirements)
                    : Earnfi_X402_Payment_Method_Id::infer_facilitator_from_network((string) ($requirements['network'] ?? ''));
                return [
                    'accept'              => $requirements,
                    'index'               => 0,
                    'payment_method_id'   => Earnfi_X402_Payment_Method_Id::from_accept($requirements, $fac),
                    'facilitator_id'      => $fac,
                ];
            }
            return new WP_Error('quote_missing', 'Payment quote not found or expired.', ['status' => 400]);
        }

        $matches = PayAI_X402_Quote_Store::find_matching_accept_indices($requirements, $stored_402['accepts']);
        if (count($matches) === 0) {
            return new WP_Error(
                'accept_mismatch',
                'Signed payment does not match any option from the original quote.',
                ['status' => 400]
            );
        }
        if (count($matches) > 1) {
            return new WP_Error(
                'accept_ambiguous',
                'Signed payment matches more than one quote option.',
                ['status' => 400]
            );
        }

        $index  = (int) $matches[0];
        $accept = $stored_402['accepts'][$index];
        if (!is_array($accept)) {
            return new WP_Error('accept_invalid', 'Stored quote accept is invalid.', ['status' => 500]);
        }

        $fac = Earnfi_X402_Payment_Method_Id::infer_facilitator_from_network((string) ($accept['network'] ?? ''));
        if (!empty($accept['extra']['facilitator'])) {
            $fac = sanitize_key((string) $accept['extra']['facilitator']);
        }

        return [
            'accept'            => $accept,
            'index'             => $index,
            'payment_method_id' => Earnfi_X402_Payment_Method_Id::from_accept($accept, $fac),
            'facilitator_id'    => $fac,
        ];
    }

    /**
     * Reject method switch when a prior attempt exists for the same quote.
     *
     * @param array<string, mixed> $prior_attempt
     * @param string $new_payment_method_id
     * @return true|WP_Error
     */
    public static function assert_no_method_switch(array $prior_attempt, $new_payment_method_id) {
        $prev = sanitize_text_field((string) ($prior_attempt['payment_method_id'] ?? ''));
        $new  = sanitize_text_field((string) $new_payment_method_id);
        if ($prev !== '' && $new !== '' && $prev !== $new) {
            return new WP_Error(
                'payment_method_switch',
                'Cannot change payment method on retry for the same quote.',
                ['status' => 400]
            );
        }
        return true;
    }
}
