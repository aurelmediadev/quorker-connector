<?php

defined('ABSPATH') || exit;

/**
 * Las imágenes de Quorker en la biblioteca de medios: cada dirección se baja una sola vez (se
 * recuerda en el adjunto, meta "acw_image_source") y se reutiliza en las sincronizaciones que siguen.
 */
final class ACWC_Media
{
    /** @return int|null el id del adjunto, o null si no se pudo bajar */
    public static function attachment(string $url, int $postId): ?int
    {
        if ($url === '') {
            return null;
        }
        $existing = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => 'acw_image_source',
            'meta_value' => $url,
        ]);
        if ($existing) {
            return (int) $existing[0];
        }
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $attachment = media_sideload_image($url, $postId, null, 'id');
        if (is_wp_error($attachment)) {
            return null;
        }
        update_post_meta((int) $attachment, 'acw_image_source', $url);

        return (int) $attachment;
    }

    /**
     * La primera imagen como destacada y todas como galería (meta "acw_gallery": ids separados por coma).
     *
     * @param  list<string>  $urls
     */
    public static function gallery(int $postId, array $urls): void
    {
        $ids = [];
        foreach ($urls as $url) {
            $id = self::attachment((string) $url, $postId);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        if ($ids !== []) {
            set_post_thumbnail($postId, $ids[0]);
        } else {
            delete_post_thumbnail($postId);
        }
        update_post_meta($postId, 'acw_gallery', implode(',', $ids));
    }
}
