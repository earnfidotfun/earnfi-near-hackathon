<?php
/**
 * Purpose-specific funding after Earnfi_Verified_Settlement (owner-approved purposes only).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Purpose_Funding {

    /** Inbound x402 purposes with verified handlers (see PHASE-0-OPERATION-INVENTORY.md). */
    public const APPROVED_INBOUND = [
        'job_create',
        'job_update',
        'contest_boost',
        'campaign_fund',
        'job_listing_fee',
        'store_checkout',
        'direct_payment',
        'balance_deposit',
        'creator_wallet_top_up',
        'deal_fund',
        'agent_deal_fund',
        'agent_order_fund',
        'job_contract_fund',
    ];

    /** Explicitly excluded from inbound router until product defines handler. */
    public const NOT_INBOUND = [
        'agent_payout',
    ];

    public static function is_approved_inbound(string $purpose): bool {
        $purpose = sanitize_key($purpose);
        if (in_array($purpose, self::NOT_INBOUND, true)) {
            return false;
        }
        return in_array($purpose, self::APPROVED_INBOUND, true);
    }

    public static function is_escrow_purpose(string $purpose): bool {
        return in_array(sanitize_key($purpose), ['deal_fund', 'agent_deal_fund', 'agent_order_fund', 'job_contract_fund'], true);
    }

    public static function is_job_purpose(string $purpose): bool {
        $purpose = sanitize_key($purpose);
        return in_array($purpose, ['job_create', 'job_update', 'contest_boost', 'campaign_fund', 'job_listing_fee'], true)
            || $purpose === '';
    }

    /**
     * Map settlement state after purpose handler outcome.
     */
    public static function funding_state_from_result($result): string {
        if (is_wp_error($result)) {
            return Earnfi_X402_Settlement_State::FUNDING_FAILED;
        }
        return Earnfi_X402_Settlement_State::FUNDED;
    }

    /**
     * Post-settlement purpose handler (incremental — only implemented strategies).
     *
     * @param array<string, mixed> $context user_id, idempotency_key, job_data, …
     * @return int|true|WP_Error
     */
    public static function apply(Earnfi_Verified_Settlement $settlement, array $context = []) {
        $purpose = sanitize_key($settlement->purpose !== '' ? $settlement->purpose : (string) ($context['purpose'] ?? ''));
        if ($purpose === '') {
            return new WP_Error('missing_purpose', 'Payment purpose is required.', ['status' => 400]);
        }
        if (!self::is_approved_inbound($purpose)) {
            return new WP_Error('purpose_not_inbound', 'Purpose is not an approved inbound x402 operation.', ['status' => 400]);
        }
        if ($purpose === 'balance_deposit') {
            if (!class_exists('Earnfi_X402_Balance_Deposit', false)) {
                return new WP_Error('not_ready', 'Balance deposit handler not loaded.', ['status' => 503]);
            }
            $settlement->purpose = 'balance_deposit';
            return Earnfi_X402_Balance_Deposit::apply($settlement, (int) ($context['user_id'] ?? 0), $context);
        }
        if (self::is_job_purpose($purpose)) {
            return new WP_Error(
                'use_job_funding',
                'Job purposes must use Earnfi_Rail_Job_Funding after settlement.',
                ['status' => 501]
            );
        }
        if (self::is_escrow_purpose($purpose)) {
            if (!class_exists('Earnfi_X402_Escrow_Funding', false)) {
                return new WP_Error('not_ready', 'Escrow funding handler not loaded.', ['status' => 503]);
            }
            $settlement->purpose = $purpose;
            return Earnfi_X402_Escrow_Funding::apply($settlement, $context);
        }
        return new WP_Error('purpose_not_wired', 'Purpose handler not implemented in shared router yet.', ['status' => 501]);
    }
}
