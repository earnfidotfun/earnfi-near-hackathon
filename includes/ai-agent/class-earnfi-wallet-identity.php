<?php
/**
 * Wallet identity rules: a Solana wallet is either an AI agent wallet (ai_agents)
 * or a human platform wallet (users), not both.
 */
if (!defined('ABSPATH')) {
    exit;
}

class EarnFi_Wallet_Identity {

    public static function is_agent_wallet(string $wallet_address): bool {
        $wallet = is_string($wallet_address) ? trim($wallet_address) : '';
        if ($wallet === '') {
            return false;
        }
        $db = new GROWL_Database();
        $row = $db->get_ai_agent_by_wallet($wallet);
        return $row && !empty($row->agent_id);
    }

    /**
     * Human wallet = users row exists and wallet is not registered as an agent.
     * Settlement stubs for agent payers are ignored when ai_agents row exists.
     */
    public static function is_human_wallet(string $wallet_address): bool {
        $wallet = is_string($wallet_address) ? trim($wallet_address) : '';
        if ($wallet === '' || self::is_agent_wallet($wallet)) {
            return false;
        }
        $db = new GROWL_Database();
        $user = $db->get_user_by_wallet($wallet, false);
        return $user && !empty($user->user_id);
    }

    /**
     * @return true|WP_Error
     */
    public static function assert_can_register_agent(string $wallet_address) {
        if (self::is_human_wallet($wallet_address)) {
            return new WP_Error(
                'wallet_reserved_for_human_user',
                'This wallet is already linked to an EarnFi human user account and cannot be registered as an AI agent wallet.',
                ['status' => 409]
            );
        }
        return true;
    }

    /**
     * @return true|WP_Error
     */
    public static function assert_can_use_human_wallet(string $wallet_address) {
        if (self::is_agent_wallet($wallet_address)) {
            return new WP_Error(
                'wallet_reserved_for_agent',
                'This wallet is registered as an AI agent wallet and cannot be used for a human EarnFi account. Use the Agent API (/register) instead.',
                ['status' => 409]
            );
        }
        return true;
    }
}
