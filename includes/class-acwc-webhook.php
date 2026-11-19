<?php

defined('ABSPATH') || exit;

/**
 * Aviso de Quorker: POST /wp-json/aurel-cowork/v1/webhook, firmado con la clave del conector
 * (X-Aurel-Signature: sha256=HMAC del cuerpo). Solo dice que algo cambió: el plugin vuelve a leer
 * la API pública. Sin firma válida, 401. (La ruta y el encabezado conservan el nombre de la 0.2,
 * que es el que usa Quorker.)
 */
final class ACWC_Webhook
{
    public static function boot(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route('aurel-cowork/v1', '/webhook', [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $key = ACWC_Settings::get()['key'];
        $signature = (string) $request->get_header('x_aurel_signature');
        $expected = 'sha256='.hash_hmac('sha256', $request->get_body(), $key);
        if ($key === '' || ! hash_equals($expected, $signature)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Firma inválida'], 401);
        }
        $payload = json_decode($request->get_body(), true);
        if (($payload['type'] ?? '') === 'ping') {
            return new WP_REST_Response(['ok' => true, 'version' => ACWC_VERSION]);
        }
        $result = ACWC_Sync::all();

        return new WP_REST_Response($result, $result['ok'] ? 200 : 502);
    }
}
