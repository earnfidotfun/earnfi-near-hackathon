<?php
/**
 * Build /.well-known/x402 from x402.txt + live ownership proofs.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_X402_Well_Known {

    /**
     * @return array<string, mixed>
     */
    public static function build_document(): array {
        $path = defined('GROWL_PLUGIN_PATH')
            ? GROWL_PLUGIN_PATH . 'x402.txt'
            : dirname(__DIR__, 2) . '/x402.txt';

        $doc = [
            'version'         => 1,
            'resources'       => [],
            'ownershipProofs' => self::ownership_proof_signatures(),
            'instructions'    => '',
        ];

        if (is_readable($path)) {
            $raw = (string) file_get_contents($path);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $doc = array_merge($doc, $decoded);
            }
        }

        $doc['resources'] = self::localize_resource_urls($doc['resources'] ?? []);
        $doc['ownershipProofs'] = self::ownership_proof_signatures();
        if (!empty($doc['instructions']) && is_string($doc['instructions'])) {
            $home = untrailingslashit(home_url());
            $doc['instructions'] = str_replace('https://app.earnfi.fun', $home, $doc['instructions']);
        }

        $doc['resources'] = array_values(array_unique(array_filter($doc['resources'])));
        return $doc;
    }

    /**
     * @param array<int, string> $resources
     * @return array<int, string>
     */
    private static function localize_resource_urls(array $resources): array {
        $home = untrailingslashit(home_url());
        $out = [];
        foreach ($resources as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            if (strpos($url, 'https://app.earnfi.fun/api/') === 0) {
                $path = substr($url, strlen('https://app.earnfi.fun/api/'));
                $out[] = untrailingslashit(rest_url($path));
                continue;
            }
            if (strpos($url, 'https://app.earnfi.fun/') === 0) {
                $suffix = substr($url, strlen('https://app.earnfi.fun'));
                $out[] = $home . $suffix;
                continue;
            }
            $out[] = $url;
        }
        return $out;
    }

    /**
     * @return array<int, string>
     */
    public static function ownership_proof_signatures(): array {
        $proofs = get_option('earnfi_x402_ownership_proofs', []);
        $sigs = [];
        if (is_array($proofs)) {
            foreach ($proofs as $row) {
                if (is_array($row) && !empty($row['signature']) && is_string($row['signature'])) {
                    $sigs[] = $row['signature'];
                }
            }
        }
        if ($sigs !== []) {
            return array_values(array_unique($sigs));
        }
        $legacy = get_option('earnfi_x402_ownership_proof_permanent', '');
        if (is_string($legacy) && $legacy !== '') {
            return [$legacy];
        }
        return [];
    }
}
