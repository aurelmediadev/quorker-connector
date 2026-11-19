<?php

defined('ABSPATH') || exit;

/**
 * Los planes de membresía y los packs de horas de Quorker. No tienen página propia: se guardan al
 * sincronizar (opción "acwc_plans") y los muestra el shortcode [quorker_planes]. De paso se guarda la
 * moneda del coworking ("acwc_currency"), que usan todos los precios del sitio.
 */
final class ACWC_Plans
{
    public const OPTION = 'acwc_plans';

    /**
     * @return array{ok: bool, count: int, error: ?string}
     */
    public static function sync(): array
    {
        $catalog = ACWC_Api::get('plans');
        if (is_wp_error($catalog)) {
            return ['ok' => false, 'count' => 0, 'error' => $catalog->get_error_message()];
        }
        if (! empty($catalog['currency'])) {
            update_option('acwc_currency', strtoupper(sanitize_text_field((string) $catalog['currency'])), false);
        }
        $plans = array_values(array_filter((array) ($catalog['plans'] ?? []), 'is_array'));
        $packs = array_values(array_filter((array) ($catalog['packs'] ?? []), 'is_array'));
        update_option(self::OPTION, ['plans' => $plans, 'packs' => $packs], false);

        return ['ok' => true, 'count' => count($plans), 'error' => null];
    }

    /**
     * @return array{plans: list<array<string, mixed>>, packs: list<array<string, mixed>>}
     */
    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);

        return [
            'plans' => array_values((array) ($saved['plans'] ?? [])),
            'packs' => array_values((array) ($saved['packs'] ?? [])),
        ];
    }
}
