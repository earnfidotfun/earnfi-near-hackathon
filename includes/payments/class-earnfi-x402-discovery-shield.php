<?php
/**
 * Registry / discovery crawlers (x402scan, agentcash) hit many endpoints quickly.
 * Mark those requests so app rate limits stay out of the way; edge (Cloudflare) limits
 * must be relaxed separately for bulk registration.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Discovery_Shield {

    /** @var bool */
    private static $registry_probe = false;

    /** @var bool */
    private static $booted = false;

    public static function boot(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_filter('rest_pre_dispatch', [__CLASS__, 'detect_registry_probe'], 1, 3);
        add_filter('rest_post_dispatch', [__CLASS__, 'cache_control_on_402_probe'], 20, 3);
    }

    public static function is_registry_probe(): bool {
        return self::$registry_probe;
    }

    /**
     * @param mixed           $result
     * @param WP_REST_Server  $server
     * @param WP_REST_Request $request
     * @return mixed
     */
    public static function detect_registry_probe($result, $server, $request) {
        if ($result !== null || ! $request instanceof WP_REST_Request) {
            return $result;
        }
        if (self::looks_like_registry_probe($request)) {
            self::$registry_probe = true;
        }
        return $result;
    }

    public static function looks_like_registry_probe(WP_REST_Request $request): bool {
        $route = (string) $request->get_route();
        if (! class_exists('GROWL_EarnFi_Stack_Hardening', false)
            || ! GROWL_EarnFi_Stack_Hardening::is_public_earnfi_rest_route($route)) {
            return false;
        }

        if (strtolower((string) $request->get_header('x-earfi-registry-probe')) === '1') {
            return true;
        }

        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        foreach (['x402scan', 'agentcash', '@agentcash/discovery', 'merit.systems'] as $needle) {
            if ($needle !== '' && str_contains($ua, $needle)) {
                return true;
            }
        }

        $pay = trim((string) $request->get_header('payment-signature'));
        if ($pay !== '') {
            return false;
        }

        return self::route_is_unpaid_probe_surface($route);
    }

    private static function route_is_unpaid_probe_surface(string $route): bool {
        $route = '/' . ltrim($route, '/');
        $prefixes = [
            '/ai-agent/v1/jobs/social',
            '/ai-agent/v1/jobs/manual',
            '/ai-agent/v1/jobs/contest',
            '/ai-agent/v1/interrupt',
            '/ai-agent/v1/human-actions',
            '/ai-agent/v1/actions',
            '/ai-agent/v1/x402',
            '/growl/v1/x402',
            '/work/v1/jobs/',
        ];
        foreach ($prefixes as $p) {
            if (str_starts_with($route, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response
     * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
     */
    public static function cache_control_on_402_probe($response, $server, $request) {
        if (! self::$registry_probe || ! $response instanceof WP_REST_Response) {
            return $response;
        }
        if ((int) $response->get_status() === 402) {
            $response->header('Cache-Control', 'public, max-age=120', true);
        }
        return $response;
    }
}
