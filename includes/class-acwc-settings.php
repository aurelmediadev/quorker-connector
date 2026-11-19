<?php

defined('ABSPATH') || exit;

/**
 * Ajustes → Quorker: la dirección del coworking en Quorker, la clave del conector, las direcciones
 * de las páginas de espacios y eventos, y a dónde llevan los botones "Reservar" y "Quiero este plan":
 *
 * - portal (por defecto): al portal de clientes de Quorker, con el espacio ya elegido (el cliente
 *   entra con su cuenta);
 * - whatsapp: a un chat de WhatsApp con el mensaje armado ({espacio} o {plan} se reemplazan);
 * - url: a otra dirección (un formulario de contacto, por ejemplo).
 *
 * Muestra el estado de la última sincronización y permite sincronizar a mano.
 */
final class ACWC_Settings
{
    public const OPTION = 'acwc_settings';

    public const PAGE = 'quorker';

    private const DEFAULTS = [
        'api_url' => '',
        'key' => '',
        'events_slug' => 'eventos',
        'spaces_slug' => 'espacios',
        'auto_content' => true,
        'book_mode' => 'portal',
        'whatsapp' => '',
        'book_message' => 'Hola, quiero reservar {espacio}.',
        'plan_message' => 'Hola, me interesa el plan {plan}.',
        'custom_url' => '',
        'book_label' => 'Reservar',
        'plan_label' => 'Quiero este plan',
    ];

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
        add_action('admin_post_acwc_sync', [self::class, 'sync_now']);
        // Los botones guardados en cada espacio cambian con estos ajustes: se vuelve a sincronizar.
        add_action('update_option_'.self::OPTION, static function (): void {
            wp_schedule_single_event(time(), ACWC_Sync::CRON_HOOK);
        });
        add_filter('plugin_action_links_'.plugin_basename(ACWC_FILE), static function (array $links): array {
            array_unshift($links, '<a href="'.esc_url(admin_url('options-general.php?page='.self::PAGE)).'">Ajustes</a>');

            return $links;
        });
    }

    /**
     * @return array{api_url: string, key: string, events_slug: string, spaces_slug: string, auto_content: bool, book_mode: string, whatsapp: string, book_message: string, plan_message: string, custom_url: string, book_label: string, plan_label: string}
     */
    public static function get(): array
    {
        $saved = (array) get_option(self::OPTION, []);
        $s = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        $s['api_url'] = rtrim((string) $s['api_url'], '/');
        $s['events_slug'] = (string) $s['events_slug'] ?: 'eventos';
        $s['spaces_slug'] = (string) $s['spaces_slug'] ?: 'espacios';
        $s['auto_content'] = (bool) $s['auto_content'];
        $s['book_mode'] = in_array($s['book_mode'], ['portal', 'whatsapp', 'url'], true) ? $s['book_mode'] : 'portal';
        foreach (['book_label', 'plan_label', 'book_message', 'plan_message'] as $text) {
            $s[$text] = (string) $s[$text] ?: self::DEFAULTS[$text];
        }

        return $s;
    }

    /** A dónde lleva "Reservar" un espacio según los ajustes. */
    public static function book_url(string $space, string $portalUrl): string
    {
        return self::target(str_replace('{espacio}', $space, self::get()['book_message']), $portalUrl);
    }

    /** A dónde lleva "Quiero este plan". */
    public static function plan_url(string $plan, string $portalUrl): string
    {
        return self::target(str_replace('{plan}', $plan, self::get()['plan_message']), $portalUrl);
    }

    private static function target(string $message, string $portalUrl): string
    {
        $s = self::get();
        if ($s['book_mode'] === 'whatsapp' && $s['whatsapp'] !== '') {
            return 'https://wa.me/'.$s['whatsapp'].'?text='.rawurlencode($message);
        }
        if ($s['book_mode'] === 'url' && $s['custom_url'] !== '') {
            return $s['custom_url'];
        }

        return $portalUrl !== '' ? $portalUrl : $s['api_url'].'/panel';
    }

    public static function menu(): void
    {
        add_options_page('Quorker', 'Quorker', 'manage_options', self::PAGE, [self::class, 'page']);
    }

    public static function register(): void
    {
        register_setting('acwc', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => static function ($input): array {
                $input = is_array($input) ? $input : [];
                $current = self::get();
                $events = sanitize_title((string) ($input['events_slug'] ?? 'eventos')) ?: 'eventos';
                $spaces = sanitize_title((string) ($input['spaces_slug'] ?? 'espacios')) ?: 'espacios';
                if ($events !== $current['events_slug'] || $spaces !== $current['spaces_slug']) {
                    update_option('acwc_flush_rewrite', 1);
                }
                $text = static fn (string $key): string => sanitize_text_field((string) ($input[$key] ?? ''));

                return [
                    'api_url' => esc_url_raw(rtrim(trim((string) ($input['api_url'] ?? '')), '/')),
                    // La clave no se muestra: si el campo viene vacío, queda la que había.
                    'key' => trim((string) ($input['key'] ?? '')) !== '' ? sanitize_text_field((string) $input['key']) : $current['key'],
                    'events_slug' => $events,
                    'spaces_slug' => $spaces,
                    'auto_content' => ! empty($input['auto_content']),
                    'book_mode' => in_array($input['book_mode'] ?? '', ['portal', 'whatsapp', 'url'], true) ? $input['book_mode'] : 'portal',
                    // Solo números, con el código de país (5491155550000).
                    'whatsapp' => preg_replace('/\D+/', '', (string) ($input['whatsapp'] ?? '')),
                    'book_message' => $text('book_message'),
                    'plan_message' => $text('plan_message'),
                    'custom_url' => esc_url_raw(trim((string) ($input['custom_url'] ?? ''))),
                    'book_label' => $text('book_label'),
                    'plan_label' => $text('plan_label'),
                ];
            },
        ]);
    }

    public static function page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $s = self::get();
        $last = get_option('acwc_last_sync', null);
        $name = static fn (string $key): string => esc_attr(self::OPTION.'['.$key.']');
        $labels = ['plans' => 'planes', 'spaces' => 'espacios', 'events' => 'eventos'];
        ?>
        <div class="wrap">
            <h1>Quorker</h1>
            <?php if (isset($_GET['acwc_synced'])) { ?>
                <div class="notice notice-success is-dismissible"><p>Sincronización terminada.</p></div>
            <?php } ?>
            <form method="post" action="options.php">
                <?php settings_fields('acwc'); ?>
                <h2>Conexión</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="acwc-api">Dirección del coworking</label></th>
                        <td>
                            <input id="acwc-api" class="regular-text" type="url" name="<?php echo $name('api_url'); ?>" value="<?php echo esc_attr($s['api_url']); ?>" placeholder="https://tucoworking.quorker.com">
                            <p class="description">La que muestra Quorker en <em>Sitio web</em>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acwc-key">Clave del conector</label></th>
                        <td>
                            <input id="acwc-key" class="regular-text" type="password" autocomplete="off" name="<?php echo $name('key'); ?>" value="" placeholder="<?php echo $s['key'] !== '' ? esc_attr('Guardada (…'.substr($s['key'], -4).')') : 'acw_…'; ?>">
                            <p class="description">Se genera en Quorker → <em>Sitio web</em>. Dejalo vacío para mantener la guardada.</p>
                        </td>
                    </tr>
                </table>

                <h2>Páginas</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="acwc-spaces">Dirección de los espacios</label></th>
                        <td><code><?php echo esc_html(home_url('/')); ?></code><input id="acwc-spaces" type="text" name="<?php echo $name('spaces_slug'); ?>" value="<?php echo esc_attr($s['spaces_slug']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acwc-slug">Dirección de los eventos</label></th>
                        <td><code><?php echo esc_html(home_url('/')); ?></code><input id="acwc-slug" type="text" name="<?php echo $name('events_slug'); ?>" value="<?php echo esc_attr($s['events_slug']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Contenido automático</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo $name('auto_content'); ?>" value="1" <?php checked($s['auto_content']); ?>> En la página de cada espacio y evento, mostrar los datos, los precios y el botón o el formulario</label>
                            <p class="description">Apagalo si diseñás esas páginas con plantillas de Elementor (campos <code>acw_*</code> y los shortcodes).</p>
                        </td>
                    </tr>
                </table>

                <h2>Botones de reserva y de planes</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Llevan a</th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="<?php echo $name('book_mode'); ?>" value="portal" <?php checked($s['book_mode'], 'portal'); ?>> El portal de clientes de Quorker (reservan y pagan con su cuenta)</label><br>
                                <label><input type="radio" name="<?php echo $name('book_mode'); ?>" value="whatsapp" <?php checked($s['book_mode'], 'whatsapp'); ?>> Un chat de WhatsApp con el mensaje armado</label><br>
                                <label><input type="radio" name="<?php echo $name('book_mode'); ?>" value="url" <?php checked($s['book_mode'], 'url'); ?>> Otra dirección</label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acwc-wa">WhatsApp</label></th>
                        <td>
                            <input id="acwc-wa" type="text" name="<?php echo $name('whatsapp'); ?>" value="<?php echo esc_attr($s['whatsapp']); ?>" placeholder="5491155550000">
                            <p class="description">Con el código de país, sin espacios ni signos.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acwc-bm">Mensaje para reservar</label></th>
                        <td><input id="acwc-bm" class="large-text" type="text" name="<?php echo $name('book_message'); ?>" value="<?php echo esc_attr($s['book_message']); ?>"><p class="description"><code>{espacio}</code> se reemplaza por el nombre del espacio.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acwc-pm">Mensaje para un plan</label></th>
                        <td><input id="acwc-pm" class="large-text" type="text" name="<?php echo $name('plan_message'); ?>" value="<?php echo esc_attr($s['plan_message']); ?>"><p class="description"><code>{plan}</code> se reemplaza por el nombre del plan.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acwc-url">Otra dirección</label></th>
                        <td><input id="acwc-url" class="regular-text" type="url" name="<?php echo $name('custom_url'); ?>" value="<?php echo esc_attr($s['custom_url']); ?>" placeholder="<?php echo esc_attr(home_url('/contacto/')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Textos de los botones</th>
                        <td>
                            <label>Espacios <input type="text" name="<?php echo $name('book_label'); ?>" value="<?php echo esc_attr($s['book_label']); ?>"></label>
                            <label style="margin-left: 12px">Planes <input type="text" name="<?php echo $name('plan_label'); ?>" value="<?php echo esc_attr($s['plan_label']); ?>"></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar'); ?>
            </form>

            <h2>Sincronización</h2>
            <p>
                <?php if (is_array($last) && isset($last['parts'])) { ?>
                    Última: <?php echo esc_html(wp_date('d/m/Y H:i', (int) $last['at'])); ?> —
                    <?php
                    $bits = [];
                    foreach ((array) $last['parts'] as $part => $r) {
                        $bits[] = $r['ok'] ? sprintf('%d %s', (int) $r['count'], $labels[$part] ?? $part) : sprintf('%s: error (%s)', $labels[$part] ?? $part, (string) $r['error']);
                    }
                    echo esc_html(implode(' · ', $bits));
                    ?>
                <?php } else { ?>
                    Todavía no se sincronizó.
                <?php } ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="acwc_sync">
                <?php wp_nonce_field('acwc_sync'); ?>
                <?php submit_button('Sincronizar ahora', 'secondary', 'submit', false); ?>
            </form>

            <h2>Cómo usarlo</h2>
            <ul style="list-style: disc; padding-left: 20px">
                <li><strong>Espacios:</strong> cada espacio con página pública en Quorker tiene su página en <code><?php echo esc_html(home_url('/'.$s['spaces_slug'].'/')); ?></code> (tipo de contenido <code>acw_space</code>, categorías en <code>acw_space_category</code>). La lista: <code>[quorker_espacios]</code> (con <code>categoria="salas"</code>, <code>cantidad="6"</code> o <code>columnas="2"</code>). El botón suelto: <code>[quorker_reservar espacio="identificador"]</code>.</li>
                <li><strong>Planes:</strong> <code>[quorker_planes]</code> muestra los planes y, con <code>packs="si"</code>, los packs de horas.</li>
                <li><strong>Eventos:</strong> cada evento publicado tiene su página en <code><?php echo esc_html(home_url('/'.$s['events_slug'].'/')); ?></code> (<code>acw_event</code>). La lista: <code>[quorker_eventos]</code>. El formulario de inscripción: <code>[quorker_evento_inscripcion]</code> en la plantilla del evento (o con <code>evento="identificador"</code> en cualquier página).</li>
                <li><strong>Elementor y JetEngine:</strong> diseñá las páginas con plantillas (individual y archivo) y los campos <code>acw_*</code>; apagá el contenido automático.</li>
            </ul>
            <p class="description">Versión <?php echo esc_html(ACWC_VERSION); ?>. Las actualizaciones aparecen en <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">Plugins</a> como las de cualquier plugin.</p>
        </div>
        <?php
    }

    public static function sync_now(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('acwc_sync')) {
            wp_die('Sin permiso.');
        }
        ACWC_Sync::all();
        wp_safe_redirect(admin_url('options-general.php?page='.self::PAGE.'&acwc_synced=1'));
        exit;
    }
}
