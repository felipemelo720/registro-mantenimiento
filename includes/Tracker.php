<?php
// Bloquea acceso directo al archivo fuera de WordPress.
defined( 'ABSPATH' ) || exit;

/**
 * Clase RM_Tracker
 *
 * Detecta cuándo WordPress actualiza plugins y registra el evento en la BD.
 *
 * Flujo:
 *   1. Constructor              → Lee y guarda versiones de TODOS los plugins instalados
 *                                 al inicio de la request, antes de cualquier upgrade.
 *   2. upgrader_process_complete → Tras el update, compara versiones y guarda en BD.
 *
 * Por qué no usamos upgrader_pre_install para capturar la versión vieja:
 *   Ese hook corre dentro de install_package() pero en algunos flows (auto-updates,
 *   bulk, ciertas configuraciones de hosting) los archivos ya fueron reemplazados
 *   o el hook llega con $hook_extra incompleto. Leer las versiones al constructor
 *   (via get_plugins() antes de cualquier upgrade) es la forma más robusta y confiable.
 */
class RM_Tracker {

    /**
     * Versiones de todos los plugins instalados, capturadas al inicio de la request.
     * Formato: [ 'akismet/akismet.php' => '5.3.1', ... ]
     *
     * @var array
     */
    private $pre_versions = [];

    /**
     * Al instanciar, hace snapshot inmediato de versiones y registra el hook post-update.
     */
    public function __construct() {
        // Lee versiones actuales antes de que empiece cualquier proceso de actualización.
        $this->snapshot_current_versions();

        // Prioridad 10, 2 argumentos: $upgrader (WP_Upgrader) y $hook_extra (metadatos).
        add_action( 'upgrader_process_complete', [ $this, 'log_updates' ], 10, 2 );
    }

    /**
     * Guarda en memoria las versiones de todos los plugins instalados.
     *
     * get_plugins() requiere wp-admin/includes/plugin.php, que no se carga
     * automáticamente en todas las requests (ej: auto-updates via cron).
     * Por eso verificamos y cargamos el archivo si es necesario.
     */
    private function snapshot_current_versions() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // get_plugins() devuelve array: [ 'slug/plugin.php' => [ 'Version' => '1.2', ... ] ]
        foreach ( get_plugins() as $slug => $data ) {
            $this->pre_versions[ $slug ] = $data['Version'];
        }
    }

    /**
     * Hook: upgrader_process_complete
     *
     * Se ejecuta después de que WordPress termina de reemplazar los archivos del plugin.
     * Compara la versión guardada antes (pre_versions) contra la nueva en disco
     * y registra el cambio en la base de datos.
     *
     * Para bulk updates, este hook se dispara UNA VEZ POR PLUGIN (no una vez para todos).
     * Por eso resolve_plugin_list() prioriza 'plugin' singular sobre 'plugins' plural,
     * evitando registrar duplicados en cada disparo del bulk.
     *
     * @param  WP_Upgrader $upgrader   Instancia del upgrader (no se usa directamente).
     * @param  array       $hook_extra Metadatos del update: action, type, plugin, plugins.
     */
    public function log_updates( $upgrader, $hook_extra ) {
        // Ignorar si no es una actualización de plugins.
        if ( ! $this->is_plugin_update( $hook_extra ) ) {
            return;
        }

        // Limpia la caché de la lista de plugins para forzar re-lectura del disco.
        // Sin esto, get_plugins() podría devolver datos viejos en caché.
        wp_clean_plugins_cache( false );

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Lee los plugins tal como están en disco ahora (versiones nuevas).
        $updated_plugins = get_plugins();

        // Usuario que ejecutó el update. Es 0 en auto-updates por WP-Cron.
        $user_id = get_current_user_id();

        foreach ( $this->resolve_plugin_list( $hook_extra ) as $slug ) {
            // Si el plugin no está en disco (fue desinstalado durante el proceso), omitir.
            if ( ! isset( $updated_plugins[ $slug ] ) ) {
                continue;
            }

            // Versión previa guardada en el snapshot al inicio de la request.
            // Si es '' el plugin era nuevo (instalación, no actualización).
            $old_version = $this->pre_versions[ $slug ] ?? '';
            $new_version = $updated_plugins[ $slug ]['Version'];

            // Solo registra si la versión realmente cambió.
            // Evita duplicados en edge cases donde el upgrader se dispara sin cambio real.
            if ( $old_version === $new_version ) {
                continue;
            }

            RM_Database::insert_log( [
                'plugin_slug' => $slug,
                'plugin_name' => $updated_plugins[ $slug ]['Name'],
                'old_version' => $old_version,
                'new_version' => $new_version,
                'user_id'     => $user_id,
                'type'        => 'auto', // Registro generado automáticamente por el upgrader de WordPress.
            ] );
        }
    }

    // -------------------------------------------------------------------------
    // Métodos privados de soporte
    // -------------------------------------------------------------------------

    /**
     * Verifica que $hook_extra corresponde a una actualización de plugin.
     * Filtra instalaciones nuevas, updates de temas, updates del core, etc.
     *
     * @param  array $hook_extra
     * @return bool
     */
    private function is_plugin_update( array $hook_extra ): bool {
        return isset( $hook_extra['action'], $hook_extra['type'] )
            && $hook_extra['action'] === 'update'
            && $hook_extra['type']   === 'plugin';
    }

    /**
     * Devuelve la lista de plugins involucrados en este evento específico.
     *
     * Prioriza 'plugin' singular sobre 'plugins' plural porque:
     * - En single update: solo existe 'plugin' (string).
     * - En bulk update: existen ambos, pero 'plugin' es el plugin procesado en ESTA
     *   llamada específica de upgrader_process_complete. 'plugins' contiene TODOS
     *   los del bulk, lo que causaría registros duplicados si se usara.
     *
     * @param  array $hook_extra
     * @return string[] Slugs de plugins, ej: ['akismet/akismet.php'].
     */
    private function resolve_plugin_list( array $hook_extra ): array {
        if ( ! empty( $hook_extra['plugin'] ) ) {
            return [ $hook_extra['plugin'] ]; // Single o plugin actual en bulk.
        }
        if ( ! empty( $hook_extra['plugins'] ) ) {
            return (array) $hook_extra['plugins']; // Fallback: bulk sin clave singular.
        }
        return [];
    }
}
