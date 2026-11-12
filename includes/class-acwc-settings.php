<?php

defined('ABSPATH') || exit;

/**
 * Ajustes → Aurel Cowork: la dirección del coworking en Aurel Cowork, la clave del conector y la
 * dirección de las páginas de eventos. Muestra el estado de la última sincronización y permite
 * sincronizar a mano.
 */
final class ACWC_Settings
{
    public const OPTION = 'acwc_settings';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
        add_action('admin_post_acwc_sync', [self::class, 'sync_now']);
    }

    /**
     * @return array{api_url: string, key: string, events_slug: string, auto_content: bool}
     */
    public static function get(): array
    {
        $saved = get_option(self::OPTION, []);

        return [
            'api_url' => rtrim((string) ($saved['api_url'] ?? ''), '/'),
            'key' => (string) ($saved['key'] ?? ''),
            'events_slug' => (string) ($saved['events_slug'] ?? 'eventos') ?: 'eventos',
            'auto_content' => (bool) ($saved['auto_content'] ?? true),
        ];
    }

    public static function menu(): void
    {
        add_options_page('Aurel Cowork', 'Aurel Cowork', 'manage_options', 'aurel-cowork', [self::class, 'page']);
    }

    public static function register(): void
    {
        register_setting('acwc', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => static function ($input): array {
                $current = self::get();
                $slug = sanitize_title((string) ($input['events_slug'] ?? 'eventos')) ?: 'eventos';
                if ($slug !== $current['events_slug']) {
                    update_option('acwc_flush_rewrite', 1);
                }

                return [
                    'api_url' => esc_url_raw(rtrim(trim((string) ($input['api_url'] ?? '')), '/')),
                    // La clave no se muestra: si el campo viene vacío, queda la que había.
                    'key' => trim((string) ($input['key'] ?? '')) !== '' ? sanitize_text_field((string) $input['key']) : $current['key'],
                    'events_slug' => $slug,
                    'auto_content' => ! empty($input['auto_content']),
                ];
            },
        ]);
    }

    public static function page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $settings = self::get();
        $last = get_option('acwc_last_sync', null);
        ?>
        <div class="wrap">
            <h1>Aurel Cowork</h1>
            <?php if (isset($_GET['acwc_synced'])) { ?>
                <div class="notice notice-success is-dismissible"><p>Sincronización terminada.</p></div>
            <?php } ?>
            <form method="post" action="options.php">
                <?php settings_fields('acwc'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="acwc-api">Dirección del coworking</label></th>
                        <td>
                            <input id="acwc-api" class="regular-text" type="url" name="<?php echo esc_attr(self::OPTION); ?>[api_url]" value="<?php echo esc_attr($settings['api_url']); ?>" placeholder="https://tucoworking.aurelcowork.com">
                            <p class="description">La que muestra Aurel Cowork en <em>Sitio web</em>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acwc-key">Clave del conector</label></th>
                        <td>
                            <input id="acwc-key" class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr(self::OPTION); ?>[key]" value="" placeholder="<?php echo $settings['key'] !== '' ? esc_attr('Guardada (…'.substr($settings['key'], -4).')') : 'acw_…'; ?>">
                            <p class="description">Se genera en Aurel Cowork → <em>Sitio web</em>. Dejalo vacío para mantener la guardada.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acwc-slug">Dirección de los eventos</label></th>
                        <td>
                            <code><?php echo esc_html(home_url('/')); ?></code><input id="acwc-slug" type="text" name="<?php echo esc_attr(self::OPTION); ?>[events_slug]" value="<?php echo esc_attr($settings['events_slug']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Página del evento</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auto_content]" value="1" <?php checked($settings['auto_content']); ?>> Mostrar los datos, las entradas y el formulario automáticamente</label>
                            <p class="description">Apagalo si diseñás la página con una plantilla de Elementor (campos <code>acw_*</code> y el shortcode del formulario).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar'); ?>
            </form>

            <h2>Sincronización</h2>
            <p>
                <?php if (is_array($last)) { ?>
                    Última: <?php echo esc_html(wp_date('d/m/Y H:i', (int) $last['at'])); ?> —
                    <?php echo $last['ok'] ? esc_html(sprintf('%d eventos al día.', (int) $last['count'])) : esc_html('Error: '.$last['error']); ?>
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
                <li>Cada evento publicado en Aurel Cowork tiene su página en <code><?php echo esc_html(home_url('/'.$settings['events_slug'].'/')); ?></code> (tipo de contenido <code>acw_event</code>): diseñala con una plantilla de Elementor (individual y archivo) y los campos <code>acw_*</code> con JetEngine.</li>
                <li>El formulario de inscripción: shortcode <code>[aurel_evento_inscripcion]</code> en la plantilla del evento (o <code>[aurel_evento_inscripcion evento="identificador"]</code> en cualquier página).</li>
                <li>La lista de próximos eventos: <code>[aurel_eventos]</code>.</li>
            </ul>
        </div>
        <?php
    }

    public static function sync_now(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('acwc_sync')) {
            wp_die('Sin permiso.');
        }
        ACWC_Events::sync();
        wp_safe_redirect(admin_url('options-general.php?page=aurel-cowork&acwc_synced=1'));
        exit;
    }
}
