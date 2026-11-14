<?php
/**
 * EIP-191 personal_sign verification for EarnFi Agent API (OKX / EVM rail).
 *
 * Uses a compact GMP-based ecrecover (attribution: marcmasmar/php-ecrecover-simple,
 * derived from wmh/php-ecrecover + kornrunner/keccak patterns).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Evm_Personal_Sign {

    public const HINT = 'Prefer ethers Wallet.signMessage(message) or viem personalSign({ message }). Return 0x + 130 hex chars (65 bytes). EIP-191 prefixes with UTF-8 byte length — if hand-rolling, use Buffer.byteLength(message, "utf8") / TextEncoder, never JS string.length. Sign the exact challenge message string.';

    /**
     * @param string $message Exact UTF-8 message that was signed
     * @param string $signature Hex signature (0x… or bare 130+ hex)
     * @param string $expected_address 0x… address (case-insensitive)
     */
    public static function verify(string $message, string $signature, string $expected_address): bool {
        $expected = self::normalize_address($expected_address);
        if ($expected === '' || $message === '') {
            return false;
        }
        $recovered = self::recover_address($message, $signature);
        if ($recovered === '') {
            return false;
        }
        return hash_equals($expected, $recovered);
    }

    /**
     * @return string lowercase 0x address or ''
     */
    public static function recover_address(string $message, string $signature): string {
        if (!extension_loaded('gmp')) {
            error_log('EarnFi EVM personal_sign: GMP extension required');
            return '';
        }
        $sig = self::normalize_signature_hex($signature);
        if ($sig === '') {
            return '';
        }
        $lib = GROWL_PLUGIN_PATH . 'includes/lib/evm/ecrecover-simple.php';
        if (!is_readable($lib)) {
            error_log('EarnFi EVM personal_sign: ecrecover library missing');
            return '';
        }
        require_once $lib;
        if (!function_exists('\\ECRecoverSimple\\fromMessage')) {
            return '';
        }
        try {
            $addr = \ECRecoverSimple\fromMessage($message, $sig);
            return self::normalize_address(is_string($addr) ? $addr : '');
        } catch (\Throwable $e) {
            error_log('EarnFi EVM personal_sign: ' . $e->getMessage());
            return '';
        }
    }

    public static function normalize_address(string $addr): string {
        $a = strtolower(trim($addr));
        if (!preg_match('/^0x[a-f0-9]{40}$/', $a)) {
            return '';
        }
        return $a;
    }

    /**
     * @return string 0x + 130 hex chars, or ''
     */
    public static function normalize_signature_hex($raw): string {
        if (is_array($raw)) {
            if (count($raw) !== 65) {
                return '';
            }
            $bin = '';
            foreach ($raw as $b) {
                if (!is_numeric($b)) {
                    return '';
                }
                $v = (int) $b;
                if ($v < 0 || $v > 255) {
                    return '';
                }
                $bin .= chr($v);
            }
            return '0x' . bin2hex($bin);
        }
        if (!is_string($raw)) {
            return '';
        }
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        if (strpos($s, '0x') === 0 || strpos($s, '0X') === 0) {
            $s = substr($s, 2);
        }
        // base64 of 65 bytes
        if (!preg_match('/^[0-9a-fA-F]{130}$/', $s)) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $raw), true);
            if (is_string($decoded) && strlen($decoded) === 65) {
                $s = bin2hex($decoded);
            }
        }
        if (!preg_match('/^[0-9a-fA-F]{130}$/', $s)) {
            return '';
        }
        return '0x' . strtolower($s);
    }
}
