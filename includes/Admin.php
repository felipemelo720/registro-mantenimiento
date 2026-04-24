<?php
// Bloquea acceso directo al archivo fuera de WordPress.
defined( 'ABSPATH' ) || exit;

/**
 * Clase RM_Admin
 *
 * Registra el menú en el panel de WordPress y renderiza:
 * - Formulario para agregar registros manuales de mantenimiento.
 * - Tabla con el historial de actualizaciones (automáticas y manuales).
 */
class RM_Admin {

    /**
     * Engancha el registro del menú al hook admin_menu de WordPress.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    /**
     * Agrega la página "Update Logs" bajo el menú "Herramientas" del panel.
     * Requiere capacidad manage_options (solo administradores).
     */
    public function register_menu() {
        add_management_page(
            __( 'Registro de Mantenimientos', 'registro-mantenimientos' ), // Título pestaña del navegador.
            __( 'Update Logs', 'registro-mantenimientos' ),                // Texto visible en el menú lateral.
            self::required_capability(),                                   // Capacidad requerida (varía en multisite).
            'rm-update-logs',                                              // Slug único de la página (usado en la URL).
            [ $this, 'render_page' ]                                       // Función que imprime el contenido.
        );
    }

    /**
     * Capacidad requerida para ver/usar la página.
     * En multisite usamos manage_network_options (super admin) para que el plugin
     * tenga sentido network-activated. En single site, manage_options.
     *
     * @return string
     */
    private static function required_capability(): string {
        return is_multisite() ? 'manage_network_options' : 'manage_options';
    }

    /**
     * Renderiza la página completa: procesa el formulario y muestra el historial.
     * Todo el output está escapado para prevenir XSS.
     */
    public function render_page() {
        // Doble verificación de capacidad: nunca confiar solo en el registro del menú.
        if ( ! current_user_can( self::required_capability() ) ) {
            return;
        }

        // Procesa el formulario manual antes de mostrar el HTML.
        $notice = $this->handle_manual_form();

        global $wpdb;

        // Guarda: si la tabla no existe (permisos MySQL, mu-plugin, etc.), intentar
        // crearla una vez y si falla mostrar aviso en vez de warning de SQL.
        if ( ! RM_Database::table_exists() ) {
            RM_Database::create_table();
        }

        // Obtiene los últimos 50 registros ordenados del más reciente al más antiguo.
        // Si la tabla sigue sin existir, $rows será array vacío en vez de warning.
        $rows = RM_Database::table_exists()
            ? $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}plugin_update_logs ORDER BY id DESC LIMIT 50"
            )
            : [];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Registro de Mantenimientos', 'registro-mantenimientos' ); ?></h1>

            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                    <p><?php echo esc_html( $notice['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <!-- ================================================================
                 FORMULARIO DE REGISTRO MANUAL
                 Permite al administrador agregar entradas sin necesidad de que
                 WordPress ejecute una actualización automática.
                 ================================================================ -->
            <div class="card" style="max-width:700px; padding:1.5em; margin:1.5em 0;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Agregar Registro Manual', 'registro-mantenimientos' ); ?></h2>

                <form method="post" action="">
                    <?php
                    // Nonce de seguridad: verifica que el POST venga de esta página
                    // y del usuario autenticado. Previene CSRF.
                    wp_nonce_field( 'rm_manual_log', 'rm_nonce' );
                    ?>
                    <input type="hidden" name="rm_action" value="add_manual_log">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="rm_plugin_name">
                                    <?php esc_html_e( 'Nombre del Plugin', 'registro-mantenimientos' ); ?>
                                    <span style="color:red;">*</span>
                                </label>
                            </th>
                            <td>
                                <input type="text"
                                       id="rm_plugin_name"
                                       name="rm_plugin_name"
                                       class="regular-text"
                                       required
                                       placeholder="Ej: WooCommerce">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="rm_plugin_slug">
                                    <?php esc_html_e( 'Slug del Plugin', 'registro-mantenimientos' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text"
                                       id="rm_plugin_slug"
                                       name="rm_plugin_slug"
                                       class="regular-text"
                                       placeholder="Ej: woocommerce/woocommerce.php">
                                <p class="description">
                                    <?php esc_html_e( 'Opcional. Ruta relativa al archivo principal del plugin.', 'registro-mantenimientos' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php esc_html_e( 'Versiones', 'registro-mantenimientos' ); ?>
                            </th>
                            <td>
                                <input type="text"
                                       name="rm_old_version"
                                       class="small-text"
                                       placeholder="<?php esc_attr_e( 'Antes', 'registro-mantenimientos' ); ?>">
                                &nbsp;→&nbsp;
                                <input type="text"
                                       name="rm_new_version"
                                       class="small-text"
                                       placeholder="<?php esc_attr_e( 'Después', 'registro-mantenimientos' ); ?>">
                                <p class="description">
                                    <?php esc_html_e( 'Opcional. Versión anterior y nueva del plugin.', 'registro-mantenimientos' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="rm_notes">
                                    <?php esc_html_e( 'Notas', 'registro-mantenimientos' ); ?>
                                </label>
                            </th>
                            <td>
                                <textarea id="rm_notes"
                                          name="rm_notes"
                                          class="large-text"
                                          rows="3"
                                          placeholder="<?php esc_attr_e( 'Descripción del mantenimiento realizado...', 'registro-mantenimientos' ); ?>"></textarea>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( __( 'Agregar Registro', 'registro-mantenimientos' ) ); ?>
                </form>
            </div>

            <!-- ================================================================
                 TABLA DE HISTORIAL
                 ================================================================ -->
            <h2><?php esc_html_e( 'Historial de Actualizaciones', 'registro-mantenimientos' ); ?></h2>

            <?php if ( empty( $rows ) ) : ?>
                <p><?php esc_html_e( 'No hay registros todavía.', 'registro-mantenimientos' ); ?></p>
            <?php else : ?>
                <table class="widefat fixed striped" style="margin-top:0.5em;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Plugin',   'registro-mantenimientos' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Antes',   'registro-mantenimientos' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Después', 'registro-mantenimientos' ); ?></th>
                            <th><?php esc_html_e( 'Notas',    'registro-mantenimientos' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( 'Usuario', 'registro-mantenimientos' ); ?></th>
                            <th style="width:140px;"><?php esc_html_e( 'Fecha',   'registro-mantenimientos' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) :
                            // Resuelve el nombre de usuario. NULL en auto-updates por cron.
                            $user     = $row->user_id ? get_userdata( (int) $row->user_id ) : false;
                            $username = $user ? esc_html( $user->user_login ) : '—';
                        ?>
                        <tr>
                            <td>
                                <!-- Nombre legible en negrita y slug técnico en pequeño debajo. -->
                                <strong><?php echo esc_html( $row->plugin_name ); ?></strong>
                                <?php if ( ! empty( $row->plugin_slug ) ) : ?>
                                    <br><small style="color:#888;"><?php echo esc_html( $row->plugin_slug ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $row->old_version ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row->new_version ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row->notes ?: '' ); ?></td>
                            <td><?php echo $username; /* Ya escapado arriba con esc_html(). */ ?></td>
                            <td><?php echo esc_html( $row->created_at ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Procesa el POST del formulario de registro manual.
     *
     * Valida el nonce, sanitiza los campos y delega la inserción a RM_Database.
     * Retorna un array con 'type' y 'message' para mostrar como notice en la UI,
     * o null si no hay POST que procesar.
     *
     * @return array|null [ 'type' => 'success'|'error', 'message' => string ] o null.
     */
    private function handle_manual_form() {
        // Solo procesar si el formulario fue enviado con la acción correcta.
        if ( ! isset( $_POST['rm_action'] ) || $_POST['rm_action'] !== 'add_manual_log' ) {
            return null;
        }

        // Verificación de capacidad: el usuario debe ser administrador.
        if ( ! current_user_can( self::required_capability() ) ) {
            return [ 'type' => 'error', 'message' => __( 'No tienes permisos para realizar esta acción.', 'registro-mantenimientos' ) ];
        }

        // Verificación de nonce: previene ataques CSRF.
        // wp_verify_nonce() valida que el token sea válido y no haya expirado (24h por defecto).
        if ( ! isset( $_POST['rm_nonce'] ) || ! wp_verify_nonce( $_POST['rm_nonce'], 'rm_manual_log' ) ) {
            return [ 'type' => 'error', 'message' => __( 'Token de seguridad inválido. Recarga la página e intenta nuevamente.', 'registro-mantenimientos' ) ];
        }

        // Sanitiza cada campo de entrada.
        // sanitize_text_field() elimina tags HTML, caracteres de control y espacios extra.
        $plugin_name = sanitize_text_field( $_POST['rm_plugin_name'] ?? '' );
        $plugin_slug = sanitize_text_field( $_POST['rm_plugin_slug'] ?? '' );
        $old_version = sanitize_text_field( $_POST['rm_old_version'] ?? '' );
        $new_version = sanitize_text_field( $_POST['rm_new_version'] ?? '' );

        // sanitize_textarea_field() hace lo mismo que sanitize_text_field() pero
        // preserva los saltos de línea (necesario para campos textarea).
        $notes = sanitize_textarea_field( $_POST['rm_notes'] ?? '' );

        // Validación mínima: el nombre del plugin es obligatorio.
        if ( empty( $plugin_name ) ) {
            return [ 'type' => 'error', 'message' => __( 'El nombre del plugin es obligatorio.', 'registro-mantenimientos' ) ];
        }

        RM_Database::insert_log( [
            'plugin_slug' => $plugin_slug,
            'plugin_name' => $plugin_name,
            'old_version' => $old_version,
            'new_version' => $new_version,
            'user_id'     => get_current_user_id(),
            'type'        => 'manual', // Marca explícitamente como registro manual.
            'notes'       => $notes ?: null,
        ] );

        return [ 'type' => 'success', 'message' => __( 'Registro agregado correctamente.', 'registro-mantenimientos' ) ];
    }
}
