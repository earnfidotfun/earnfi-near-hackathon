<?php
/**
 * Escrow purposes: x402 settlement evidence → lock escrow liability (not seller payout).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Escrow_Funding {

    /**
     * After on-chain settlement is recorded (settle_wallet_payment credits available), lock escrow for the entity.
     *
     * @param array<string, mixed> $context user_id, order_id|deal_id|contract_id, idempotency_key, settlement_row_id
     * @return array<string, mixed>|WP_Error
     */
    public static function apply(Earnfi_Verified_Settlement $settlement, array $context = []) {
        $purpose = sanitize_key($settlement->purpose !== '' ? $settlement->purpose : (string) ($context['purpose'] ?? ''));
        if (!Earnfi_X402_Purpose_Funding::is_escrow_purpose($purpose)) {
            return new WP_Error('invalid_purpose', 'Not an escrow funding purpose.', ['status' => 400]);
        }
        $settlement_id = (int) ($context['settlement_row_id'] ?? $settlement->settlement_row_id ?? 0);
        if ($settlement_id <= 0) {
            return new WP_Error('missing_settlement', 'Settlement evidence is required before escrow lock.', ['status' => 400]);
        }
        $user_id = (int) ($context['user_id'] ?? 0);
        $idem = Growl_Money::sanitize_idempotency_key($context['idempotency_key'] ?? '');
        if ($idem === '' && $settlement->tx_reference !== '') {
            $idem = substr($purpose . ':' . $settlement->tx_reference, 0, 64);
        }

        if ($purpose === 'agent_order_fund') {
            $order_id = (int) ($context['order_id'] ?? 0);
            if ($order_id <= 0) {
                return new WP_Error('missing_order', 'order_id is required.', ['status' => 400]);
            }
            if ($user_id <= 0) {
                return new WP_Error('auth_required', 'Authenticated buyer is required.', ['status' => 401]);
            }
            if (!class_exists('Growl_Agent_Order_Service', false)) {
                require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-order-service.php';
            }
            $funded = (new Growl_Agent_Order_Service())->fund_order($order_id, $user_id, 'x402', $settlement_id, $idem);
            if (is_wp_error($funded)) {
                return $funded;
            }
            return is_array($funded) ? $funded : ['order' => $funded];
        }

        if (in_array($purpose, ['deal_fund', 'agent_deal_fund'], true)) {
            $deal_id = (int) ($context['deal_id'] ?? $context['agent_deal_id'] ?? 0);
            if ($deal_id <= 0) {
                return new WP_Error('missing_deal', 'deal_id is required.', ['status' => 400]);
            }
            if ($user_id <= 0) {
                return new WP_Error('auth_required', 'Authenticated buyer is required.', ['status' => 401]);
            }
            if (!class_exists('Growl_Agent_Deal_Service', false)) {
                require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-agent-deal-service.php';
            }
            $funded = (new Growl_Agent_Deal_Service())->fund(
                $deal_id,
                'human',
                (string) $user_id,
                'x402',
                $settlement_id,
                $idem
            );
            if (is_wp_error($funded)) {
                return $funded;
            }
            return is_array($funded) ? $funded : ['deal' => $funded];
        }

        if ($purpose === 'job_contract_fund') {
            $contract_id = (int) ($context['contract_id'] ?? 0);
            if ($contract_id <= 0) {
                return new WP_Error('missing_contract', 'contract_id is required.', ['status' => 400]);
            }
            if ($user_id <= 0) {
                return new WP_Error('auth_required', 'Authenticated employer is required.', ['status' => 401]);
            }
            if (!class_exists('Growl_Job_Contract_Service', false)) {
                require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-job-contract-service.php';
            }
            $funded = (new Growl_Job_Contract_Service())->fund($contract_id, $user_id, 'x402', $settlement_id, $idem);
            if (is_wp_error($funded)) {
                return $funded;
            }
            return is_array($funded) ? $funded : ['contract' => $funded];
        }

        return new WP_Error('purpose_not_wired', 'Escrow handler not wired for this purpose.', ['status' => 501]);
    }

    /**
     * settle_wallet_payment (credits available) + lock escrow entity — shared escrow x402 confirm path.
     *
     * @param array<string, mixed> $metadata passed to settle_wallet_payment
     * @return array<string, mixed>|WP_Error
     */
    public static function settle_wallet_and_fund(
        int $user_id,
        string $signed_tx,
        array $requirements,
        array $metadata,
        array $entity_context
    ) {
        $payments = new Growl_X402_Payment_Service();
        $purpose = sanitize_key((string) ($metadata['purpose'] ?? ''));
        $settlement_id = $payments->settle_wallet_payment($user_id, $signed_tx, $requirements, $metadata);
        if (is_wp_error($settlement_id)) {
            return Growl_X402_Payment_Service::public_error($settlement_id);
        }
        $dto = Earnfi_Verified_Settlement::from_array([
            'purpose'           => $purpose,
            'amount_atomic'     => (string) ($metadata['amount_micro'] ?? '0'),
            'settlement_row_id' => (int) $settlement_id,
        ]);
        $entity_context['user_id'] = $user_id;
        $entity_context['settlement_row_id'] = (int) $settlement_id;
        $entity_context['purpose'] = $purpose;
        return self::apply($dto, $entity_context);
    }
}
