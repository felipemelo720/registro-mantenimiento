<?php
// Bloquea acceso directo al archivo fuera de WordPress.
defined( 'ABSPATH' ) || exit;

/**
 * Clase RM_Database
 *
 * Responsable de crear/migrar la tabla en la base de datos y de insertar registros.
 */
class RM_Database {

    /**
     * Versión del esquema de la tabla.
     * Incrementar este valor cuando se modifica la estructura de la tabla,
     * para que maybe_update_db() detecte el cambio y ejecute dbDelta().
     */
    const DB_VERSION = '1.2';

    /**
     * Crea o actualiza la tabla wp_plugin_update_logs.
     *
     * Usa dbDelta() que es la función oficial de WordPress para crear/actualizar tablas:
     * no destruye datos si la tabla ya existe, solo aplica diferencias de esquema
     * (puede agregar columnas nuevas, pero no las elimina ni las renombra).
     *
     * Se llama desde register_activation_hook() al activar el plugin,
     * y desde maybe_update_db() cuando la versión del esquema cambia.
     */
    public static function create_table() {
        global $wpdb;

        // Nombre completo de la tabla respetando el prefijo configurado en wp-config.php.
        $table   = $wpdb->prefix . 'plugin_update_logs';

        // Collation de la base de datos (ej: utf8mb4_unicode_ci).
        $charset = $wpdb->get_charset_collate();

        /*
         * Esquema de la tabla.
         *
         * IMPORTANTE: dbDelta() tiene un parser regex muy estricto. No acepta:
         *   - Comentarios SQL inline ("-- ..." al final de línea).
         *   - Backticks alrededor de nombres de columnas.
         *   - Menos de 2 espacios entre PRIMARY KEY y su definición.
         * Cualquiera de estas rompe silenciosamente la detección de columnas
         * y la migración falla sin arrojar error.
         *
         * Columnas:
         *   id           BIGINT autoincremental, PK.
         *   plugin_slug  Ruta del plugin, ej: "akismet/akismet.php".
         *   plugin_name  Nombre legible del plugin.
         *   old_version  Versión antes del evento.
         *   new_version  Versión después del evento.
         *   user_id      ID de usuario (NULL en auto-updates por cron).
         *   type         'auto' (upgrader de WP) o 'manual' (formulario admin).
         *   notes        Notas libres, solo en registros manuales.
         *   created_at   Fecha/hora local del evento.
         */
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_slug VARCHAR(255) NOT NULL DEFAULT '',
            plugin_name VARCHAR(255) NOT NULL DEFAULT '',
            old_version VARCHAR(50) NOT NULL DEFAULT '',
            new_version VARCHAR(50) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NULL,
            type VARCHAR(10) NOT NULL DEFAULT 'auto',
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset};";

        // upgrade.php es necesario para que dbDelta() esté disponible fuera del contexto de actualización de WP.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Guarda la versión del esquema aplicado para detectar futuras migraciones.
        update_option( 'rm_db_version', self::DB_VERSION );
    }

    /**
     * Ejecuta dbDelta() solo si la versión del esquema almacenada difiere de la actual
     * o si la tabla no existe físicamente en la BD (ej: plugin cargado como mu-plugin
     * sin que corriera register_activation_hook, o DB restaurada sin la tabla).
     * Se llama en cada carga del plugin (plugins_loaded) con costo mínimo:
     * si todo está en orden, retorna inmediatamente sin tocar la BD.
     */
    public static function maybe_update_db() {
        if ( get_option( 'rm_db_version' ) !== self::DB_VERSION || ! self::table_exists() ) {
            self::create_table();
        }
    }

    /**
     * Verifica si la tabla existe físicamente en la BD.
     * Necesario porque get_option('rm_db_version') puede estar seteado
     * pero la tabla haber sido dropeada manualmente o no creada (mu-plugin,
     * usuario MySQL sin permiso CREATE, etc).
     *
     * @return bool
     */
    public static function table_exists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'plugin_update_logs';
        // $wpdb->prepare con %i requiere WP 6.2+. Usamos LIKE escapado manual.
        $like = $wpdb->esc_like( $table );
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table;
    }

    /**
     * Inserta un registro en la tabla.
     *
     * @param array $data {
     *     @type string $plugin_slug  Ruta relativa del plugin (ej: akismet/akismet.php).
     *     @type string $plugin_name  Nombre legible del plugin.
     *     @type string $old_version  Versión antes de actualizar.
     *     @type string $new_version  Versión después de actualizar.
     *     @type int    $user_id      ID del usuario (0 si es update automático).
     *     @type string $type         'auto' o 'manual'. Por defecto 'auto'.
     *     @type string $notes        Notas libres (solo en registros manuales). Por defecto ''.
     * }
     */
    public static function insert_log( array $data ) {
        global $wpdb;

        // Guarda: si la tabla no existe (create_table falló por permisos MySQL,
        // o alguien la dropeó), intentamos recrearla una vez antes de insertar.
        // Si aún así no existe, abortamos silenciosamente para no tirar warning.
        if ( ! self::table_exists() ) {
            self::create_table();
            if ( ! self::table_exists() ) {
                return;
            }
        }

        $wpdb->insert(
            $wpdb->prefix . 'plugin_update_logs',
            [
                'plugin_slug' => $data['plugin_slug'],
                'plugin_name' => $data['plugin_name'],
                'old_version' => $data['old_version'],
                'new_version' => $data['new_version'],
                'user_id'     => $data['user_id'] ?: null,      // NULL si user_id es 0 (update automático).
                'type'        => $data['type']  ?? 'auto',       // 'auto' por defecto si no se especifica.
                'notes'       => $data['notes'] ?? null,         // NULL si no hay notas.
                'created_at'  => current_time( 'mysql' ),        // Hora local configurada en WordPress, no UTC.
            ],
            // Formatos de cada campo para escapado seguro en la consulta SQL.
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );
    }
}
