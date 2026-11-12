<?php

defined('ABSPATH') || exit;

/**
 * Shortcodes:
 *
 * - [aurel_evento_inscripcion evento="identificador"]: el formulario de inscripción. En la página de
 *   un evento no hace falta el atributo. Lee las entradas en vivo de Aurel Cowork y manda la
 *   inscripción directo allá (Aurel Cowork habilita este sitio en "Sitio web").
 * - [aurel_eventos cantidad="6"]: los próximos eventos, con link a su página.
 */
final class ACWC_Shortcodes
{
    public static function boot(): void
    {
        add_shortcode('aurel_evento_inscripcion', [self::class, 'registration']);
        add_shortcode('aurel_eventos', [self::class, 'list']);
        add_filter('the_content', [self::class, 'event_content'], 20);
        add_action('wp_enqueue_scripts', static function (): void {
            wp_register_script('acwc-registration', ACWC_URL.'assets/registration.js', [], ACWC_VERSION, true);
            wp_register_style('acwc', ACWC_URL.'assets/acwc.css', [], ACWC_VERSION);
        });
    }

    /**
     * @param  array<string, string>|string  $atts
     */
    public static function registration($atts): string
    {
        $atts = shortcode_atts(['evento' => ''], is_array($atts) ? $atts : [], 'aurel_evento_inscripcion');
        $slug = sanitize_title($atts['evento']);
        if ($slug === '' && get_post_type() === ACWC_Events::POST_TYPE) {
            $slug = (string) get_post_meta((int) get_the_ID(), 'acw_event_slug', true);
        }
        $api = ACWC_Settings::get()['api_url'];
        if ($slug === '' || $api === '') {
            return current_user_can('manage_options') ? '<p><em>Aurel Cowork: falta el evento o la dirección del coworking.</em></p>' : '';
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
     * formulario debajo del texto. Se apaga en Ajustes → Aurel Cowork (para diseñarla con Elementor).
     */
    public static function event_content(string $content): string
    {
        if (! is_singular(ACWC_Events::POST_TYPE) || ! in_the_loop() || ! is_main_query() || ! ACWC_Settings::get()['auto_content'] || has_shortcode($content, 'aurel_evento_inscripcion')) {
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
        $atts = shortcode_atts(['cantidad' => '6'], is_array($atts) ? $atts : [], 'aurel_eventos');
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
