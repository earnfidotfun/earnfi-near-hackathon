<?php
/**
 * balance_deposit: x402 settle → exactly-once EarnFi Balance credit (not direct_payment).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Balance_Deposit {

    /**
     * @param Earnfi_Verified_Settlement $settlement
     * @param int $user_id Beneficiary (authenticated user)
     * @param array<string, mixed> $context idempotency_key, intent_id
     * @return int|WP_Error ledger-related settlement_id from x402_settlements path
     */
    public static function apply(Earnfi_Verified_Settlement $settlement, $user_id, array $context = []) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('auth_required', 'Balance deposit requires an authenticated user.', ['status' => 401]);
        }
        if ($settlement->purpose !== '' && $settlement->purpose !== 'balance_deposit') {
            return new WP_Error('invalid_purpose', 'Not a balance deposit settlement.', ['status' => 400]);
        }

        $payments = new Growl_X402_Payment_Service();
        $amount   = $settlement->amount_atomic;
        if (!Growl_Money::is_positive($amount)) {
            return new WP_Error('invalid_amount', 'Invalid deposit amount.', ['status' => 400]);
        }

        $idem = Growl_Money::sanitize_idempotency_key($context['idempotency_key'] ?? '');
        if ($idem === '' && $settlement->tx_reference !== '') {
            $idem = 'balance_deposit:' . substr($settlement->tx_reference, 0, 48);
        }

        $meta = [
            'purpose'          => 'balance_deposit',
            'amount_micro'     => $amount,
            'payer_wallet'     => $settlement->payer,
            'intent_id'        => $settlement->quote_id,
            'idempotency_key'  => $idem,
            'ref_type'         => 'balance_deposit',
            'ref_id'           => (string) $user_id,
        ];

        // Re-use verified tx evidence — do not re-settle on chain.
        $settlement_id = $payments->record_settlement([
            'tx_hash'         => $settlement->tx_reference,
            'payer_wallet'    => $settlement->payer,
            'amount_micro'    => $amount,
            'purpose'         => 'balance_deposit',
            'ref_type'        => 'balance_deposit',
            'ref_id'          => (string) $user_id,
            'user_id'         => $user_id,
            'source'          => 'x402',
            'status'          => Earnfi_X402_Settlement_State::SETTLED,
            'idempotency_key' => $idem !== '' ? $idem : null,
        ]);
        if (is_wp_error($settlement_id)) {
            return $settlement_id;
        }

        $balance = new Growl_Balance_Service(new GROWL_Database());
        $credit_idem = ($idem !== '' ? $idem : ('x402_balance:' . (int) $settlement_id)) . ':credit';
        $credit = $balance->apply_ledger_entry([
            'user_id'               => $user_id,
            'delta_available_micro' => $amount,
            'delta_escrow_micro'    => '0',
            'entry_type'            => 'x402_credit',
            'purpose'               => 'balance_deposit',
            'ref_type'              => 'x402',
            'ref_id'                => (string) $settlement_id,
            'settlement_id'         => (int) $settlement_id,
            'idempotency_key'       => $credit_idem,
        ], true);
        if (is_wp_error($credit)) {
            return $credit;
        }

        return (int) $settlement_id;
    }
}
