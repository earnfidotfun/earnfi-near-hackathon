<?php
// /wp-content/plugins/growl-fun/includes/class-security.php
class GROWL_Security {
    
    private $encryption_method = 'AES-256-CBC';
    private $encryption_key;
    
    public function __construct() {
        $this->encryption_key = GROWL_ENCRYPTION_KEY;
    }
    
    // ENHANCED: API Request Security Validation
    public function validate_api_request($request) {
        $ip = $this->get_client_ip();
        $user_agent = $request->get_header('User-Agent');
        $endpoint = $request->get_route();
        
        // Rate limiting by IP and endpoint
        $rate_limit_key = 'api_rate_' . md5($ip . $endpoint);
        
        if (!$this->rate_limit_check($rate_limit_key, 'api_request', 100, 60)) {
            error_log("GROWL_SECURITY: Rate limit exceeded for IP: {$ip}, Endpoint: {$endpoint}");
            throw new Exception('Rate limit exceeded. Please try again later.');
        }
        
        // Validate User-Agent
        if (empty($user_agent) || strlen($user_agent) > 500) {
            throw new Exception('Invalid User-Agent');
        }
        
        // Check for suspicious patterns in request data
        $params = $request->get_params();
        foreach ($params as $key => $value) {
            if (is_string($value) && $this->contains_malicious_patterns($value)) {
                $this->log_security_event('malicious_input_detected', [
                    'ip' => $ip,
                    'endpoint' => $endpoint,
                    'parameter' => $key,
                    'value_preview' => substr($value, 0, 100)
                ]);
                throw new Exception('Suspicious input detected');
            }
        }
        
        return true;
    }
    
    // ENHANCED: SQL Injection Protection
    public function sanitize_sql_input($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitize_sql_input'], $input);
        }
        
        // Remove potential SQL injection patterns
        $input = preg_replace('/\b(UNION|SELECT|INSERT|DELETE|UPDATE|DROP|EXEC|ALTER|CREATE|TRUNCATE)\b/i', '', $input);
        
        // Additional security cleaning
        $input = preg_replace('/[\x00-\x1F\x7F]/', '', $input); // Remove control characters
        
        return $input;
    }
    
    // ENHANCED: Secure Nonce Generation
    public function generate_secure_nonce($action = 'default', $expiry = 3600) {
        $nonce = wp_create_nonce('growl_secure_' . $action);
        $token = bin2hex(random_bytes(16));
        
        // Store with expiry for additional security
        set_transient('growl_nonce_' . $token, [
            'nonce' => $nonce,
            'action' => $action,
            'created' => time()
        ], $expiry);
        
        return $token;
    }
    
    public function verify_secure_nonce($token, $action = 'default') {
        $stored_data = get_transient('growl_nonce_' . $token);
        
        if (!$stored_data || $stored_data['action'] !== $action) {
            return false;
        }
        
        $valid = wp_verify_nonce($stored_data['nonce'], 'growl_secure_' . $action);
        
        // Clean up used nonce regardless of validity
        delete_transient('growl_nonce_' . $token);
        
        return $valid;
    }
    
    // ENHANCED: JWT Security with Additional Claims
    public function generate_secure_jwt($payload) {
        // Add security claims
        $payload['iss'] = get_site_url(); // Issuer
        $payload['aud'] = 'growl.fun'; // Audience
        $payload['iat'] = time(); // Issued at
        $payload['nbf'] = time() - 30; // Not before 30 seconds ago
        $payload['jti'] = bin2hex(random_bytes(16)); // Unique token ID
        
        if (!isset($payload['exp'])) {
            $payload['exp'] = time() + (24 * 60 * 60); // 24 hours default
        }
        
        return $this->generate_jwt($payload);
    }
    
    public function verify_secure_jwt($jwt) {
        $payload = $this->verify_jwt($jwt);
        
        if (!$payload) {
            return false;
        }
        
        // Validate claims
        if (!isset($payload['iss']) || $payload['iss'] !== get_site_url()) {
            error_log('GROWL_JWT: Invalid issuer');
            return false;
        }
        
        // Audience is required only when present (legacy wallet-issued tokens may omit `aud`).
        if (isset($payload['aud']) && $payload['aud'] !== 'growl.fun') {
            error_log('GROWL_JWT: Invalid audience');
            return false;
        }
        
        if (isset($payload['nbf']) && $payload['nbf'] > time()) {
            error_log('GROWL_JWT: Token not yet valid');
            return false;
        }
        
        // Check if token was revoked
        if (isset($payload['jti']) && $this->is_token_revoked($payload['jti'])) {
            error_log('GROWL_JWT: Token revoked');
            return false;
        }
        
        return $payload;
    }
    
    private function is_token_revoked($token_id) {
        return get_transient('growl_revoked_token_' . $token_id) !== false;
    }
    
    public function revoke_token($token_id, $expiry = 86400) {
        set_transient('growl_revoked_token_' . $token_id, true, $expiry);
    }
    
    // ENHANCED: Malicious Pattern Detection
    private function contains_malicious_patterns($input) {
        $patterns = [
            '/<script\b[^>]*>(.*?)<\/script>/is', // Script tags
            '/javascript:/i', // JavaScript protocol
            '/on\w+\s*=/i', // Event handlers
            '/union\s+select/i', // SQL injection
            '/sleep\s*\(\s*\d+\s*\)/i', // SQL delay
            '/benchmark\s*\(/i', // SQL benchmark
            '/\.\.\//', // Directory traversal
            '/\b(alert|confirm|prompt)\s*\(/i', // JS alerts
            '/<iframe/i', // IFrame tags
            '/<object/i', // Object tags
            '/<embed/i', // Embed tags
            '/<applet/i', // Applet tags
            '/<meta\s+http-equiv=/i', // Meta refresh
            '/expression\s*\(/i', // CSS expressions
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                error_log("GROWL_SECURITY: Malicious pattern detected: {$pattern}");
                return true;
            }
        }
        
        return false;
    }
    
    // ENHANCED: Client IP Detection
    public function get_client_ip() {
        // REMOTE_ADDR is always the direct peer — it cannot be spoofed.
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Only trust forwarded-IP headers when the direct peer is a known trusted proxy.
        // Define GROWL_TRUSTED_PROXIES in wp-config.php as a comma-separated list of
        // proxy CIDRs/IPs, e.g. define('GROWL_TRUSTED_PROXIES', '10.0.0.1,172.16.0.0/12').
        // You can also use the filter 'growl_trusted_proxies' to supply the list dynamically.
        $trusted_proxies_raw = defined('GROWL_TRUSTED_PROXIES') ? GROWL_TRUSTED_PROXIES : '';
        $trusted_proxies_raw = (string) apply_filters('growl_trusted_proxies', $trusted_proxies_raw);
        $trusted_proxies     = array_filter(array_map('trim', explode(',', $trusted_proxies_raw)));

        if (!empty($trusted_proxies) && $this->ip_in_list($remote_addr, $trusted_proxies)) {
            // Peer is a trusted proxy — read the client IP from forwarded headers.
            $forwarded_headers = [
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
                'HTTP_CLIENT_IP',
                'HTTP_X_CLUSTER_CLIENT_IP',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
            ];
            foreach ($forwarded_headers as $key) {
                if (!empty($_SERVER[$key])) {
                    // X-Forwarded-For may be a comma-separated list; leftmost is the client.
                    $ip = trim(current(explode(',', $_SERVER[$key])));
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        // No trusted proxy configured or peer is not a proxy — use REMOTE_ADDR directly.
        return filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '0.0.0.0';
    }

    /**
     * Check whether $ip is contained in any of the $list entries (plain IP or CIDR).
     */
    private function ip_in_list($ip, array $list) {
        foreach ($list as $entry) {
            if (strpos($entry, '/') !== false) {
                if ($this->ip_in_cidr($ip, $entry)) {
                    return true;
                }
            } elseif ($ip === $entry) {
                return true;
            }
        }
        return false;
    }

    /**
     * Test whether $ip falls within a $cidr range (IPv4 only).
     */
    private function ip_in_cidr($ip, $cidr) {
        list($subnet, $bits) = explode('/', $cidr, 2);
        $bits   = (int) $bits;
        $ip_int = ip2long($ip);
        $sub_int = ip2long($subnet);
        if ($ip_int === false || $sub_int === false || $bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
        return ($ip_int & $mask) === ($sub_int & $mask);
    }
    
    // ENHANCED: Security Event Logging
    public function log_security_event($event_type, $details = []) {
        global $wpdb;
        
        $wpdb->insert("{$wpdb->prefix}growl_audit_log", [
            'user_id' => get_current_user_id() ?: 0,
            'action_type' => $event_type,
            'object_type' => 'security',
            'object_id' => 0,
            'details' => json_encode(array_merge($details, [
                'ip_address' => $this->get_client_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'timestamp' => current_time('mysql')
            ])),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => current_time('mysql')
        ]);
        
        // Also log to error log for immediate visibility
        error_log("GROWL_SECURITY_EVENT: {$event_type} - " . json_encode($details));
    }
    
    // ENHANCED: Input Validation for Specific Types
    public function validate_email($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Additional email validation
        if (strlen($email) > 254) {
            return false;
        }
        
        return sanitize_email($email);
    }
    
    public function validate_url($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Only allow HTTP/HTTPS URLs
        if (!preg_match('/^https?:\/\//i', $url)) {
            return false;
        }
        
        return esc_url_raw($url);
    }
    
    public function validate_numeric_range($value, $min = null, $max = null) {
        if (!is_numeric($value)) {
            return false;
        }
        
        $value = floatval($value);
        
        if ($min !== null && $value < $min) {
            return false;
        }
        
        if ($max !== null && $value > $max) {
            return false;
        }
        
        return $value;
    }
    
    // ENHANCED: Bulk Data Validation
    public function validate_bulk_data($data, $validation_rules) {
        $errors = [];
        $validated_data = [];
        
        foreach ($validation_rules as $field => $rules) {
            $value = $data[$field] ?? null;
            
            foreach ($rules as $rule => $param) {
                switch ($rule) {
                    case 'required':
                        if ($param && empty($value)) {
                            $errors[] = "{$field} is required";
                        }
                        break;
                        
                    case 'email':
                        if (!empty($value) && !$this->validate_email($value)) {
                            $errors[] = "{$field} must be a valid email";
                        }
                        break;
                        
                    case 'url':
                        if (!empty($value) && !$this->validate_url($value)) {
                            $errors[] = "{$field} must be a valid URL";
                        }
                        break;
                        
                    case 'numeric':
                        if (!empty($value) && !is_numeric($value)) {
                            $errors[] = "{$field} must be numeric";
                        }
                        break;
                        
                    case 'min':
                        if (!empty($value) && $value < $param) {
                            $errors[] = "{$field} must be at least {$param}";
                        }
                        break;
                        
                    case 'max':
                        if (!empty($value) && $value > $param) {
                            $errors[] = "{$field} must be at most {$param}";
                        }
                        break;
                        
                    case 'length_min':
                        if (!empty($value) && strlen($value) < $param) {
                            $errors[] = "{$field} must be at least {$param} characters";
                        }
                        break;
                        
                    case 'length_max':
                        if (!empty($value) && strlen($value) > $param) {
                            $errors[] = "{$field} must be at most {$param} characters";
                        }
                        break;
                }
            }
            
            if (!in_array($field, array_keys($errors))) {
                $validated_data[$field] = $value;
            }
        }
        
        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }
        
        return $validated_data;
    }
    
    // ORIGINAL METHODS (preserved for backward compatibility)
    
    public function encrypt($data) {
        if (empty($data)) return $data;
        
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($this->encryption_method));
        $encrypted = openssl_encrypt($data, $this->encryption_method, $this->encryption_key, 0, $iv);
        
        if ($encrypted === false) {
            throw new Exception('Encryption failed');
        }
        
        return base64_encode($iv . $encrypted);
    }
    
    public function decrypt($encrypted_data) {
        if (empty($encrypted_data)) return $encrypted_data;
        
        $data = base64_decode($encrypted_data);
        $iv_length = openssl_cipher_iv_length($this->encryption_method);
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);
        
        $decrypted = openssl_decrypt($encrypted, $this->encryption_method, $this->encryption_key, 0, $iv);
        
        if ($decrypted === false) {
            throw new Exception('Decryption failed');
        }
        
        return $decrypted;
    }
    
    public function generate_jwt($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);
        
        $base64UrlHeader = $this->base64url_encode($header);
        $base64UrlPayload = $this->base64url_encode($payload);
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->encryption_key, true);
        $base64UrlSignature = $this->base64url_encode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
    
    public function verify_jwt($jwt) {
        $parts = explode('.', $jwt);
        if (count($parts) != 3) return false;
        
        list($header, $payload, $signature) = $parts;
        
        $valid_signature = hash_hmac('sha256', $header . "." . $payload, $this->encryption_key, true);
        $valid_signature = $this->base64url_encode($valid_signature);
        
        if (!hash_equals($valid_signature, $signature)) return false;
        
        $payload_data = json_decode($this->base64url_decode($payload), true);
        
        if (isset($payload_data['exp']) && $payload_data['exp'] < time()) {
            return false;
        }
        
        return $payload_data;
    }
    
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private function base64url_decode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
    
    public function generate_nonce($action = 'default') {
        return wp_create_nonce('growl_' . $action);
    }
    
    public function verify_nonce($nonce, $action = 'default') {
        return wp_verify_nonce($nonce, 'growl_' . $action);
    }
    
    public function sanitize_wallet_address($address) {
        if (!is_string($address) || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) {
            return false;
        }
        return $address;
    }
    
    public function sanitize_x_username($username) {
        $username = sanitize_text_field($username);
        $username = ltrim($username, '@');
        if (!preg_match('/^[A-Za-z0-9_]{1,15}$/', $username)) return false;
        return $username;
    }
    
    public function rate_limit_check($identifier, $action, $limit = 100, $window = 30) {
        $transient_key = 'growl_rate_limit_' . $action . '_' . md5($identifier);
        $attempts = get_transient($transient_key) ?: 0;
        
        if ($attempts >= $limit) return false;
        
        $attempts++;
        set_transient($transient_key, $attempts, $window);
        return true;
    }

    /**
     * Admin OTP request rate limit: allow several legitimate requests per minute
     * (unlock + save + resend), with progressive cooldown on abuse.
     *
     * @return array{allowed:bool,retry_after:int,message:string}
     */
    public function otp_request_rate_limit_check(int $admin_id): array {
        $now = time();
        $req_key = 'growl_otp_req_times_' . $admin_id;
        $cooldown_key = 'growl_otp_cooldown_' . $admin_id;
        $tier_key = 'growl_otp_violation_tier_' . $admin_id;

        $cooldown_until = (int) get_transient($cooldown_key);
        if ($cooldown_until > $now) {
            $retry = $cooldown_until - $now;
            $mins = max(1, (int) ceil($retry / 60));
            return [
                'allowed'     => false,
                'retry_after' => $retry,
                'message'     => sprintf('Please wait %d minute(s) before requesting another OTP.', $mins),
            ];
        }

        $times = get_transient($req_key);
        if (!is_array($times)) {
            $times = [];
        }
        $times = array_values(array_filter($times, static function ($t) use ($now) {
            return ($now - (int) $t) < 60;
        }));

        $max_per_minute = 5;
        if (count($times) >= $max_per_minute) {
            $tier = max(1, (int) get_transient($tier_key));
            $cooldowns = [120, 300, 600, 900];
            $cooldown_sec = $cooldowns[min($tier - 1, count($cooldowns) - 1)];
            set_transient($cooldown_key, $now + $cooldown_sec, $cooldown_sec);
            set_transient($tier_key, $tier + 1, DAY_IN_SECONDS);
            delete_transient($req_key);
            $mins = max(1, (int) ceil($cooldown_sec / 60));
            return [
                'allowed'     => false,
                'retry_after' => $cooldown_sec,
                'message'     => sprintf('Too many OTP requests. Please wait %d minute(s).', $mins),
            ];
        }

        $times[] = $now;
        set_transient($req_key, $times, 120);

        return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
    }

    /** Reset progressive OTP violation tier after a successful verify (normal admin flow). */
    public function otp_request_rate_limit_reset_violations(int $admin_id): void {
        delete_transient('growl_otp_violation_tier_' . $admin_id);
        delete_transient('growl_otp_cooldown_' . $admin_id);
    }
    
    /**
     * Validate Solana Ed25519 signature
     * This uses PHP's native hash functions for Ed25519 verification
     */
    public function validate_solana_signature($message, $signature, $public_key) {
        try {
            // Validate inputs
            if (!is_string($message) || empty($message)) {
                error_log('ED25519_VERIFY: Invalid message');
                return false;
            }
            
            if (!is_array($signature) || count($signature) !== 64) {
                error_log('ED25519_VERIFY: Invalid signature length: ' . (is_array($signature) ? count($signature) : 'not array'));
                return false;
            }
            
            if (!$this->sanitize_wallet_address($public_key)) {
                error_log('ED25519_VERIFY: Invalid public key format');
                return false;
            }
            
            // Convert signature array to binary string
            $signature_binary = '';
            foreach ($signature as $byte) {
                if (!is_int($byte) || $byte < 0 || $byte > 255) {
                    error_log('ED25519_VERIFY: Invalid byte in signature');
                    return false;
                }
                $signature_binary .= chr($byte);
            }
            
            // Decode public key from Base58 to binary
            $public_key_binary = $this->base58_decode($public_key);
            
            if (strlen($public_key_binary) !== 32) {
                error_log('ED25519_VERIFY: Public key wrong length after decode: ' . strlen($public_key_binary));
                return false;
            }
            
            // Log for debugging
            error_log('ED25519_VERIFY: message_len=' . strlen($message) . 
                     ' sig_len=' . strlen($signature_binary) . 
                     ' pubkey_len=' . strlen($public_key_binary));
            
            // Use PHP's built-in Ed25519 verification if available (PHP 7.2+)
            if (function_exists('sodium_crypto_sign_verify_detached')) {
                error_log('ED25519_VERIFY: Using sodium extension');
                return sodium_crypto_sign_verify_detached(
                    $signature_binary,
                    $message,
                    $public_key_binary
                );
            }
            
            // Fallback: Use OpenSSL if available
            if (function_exists('openssl_verify')) {
                error_log('ED25519_VERIFY: Attempting OpenSSL method');
                return $this->verify_ed25519_openssl($message, $signature_binary, $public_key_binary);
            }
            
            error_log('ED25519_VERIFY: No verification method available');
            return false;
            
        } catch (Exception $e) {
            error_log('ED25519_VERIFY_EXCEPTION: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * OpenSSL-based Ed25519 verification
     */
    private function verify_ed25519_openssl($message, $signature, $public_key_bytes) {
        try {
            // Convert raw Ed25519 public key to PEM format
            $pem = $this->ed25519_public_key_to_pem($public_key_bytes);
            
            if (!$pem) {
                error_log('ED25519_OPENSSL: Failed to convert to PEM');
                return false;
            }
            
            // Try to verify using openssl_verify
            $pubkey_resource = openssl_pkey_get_public($pem);
            
            if ($pubkey_resource === false) {
                error_log('ED25519_OPENSSL: Failed to load public key: ' . openssl_error_string());
                return false;
            }
            
            // Ed25519 doesn't use a hash, it signs the raw message
            $result = openssl_verify($message, $signature, $pubkey_resource, OPENSSL_ALGO_SHA512);
            
            if ($pubkey_resource instanceof OpenSSLAsymmetricKey) {
                $pubkey_resource = null;
            }
            
            error_log('ED25519_OPENSSL: verify result=' . $result);
            
            return $result === 1;
            
        } catch (Exception $e) {
            error_log('ED25519_OPENSSL_EXCEPTION: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Convert raw Ed25519 public key (32 bytes) to PEM format
     */
    private function ed25519_public_key_to_pem($public_key_bytes) {
        // Ed25519 public key in ASN.1 DER format
        // This is the OID for Ed25519: 1.3.101.112
        $oid = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";
        $der = $oid . $public_key_bytes;
        
        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";
        
        return $pem;
    }
    
    /**
     * Base58 decode (Bitcoin/Solana style)
     */
    private function base58_decode($input) {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = gmp_init(58);
        $decoded = gmp_init(0);
        
        // Decode base58
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            $value = strpos($alphabet, $char);
            
            if ($value === false) {
                throw new Exception('Invalid Base58 character: ' . $char);
            }
            
            $decoded = gmp_add(gmp_mul($decoded, $base), gmp_init($value));
        }
        
        // Convert to binary
        $hex = gmp_strval($decoded, 16);
        
        // Pad to even length
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        
        $binary = hex2bin($hex);
        
        // Count leading zeros in input (they become leading null bytes)
        $leading_zeros = 0;
        for ($i = 0; $i < strlen($input) && $input[$i] === '1'; $i++) {
            $leading_zeros++;
        }
        
        return str_repeat("\x00", $leading_zeros) . $binary;
    }
    
    public function generate_anti_bot_challenge() {
        $operations = ['+', '-', '*'];
        $operation = $operations[array_rand($operations)];
        
        if ($operation === '*') {
            $a = rand(2, 5);
            $b = rand(2, 5);
        } else {
            $a = rand(10, 50);
            $b = rand(1, $a - 1);
        }
        
        switch ($operation) {
            case '+': $answer = $a + $b; break;
            case '-': $answer = $a - $b; break;
            case '*': $answer = $a * $b; break;
            default: $answer = $a + $b;
        }
        
        // Use a simple consistent hash
        $hash = 'challenge_' . $answer;
        
        return [
            'question' => "$a $operation $b",
            'answer' => $answer,
            'hash' => $hash
        ];
    }

    public function verify_anti_bot_challenge($user_answer, $hash) {
        // Simple verification - check if hash matches expected format
        $expected_hash = 'challenge_' . $user_answer;
        return $expected_hash === $hash;
    }
    
    public function validate_timestamp($timestamp, $max_age = 300) {
        $current_time = time();
        return ($current_time - $timestamp) <= $max_age;
    }
    
    public function generate_csrf_token() {
        $token = bin2hex(random_bytes(32));
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['growl_csrf_token'] = $token;
        }
        return $token;
    }
    
    public function verify_csrf_token($token) {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        return isset($_SESSION['growl_csrf_token']) && 
               hash_equals($_SESSION['growl_csrf_token'], $token);
    }
    
    public function validate_api_parameters($params, $required = []) {
        foreach ($required as $field) {
            if (!isset($params[$field]) || empty($params[$field])) {
                throw new Exception("Missing required parameter: $field");
            }
        }
        return true;
    }

    public function validate_x_verification_data($verification_code, $post_url) {
        // Validate verification code format
        if (!preg_match('/^GROWL[A-Z0-9]{8}$/', $verification_code)) {
            return false;
        }
        
        // Validate URL format
        if (!filter_var($post_url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Ensure it's an X/Twitter URL
        if (!preg_match('/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/.+\/status\/\d+/', $post_url)) {
            return false;
        }
        
        return true;
    }

    public function rate_limit_x_verification($user_id) {
        $transient_key = 'growl_x_verify_' . $user_id;
        $attempts = get_transient($transient_key) ?: 0;
        
        if ($attempts >= 3) {
            return false;
        }
        
        $attempts++;
        set_transient($transient_key, $attempts, 15 * MINUTE_IN_SECONDS); // 15 minute window
        return true;
    }
}