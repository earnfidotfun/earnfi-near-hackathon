<?php
/**
 * Immutable DTO after adapter-confirmed settlement (plan §5.5). Not produced by /verify alone.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Verified_Settlement {

    /** @var string */
    public $tx_reference;

    /** @var string CAIP-2 */
    public $network_caip2;

    /** @var string */
    public $scheme;

    /** @var string */
    public $asset;

    /** @var string Atomic amount string */
    public $amount_atomic;

    /** @var string */
    public $payer;

    /** @var string */
    public $pay_to;

    /** @var string Internal facilitator id */
    public $facilitator_id;

    /** @var string */
    public $purpose;

    /** @var string */
    public $quote_id;

    /** @var string */
    public $payment_attempt_id;

    /** @var string */
    public $resource_url;

    /** @var int|null */
    public $settlement_row_id;

    /**
     * @param array<string, mixed> $row
     */
    public static function from_array(array $row): self {
        $dto = new self();
        $dto->tx_reference       = sanitize_text_field((string) ($row['tx_reference'] ?? $row['tx_hash'] ?? ''));
        $dto->network_caip2      = sanitize_text_field((string) ($row['network_caip2'] ?? $row['network'] ?? ''));
        $dto->scheme             = sanitize_key((string) ($row['scheme'] ?? 'exact'));
        $dto->asset              = sanitize_text_field((string) ($row['asset'] ?? ''));
        $dto->amount_atomic      = (string) ($row['amount_atomic'] ?? $row['amount_micro'] ?? '0');
        $dto->payer              = sanitize_text_field((string) ($row['payer'] ?? $row['payer_wallet'] ?? ''));
        $dto->pay_to             = sanitize_text_field((string) ($row['pay_to'] ?? $row['payTo'] ?? ''));
        $dto->facilitator_id     = sanitize_key((string) ($row['facilitator_id'] ?? ''));
        $dto->purpose            = sanitize_key((string) ($row['purpose'] ?? ''));
        $dto->quote_id           = sanitize_text_field((string) ($row['quote_id'] ?? $row['intent_id'] ?? ''));
        $dto->payment_attempt_id = sanitize_text_field((string) ($row['payment_attempt_id'] ?? ''));
        $dto->resource_url       = esc_url_raw((string) ($row['resource_url'] ?? ''));
        $dto->settlement_row_id  = isset($row['settlement_row_id']) ? (int) $row['settlement_row_id'] : null;
        return $dto;
    }

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'tx_reference'       => $this->tx_reference,
            'network_caip2'      => $this->network_caip2,
            'scheme'             => $this->scheme,
            'asset'              => $this->asset,
            'amount_atomic'      => $this->amount_atomic,
            'payer'              => $this->payer,
            'pay_to'             => $this->pay_to,
            'facilitator_id'     => $this->facilitator_id,
            'purpose'            => $this->purpose,
            'quote_id'           => $this->quote_id,
            'payment_attempt_id' => $this->payment_attempt_id,
            'resource_url'       => $this->resource_url,
            'settlement_row_id'  => $this->settlement_row_id,
        ];
    }
}
