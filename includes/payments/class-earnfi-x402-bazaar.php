<?php
/**
 * Shared x402 v2 resource + Bazaar extension builders (PayAI catalog / x402scan).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Bazaar {

    public const SERVICE_NAME = 'EarnFi';
    public const DEFAULT_TAGS = ['jobs', 'ai-agents', 'social', 'tasks', 'crypto', 'utility', 'escrow', 'solana'];

    /**
     * @return array<string, mixed>
     */
    public static function resource_meta(string $url, string $description, string $mime_type = 'application/json'): array {
        if (function_exists('earnfi_x402_normalize_resource_url')) {
            $url = earnfi_x402_normalize_resource_url($url);
        }
        $home = untrailingslashit(home_url('/'));
        return [
            'url'         => $url,
            'description' => $description,
            'mimeType'    => $mime_type,
            'serviceName' => self::SERVICE_NAME,
            'tags'        => self::DEFAULT_TAGS,
            'iconUrl'     => $home . '/favicon.ico',
        ];
    }

    /**
     * @param array<string, mixed> $schema JSON Schema for extensions.bazaar.schema
     * @param array<string, mixed> $example_output
     * @return array<string, mixed>
     */
    public static function extensions(string $method, array $schema, array $example_output = [], string $body_type = 'application/json'): array {
        $method = strtoupper($method);
        return [
            'bazaar' => [
                'info' => [
                    'input' => [
                        'type'        => 'http',
                        'method'      => $method,
                        'bodyType'    => $body_type,
                        'queryParams' => (object) [],
                    ],
                    'output' => [
                        'type'    => 'json',
                        'example' => !empty($example_output) ? $example_output : ['ok' => true, 'paid' => true],
                    ],
                ],
                'schema' => $schema,
            ],
        ];
    }

    /**
     * Full x402 v2 PAYMENT-REQUIRED body.
     *
     * @param array<int, array<string, mixed>> $accepts
     * @param array<string, mixed>             $extensions
     * @return array<string, mixed>
     */
    public static function payment_required_body(array $resource, array $accepts, array $extensions): array {
        return [
            'x402Version' => 2,
            'error'       => 'PAYMENT-SIGNATURE header is required',
            'resource'    => $resource,
            'accepts'     => $accepts,
            'extensions'  => !empty($extensions) ? $extensions : new stdClass(),
        ];
    }
}
