<?php
/**
 * NEP-413 signed message verification (matches @near-js/signers signNep413Message).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Nep413_Verify {

    private const PREFIX = 2147484061;

    /**
     * @param string $nonce_raw Exactly 32 bytes
     */
    public static function verify(
        string $message,
        string $recipient,
        string $nonce_raw,
        string $account_id,
        string $public_key_near,
        string $signature_b58,
        ?string $callback_url = null
    ): bool {
        $account_id = trim($account_id);
        if ($account_id === '' || strlen($nonce_raw) !== 32) {
            return false;
        }
        $pub_bytes = self::decode_near_public_key($public_key_near);
        $sig_bytes = self::base58_decode_safe($signature_b58);
        if ($pub_bytes === null || $sig_bytes === null || strlen($pub_bytes) !== 32 || strlen($sig_bytes) !== 64) {
            return false;
        }
        $payload = self::serialize_nep413_payload($message, $recipient, $nonce_raw, $callback_url);
        if ($payload === null) {
            return false;
        }
        $hash = hash('sha256', $payload, true);
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            error_log('EarnFi NEP-413: sodium extension required');
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig_bytes, $hash, $pub_bytes);
    }

    /**
     * @return string|null binary payload to hash
     */
    private static function serialize_nep413_payload(
        string $message,
        string $recipient,
        string $nonce_raw,
        ?string $callback_url
    ): ?string {
        $parts = pack('V', self::PREFIX);
        $parts .= self::borsh_string($message);
        $parts .= $nonce_raw;
        $parts .= self::borsh_string($recipient);
        if ($callback_url === null || $callback_url === '') {
            $parts .= "\0";
        } else {
            $parts .= "\1" . self::borsh_string($callback_url);
        }
        return $parts;
    }

    private static function borsh_string(string $s): string {
        $utf8 = $s;
        return pack('V', strlen($utf8)) . $utf8;
    }

    /**
     * @return string|null 32-byte ed25519 public key
     */
    private static function decode_near_public_key(string $key): ?string {
        $key = trim($key);
        if (stripos($key, 'ed25519:') === 0) {
            $key = substr($key, 8);
        }
        $bin = self::base58_decode_safe($key);
        return ($bin !== null && strlen($bin) === 32) ? $bin : null;
    }

    /**
     * @return string|null
     */
    private static function base58_decode_safe(string $input): ?string {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (function_exists('base58_decode')) {
            $decoded = @base58_decode($input);
            return is_string($decoded) ? $decoded : null;
        }
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num = gmp_init(0);
        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $pos = strpos($alphabet, $input[$i]);
            if ($pos === false) {
                return null;
            }
            $num = gmp_add(gmp_mul($num, 58), $pos);
        }
        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        $bin = hex2bin($hex);
        if ($bin === false) {
            return null;
        }
        $leading = 0;
        for ($i = 0, $len = strlen($input); $i < $len && $input[$i] === '1'; $i++) {
            $leading++;
        }
        return str_repeat("\0", $leading) . $bin;
    }
}
