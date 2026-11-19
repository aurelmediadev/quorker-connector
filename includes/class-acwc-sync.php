<?php

defined('ABSPATH') || exit;

/**
 * Sincroniza todo lo que el sitio publica de Quorker: planes (y la moneda), espacios y eventos.
 * Corre cuando Quorker avisa, cada hora y con "Sincronizar ahora". Guarda el resultado de cada
 * parte en "acwc_last_sync" para mostrarlo en Ajustes → Quorker.
 */
final class ACWC_Sync
{
    /** El mismo nombre que en la 0.2 (así queda la tarea programada de los sitios que actualizan). */
    public const CRON_HOOK = 'acwc_sync_events';

    public static function boot(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'all']);
    }

    /**
     * @return array{ok: bool, parts: array<string, array{ok: bool, count: int, error: ?string}>, at: int}
     */
    public static function all(): array
    {
        if (! defined('ACWC_SYNCING')) {
            define('ACWC_SYNCING', true);
        }
        $parts = [
            'plans' => ACWC_Plans::sync(),
            'spaces' => ACWC_Spaces::sync(),
            'events' => ACWC_Events::sync(),
        ];
        $result = [
            'ok' => ! in_array(false, array_column($parts, 'ok'), true),
            'parts' => $parts,
            'at' => time(),
        ];
        update_option('acwc_last_sync', $result, false);

        return $result;
    }
}
