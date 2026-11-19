<?php

defined('ABSPATH') || exit;

/**
 * Shortcodes (los "aurel_*" de la 0.2 siguen funcionando):
 *
 * - [quorker_espacios categoria="salas" cantidad="12" columnas="3"]: tarjetas de los espacios, con
 *   link a su página y el botón de reservar.
 * - [quorker_reservar espacio="identificador" texto="Reservar"]: el botón de reservar. En la página
 *   de un espacio no hace falta el atributo.
 * - [quorker_planes packs="si"]: los planes de membresía (y los packs de horas).
 * - [quorker_evento_inscripcion evento="identificador"]: el formulario de inscripción. En la página de
 *   un evento no hace falta el atributo. Lee las entradas en vivo de Quorker y manda la inscripción
 *   directo allá (Quorker habilita este sitio en "Sitio web").
 * - [quorker_eventos cantidad="6"]: los próximos eventos, con link a su página.
 */
final class ACWC_Shortcodes
{
    public static function boot(): void
    {
        foreach (['quorker_evento_inscripcion', 'aurel_evento_inscripcion'] as $tag) {
            add_shortcode($tag, [self::class, 'registration']);
        }
        foreach (['quorker_eventos', 'aurel_eventos'] as $tag) {
            add_shortcode($tag, [self::class, 'list']);
        }
        add_shortcode('quorker_espacios', [self::class, 'spaces']);
        add_shortcode('quorker_reservar', [self::class, 'book_button']);
        add_shortcode('quorker_planes', [self::class, 'plans']);
        add_filter('the_content', [self::class, 'event_content'], 20);
        add_filter('the_content', [self::class, 'space_content'], 20);
        add_action('wp_enqueue_scripts', static function (): void {
            wp_register_script('acwc-registration', ACWC_URL.'assets/registration.js', [], ACWC_VERSION, true);
            wp_register_style('acwc', ACWC_URL.'assets/acwc.css', [], ACWC_VERSION);
        });
    }

    // --- Espacios -------------------------------------------------------------------------

    /**
     * @param  array<string, string>|string  $atts
     */
    public static function spaces($atts): string
    {
        $atts = shortcode_atts(['categoria' => '', 'cantidad' => '50', 'columnas' => '3'], is_array($atts) ? $atts : [], 'quorker_espacios');
        $query = [
            'post_type' => ACWC_Spaces::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => max(1, min(100, (int) $atts['cantidad'])),
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ];
        if ($atts['categoria'] !== '') {
            $query['tax_query'] = [['taxonomy' => ACWC_Spaces::TAXONOMY, 'field' => 'slug', 'terms' => array_map('sanitize_title', explode(',', $atts['categoria']))]];
        }
        $posts = get_posts($query);
        if ($posts === []) {
            return '<p class="acwc-muted">Por ahora no hay espacios para mostrar.</p>';
        }
        wp_enqueue_style('acwc');
        $columns = max(1, min(4, (int) $atts['columnas']));
        $cards = '';
        foreach ($posts as $post) {
            $meta = static fn (string $key): string => (string) get_post_meta($post->ID, $key, true);
            $image = get_the_post_thumbnail($post, 'medium_large', ['class' => 'acwc-card__image', 'loading' => 'lazy']);
            $cards .= sprintf(
                '<article class="acwc-card">%s<div class="acwc-card__body"><span class="acwc-muted">%s</span><h3 class="acwc-card__title"><a href="%s">%s</a></h3><p class="acwc-muted">%s</p><p class="acwc-card__price">%s</p><div class="acwc-actions">%s<a class="acwc-link" href="%s">Ver el espacio</a></div></div></article>',
                $image !== '' ? '<a href="'.esc_url(get_permalink($post)).'">'.$image.'</a>' : '',
                esc_html($meta('acw_category')),
                esc_url(get_permalink($post)),
                esc_html(get_the_title($post)),
                esc_html(trim($meta('acw_capacity_label').($meta('acw_location') !== '' ? ' · '.$meta('acw_location') : ''))),
                esc_html($meta('acw_price_label')),
                self::space_button($post->ID),
                esc_url(get_permalink($post))
            );
        }

        return '<div class="acwc-cards" style="--acwc-columns: '.$columns.'">'.$cards.'</div>';
    }

    /**
     * @param  array<string, string>|string  $atts
     */
    public static function book_button($atts): string
    {
        $atts = shortcode_atts(['espacio' => '', 'texto' => ''], is_array($atts) ? $atts : [], 'quorker_reservar');
        $postId = $atts['espacio'] !== '' ? ACWC_Spaces::find($atts['espacio']) : (get_post_type() === ACWC_Spaces::POST_TYPE ? (int) get_the_ID() : null);
        if ($postId === null) {
            return current_user_can('manage_options') ? '<p><em>Quorker: no encontré ese espacio.</em></p>' : '';
        }
        wp_enqueue_style('acwc');

        return self::space_button($postId, $atts['texto']);
    }

    private static function space_button(int $postId, string $label = ''): string
    {
        $settings = ACWC_Settings::get();
        $url = ACWC_Settings::book_url((string) get_post_field('post_title', $postId, 'raw'), (string) get_post_meta($postId, 'acw_portal_url', true));
        $external = $settings['book_mode'] !== 'url';

        return sprintf(
            '<a class="acwc-button" href="%s"%s>%s</a>',
            esc_url($url),
            $external ? ' target="_blank" rel="noopener"' : '',
            esc_html($label !== '' ? $label : $settings['book_label'])
        );
    }

    /**
     * En la página de un espacio, si el tema no la arma con su plantilla: los datos, las fotos, los
     * precios, las comodidades y el botón. Se apaga en Ajustes → Quorker.
     */
    public static function space_content(string $content): string
    {
        if (! is_singular(ACWC_Spaces::POST_TYPE) || ! in_the_loop() || ! is_main_query() || ! ACWC_Settings::get()['auto_content'] || has_shortcode($content, 'quorker_reservar')) {
            return $content;
        }
        wp_enqueue_style('acwc');
        $id = (int) get_the_ID();
        $meta = static fn (string $key): string => (string) get_post_meta($id, $key, true);
        $facts = sprintf(
            '<p class="acwc-facts"><strong>%s</strong><br>%s%s</p>',
            esc_html(trim($meta('acw_category').' · '.$meta('acw_capacity_label'), ' ·')),
            esc_html($meta('acw_location')),
            $meta('acw_address') !== '' ? esc_html(', '.$meta('acw_address')) : ''
        );
        $gallery = '';
        $images = array_filter(array_map('intval', explode(',', $meta('acw_gallery'))));
        if (count($images) > 1) {
            foreach ($images as $attachment) {
                $gallery .= wp_get_attachment_image($attachment, 'medium', false, ['loading' => 'lazy']);
            }
            $gallery = '<div class="acwc-gallery">'.$gallery.'</div>';
        }
        $amenities = $meta('acw_amenities_list') !== '' ? '<h3>Comodidades</h3>'.wp_kses_post($meta('acw_amenities_list')) : '';
        $price = $meta('acw_rates_table') !== '' ? '<h3>Precios</h3>'.wp_kses_post($meta('acw_rates_table')) : ($meta('acw_price_label') !== '' ? '<p class="acwc-card__price">'.esc_html($meta('acw_price_label')).'</p>' : '');

        return $facts.$gallery.$content.$price.$amenities.'<p>'.self::space_button($id).'</p>';
    }

    // --- Planes ---------------------------------------------------------------------------

    /**
     * @param  array<string, string>|string  $atts
     */
    public static function plans($atts): string
    {
        $atts = shortcode_atts(['packs' => 'no', 'columnas' => '3'], is_array($atts) ? $atts : [], 'quorker_planes');
        $catalog = ACWC_Plans::all();
        if ($catalog['plans'] === []) {
            return '<p class="acwc-muted">Por ahora no hay planes para mostrar.</p>';
        }
        wp_enqueue_style('acwc');
        $settings = ACWC_Settings::get();
        $cards = '';
        foreach ($catalog['plans'] as $plan) {
            $lines = [];
            if (($plan['kind'] ?? '') === 'hours' && (int) ($plan['included_minutes'] ?? 0) > 0) {
                $lines[] = ACWC_Format::duration((int) $plan['included_minutes']).' para reservar';
            }
            foreach ((array) ($plan['inclusions'] ?? []) as $inclusion) {
                $lines[] = (string) $inclusion;
            }
            if ((int) ($plan['trial_days'] ?? 0) > 0) {
                $lines[] = (int) $plan['trial_days'].' días de prueba';
            }
            $url = ACWC_Settings::plan_url((string) $plan['name'], (string) ($plan['signup_url'] ?? ''));
            $cards .= sprintf(
                '<article class="acwc-card acwc-plan"><div class="acwc-card__body"><h3 class="acwc-card__title">%s</h3><p class="acwc-plan__price"><strong>%s</strong> <span class="acwc-muted">%s</span></p>%s%s<div class="acwc-actions"><a class="acwc-button" href="%s"%s>%s</a></div></div></article>',
                esc_html((string) $plan['name']),
                esc_html(ACWC_Format::money((int) $plan['price_cents'])),
                esc_html(ACWC_Format::interval((string) ($plan['interval_unit'] ?? 'month'), (int) ($plan['interval_count'] ?? 1))),
                ! empty($plan['description']) ? '<p>'.esc_html((string) $plan['description']).'</p>' : '',
                $lines === [] ? '' : '<ul class="acwc-plan__list">'.implode('', array_map(static fn (string $l): string => '<li>'.esc_html($l).'</li>', $lines)).'</ul>',
                esc_url($url),
                $settings['book_mode'] !== 'url' ? ' target="_blank" rel="noopener"' : '',
                esc_html($settings['plan_label'])
            );
        }
        $html = '<div class="acwc-cards" style="--acwc-columns: '.max(1, min(4, (int) $atts['columnas'])).'">'.$cards.'</div>';

        if (in_array(strtolower($atts['packs']), ['si', 'sí', 'yes', '1'], true) && $catalog['packs'] !== []) {
            $rows = '';
            foreach ($catalog['packs'] as $pack) {
                $rows .= '<tr><td>'.esc_html((string) $pack['name']).'</td><td>'.esc_html(ACWC_Format::duration((int) $pack['minutes'])).'</td><td>'.esc_html(ACWC_Format::money((int) $pack['price_cents'])).'</td></tr>';
            }
            $html .= '<h3>Packs de horas</h3><table class="acw-rates"><thead><tr><th>Pack</th><th>Horas</th><th>Precio</th></tr></thead><tbody>'.$rows.'</tbody></table>';
        }

        return $html;
    }

    // --- Eventos --------------------------------------------------------------------------

    /**
     * @param  array<string, string>|string  $atts
     */
    public static function registration($atts): string
    {
        $atts = shortcode_atts(['evento' => ''], is_array($atts) ? $atts : [], 'quorker_evento_inscripcion');
        $slug = sanitize_title($atts['evento']);
        if ($slug === '' && get_post_type() === ACWC_Events::POST_TYPE) {
            $slug = (string) get_post_meta((int) get_the_ID(), 'acw_event_slug', true);
        }
        $api = ACWC_Settings::get()['api_url'];
        if ($slug === '' || $api === '') {
            return current_user_can('manage_options') ? '<p><em>Quorker: falta el evento o la dirección del coworking.</em></p>' : '';
        }
        wp_enqueue_script('acwc-registration');
        wp_enqueue_style('acwc');

        return sprintf(
            '<div id="inscripcion" class="acwc-register" data-api="%s" data-event="%s" data-site="%s"><p class="acwc-muted">Cargando el formulario…</p><noscript>Para inscribirte, activá JavaScript o entrá a <a href="%s">la página del evento</a>.</noscript></div>',
            esc_attr($api),
            esc_attr($slug),
            esc_attr(get_bloginfo('name')),
            esc_url($api.'/eventos/'.$slug)
        );
    }

    /**
     * En la página de un evento, si el tema no la arma con su plantilla: los datos, las entradas y el
     * formulario debajo del texto. Se apaga en Ajustes → Quorker (para diseñarla con Elementor).
     */
    public static function event_content(string $content): string
    {
        if (! is_singular(ACWC_Events::POST_TYPE) || ! in_the_loop() || ! is_main_query() || ! ACWC_Settings::get()['auto_content'] || has_shortcode($content, 'aurel_evento_inscripcion') || has_shortcode($content, 'quorker_evento_inscripcion')) {
            return $content;
        }
        $id = (int) get_the_ID();
        $meta = static fn (string $key): string => (string) get_post_meta($id, $key, true);
        $facts = sprintf(
            '<p class="acwc-facts"><strong>%s, %s</strong><br>%s%s<br>%s</p>',
            esc_html(ucfirst($meta('acw_date_label'))),
            esc_html($meta('acw_time_label')),
            esc_html($meta('acw_place')),
            $meta('acw_address') !== '' ? esc_html(', '.$meta('acw_address')) : '',
            esc_html(trim($meta('acw_price_from_label').($meta('acw_seats_label') !== '' ? ' · '.$meta('acw_seats_label') : '')))
        );

        return $facts.$content.wp_kses_post($meta('acw_tickets_table')).($meta('acw_is_past') === '1' ? '<p class="acwc-notice">Este evento ya pasó.</p>' : self::registration([]));
    }

    /**
     * @param  array<string, string>|string  $atts
     */
    public static function list($atts): string
    {
        $atts = shortcode_atts(['cantidad' => '6'], is_array($atts) ? $atts : [], 'quorker_eventos');
        $posts = get_posts([
            'post_type' => ACWC_Events::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => max(1, min(50, (int) $atts['cantidad'])),
            'meta_key' => 'acw_starts_at',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_query' => [['key' => 'acw_is_past', 'value' => '0']],
        ]);
        if ($posts === []) {
            return '<p class="acwc-muted">Por ahora no hay eventos próximos.</p>';
        }
        wp_enqueue_style('acwc');
        $items = '';
        foreach ($posts as $post) {
            $items .= sprintf(
                '<li class="acwc-event"><a href="%s"><strong>%s</strong></a><span>%s, %s · %s</span><span class="acwc-muted">%s</span></li>',
                esc_url(get_permalink($post)),
                esc_html(get_the_title($post)),
                esc_html(ucfirst((string) get_post_meta($post->ID, 'acw_date_label', true))),
                esc_html((string) get_post_meta($post->ID, 'acw_time_label', true)),
                esc_html((string) get_post_meta($post->ID, 'acw_place', true)),
                esc_html((string) get_post_meta($post->ID, 'acw_price_from_label', true))
            );
        }

        return '<ul class="acwc-events">'.$items.'</ul>';
    }
}
