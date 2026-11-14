<?php
/**
 * Public HTTP 402 descriptors for growl/v1/x402/* intent resources.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Paid_Probe {

    public static function register_routes(): void {
        self::register_namespace_routes('growl/v1', 'v1');
        self::register_namespace_routes('ai-agent/v1', 'v1');
        if (class_exists('Earnfi_X402_Paid_Resource_Handler', false)) {
            Earnfi_X402_Paid_Resource_Handler::register_growl_v1_routes();
        }
    }

    /**
     * Mirror growl/v1/x402/* on ai-agent/v1/x402/* for x402scan. resource.url always uses the ai-agent mirror (registered on scan).
     */
    private static function register_namespace_routes(string $namespace, string $api_version, bool $growl_v2_flat_routes = false): void {
        foreach (Earnfi_X402_Paid_Resources::probe_definitions() as $slug => $def) {
            if ($api_version === 'v1' && ($def['purpose'] ?? '') === 'balance_deposit') {
                continue;
            }
            $route = $growl_v2_flat_routes ? ('/' . ltrim($slug, '/')) : ('/x402/' . $slug);
            register_rest_route($namespace, $route, [
                'methods'             => ['GET', 'POST'],
                'callback'            => static function (WP_REST_Request $request) use ($slug, $def, $api_version) {
                    return self::respond($request, $slug, $def, $api_version);
                },
                'permission_callback' => '__return_true',
            ]);
        }
    }

    /**
     * Legacy alias: growl/v1/x402 → job/create descriptor.
     */
    public static function respond_legacy_listing(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $def = Earnfi_X402_Paid_Resources::probe_definitions()['job/create'];
        return self::respond($request, 'job/create', $def);
    }

    /**
     * @param array<string, mixed> $def
     */
    public static function respond(WP_REST_Request $request, string $slug, array $def, string $api_version = 'v1'): WP_REST_Response|WP_Error {
        if (class_exists('Earnfi_X402_Paid_Resource_Handler', false)) {
            return Earnfi_X402_Paid_Resource_Handler::respond_probe($request, $slug, $def, $api_version);
        }
        return new WP_Error('not_ready', 'Paid resource handler not loaded.', ['status' => 503]);
    }
}
