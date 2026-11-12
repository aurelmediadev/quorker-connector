<?php

/**
 * Plugin Name:       Aurel Cowork Conector
 * Description:       Publica en este sitio los eventos del coworking cargados en Aurel Cowork (una página por evento, con campos para Elementor y JetEngine) y suma el formulario de inscripción.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Aurel Media
 * Text Domain:       aurel-cowork-connector
 * License:           Propietaria
 *
 * Aurel Cowork es la fuente de verdad: los eventos se crean y se editan allá. Este plugin los lee de
 * la API pública del coworking y mantiene al día una entrada "acw_event" por evento (no se editan
 * desde WordPress). Se actualiza cuando Aurel Cowork avisa (aviso firmado con la clave del
 * conector) y, por las dudas, cada hora.
 */
defined('ABSPATH') || exit;

define('ACWC_VERSION', '0.1.0');
define('ACWC_FILE', __FILE__);
define('ACWC_DIR', plugin_dir_path(__FILE__));
define('ACWC_URL', plugin_dir_url(__FILE__));

require_once ACWC_DIR.'includes/class-acwc-settings.php';
require_once ACWC_DIR.'includes/class-acwc-api.php';
require_once ACWC_DIR.'includes/class-acwc-events.php';
require_once ACWC_DIR.'includes/class-acwc-webhook.php';
require_once ACWC_DIR.'includes/class-acwc-shortcodes.php';

add_action('plugins_loaded', static function (): void {
    ACWC_Settings::boot();
    ACWC_Events::boot();
    ACWC_Webhook::boot();
    ACWC_Shortcodes::boot();
});

register_activation_hook(__FILE__, static function (): void {
    ACWC_Events::register_post_type();
    flush_rewrite_rules();
    if (! wp_next_scheduled(ACWC_Events::CRON_HOOK)) {
        wp_schedule_event(time() + 60, 'hourly', ACWC_Events::CRON_HOOK);
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(ACWC_Events::CRON_HOOK);
    flush_rewrite_rules();
});
