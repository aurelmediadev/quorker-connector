<?php

defined('ABSPATH') || exit;

/**
 * La API pública del coworking en Quorker (solo lectura, sin claves: son datos públicos).
 */
final class ACWC_Api
{
    /**
     * @return array<mixed>|WP_Error
     */
    public static function get(string $path)
    {
        $base = ACWC_Settings::get()['api_url'];
        if ($base === '') {
            return new WP_Error('acwc_no_url', 'Falta la dirección del coworking en Ajustes → Quorker.');
        }
        $response = wp_remote_get($base.'/api/v1/public/'.ltrim($path, '/'), [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || ! is_array($body)) {
            return new WP_Error('acwc_http', sprintf('Quorker respondió %d.', $code));
        }

        return $body;
    }
}
