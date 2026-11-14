<?php
/**
 * Per-user linked wallets by CAIP-2 (auth, x402 payment, Solana withdrawal).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_User_Chain_Wallets {

    public const LINK_AUTH       = 'auth';
    public const LINK_PAYMENT    = 'payment';
    public const LINK_WITHDRAWAL = 'withdrawal';

    /** @var GROWL_Database */
    private $database;

    public function __construct(?GROWL_Database $database = null) {
        $this->database = $database ?: new GROWL_Database();
    }

    public function table(): string {
        return $this->database->table_prefix . 'user_chain_wallets';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_for_user(int $user_id): array {
        if ($user_id <= 0) {
            return [];
        }
        $rows = $this->database->wpdb->get_results(
            $this->database->wpdb->prepare(
                "SELECT chain_caip2, wallet_address, link_type, wallet_type, verified_at, created_at, updated_at
                 FROM {$this->table()} WHERE user_id = %d ORDER BY link_type, chain_caip2",
                $user_id
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'chain_caip2'    => (string) ($row['chain_caip2'] ?? ''),
                'wallet_address' => (string) ($row['wallet_address'] ?? ''),
                'link_type'      => (string) ($row['link_type'] ?? ''),
                'wallet_type'    => (string) ($row['wallet_type'] ?? ''),
                'verified_at'    => $row['verified_at'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return object|null users row
     */
    public function find_user_by_chain_wallet(string $chain_caip2, string $wallet_address) {
        $wallet_address = $this->normalize_address($chain_caip2, $wallet_address);
        if ($wallet_address === '') {
            return null;
        }
        $user = $this->database->get_user_by_wallet($wallet_address, false);
        if ($user && !empty($user->user_id)) {
            return $user;
        }
        $row = $this->database->wpdb->get_row(
            $this->database->wpdb->prepare(
                "SELECT u.* FROM {$this->table()} l
                 INNER JOIN {$this->database->table_prefix}users u ON u.user_id = l.user_id
                 WHERE l.chain_caip2 = %s AND l.wallet_address = %s AND l.link_type IN ('auth','payment')
                 LIMIT 1",
                $chain_caip2,
                $wallet_address
            )
        );
        return $row ?: null;
    }

    /**
     * @return true|WP_Error
     */
    public function upsert_link(
        int $user_id,
        string $chain_caip2,
        string $wallet_address,
        string $link_type,
        ?string $wallet_type = null
    ) {
        $wallet_address = $this->normalize_address($chain_caip2, $wallet_address);
        if ($user_id <= 0 || $wallet_address === '') {
            return new WP_Error('invalid_params', 'Invalid wallet link.', ['status' => 400]);
        }
        if (!in_array($link_type, [self::LINK_AUTH, self::LINK_PAYMENT, self::LINK_WITHDRAWAL], true)) {
            return new WP_Error('invalid_params', 'Invalid link type.', ['status' => 400]);
        }
        if ($link_type === self::LINK_WITHDRAWAL
            && strpos($chain_caip2, 'solana:') !== 0) {
            return new WP_Error(
                'withdrawal_solana_only',
                'Withdrawals are only supported to Solana addresses.',
                ['status' => 400]
            );
        }
        $valid = Earnfi_Wallet_Address_Validator::validate_for_network($wallet_address, $chain_caip2);
        if (is_wp_error($valid)) {
            return $valid;
        }
        if ($wallet_type === null || $wallet_type === '') {
            $wallet_type = Earnfi_Wallet_Address_Validator::infer_wallet_type($wallet_address, ['network' => $chain_caip2]);
        }

        $existing_owner = $this->database->wpdb->get_var(
            $this->database->wpdb->prepare(
                "SELECT user_id FROM {$this->table()}
                 WHERE chain_caip2 = %s AND wallet_address = %s AND user_id <> %d LIMIT 1",
                $chain_caip2,
                $wallet_address,
                $user_id
            )
        );
        if ($existing_owner) {
            return new WP_Error('wallet_in_use', 'This wallet is linked to another account.', ['status' => 409]);
        }

        $now = current_time('mysql');
        $table = $this->table();
        $updated = $this->database->wpdb->query(
            $this->database->wpdb->prepare(
                "INSERT INTO {$table}
                    (user_id, chain_caip2, wallet_address, link_type, wallet_type, verified_at, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    wallet_address = VALUES(wallet_address),
                    wallet_type = VALUES(wallet_type),
                    verified_at = VALUES(verified_at),
                    updated_at = VALUES(updated_at)",
                $user_id,
                $chain_caip2,
                $wallet_address,
                $link_type,
                $wallet_type,
                $now,
                $now,
                $now
            )
        );
        if ($updated === false) {
            return new WP_Error('db_error', 'Could not save wallet link.', ['status' => 500]);
        }
        wp_cache_delete('user_profile_' . $user_id, 'growl_user_profile');
        return true;
    }

    public function normalize_address(string $chain_caip2, string $address): string {
        $address = trim($address);
        if (strpos($chain_caip2, 'eip155:') === 0 && class_exists('Earnfi_Evm_Personal_Sign', false)) {
            return Earnfi_Evm_Personal_Sign::normalize_address($address);
        }
        if (strpos($chain_caip2, 'near:') === 0) {
            return strtolower($address);
        }
        return $address;
    }
}
