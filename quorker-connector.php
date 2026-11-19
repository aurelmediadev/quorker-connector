<?php

/**
 * Plugin Name:       Quorker Conector
 * Plugin URI:        https://github.com/aurelmediadev/quorker-connector
 * Description:       Publica en este sitio los espacios, los planes y los eventos del coworking cargados en Quorker (una página por espacio y por evento, con campos para Elementor y JetEngine), con botones para reservar y el formulario de inscripción a eventos.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Quorker
 * Author URI:        https://quorker.com
 * Text Domain:       quorker-connector
 * License:           Propietaria
 * Update URI:        https://github.com/aurelmediadev/quorker-connector
 *
 * Quorker es la fuente de verdad: espacios, planes y eventos se crean y se editan allá. Este plugin
 * los lee de la API pública del coworking y mantiene al día una entrada por espacio ("acw_space") y
 * por evento ("acw_event"), que no se editan desde WordPress. Se actualiza cuando Quorker avisa
 * (aviso firmado con la clave del conector) y, por las dudas, cada hora.
 *
 * Las versiones nuevas salen como releases de GitHub y WordPress las ofrece como cualquier
 * actualización de plugin (ACWC_Updater).
 */
defined('ABSPATH') || exit;

define('ACWC_VERSION', '0.3.0');
define('ACWC_FILE', __FILE__);
define('ACWC_DIR', plugin_dir_path(__FILE__));
define('ACWC_URL', plugin_dir_url(__FILE__));

require_once ACWC_DIR.'includes/class-acwc-settings.php';
require_once ACWC_DIR.'includes/class-acwc-api.php';
require_once ACWC_DIR.'includes/class-acwc-format.php';
require_once ACWC_DIR.'includes/class-acwc-media.php';
require_once ACWC_DIR.'includes/class-acwc-events.php';
require_once ACWC_DIR.'includes/class-acwc-spaces.php';
require_once ACWC_DIR.'includes/class-acwc-plans.php';
require_once ACWC_DIR.'includes/class-acwc-sync.php';
require_once ACWC_DIR.'includes/class-acwc-webhook.php';
require_once ACWC_DIR.'includes/class-acwc-shortcodes.php';
require_once ACWC_DIR.'includes/class-acwc-updater.php';

add_action('plugins_loaded', static function (): void {
    ACWC_Settings::boot();
    ACWC_Events::boot();
    ACWC_Spaces::boot();
    ACWC_Sync::boot();
    ACWC_Webhook::boot();
    ACWC_Shortcodes::boot();
    ACWC_Updater::boot();
});

register_activation_hook(__FILE__, static function (): void {
    ACWC_Events::register_post_type();
    ACWC_Spaces::register_post_type();
    flush_rewrite_rules();
    if (! wp_next_scheduled(ACWC_Sync::CRON_HOOK)) {
        wp_schedule_event(time() + 60, 'hourly', ACWC_Sync::CRON_HOOK);
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(ACWC_Sync::CRON_HOOK);
    flush_rewrite_rules();
});
