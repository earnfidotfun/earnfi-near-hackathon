<?php
/**
 * Job funding after verified settlement — delegate-first extraction from GROWL_REST_API.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Earnfi_Rail_Job_Funding {

    /**
     * @param GROWL_REST_API $rest_api
     * @param array<string, mixed> $settlement Facilitator settlement payload
     * @param array<string, mixed> $job_data
     * @return WP_REST_Response|WP_Error
     */
    public static function settle_and_create_from_wallet($rest_api, $from_wallet, array $settlement, $job_data) {
        if (!is_object($rest_api) || !method_exists($rest_api, 'rail_settle_and_create_job_from_payment_for_wallet')) {
            return new WP_Error('internal_error', 'Job funding service unavailable.', ['status' => 500]);
        }
        return $rest_api->rail_settle_and_create_job_from_payment_for_wallet($from_wallet, $settlement, $job_data);
    }

    /**
     * @param GROWL_REST_API $rest_api
     * @return WP_REST_Response|WP_Error
     */
    public static function payai_settle_and_create_for_wallet(
        $rest_api,
        $from_wallet,
        $signed_tx,
        array $requirements,
        $job_data,
        $payment_payload_v2 = null
    ) {
        if (!is_object($rest_api) || !method_exists($rest_api, 'payai_verify_settle_and_create_job_from_payment_for_wallet')) {
            return new WP_Error('internal_error', 'PayAI job funding service unavailable.', ['status' => 500]);
        }
        return $rest_api->payai_verify_settle_and_create_job_from_payment_for_wallet(
            $from_wallet,
            $signed_tx,
            $requirements,
            $job_data,
            $payment_payload_v2
        );
    }
}
