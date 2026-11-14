<?php
/**
 * Work + Money split fee engine.
 * Default: 10% total, 50/50 payer/earner → 5% each.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Work_Fee_Engine {

    public const PRODUCTS = ['jobs', 'deals', 'agent_orders'];

    /**
     * @return array{total_percent:float,employer_share_of_total:float,employer_bps:int,earner_bps:int,total_bps:int}
     */
    public static function quote($product = 'deals') {
        $product = sanitize_key((string) $product);
        if (!in_array($product, self::PRODUCTS, true)) {
            $product = 'deals';
        }
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $total = self::read_total_percent($settings, $product);
        $employer_share = self::read_employer_share($settings, $product);
        $employer_share = max(0.0, min(100.0, $employer_share));

        $total_bps = (int) round($total * 100);
        $employer_bps = (int) round($total_bps * ($employer_share / 100.0));
        $earner_bps = max(0, $total_bps - $employer_bps);

        return [
            'product'                 => $product,
            'total_percent'           => $total,
            'employer_share_of_total' => $employer_share,
            'employer_bps'            => $employer_bps,
            'earner_bps'              => $earner_bps,
            'total_bps'               => $total_bps,
        ];
    }

    /**
     * @param string|int $gross_micro Contract gross (what earner is priced at before earner fee)
     * @return array<string,mixed>
     */
    public static function split_for_gross($gross_micro, $product = 'deals') {
        $q = self::quote($product);
        $gross = Growl_Money::assert_micro($gross_micro);
        $employer_fee = Growl_Money::fee_micro($gross, $q['employer_bps']);
        $earner_fee = Growl_Money::fee_micro($gross, $q['earner_bps']);
        $deposit = Growl_Money::add($gross, $employer_fee);
        $net = Growl_Money::sub($gross, $earner_fee);
        $platform = Growl_Money::add($employer_fee, $earner_fee);

        return array_merge($q, [
            'gross_micro'          => $gross,
            'gross_display'        => Growl_Money::to_display($gross),
            'employer_fee_micro'   => $employer_fee,
            'employer_fee_display' => Growl_Money::to_display($employer_fee),
            'earner_fee_micro'     => $earner_fee,
            'earner_fee_display'   => Growl_Money::to_display($earner_fee),
            'deposit_micro'        => $deposit,
            'deposit_display'      => Growl_Money::to_display($deposit),
            'net_to_earner_micro'  => $net,
            'net_to_earner_display'=> Growl_Money::to_display($net),
            'platform_fee_micro'   => $platform,
            'platform_fee_display' => Growl_Money::to_display($platform),
            // Legacy single-cut view: earner-side bps for release_escrow_to_seller net calc
            'legacy_seller_fee_bps'=> (int) $q['earner_bps'],
        ]);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function read_total_percent(array $settings, $product) {
        $override_key = 'work_fee_' . $product . '_total_percent';
        if (isset($settings[$override_key]) && $settings[$override_key] !== '' && is_numeric($settings[$override_key])) {
            return self::clamp_total((float) $settings[$override_key]);
        }
        if (isset($settings['work_fee_total_percent']) && $settings['work_fee_total_percent'] !== '' && is_numeric($settings['work_fee_total_percent'])) {
            return self::clamp_total((float) $settings['work_fee_total_percent']);
        }
        // Legacy map
        if (isset($settings['platform_fee_percent']) && is_numeric($settings['platform_fee_percent'])) {
            return self::clamp_total((float) $settings['platform_fee_percent']);
        }
        return 10.0;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function read_employer_share(array $settings, $product) {
        $override_key = 'work_fee_' . $product . '_employer_share_percent';
        if (isset($settings[$override_key]) && $settings[$override_key] !== '' && is_numeric($settings[$override_key])) {
            return (float) $settings[$override_key];
        }
        if (isset($settings['work_fee_split_employer_percent']) && $settings['work_fee_split_employer_percent'] !== '' && is_numeric($settings['work_fee_split_employer_percent'])) {
            return (float) $settings['work_fee_split_employer_percent'];
        }
        return 50.0;
    }

    private static function clamp_total($v) {
        return max(0.0, min(15.0, (float) $v));
    }
}
