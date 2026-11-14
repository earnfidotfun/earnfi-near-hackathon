<?php
/**
 * x402 settlement lifecycle states (plan §5.1). Persistence wiring in Phase 1.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Settlement_State {

    public const QUOTED = 'quoted';
    public const PAYMENT_SUBMITTED = 'payment_submitted';
    public const VERIFYING = 'verifying';
    public const SETTLING = 'settling';
    public const SETTLED = 'settled';
    public const FUNDING_PENDING = 'funding_pending';
    public const FUNDED = 'funded';
    /** Purpose handler failed; retry fulfillment from settlement record — do not re-collect payment. */
    public const FUNDING_FAILED = 'funding_failed';

    public const VERIFICATION_FAILED = 'verification_failed';
    public const SETTLEMENT_FAILED = 'settlement_failed';
    public const SETTLEMENT_UNKNOWN = 'settlement_unknown';
    public const RECONCILIATION_REQUIRED = 'reconciliation_required';
    public const EXPIRED = 'expired';

    /**
     * @return string[]
     */
    public static function terminal_states(): array {
        return [
            self::FUNDED,
            self::VERIFICATION_FAILED,
            self::SETTLEMENT_FAILED,
            self::EXPIRED,
        ];
    }

    /**
     * Funding may be retried without a new x402 payment.
     *
     * @return string[]
     */
    public static function funding_retryable_states(): array {
        return [self::SETTLED, self::FUNDING_PENDING, self::FUNDING_FAILED];
    }

    /**
     * @param string $from
     * @param string $to
     */
    public static function can_transition($from, $to): bool {
        $from = sanitize_key((string) $from);
        $to   = sanitize_key((string) $to);
        $allowed = [
            self::QUOTED => [self::PAYMENT_SUBMITTED, self::EXPIRED],
            self::PAYMENT_SUBMITTED => [self::VERIFYING, self::EXPIRED],
            self::VERIFYING => [self::SETTLING, self::VERIFICATION_FAILED],
            self::SETTLING => [self::SETTLED, self::SETTLEMENT_FAILED, self::SETTLEMENT_UNKNOWN],
            self::SETTLEMENT_UNKNOWN => [self::RECONCILIATION_REQUIRED, self::SETTLED],
            self::RECONCILIATION_REQUIRED => [self::SETTLED, self::SETTLEMENT_FAILED],
            self::SETTLED => [self::FUNDING_PENDING, self::FUNDED],
            self::FUNDING_PENDING => [self::FUNDED, self::FUNDING_FAILED],
            self::FUNDING_FAILED => [self::FUNDING_PENDING, self::FUNDED],
        ];
        return isset($allowed[$from]) && in_array($to, $allowed[$from], true);
    }

    /**
     * Facilitator verify success must not imply this state.
     */
    public static function is_settled(string $state): bool {
        return in_array($state, [self::SETTLED, self::FUNDING_PENDING, self::FUNDED], true);
    }

    /**
     * Business purpose completed (job, escrow, balance credit, …).
     */
    public static function is_funded(string $state): bool {
        return $state === self::FUNDED;
    }
}
