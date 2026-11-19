<?php

defined('ABSPATH') || exit;

/**
 * Las páginas de espacios ("acw_space"): una entrada por espacio de Quorker que tiene encendida la
 * "página pública", con su descripción, sus fotos (la primera como destacada) y su categoría como
 * término de "acw_space_category". Campos para el Dynamic Field de JetEngine (fuente "Meta Data"):
 *
 *   acw_space_id, acw_space_slug, acw_category, acw_capacity, acw_capacity_label, acw_location,
 *   acw_address, acw_price_label, acw_hourly_price, acw_monthly_rental, acw_rates_table,
 *   acw_amenities, acw_amenities_list, acw_gallery (ids de adjuntos), acw_book_url, acw_portal_url
 *
 * Al sincronizar: los espacios que vienen de la API se crean o actualizan; los que ya no vienen
 * (se archivaron o se les apagó la página pública) pasan a borrador.
 */
final class ACWC_Spaces
{
    public const POST_TYPE = 'acw_space';

    public const TAXONOMY = 'acw_space_category';

    public static function boot(): void
    {
        add_action('init', [self::class, 'register_post_type']);
        add_action('pre_get_posts', static function (WP_Query $query): void {
            if (! is_admin() && $query->is_main_query() && ($query->is_post_type_archive(self::POST_TYPE) || $query->is_tax(self::TAXONOMY))) {
                $query->set('orderby', ['menu_order' => 'ASC', 'title' => 'ASC']);
            }
        });
        add_filter('user_has_cap', [self::class, 'read_only'], 10, 3);
    }

    public static function register_post_type(): void
    {
        $slug = ACWC_Settings::get()['spaces_slug'];
        register_post_type(self::POST_TYPE, [
            'label' => 'Espacios',
            'labels' => ['name' => 'Espacios', 'singular_name' => 'Espacio'],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => $slug, 'with_front' => false],
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'page-attributes'],
            'show_in_rest' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-building',
            'capability_type' => 'post',
        ]);
        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'label' => 'Categorías de espacios',
            'labels' => ['name' => 'Categorías de espacios', 'singular_name' => 'Categoría'],
            'public' => true,
            'hierarchical' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => $slug.'/categoria', 'with_front' => false],
        ]);
    }

    /**
     * Se ven en el admin pero no se editan ni borran (se pisan al sincronizar).
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
     * @return array{ok: bool, count: int, error: ?string}
     */
    public static function sync(): array
    {
        $spaces = ACWC_Api::get('spaces');
        if (is_wp_error($spaces)) {
            return ['ok' => false, 'count' => 0, 'error' => $spaces->get_error_message()];
        }

        $seen = [];
        foreach (array_values($spaces) as $order => $space) {
            if (! is_array($space) || empty($space['slug'])) {
                continue;
            }
            $seen[] = self::upsert($space, $order);
        }

        $others = get_posts(['post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'post__not_in' => $seen ?: [0], 'fields' => 'ids']);
        foreach ($others as $postId) {
            wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
        }

        return ['ok' => true, 'count' => count($seen), 'error' => null];
    }

    /** La entrada de un espacio por su identificador en Quorker, o null. */
    public static function find(string $slug): ?int
    {
        $ids = get_posts(['post_type' => self::POST_TYPE, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => 'acw_space_slug', 'meta_value' => sanitize_title($slug)]);

        return $ids ? (int) $ids[0] : null;
    }

    /**
     * @param  array<string, mixed>  $space
     */
    private static function upsert(array $space, int $order): int
    {
        $slug = sanitize_title((string) $space['slug']);
        $existing = self::find($slug);
        $description = (string) ($space['description'] ?? '');
        $postarr = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => sanitize_text_field((string) $space['name']),
            'post_name' => $slug,
            'post_content' => wp_kses_post(wpautop(esc_html($description))),
            'post_excerpt' => wp_trim_words($description, 30),
            'menu_order' => $order,
        ];
        $postId = (int) ($existing !== null ? wp_update_post(['ID' => $existing] + $postarr) : wp_insert_post($postarr));

        $category = is_array($space['category'] ?? null) ? $space['category'] : null;
        if ($category !== null && ! empty($category['name'])) {
            $term = term_exists(sanitize_title((string) $category['slug']), self::TAXONOMY);
            if (! $term) {
                $term = wp_insert_term(sanitize_text_field((string) $category['name']), self::TAXONOMY, ['slug' => sanitize_title((string) $category['slug'])]);
            }
            if (! is_wp_error($term)) {
                wp_set_object_terms($postId, [(int) $term['term_id']], self::TAXONOMY);
            }
        } else {
            wp_set_object_terms($postId, [], self::TAXONOMY);
        }

        foreach (self::meta($space) as $key => $value) {
            update_post_meta($postId, $key, $value);
        }
        update_post_meta($postId, 'acw_book_url', ACWC_Settings::book_url((string) $space['name'], (string) ($space['book_url'] ?? '')));
        ACWC_Media::gallery($postId, array_values(array_filter((array) ($space['images'] ?? []), 'is_string')));

        return $postId;
    }

    /**
     * @param  array<string, mixed>  $space
     * @return array<string, mixed>
     */
    private static function meta(array $space): array
    {
        $capacity = (int) ($space['capacity'] ?? 1);
        $hourly = $space['hourly_price_cents'] ?? null;
        $rates = array_values(array_filter((array) ($space['duration_rates'] ?? []), 'is_array'));
        $amenities = array_values(array_filter((array) ($space['amenities'] ?? []), 'is_string'));
        $monthly = ! empty($space['monthly_rental']);

        return [
            'acw_space_id' => (int) $space['id'],
            'acw_space_slug' => sanitize_title((string) $space['slug']),
            'acw_category' => sanitize_text_field((string) ($space['category']['name'] ?? '')),
            'acw_capacity' => $capacity,
            'acw_capacity_label' => $capacity === 1 ? '1 persona' : $capacity.' personas',
            'acw_location' => sanitize_text_field((string) ($space['location_name'] ?? '')),
            'acw_address' => sanitize_text_field((string) ($space['location_address'] ?? '')),
            'acw_hourly_price' => $hourly === null ? '' : (int) $hourly,
            'acw_monthly_rental' => $monthly ? 1 : 0,
            'acw_price_label' => self::price_label($monthly, $hourly === null ? null : (int) $hourly, $rates),
            'acw_rates_table' => self::rates_table($hourly === null ? null : (int) $hourly, $rates),
            'acw_amenities' => implode(', ', array_map('sanitize_text_field', $amenities)),
            'acw_amenities_list' => $amenities === [] ? '' : '<ul class="acwc-amenities">'.implode('', array_map(static fn (string $a): string => '<li>'.esc_html($a).'</li>', $amenities)).'</ul>',
            'acw_portal_url' => esc_url_raw((string) ($space['book_url'] ?? '')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rates
     */
    private static function price_label(bool $monthly, ?int $hourly, array $rates): string
    {
        if ($monthly) {
            return 'Alquiler mensual';
        }
        if ($hourly !== null && $hourly > 0) {
            return ACWC_Format::money($hourly).' por hora';
        }
        if ($rates !== []) {
            return 'Desde '.ACWC_Format::money((int) $rates[0]['price_cents']);
        }

        return '';
    }

    /**
     * @param  list<array<string, mixed>>  $rates
     */
    private static function rates_table(?int $hourly, array $rates): string
    {
        $rows = '';
        if ($hourly !== null && $hourly > 0) {
            $rows .= '<tr><td>1 h</td><td>'.esc_html(ACWC_Format::money($hourly)).'</td></tr>';
        }
        foreach ($rates as $rate) {
            $rows .= '<tr><td>'.esc_html(ACWC_Format::duration((int) $rate['minutes'])).'</td><td>'.esc_html(ACWC_Format::money((int) $rate['price_cents'])).'</td></tr>';
        }

        return $rows === '' ? '' : '<table class="acw-rates"><thead><tr><th>Duración</th><th>Precio</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }
}
