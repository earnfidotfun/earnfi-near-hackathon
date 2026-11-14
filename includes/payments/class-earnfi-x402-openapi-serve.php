<?php
/**
 * Serve x402scan-compatible OpenAPI at site root and per-API-base paths.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_OpenApi_Serve {

    /** @var array<string, array{min: string, max: string}> */
    private static $default_dynamic_ranges = [
        'social'   => ['min' => '0.03', 'max' => '500'],
        'manual'   => ['min' => '0.20', 'max' => '500'],
        'contest'  => ['min' => '3.00', 'max' => '500'],
        'interrupt'=> ['min' => '0.05', 'max' => '50'],
        'human'    => ['min' => '0.05', 'max' => '50'],
        'intent'   => ['min' => '0.01', 'max' => '5000'],
        'commerce' => ['min' => '0.01', 'max' => '5000'],
    ];

    /**
     * @param string $scope site|agent|work|growl|okx
     */
    public static function emit_json(string $scope = 'site'): void {
        $body = self::build_json($scope);
        if (class_exists('GROWL_EarnFi_Stack_Hardening')) {
            GROWL_EarnFi_Stack_Hardening::prepare_discovery_response();
        }
        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=600');
        echo $body;
        exit;
    }

    /**
     * @param string $scope site|agent|work|growl|okx
     */
    public static function build_json(string $scope = 'site'): string {
        $path = GROWL_PLUGIN_PATH . 'includes/data/openapi-x402.json';
        $body = is_readable($path)
            ? (string) file_get_contents($path)
            : '{"openapi":"3.1.0","info":{"title":"EarnFi","version":"0"},"paths":{}}';

        $settings = get_option('growl_settings', []);
        $app_url  = ! empty($settings['app_url']) ? (string) $settings['app_url'] : home_url('/');
        $app_url  = untrailingslashit($app_url);
        $home_url = untrailingslashit(home_url('/'));

        $body = str_replace('{{APP_URL}}', $app_url, $body);
        $body = str_replace('https://app.earnfi.fun', $app_url, $body);
        $body = str_replace('https://earnfi.fun', $app_url, $body);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $body;
        }

        self::apply_scope($decoded, $scope, $app_url, $home_url);
        self::ensure_security_schemes($decoded);
        self::ensure_x402_component_schemas($decoded);
        self::normalize_document($decoded, $scope, $app_url, $home_url);

        $encoded = wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return is_string($encoded) ? $encoded : $body;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private static function apply_scope(array &$doc, string $scope, string $app_url, string $home_url): void {
        $scope_paths = [
            'agent' => '/api/ai-agent/v1/openapi.json',
            'okx'   => '/api/ai-agent/okx/v1/openapi.json',
            'work'  => '/api/work/v1/openapi.json',
            'growl' => '/api/growl/v1/openapi.json',
            'site'  => '/openapi.json',
        ];
        $openapi_path = $scope_paths[$scope] ?? $scope_paths['site'];
        $openapi_url  = $home_url . $openapi_path;

        if ($scope !== 'site' && isset($doc['servers']) && is_array($doc['servers'])) {
            $suffix = [
                'agent' => '/api/ai-agent/v1',
                'okx'   => '/api/ai-agent/okx/v1',
                'work'  => '/api/work/v1',
                'growl' => '/api/growl/v1',
            ][$scope] ?? '';
            if ($suffix !== '') {
                $needle = $app_url . $suffix;
                $filtered = [];
                foreach ($doc['servers'] as $server) {
                    if (!is_array($server)) {
                        continue;
                    }
                    $url = (string) ($server['url'] ?? '');
                    if ($url === $needle || str_replace($home_url, $app_url, $url) === $needle) {
                        $filtered[] = $server;
                    }
                }
                if ($filtered !== []) {
                    $doc['servers'] = $filtered;
                }
            }
        }

        if (!isset($doc['info']) || !is_array($doc['info'])) {
            $doc['info'] = [];
        }
        $ownership = class_exists('Earnfi_X402_Well_Known', false)
            ? Earnfi_X402_Well_Known::ownership_proof_signatures()
            : [];
        $doc['info']['x-discovery'] = [
            'wellKnownUrl'    => $home_url . '/.well-known/x402',
            'openapiUrl'      => $openapi_url,
            'ownershipProofs' => $ownership,
        ];
    }

    /**
     * @param array<string, mixed> $doc
     */
    private static function normalize_document(array &$doc, string $scope, string $app_url, string $home_url): void {
        if (!isset($doc['info']) || !is_array($doc['info'])) {
            $doc['info'] = [];
        }
        $info = &$doc['info'];

        $info['title'] = 'EarnFi — Agent API, Work API & x402 (USDC on Solana)';
        $info['description'] = self::public_api_description();
        $info['x-guidance'] = self::public_api_guidance();

        if (!isset($info['contact']) || !is_array($info['contact'])) {
            $info['contact'] = [];
        }
        if (empty($info['contact']['email'])) {
            $email = isset($info['contact']['email']) ? (string) $info['contact']['email'] : '';
            if ($email === '') {
                $settings = get_option('growl_settings', []);
                $email = ! empty($settings['support_email']) ? (string) $settings['support_email'] : 'support@earnfi.fun';
            }
            $info['contact']['email'] = $email;
        }

        if (isset($doc['paths']) && is_array($doc['paths'])) {
            foreach ($doc['paths'] as $path_key => &$path_item) {
                if (!is_array($path_item)) {
                    continue;
                }
                if (str_starts_with((string) $path_key, '/x402/') && isset($path_item['servers'])) {
                    unset($path_item['servers']);
                }
                self::apply_path_parameter_examples($path_item, (string) $path_key);
                foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options'] as $method) {
                    if (!isset($path_item[$method]) || !is_array($path_item[$method])) {
                        continue;
                    }
                    self::apply_operation_security($path_item[$method], (string) $path_key, $method);
                    self::normalize_operation($path_item[$method], (string) $path_key, $method);
                    self::attach_operation_io_schemas($path_item[$method], (string) $path_key, $method);
                    self::scrub_operation_text($path_item[$method]);
                }
            }
            unset($path_item);
        }

        if (isset($doc['tags']) && is_array($doc['tags'])) {
            foreach ($doc['tags'] as &$tag) {
                if (!is_array($tag) || ($tag['name'] ?? '') !== 'growl-x402-intent') {
                    continue;
                }
                $tag['description'] = str_replace('verify-payai', 'wallet payment verification', (string) ($tag['description'] ?? ''));
            }
            unset($tag);
        }
    }

    /**
     * @param array<string, mixed> $doc
     */
    private static function ensure_security_schemes(array &$doc): void {
        if (!isset($doc['components']) || !is_array($doc['components'])) {
            $doc['components'] = [];
        }
        if (!isset($doc['components']['securitySchemes']) || !is_array($doc['components']['securitySchemes'])) {
            $doc['components']['securitySchemes'] = [];
        }
        $doc['components']['securitySchemes']['AgentToken'] = [
            'type' => 'apiKey',
            'in'   => 'header',
            'name' => 'Agent-Token',
        ];
    }

    /**
     * @param array<string, mixed> $path_item
     */
    private static function apply_path_parameter_examples(array &$path_item, string $path): void {
        if (isset($path_item['parameters']) && is_array($path_item['parameters'])) {
            foreach ($path_item['parameters'] as &$param) {
                if (!is_array($param) || ($param['in'] ?? '') !== 'path') {
                    continue;
                }
                $name = (string) ($param['name'] ?? '');
                if (!isset($param['example'])) {
                    $param['example'] = self::path_param_example($name, $path);
                }
                $param['required'] = true;
            }
            unset($param);
        }
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (!isset($path_item[$method]['parameters']) || !is_array($path_item[$method]['parameters'])) {
                continue;
            }
            foreach ($path_item[$method]['parameters'] as &$param) {
                if (!is_array($param) || ($param['in'] ?? '') !== 'path') {
                    continue;
                }
                $name = (string) ($param['name'] ?? '');
                if (!isset($param['example'])) {
                    $param['example'] = self::path_param_example($name, $path);
                }
                $param['required'] = true;
            }
            unset($param);
        }
    }

    private static function path_param_example(string $name, string $path): string {
        if ($name === 'id' && str_contains($path, '/agents/orders')) {
            return '999999999';
        }
        if ($name === 'id' && str_contains($path, '/agents/deals')) {
            return '999999999';
        }
        if ($name === 'id') {
            return str_contains($path, '/hire-listings') ? '999999999' : 'ABCDEF12';
        }
        if ($name === 'action_type') {
            return 'ask';
        }
        if ($name === 'action_id') {
            return 'EFI000001';
        }
        if ($name === 'action_id') {
            return 'EFI0000001';
        }
        if ($name === 'slug') {
            return 'sample-slug';
        }
        if ($name === 'receipt_id') {
            return 'wr_00000000000000000000000000000000';
        }
        if ($name === 'ref') {
            return 'sample-ref';
        }
        return 'probe';
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function apply_operation_security(array &$op, string $path, string $method): void {
        if (!empty($op['deprecated']) && isset($op['x-payment-info'])) {
            unset($op['x-payment-info']);
            $op['security'] = [['AgentToken' => []]];
            return;
        }
        if (self::is_per_id_fund_path($path)) {
            unset($op['x-payment-info']);
            $op['security'] = [['AgentToken' => []]];
            return;
        }

        $has_paid = isset($op['x-payment-info']) && is_array($op['x-payment-info']);
        $expects402 = isset($op['responses']['402']);

        if ($has_paid || ($expects402 && self::path_is_paid_surface($path))) {
            if ($expects402 && !$has_paid) {
                $op['x-payment-info'] = [
                    'protocols' => [['x402' => []]],
                    'price'     => self::infer_price_object([], $op, $path),
                ];
            }
            unset($op['security']);
            return;
        }

        if (self::operation_requires_agent_token($op, $path, $method)) {
            $op['security'] = [['AgentToken' => []]];
            return;
        }

        $op['security'] = [];
    }

    private static function is_per_id_fund_path(string $path): bool {
        return (bool) preg_match('#/(agents/orders|agents/deals)/\{id\}/fund$#', $path);
    }

    private static function path_is_paid_surface(string $path): bool {
        if ($path === '/x402' || str_starts_with($path, '/x402/')) {
            return true;
        }
        foreach (['/jobs/social', '/jobs/manual', '/jobs/contest', '/interrupt', '/human-actions', '/actions'] as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }
        if (preg_match('#/(actions|human-actions)/\{action_type\}$#', $path)) {
            return true;
        }
        return false;
    }

    public static function public_api_description(): string {
        return 'EarnFi connects autonomous AI agents, brands, and builders to paid work on Solana (USDC via x402). '
            . 'Fund micro tasks and social campaigns, run contests and custom briefs, request Human Actions (ask, review, vote, research, verify), '
            . 'hire from the agent marketplace, escrow orders and deals, and top up in-app creator wallets. '
            . 'Free: catalog, rails, registration (Agent-Token), polling with job secret, work receipts, MCP, and skill.md. '
            . 'Paid routes return HTTP 402 first; retry with PAYMENT-SIGNATURE after signing USDC. '
            . 'SDK: @earn-fi/agent-client. Discovery: /.well-known/x402 and /openapi.json.';
    }

    public static function public_api_guidance(): string {
        return 'Register origin https://app.earnfi.fun on x402scan. '
            . 'Flow: GET /catalog → optional POST /register → call a paid route without PAYMENT-SIGNATURE for 402 → sign USDC → retry with PAYMENT-SIGNATURE. '
            . 'Jobs return secret for polling; Human Actions return result_url. '
            . 'Intent checkout mirrors /api/ai-agent/v1/x402/* (settlement URLs may reference growl/v1). MCP: /mcp · Skill: /skill.md';
    }

    /**
     * @param array<string, mixed> $doc
     */
    private static function ensure_x402_component_schemas(array &$doc): void {
        if (!isset($doc['components']) || !is_array($doc['components'])) {
            $doc['components'] = [];
        }
        if (!isset($doc['components']['schemas']) || !is_array($doc['components']['schemas'])) {
            $doc['components']['schemas'] = [];
        }
        $s = &$doc['components']['schemas'];
        $s['X402WalletTopUpInput'] = [
            'type'       => 'object',
            'properties' => [
                'amount'          => ['type' => 'number', 'minimum' => 0.01, 'description' => 'USDC to add to Creator Wallet paid balance.'],
                'payment_purpose' => ['type' => 'string', 'const' => 'creator_wallet_top_up'],
            ],
        ];
        $s['X402CommerceFundInput'] = [
            'type'       => 'object',
            'properties' => [
                'id'              => ['type' => 'integer', 'description' => 'Order, deal, or contract id when funding a specific resource.'],
                'payment_purpose' => ['type' => 'string', 'description' => 'e.g. agent_order_fund, agent_deal_fund, job_contract_fund'],
            ],
        ];
        $s['X402GrowlIntentInput'] = [
            'type'                 => 'object',
            'additionalProperties' => true,
            'properties'           => [
                'payment_purpose' => ['type' => 'string'],
                'amount'          => ['type' => 'number', 'minimum' => 0.01],
            ],
            'description'          => 'Optional JSON for POST; unpaid GET/POST without body still returns HTTP 402 quote.',
        ];
        $s['HumanActionTypedInput'] = [
            'type'       => 'object',
            'properties' => [
                'prompt'          => ['type' => 'string', 'maxLength' => 10000, 'description' => 'Question or instructions for workers.'],
                'slots'           => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'reward_per_user' => ['type' => 'string', 'description' => 'USDC per accepted response (decimal string).'],
            ],
        ];
        $s['X402PaymentRequiredResponse'] = [
            'type'       => 'object',
            'required'   => ['x402Version', 'accepts', 'resource'],
            'properties' => [
                'payment_required' => ['type' => 'boolean'],
                'x402Version'      => ['type' => 'integer'],
                'accepts'          => ['type' => 'array', 'items' => ['type' => 'object']],
                'resource'         => ['type' => 'object'],
                'extensions'       => ['type' => 'object'],
                'hint'             => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function attach_operation_io_schemas(array &$op, string $path, string $method): void {
        $has_paid = isset($op['x-payment-info']);
        $is_intent = str_starts_with($path, '/x402/');
        $is_typed  = (bool) preg_match('#/(actions|human-actions)/\{action_type\}$#', $path);

        if ($is_intent && $has_paid) {
            $ref = '#/components/schemas/' . self::intent_input_schema_name($path);
            $op['requestBody'] = [
                'required' => $method === 'post',
                'content'  => [
                    'application/json' => [
                        'schema' => ['$ref' => $ref],
                    ],
                ],
            ];
            if ($method === 'get') {
                $op['requestBody']['required'] = false;
            }
            self::ensure_402_response_schema($op);
            return;
        }

        if ($is_typed && $has_paid) {
            $op['requestBody'] = [
                'required' => $method === 'post',
                'content'  => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/HumanActionTypedInput'],
                    ],
                ],
            ];
            if ($method === 'get') {
                $op['requestBody']['required'] = false;
            }
            self::ensure_402_response_schema($op);
        }
    }

    private static function intent_input_schema_name(string $path): string {
        if (str_contains($path, 'creator-wallet/top-up')) {
            return 'X402WalletTopUpInput';
        }
        if (str_contains($path, 'marketplace/order/fund')
            || str_contains($path, 'marketplace/deal/fund')
            || str_contains($path, 'job-contract/fund')) {
            return 'X402CommerceFundInput';
        }
        return 'X402GrowlIntentInput';
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function ensure_402_response_schema(array &$op): void {
        if (!isset($op['responses']) || !is_array($op['responses'])) {
            $op['responses'] = [];
        }
        if (!isset($op['responses']['402']) || !is_array($op['responses']['402'])) {
            $op['responses']['402'] = ['description' => 'Payment Required'];
        }
        $op['responses']['402']['description'] = 'Payment Required';
        $op['responses']['402']['content'] = [
            'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/X402PaymentRequiredResponse'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function scrub_operation_text(array &$op): void {
        foreach (['summary', 'description'] as $key) {
            if (!isset($op[$key]) || !is_string($op[$key])) {
                continue;
            }
            $op[$key] = self::fix_utf8_mojibake($op[$key]);
        }
    }

    private static function fix_utf8_mojibake(string $text): string {
        $replacements = [
            'â€œ' => '"',
            'â€' => '"',
            'â€™' => "'",
            'â†' => '->',
            'â€¦' => '...',
        ];
        return strtr($text, $replacements);
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function operation_requires_agent_token(array $op, string $path, string $method): bool {
        $tags = is_array($op['tags'] ?? null) ? $op['tags'] : [];
        if (in_array('creator', $tags, true) || in_array('identity', $tags, true)) {
            return true;
        }
        if (str_contains($path, '/profile')) {
            return true;
        }
        if (str_contains($path, '/providers/')) {
            return true;
        }
        if (preg_match('#/agents/(orders/mine|deals)$#', $path)) {
            return true;
        }
        if (str_contains($path, '/work/reviews/mine')) {
            return true;
        }
        if (str_contains($path, '/x-verification/')) {
            return true;
        }
        if ($method === 'post' && str_contains($path, '/agents/orders') && !str_contains($path, '/fund')) {
            return true;
        }
        if ($method === 'post' && preg_match('#/agents/deals/?$#', $path)) {
            return true;
        }
        if (str_contains($path, '/hire-listings')) {
            return true;
        }
        if (in_array('polling', $tags, true)) {
            if (str_contains($path, '/receipts/') || str_contains($path, '/work/receipts')) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function normalize_operation(array &$op, string $path, string $method): void {
        if (!isset($op['x-payment-info']) || !is_array($op['x-payment-info'])) {
            return;
        }
        $pi = &$op['x-payment-info'];

        $pi['protocols'] = self::normalize_protocols($pi['protocols'] ?? null);

        if (!isset($pi['price']) || !is_array($pi['price'])) {
            $pi['price'] = self::infer_price_object($pi, $op, $path);
        } else {
            $pi['price'] = self::normalize_price_object($pi['price']);
        }

        unset($pi['pricingMode']);

        if (isset($op['responses']) && is_array($op['responses']) && isset($op['responses']['402'])) {
            if (is_array($op['responses']['402']) && empty($op['responses']['402']['description'])) {
                $op['responses']['402']['description'] = 'Payment Required';
            }
        }
    }

    /**
     * @param mixed $protocols
     * @return array<int, array<string, mixed>>
     */
    private static function normalize_protocols($protocols): array {
        if (!is_array($protocols) || $protocols === []) {
            return [['x402' => []]];
        }
        $out = [];
        foreach ($protocols as $entry) {
            if (is_string($entry) && strtolower($entry) === 'x402') {
                $out[] = ['x402' => []];
                continue;
            }
            if (is_array($entry) && isset($entry['x402'])) {
                $out[] = ['x402' => is_array($entry['x402']) ? $entry['x402'] : []];
            }
        }
        return $out !== [] ? $out : [['x402' => []]];
    }

    /**
     * @param array<string, mixed> $pi
     * @param array<string, mixed> $op
     * @return array<string, string>
     */
    private static function infer_price_object(array $pi, array $op, string $path): array {
        $raw = $pi['price'] ?? null;
        if (is_string($raw) && preg_match('/^\$?([\d.]+)\s*-\s*\$?([\d.]+)$/', trim($raw), $m)) {
            return [
                'mode'     => 'dynamic',
                'currency' => 'USD',
                'min'      => $m[1],
                'max'      => $m[2],
            ];
        }

        $mode = (string) ($pi['pricingMode'] ?? 'dynamic');
        $range_key = self::range_key_for($op, $path);
        $range = self::$default_dynamic_ranges[$range_key] ?? self::$default_dynamic_ranges['intent'];

        if ($mode === 'fixed' && is_string($raw) && $raw !== '' && !str_contains($raw, '-')) {
            $amount = ltrim(trim($raw), '$');
            return [
                'mode'     => 'fixed',
                'currency' => 'USD',
                'amount'   => $amount,
            ];
        }

        return [
            'mode'     => 'dynamic',
            'currency' => 'USD',
            'min'      => $range['min'],
            'max'      => $range['max'],
        ];
    }

    /**
     * @param array<string, mixed> $price
     * @return array<string, string>
     */
    private static function normalize_price_object(array $price): array {
        $mode = (string) ($price['mode'] ?? 'dynamic');
        $currency = (string) ($price['currency'] ?? 'USD');
        if ($mode === 'fixed') {
            return [
                'mode'     => 'fixed',
                'currency' => $currency,
                'amount'   => (string) ($price['amount'] ?? '0.01'),
            ];
        }
        return [
            'mode'     => 'dynamic',
            'currency' => $currency,
            'min'      => (string) ($price['min'] ?? '0.01'),
            'max'      => (string) ($price['max'] ?? '500'),
        ];
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function range_key_for(array $op, string $path): string {
        $oid = (string) ($op['operationId'] ?? '');
        if (str_contains($oid, 'Social') || str_contains($path, '/jobs/social') || str_contains($path, '/x402') && !str_contains($path, 'growl')) {
            if (str_contains($path, '/jobs/social') || str_contains($oid, 'Social') || str_contains($oid, 'X402Preview')) {
                return 'social';
            }
        }
        if (str_contains($oid, 'Manual') || str_contains($path, '/jobs/manual')) {
            return 'manual';
        }
        if (str_contains($oid, 'Contest') || str_contains($path, '/jobs/contest')) {
            return 'contest';
        }
        if (str_contains($oid, 'Interrupt') || str_contains($path, '/interrupt')) {
            return 'interrupt';
        }
        if (str_contains($oid, 'Human') || str_contains($path, '/actions') || str_contains($path, '/human-actions')) {
            return 'human';
        }
        if (str_contains($oid, 'fund') || str_contains($oid, 'Fund') || str_contains($oid, 'Order') || str_contains($oid, 'Deal')) {
            return 'commerce';
        }
        if (str_contains($path, '/x402/')) {
            return 'intent';
        }
        return 'intent';
    }
}
