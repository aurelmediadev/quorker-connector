<?php

defined('ABSPATH') || exit;

/**
 * Actualizaciones desde GitHub Releases (repo público aurelmediadev/quorker-connector).
 *
 * El encabezado "Update URI" del plugin hace que WordPress no lo busque en wordpress.org y pregunte
 * acá (filtro update_plugins_github.com). Se lee la última release, que trae el zip
 * "quorker-connector.zip" (con la carpeta quorker-connector/ adentro, así la actualización reemplaza
 * el plugin en su lugar). La respuesta se guarda 6 horas para no gastar el límite de la API de GitHub.
 */
final class ACWC_Updater
{
    public const REPO = 'aurelmediadev/quorker-connector';

    private const ASSET = 'quorker-connector.zip';

    private const CACHE = 'acwc_latest_release';

    public static function boot(): void
    {
        add_filter('update_plugins_github.com', [self::class, 'check'], 10, 3);
        add_filter('plugins_api', [self::class, 'details'], 10, 3);
        // Al terminar una actualización, la próxima consulta vuelve a GitHub.
        add_action('upgrader_process_complete', static function (): void {
            delete_site_transient(self::CACHE);
        });
        // "Buscar actualizaciones" en Escritorio → Actualizaciones también limpia lo guardado.
        add_action('load-update-core.php', static function (): void {
            if (isset($_GET['force-check'])) {
                delete_site_transient(self::CACHE);
            }
        });
    }

    /**
     * @param  array<string, mixed>|false  $update
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|false
     */
    public static function check($update, array $data, string $file)
    {
        if ($file !== plugin_basename(ACWC_FILE)) {
            return $update;
        }
        $release = self::latest();
        if ($release === null) {
            return $update;
        }

        return [
            'id' => 'github.com/'.self::REPO,
            'slug' => dirname(plugin_basename(ACWC_FILE)),
            'plugin' => $file,
            'version' => $release['version'],
            'url' => 'https://github.com/'.self::REPO,
            'package' => $release['package'],
            'requires_php' => '8.0',
            'requires' => '6.4',
        ];
    }

    /**
     * La ventana "Ver detalles" de la actualización.
     *
     * @param  false|object|array<string, mixed>  $result
     * @return false|object|array<string, mixed>
     */
    public static function details($result, string $action, object $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== dirname(plugin_basename(ACWC_FILE))) {
            return $result;
        }
        $release = self::latest();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => 'Quorker Conector',
            'slug' => dirname(plugin_basename(ACWC_FILE)),
            'version' => $release['version'],
            'author' => '<a href="https://quorker.com">Quorker</a>',
            'homepage' => 'https://github.com/'.self::REPO,
            'download_link' => $release['package'],
            'requires' => '6.4',
            'requires_php' => '8.0',
            'last_updated' => $release['published_at'],
            'sections' => [
                'description' => 'Publica en tu sitio los espacios, los planes y los eventos de tu coworking cargados en Quorker.',
                'changelog' => wpautop(esc_html($release['notes'])),
            ],
        ];
    }

    /**
     * @return array{version: string, package: string, notes: string, published_at: string}|null
     */
    public static function latest(): ?array
    {
        $cached = get_site_transient(self::CACHE);
        if (is_array($cached)) {
            return $cached['version'] === '' ? null : $cached;
        }
        $response = wp_remote_get('https://api.github.com/repos/'.self::REPO.'/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'quorker-connector/'.ACWC_VERSION],
        ]);
        $empty = ['version' => '', 'package' => '', 'notes' => '', 'published_at' => ''];
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Sin respuesta: se vuelve a probar en una hora.
            set_site_transient(self::CACHE, $empty, HOUR_IN_SECONDS);

            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $package = '';
        foreach ((array) ($body['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === self::ASSET) {
                $package = (string) $asset['browser_download_url'];
            }
        }
        $version = ltrim((string) ($body['tag_name'] ?? ''), 'vV');
        $release = $package === '' || $version === '' ? $empty : [
            'version' => $version,
            'package' => $package,
            'notes' => (string) ($body['body'] ?? ''),
            'published_at' => (string) ($body['published_at'] ?? ''),
        ];
        set_site_transient(self::CACHE, $release, 6 * HOUR_IN_SECONDS);

        return $release['version'] === '' ? null : $release;
    }
}
