<?php
/**
 * Minimal JSON-schema checks for agent service input/output contracts.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Growl_Input_Schema {

    /**
     * @param mixed $schema
     * @param mixed $data
     * @return true|WP_Error
     */
    public static function validate($schema, $data, $label = 'input') {
        if (!is_array($schema) || empty($schema)) {
            return true;
        }
        if (!is_array($data)) {
            return new WP_Error('invalid_schema', ucfirst($label) . ' must be an object.', ['status' => 400]);
        }
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
        foreach ($required as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                return new WP_Error('invalid_schema', 'Missing required ' . $label . ' field: ' . $key, ['status' => 400]);
            }
        }
        $props = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        foreach ($props as $key => $rule) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }
            $type = is_array($rule) ? sanitize_key((string) ($rule['type'] ?? 'string')) : 'string';
            if (!self::value_matches($type, $data[$key])) {
                return new WP_Error('invalid_schema', $label . ' field ' . $key . ' must be ' . $type . '.', ['status' => 400]);
            }
            if ($type === 'string' && is_array($rule) && !empty($rule['maxLength'])) {
                $max = (int) $rule['maxLength'];
                $len = function_exists('mb_strlen') ? mb_strlen((string) $data[$key]) : strlen((string) $data[$key]);
                if ($max > 0 && $len > $max) {
                    return new WP_Error('invalid_schema', $label . ' field ' . $key . ' is too long.', ['status' => 400]);
                }
            }
        }
        return true;
    }

    private static function value_matches($type, $value) {
        switch ($type) {
            case 'number':
            case 'integer':
                return is_numeric($value);
            case 'boolean':
                return is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
            case 'array':
                return is_array($value) && self::is_list($value);
            case 'object':
                return is_array($value) && !self::is_list($value);
            default:
                return is_string($value) || is_numeric($value);
        }
    }

    private static function is_list($value) {
        if (!is_array($value)) {
            return false;
        }
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }
}
