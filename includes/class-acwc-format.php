<?php

defined('ABSPATH') || exit;

/**
 * Cómo se muestran precios y duraciones en el sitio: "$ 8.000", "US$ 49", "2 h", "1 h 30 min".
 * La moneda es la del coworking en Quorker (la trae el catálogo de planes).
 */
final class ACWC_Format
{
    private const SYMBOLS = [
        'ARS' => '$', 'UYU' => '$U', 'CLP' => '$', 'COP' => '$', 'MXN' => '$', 'USD' => 'US$',
        'EUR' => '€', 'PEN' => 'S/', 'BRL' => 'R$', 'PYG' => '₲', 'BOB' => 'Bs',
    ];

    public static function money(int $cents, ?string $currency = null): string
    {
        $currency = strtoupper($currency ?? (string) get_option('acwc_currency', 'ARS'));
        $symbol = self::SYMBOLS[$currency] ?? $currency;

        return $symbol.' '.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', '.');
    }

    public static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours === 0) {
            return $rest.' min';
        }

        return $hours.' h'.($rest > 0 ? ' '.$rest.' min' : '');
    }

    /** "por mes", "cada 3 meses". */
    public static function interval(string $unit, int $count): string
    {
        if ($unit !== 'month') {
            return '';
        }

        return $count <= 1 ? 'por mes' : 'cada '.$count.' meses';
    }
}
