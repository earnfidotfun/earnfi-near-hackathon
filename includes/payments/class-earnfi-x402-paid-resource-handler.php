<?php
/**
 * Shared x402 paid resource handler (402 probes + PAYMENT-SIGNATURE settlement for v2 surfaces).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Paid_Resource_Handler {

    /** @var GROWL_REST_API|null */
    private static $core_api;

    public static function register_growl_v1_routes(): void {
        self::register_multichain_discovery_routes('growl/v1');
    }

    private static function register_multichain_discovery_routes(string $namespace): void {
        register_rest_route($namespace, '/networks', [
            'methods'             => 'GET',
            'callback'            => static function () {
                if (!class_exists('Earnfi_Chain_Network_Catalog', false)) {
                    require_once GROWL_PLUGIN_PATH . 'includes/payments/class-earnfi-chain-network-catalog.php';
                }
                $networks = Earnfi_Chain_Network_Catalog::public_networks_list();
                return rest_ensure_response([
                    'solana_first'     => true,
                    'default_caip2'    => Earnfi_Chain_Network_Catalog::SOLANA_CAIP2,
                    'networks'         => $networks,
                    'withdrawals_x402' => false,
                ]);
            },
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/x402/inventory', [
            'methods'             => 'GET',
            'callback'            => static function () {
                return rest_ensure_response([
                    'x402Version' => 2,
                    'inventory'   => Earnfi_X402_Paid_Resources::x402_inventory_matrix(),
                ]);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * HTTP 402 probe with multi-accept (Solana-first) when capabilities helper is available.
     *
     * @param array<string, mixed> $def
     */
    public static function respond_probe(
        WP_REST_Request $request,
        string $slug,
        array $def,
        string $api_version = 'v1'
    ): WP_REST_Response|WP_Error {
        $purpose = sanitize_key((string) ($def['purpose'] ?? ''));
        if ($purpose === 'balance_deposit' && self::request_has_payment_signature($request)) {
            return self::confirm_balance_deposit($request, $api_version);
        }

        $resource_url = Earnfi_X402_Paid_Resources::intent_probe_url_for_slug($slug, $api_version);
        $description  = (string) ($def['description'] ?? 'EarnFi x402 paid resource.');
        $amount_param = $request->get_param('amount_micro');
        $example_amt  = is_string($amount_param) || is_numeric($amount_param) ? (string) $amount_param : null;

        $accepts = class_exists('Earnfi_X402_Capabilities', false)
            ? Earnfi_X402_Capabilities::eligible_accepts_for_descriptor($resource_url, $example_amt)
            : null;
        if (is_wp_error($accepts)) {
            return $accepts;
        }
        if (!is_array($accepts)) {
            $descriptor = PayAI_Flow::get_x402_descriptor($resource_url, $example_amt, $description);
            if (is_wp_error($descriptor)) {
                return $descriptor;
            }
            $accepts = $descriptor['accepts'] ?? [];
        }
        $filter = class_exists('Earnfi_X402_Capabilities', false)
            ? Earnfi_X402_Capabilities::accepts_filter_from_request($request)
            : trim((string) $request->get_header('X-Earnfi-Accepts-Filter'));
        if ($filter !== '' && class_exists('Earnfi_X402_Capabilities', false)) {
            $filtered = Earnfi_X402_Capabilities::filter_accepts_or_error($accepts, $filter);
            if (is_wp_error($filtered)) {
                return $filtered;
            }
            $accepts = $filtered;
        }
        if ($accepts === []) {
            return new WP_Error('no_accepts', 'No payment methods available for this resource.', ['status' => 503]);
        }

        $schema_key = (string) ($def['schema'] ?? 'growl_job');
        $bschema    = Earnfi_X402_Paid_Resources::schema_for($schema_key);
        $example    = isset($def['example_output']) && is_array($def['example_output'])
            ? $def['example_output']
            : ['ok' => true, 'paid' => true];

        $resource   = Earnfi_X402_Bazaar::resource_meta($resource_url, $description);
        $extensions = Earnfi_X402_Bazaar::extensions($request->get_method(), $bschema, $example);
        $body       = Earnfi_X402_Bazaar::payment_required_body($resource, $accepts, $extensions);
        $body['payment_purpose'] = $purpose;

        PayAI_X402_Quote_Store::persist_402_response($body, null);
        PayAI_X402_Http::send_payment_required_headers($body);

        $payload = Earnfi_Agent_Integrator_Copy::merge_agent_402_quote_fields([
            'payment_required' => true,
            'x402Version'      => 2,
            'accepts'          => $body['accepts'],
            'resource'         => $body['resource'],
            'extensions'       => $body['extensions'],
            'x402'             => $body,
        ]);
        if ($purpose !== '') {
            $payload['payment_purpose'] = $purpose;
        }
        return new WP_REST_Response($payload, 402);
    }

    /**
     * balance_deposit: facilitator settle → Purpose_Funding (no double balance credit via settle_wallet_payment).
     */
    public static function confirm_balance_deposit(WP_REST_Request $request, string $api_version = 'v2'): WP_REST_Response|WP_Error {
        $api = self::core_api();
        if (!$api->verify_jwt($request)) {
            return new WP_Error('auth_required', 'Balance deposit requires authentication.', ['status' => 401]);
        }
        $jwt_payload = $request->get_param('jwt_payload');
        $user_id = is_array($jwt_payload) && !empty($jwt_payload['wp_user_id'])
            ? (int) $jwt_payload['wp_user_id']
            : (int) get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error('auth_required', 'Balance deposit requires authentication.', ['status' => 401]);
        }

        $parsed = Earnfi_X402_Settlement_Pipeline::parse_request_payment($request);
        if (is_wp_error($parsed)) {
            return $parsed;
        }
        $requirements = $parsed['requirements'];
        $payload_v2   = $parsed['payment_payload_v2'] ?? null;
        $fac = Earnfi_X402_Settlement_Pipeline::facilitator_verify_settle(
            (string) $parsed['signed_tx'],
            $requirements,
            $payload_v2
        );
        if (is_wp_error($fac)) {
            return $fac;
        }

        $slug = 'balance/deposit';
        $resource_url = Earnfi_X402_Paid_Resources::intent_probe_url_for_slug($slug, $api_version);
        $dto = Earnfi_X402_Settlement_Pipeline::verified_settlement_from(
            $fac['settlement'],
            $requirements,
            'balance_deposit',
            $resource_url
        );
        $idem = Growl_Money::sanitize_idempotency_key((string) ($request->get_param('idempotency_key') ?? ''));
        $result = Earnfi_X402_Purpose_Funding::apply($dto, [
            'user_id'         => $user_id,
            'idempotency_key' => $idem,
            'purpose'         => 'balance_deposit',
        ]);
        if (is_wp_error($result)) {
            return Growl_X402_Payment_Service::public_error($result);
        }

        $response = rest_ensure_response([
            'success'          => true,
            'balance_credited' => true,
            'settlement_id'    => (int) $result,
            'purpose'          => 'balance_deposit',
        ]);
        if (class_exists('PayAI_X402_Http', false)) {
            PayAI_X402_Http::attach_payment_response($response, $fac['settlement'], $dto->network_caip2);
        }
        return $response;
    }

    /**
     * Agent / work paid route: delegate to v1 handler (preserves PayAI job + escrow behavior).
     */
    public static function delegate(string $method, string $route, WP_REST_Request $request): WP_REST_Response|WP_Error {
        $inner = new WP_REST_Request($method, $route);
        foreach ($request->get_params() as $key => $value) {
            $inner->set_param($key, $value);
        }
        $inner->set_body($request->get_body());
        foreach ($request->get_headers() as $name => $values) {
            if (!empty($values[0])) {
                $inner->set_header($name, $values[0]);
            }
        }
        return rest_do_request($inner);
    }

    private static function request_has_payment_signature(WP_REST_Request $request): bool {
        foreach (['payment-signature', 'PAYMENT-SIGNATURE', 'payment_signature'] as $h) {
            $v = $request->get_header($h);
            if (is_string($v) && trim($v) !== '') {
                return true;
            }
        }
        return trim((string) ($request->get_param('payment_signature') ?? '')) !== '';
    }

    private static function core_api(): GROWL_REST_API {
        if (self::$core_api === null) {
            self::$core_api = new GROWL_REST_API();
        }
        return self::$core_api;
    }
}
