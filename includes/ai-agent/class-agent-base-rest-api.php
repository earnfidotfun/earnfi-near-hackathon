<?php
/**
 * ai-agent/base/v1 — v2 paid creates + Base (eip155:8453) agent registration.
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/class-earnfi-agent-register.php';
require_once dirname(__FILE__) . '/class-earnfi-agent-rail-register-mixin.php';

class Earnfi_Agent_Base_REST_API {
    use Earnfi_Agent_Rail_Register_Mixin;

    private $namespace = 'ai-agent/base/v1';

    protected function register_scheme(): string {
        return Earnfi_Agent_Register::SCHEME_EVM;
    }

    public function register_routes() {
        register_rest_route($this->namespace, '/health', [
            'methods'             => 'GET',
            'callback'            => static function () {
                return rest_ensure_response([
                    'ok'      => true,
                    'api'     => 'ai-agent/base/v1',
                    'alias'   => 'ai-agent/v2',
                    'network' => Earnfi_Chain_Network_Catalog::BASE_CAIP2,
                    'enabled' => Earnfi_Chain_Network_Catalog::base_connect_enabled(),
                ]);
            },
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/register/challenge', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'get_register_challenge'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->namespace, '/register', [
            'methods'             => 'POST',
            'callback'            => [$this, 'register_agent'],
            'permission_callback' => '__return_true',
        ]);

        $delegate = static function (WP_REST_Request $request) {
            $route = $request->get_route();
            $v1 = preg_replace('#^/ai-agent/base/v1#', '/ai-agent/v1', $route);
            if (!is_string($v1)) {
                return new WP_Error('invalid_route', 'Unsupported route', ['status' => 404]);
            }
            $request->set_header('X-Earnfi-Accepts-Filter', Earnfi_Chain_Network_Catalog::BASE_CAIP2);
            if (class_exists('Earnfi_X402_Paid_Resource_Handler', false)) {
                return Earnfi_X402_Paid_Resource_Handler::delegate($request->get_method(), $v1, $request);
            }
            $inner = new WP_REST_Request($request->get_method(), $v1);
            foreach ($request->get_params() as $k => $v) {
                $inner->set_param($k, $v);
            }
            $inner->set_body($request->get_body());
            foreach ($request->get_headers() as $name => $values) {
                if (!empty($values[0])) {
                    $inner->set_header($name, $values[0]);
                }
            }
            return rest_do_request($inner);
        };

        foreach (['social', 'manual', 'contest'] as $kind) {
            register_rest_route($this->namespace, '/jobs/' . $kind, [
                'methods'             => ['GET', 'POST'],
                'callback'            => $delegate,
                'permission_callback' => '__return_true',
            ]);
        }
    }
}
