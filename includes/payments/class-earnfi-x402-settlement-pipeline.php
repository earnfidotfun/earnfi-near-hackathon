<?php
/**
 * Shared facilitator verify/settle + payment-method binding (no job/escrow side effects).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Settlement_Pipeline {

    /**
     * Bind stored quote accept; enforce no method switch when multiple accepts exist.
     *
     * @param array<string, mixed> $requirements
     * @return true|WP_Error
     */
    public static function bind_payment_method(array &$requirements) {
        if (!class_exists('PayAI_X402_Quote_Store', false) || !class_exists('Earnfi_X402_Payment_Method_Selector', false)) {
            return true;
        }
        $stored_quote = PayAI_X402_Quote_Store::resolve_stored_for_settlement($requirements);
        $accept_count = is_array($stored_quote) && !empty($stored_quote['accepts']) && is_array($stored_quote['accepts'])
            ? count($stored_quote['accepts'])
            : 0;
        if ($accept_count <= 0) {
            return true;
        }
        $selected = Earnfi_X402_Payment_Method_Selector::resolve($requirements, $stored_quote);
        if (is_wp_error($selected)) {
            return $accept_count > 1 ? $selected : true;
        }
        if (!is_array($selected) || empty($selected['accept'])) {
            return true;
        }
        $requirements = array_merge($selected['accept'], $requirements);
        if (!isset($requirements['maxAmountRequired']) && isset($requirements['amount'])) {
            $requirements['maxAmountRequired'] = $requirements['amount'];
        }
        $quote_id = sanitize_text_field((string) ($selected['accept']['extra']['intentId'] ?? ''));
        if ($quote_id !== '' && class_exists('Earnfi_X402_Payment_Attempt_Store', false)) {
            $prior = Earnfi_X402_Payment_Attempt_Store::get_latest_for_quote($quote_id);
            if (is_array($prior)) {
                $switch = Earnfi_X402_Payment_Method_Selector::assert_no_method_switch(
                    $prior,
                    (string) $selected['payment_method_id']
                );
                if (is_wp_error($switch)) {
                    return $switch;
                }
            }
            Earnfi_X402_Payment_Attempt_Store::create([
                'quote_id'          => $quote_id,
                'intent_id'         => $quote_id,
                'payment_method_id' => (string) $selected['payment_method_id'],
                'accept_index'      => (int) $selected['index'],
                'facilitator_id'    => (string) $selected['facilitator_id'],
                'state'             => Earnfi_X402_Settlement_State::PAYMENT_SUBMITTED,
            ]);
        }
        return true;
    }

    /**
     * @param array<string, mixed>|null $payment_payload_v2
     * @return array{settlement: array<string, mixed>, tx_hash: string, payer: string}|WP_Error
     */
    public static function facilitator_verify_settle(string $signed_tx, array $requirements, $payment_payload_v2 = null) {
        $signed_tx = trim($signed_tx);
        if ($signed_tx === '' || empty($requirements)) {
            return new WP_Error('invalid_params', 'Payment could not be verified.', ['status' => 400]);
        }
        if (!isset($requirements['maxAmountRequired']) && isset($requirements['amount'])) {
            $requirements['maxAmountRequired'] = $requirements['amount'];
        }
        $bind = self::bind_payment_method($requirements);
        if (is_wp_error($bind)) {
            return $bind;
        }
        $network = (string) ($requirements['network'] ?? '');
        if (class_exists('Earnfi_X402_Rail_Limits', false)) {
            $atomic = (string) ($requirements['amount'] ?? $requirements['maxAmountRequired'] ?? '');
            if ($atomic !== '') {
                $cap_check = Earnfi_X402_Rail_Limits::assert_atomic_within_cap($network, $atomic);
                if (is_wp_error($cap_check)) {
                    return $cap_check;
                }
            }
        }
        $facilitator_id = class_exists('Earnfi_Near_X402_Facilitator', false)
            ? Earnfi_Near_X402_Facilitator::resolve_facilitator_id($requirements)
            : Earnfi_X402_Payment_Method_Id::infer_facilitator_from_network($network);
        $stored = PayAI_X402_Quote_Store::resolve_stored_for_settlement($requirements);

        if (class_exists('Earnfi_Near_X402_Facilitator', false)
            && Earnfi_Near_X402_Facilitator::is_near_facilitator($facilitator_id)) {
            $fac_req = Earnfi_Near_X402_Facilitator::build_facilitator_request(
                $facilitator_id,
                $requirements,
                is_array($payment_payload_v2) ? $payment_payload_v2 : null,
                $stored
            );
            if (is_wp_error($fac_req)) {
                return $fac_req;
            }
            $verification = Earnfi_Near_X402_Facilitator::verify(
                $facilitator_id,
                $fac_req['paymentPayload'],
                $fac_req['paymentRequirements']
            );
            if (is_wp_error($verification)) {
                return new WP_Error('verification_failed', $verification->get_error_message(), ['status' => 400]);
            }
            if (isset($verification['isValid']) && $verification['isValid'] === false) {
                $reason = (string) ($verification['invalidReason'] ?? $verification['invalidMessage'] ?? 'Payment verification failed');
                $message = class_exists('PayAI_Payments', false)
                    ? PayAI_Payments::public_message_for_facilitator_error($reason)
                    : $reason;
                return new WP_Error('verification_failed', $message, ['status' => 400, 'invalid_reason' => $reason]);
            }
            $settlement = Earnfi_Near_X402_Facilitator::settle(
                $facilitator_id,
                $fac_req['paymentPayload'],
                $fac_req['paymentRequirements']
            );
        } else {
            if (!class_exists('PayAI_Payments')) {
                return new WP_Error('payment_unavailable', 'Payment could not be processed. Try again.', ['status' => 503]);
            }
            $verification = PayAI_Payments::verify($signed_tx, $requirements, $payment_payload_v2, $stored);
            if (isset($verification['error']) || (isset($verification['isValid']) && $verification['isValid'] === false)) {
                $reason = (string) ($verification['invalidReason'] ?? $verification['error'] ?? '');
                $message = ($reason !== '' && class_exists('PayAI_Payments', false))
                    ? PayAI_Payments::public_message_for_facilitator_error($reason)
                    : 'Payment could not be verified.';
                return new WP_Error('verification_failed', $message, ['status' => 400, 'invalid_reason' => $reason]);
            }
            $settlement = PayAI_Payments::settle($signed_tx, $requirements, $payment_payload_v2, $stored);
        }

        if (is_wp_error($settlement)) {
            return new WP_Error('settlement_failed', $settlement->get_error_message(), ['status' => 400]);
        }
        if (isset($settlement['error']) || (isset($settlement['success']) && $settlement['success'] === false)) {
            return new WP_Error('settlement_failed', 'Payment could not be verified.', ['status' => 400]);
        }
        if (!is_array($settlement)) {
            return new WP_Error('settlement_failed', 'Payment could not be verified.', ['status' => 400]);
        }
        $tx_hash = sanitize_text_field((string) (
            $settlement['transaction']
            ?? $settlement['transactionId']
            ?? $settlement['txSignature']
            ?? $settlement['signature']
            ?? ''
        ));
        if ($tx_hash === '') {
            return new WP_Error('settlement_failed', 'No transaction hash received from settlement.', ['status' => 400]);
        }
        $payer = sanitize_text_field((string) (
            $settlement['payer']
            ?? $settlement['payerAddress']
            ?? $settlement['from']
            ?? ''
        ));
        return [
            'settlement' => $settlement,
            'tx_hash'    => $tx_hash,
            'payer'      => $payer,
        ];
    }

    /**
     * @param array<string, mixed> $requirements
     * @param array<string, mixed> $facilitator_settlement
     */
    public static function verified_settlement_from(
        array $facilitator_settlement,
        array $requirements,
        string $purpose,
        string $resource_url = ''
    ): Earnfi_Verified_Settlement {
        $amount = (string) ($requirements['maxAmountRequired'] ?? $requirements['amount'] ?? '0');
        $network = (string) ($requirements['network'] ?? $facilitator_settlement['network'] ?? '');
        $tx = sanitize_text_field((string) (
            $facilitator_settlement['transaction']
            ?? $facilitator_settlement['transactionId']
            ?? $facilitator_settlement['txSignature']
            ?? $facilitator_settlement['signature']
            ?? ''
        ));
        $payer = sanitize_text_field((string) (
            $facilitator_settlement['payer']
            ?? $facilitator_settlement['payerAddress']
            ?? ''
        ));
        return Earnfi_Verified_Settlement::from_array([
            'tx_reference'  => $tx,
            'network_caip2' => $network,
            'scheme'        => (string) ($requirements['scheme'] ?? 'exact'),
            'asset'         => (string) ($requirements['asset'] ?? ''),
            'amount_atomic' => $amount,
            'payer'         => $payer,
            'pay_to'        => (string) ($requirements['payTo'] ?? ''),
            'facilitator_id'=> class_exists('Earnfi_Near_X402_Facilitator', false)
                ? Earnfi_Near_X402_Facilitator::resolve_facilitator_id($requirements)
                : Earnfi_X402_Payment_Method_Id::infer_facilitator_from_network($network),
            'purpose'       => sanitize_key($purpose),
            'quote_id'      => (string) ($requirements['extra']['intentId'] ?? ''),
            'resource_url'  => $resource_url,
        ]);
    }

    /**
     * @return array{signed_tx: string, requirements: array, payment_payload_v2?: array}|WP_Error
     */
    public static function parse_request_payment(WP_REST_Request $request) {
        $header = '';
        foreach (['payment-signature', 'PAYMENT-SIGNATURE', 'payment_signature'] as $h) {
            $v = $request->get_header($h);
            if (is_string($v) && trim($v) !== '') {
                $header = trim($v);
                break;
            }
        }
        if ($header === '') {
            $header = trim((string) ($request->get_param('payment_signature') ?? ''));
        }
        if ($header === '' || !class_exists('PayAI_Flow', false)) {
            return new WP_Error('missing_payment_signature', 'PAYMENT-SIGNATURE header is required.', ['status' => 402]);
        }
        return PayAI_Flow::parse_payment_signature_header($header);
    }
}
