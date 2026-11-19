<?php

defined('ABSPATH') || exit;

/**
 * Las páginas de eventos ("acw_event"): una entrada por evento publicado en Quorker, con su
 * texto, su imagen destacada y estos campos (para el Dynamic Field de JetEngine, fuente "Meta Data"):
 *
 *   acw_event_slug, acw_starts_at, acw_ends_at (UTC), acw_date, acw_date_label, acw_time_label,
 *   acw_place, acw_address, acw_price_from_label, acw_seats_left, acw_seats_label,
 *   acw_can_register, acw_registration_label, acw_members_only, acw_is_past, acw_tickets_table,
 *   acw_register_url, acw_public_url
 *
 * Al sincronizar: los eventos que vienen de la API se crean o actualizan; los que ya no vienen
 * pasan a borrador (se cancelaron o despublicaron), salvo los que terminaron, que siguen
 * publicados marcados como pasados.
 */
final class ACWC_Events
{
    public const POST_TYPE = 'acw_event';

    private const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public static function boot(): void
    {
        add_action('init', [self::class, 'register_post_type']);
        add_action('init', static function (): void {
            if (get_option('acwc_flush_rewrite')) {
                delete_option('acwc_flush_rewrite');
                flush_rewrite_rules();
            }
        }, 99);
        // El archivo de eventos: los próximos primero, por fecha del evento.
        add_action('pre_get_posts', static function (WP_Query $query): void {
            if (! is_admin() && $query->is_main_query() && $query->is_post_type_archive(self::POST_TYPE)) {
                $query->set('meta_key', 'acw_starts_at');
                $query->set('orderby', 'meta_value');
                $query->set('order', 'ASC');
            }
        });
        // No se editan desde WordPress: la fuente es Quorker.
        add_filter('user_has_cap', [self::class, 'read_only'], 10, 3);
    }

    public static function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'label' => 'Eventos',
            'labels' => ['name' => 'Eventos', 'singular_name' => 'Evento'],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => ACWC_Settings::get()['events_slug'], 'with_front' => false],
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            'show_in_rest' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-tickets-alt',
            'capability_type' => 'post',
        ]);
    }

    /**
     * Los usuarios ven los eventos en el admin pero no los editan ni borran (se pisan al sincronizar).
     *
     * @param  array<string, bool>  $allcaps
     * @param  array<int, string>  $caps
     * @param  array<int, mixed>  $args
     * @return array<string, bool>
     */
    public static function read_only(array $allcaps, array $caps, array $args): array
    {
        $cap = $args[0] ?? '';
        $postId = (int) ($args[2] ?? 0);
        if (in_array($cap, ['edit_post', 'delete_post'], true) && $postId > 0 && get_post_type($postId) === self::POST_TYPE && ! defined('ACWC_SYNCING')) {
            foreach ($caps as $c) {
                $allcaps[$c] = false;
            }
        }

        return $allcaps;
    }

    /**
     * Lo llama ACWC_Sync (que guarda el resultado de todo junto).
     *
     * @return array{ok: bool, count: int, error: ?string}
     */
    public static function sync(): array
    {
        $events = ACWC_Api::get('events');
        if (is_wp_error($events)) {
            return ['ok' => false, 'count' => 0, 'error' => $events->get_error_message()];
        }

        $seen = [];
        foreach ($events as $event) {
            if (! is_array($event) || empty($event['slug'])) {
                continue;
            }
            $seen[] = self::upsert($event);
        }

        // Los que ya no vienen: terminados → siguen publicados como pasados; el resto → borrador.
        $others = get_posts(['post_type' => self::POST_TYPE, 'post_status' => ['publish', 'draft'], 'numberposts' => -1, 'post__not_in' => $seen ?: [0], 'fields' => 'ids']);
        foreach ($others as $postId) {
            $ends = strtotime((string) get_post_meta($postId, 'acw_ends_at', true).' UTC');
            if ($ends !== false && $ends <= time()) {
                update_post_meta($postId, 'acw_is_past', 1);
                update_post_meta($postId, 'acw_can_register', 0);
                update_post_meta($postId, 'acw_registration_label', 'El evento ya pasó.');
            } elseif (get_post_status($postId) === 'publish') {
                wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
            }
        }

        return ['ok' => true, 'count' => count($seen), 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function upsert(array $event): int
    {
        $slug = sanitize_title((string) $event['slug']);
        $existing = get_posts(['post_type' => self::POST_TYPE, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => 'acw_event_slug', 'meta_value' => $slug]);
        $postarr = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => sanitize_text_field((string) $event['title']),
            'post_name' => $slug,
            'post_content' => wp_kses_post((string) ($event['description_html'] ?? '')),
            'post_excerpt' => wp_trim_words(wp_strip_all_tags((string) ($event['description'] ?? '')), 30),
        ];
        $postId = $existing ? wp_update_post(['ID' => (int) $existing[0]] + $postarr) : wp_insert_post($postarr);
        $postId = (int) $postId;

        foreach (self::meta($event, $postId) as $key => $value) {
            update_post_meta($postId, $key, $value);
        }
        self::image($postId, (string) ($event['image_url'] ?? ''));

        return $postId;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function meta(array $event, int $postId): array
    {
        $date = (string) $event['date'];
        $ts = strtotime($date.' 12:00:00 UTC');
        $left = $event['left'] ?? null;
        $from = $event['price_from_cents'] ?? null;

        return [
            'acw_event_slug' => sanitize_title((string) $event['slug']),
            'acw_starts_at' => (string) $event['starts_at'],
            'acw_ends_at' => (string) ($event['ends_at'] ?? $event['starts_at']),
            'acw_date' => $date,
            'acw_date_label' => self::WEEKDAYS[(int) gmdate('w', $ts)].' '.(int) gmdate('j', $ts).' de '.self::MONTHS[(int) gmdate('n', $ts) - 1],
            'acw_time_label' => $event['start'].' a '.$event['end'],
            'acw_place' => sanitize_text_field((string) ($event['place'] ?? '')),
            'acw_address' => sanitize_text_field((string) ($event['address'] ?? '')),
            'acw_price_from_label' => $from === null ? '' : ((int) $from === 0 ? 'Gratis' : 'Desde '.self::money((int) $from)),
            'acw_seats_left' => $left === null ? '' : (int) $left,
            'acw_seats_label' => $left === null ? '' : ((int) $left === 0 ? 'Sin lugares' : ((int) $left === 1 ? 'Queda 1 lugar' : 'Quedan '.(int) $left.' lugares')),
            'acw_can_register' => ! empty($event['can_register']) ? 1 : 0,
            'acw_registration_label' => ! empty($event['can_register']) ? 'Inscripción abierta' : (string) ($event['registration_message'] ?? ''),
            'acw_members_only' => ! empty($event['members_only']) ? 1 : 0,
            'acw_is_past' => 0,
            'acw_tickets_table' => self::tickets_table((array) ($event['ticket_types'] ?? [])),
            'acw_register_url' => get_permalink($postId).'#inscripcion',
            'acw_public_url' => ACWC_Settings::get()['api_url'].'/eventos/'.sanitize_title((string) $event['slug']),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $types
     */
    private static function tickets_table(array $types): string
    {
        if ($types === []) {
            return '';
        }
        $rows = '';
        foreach ($types as $type) {
            $note = empty($type['on_sale']) ? 'Venta cerrada' : (! empty($type['sales_end']) ? 'Hasta el '.substr((string) $type['sales_end'], 8, 2).'/'.substr((string) $type['sales_end'], 5, 2) : '');
            if (! empty($type['members_only'])) {
                $note = trim('Socios '.($note !== '' ? '· '.$note : ''));
            }
            $rows .= '<tr><td>'.esc_html((string) $type['name']).'</td><td>'.esc_html((int) $type['price_cents'] === 0 ? 'Gratis' : self::money((int) $type['price_cents'])).'</td><td>'.esc_html($note).'</td></tr>';
        }

        return '<table class="acw-tickets"><thead><tr><th>Entrada</th><th>Precio</th><th></th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private static function money(int $cents): string
    {
        return ACWC_Format::money($cents);
    }

    /** La imagen del evento como imagen destacada (se baja una sola vez por imagen). */
    private static function image(int $postId, string $url): void
    {
        $attachment = ACWC_Media::attachment($url, $postId);
        if ($attachment !== null) {
            set_post_thumbnail($postId, $attachment);
        }
    }
}
