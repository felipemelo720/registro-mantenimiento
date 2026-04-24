<?php
/**
 * Plugin Name: Registro de Mantenimientos
 * Plugin URI:  https://hostingsistemas.cl
 * Description: Registra cada actualización de plugins y guarda historial en base de datos.
 * Version:     1.1.0
 * Author:      Hostingsistemas
 * License:     GPL-2.0+
 * Text Domain: registro-mantenimientos
 */

// Bloquea acceso directo al archivo fuera de WordPress.
defined( 'ABSPATH' ) || exit;

// Constantes globales del plugin.
define( 'RM_VERSION', '1.1.0' );
define( 'RM_DIR', plugin_dir_path( __FILE__ ) ); // Ruta absoluta a la carpeta del plugin.

// Carga de clases principales.
require_once RM_DIR . 'includes/Database.php'; // Crea tabla y escribe registros.
require_once RM_DIR . 'includes/Tracker.php';  // Detecta actualizaciones de plugins.
require_once RM_DIR . 'includes/Admin.php';    // Menú y vista en el panel de WordPress.

// ============================================================================
// ACTUALIZACIONES AUTOMÁTICAS DESDE GITHUB
// ============================================================================
// Usa la librería YahnisElsts/plugin-update-checker para que este plugin
// reciba actualizaciones desde el repo GitHub (en vez de wordpress.org).
// Cada sitio que tenga el plugin verá los updates en Plugins > Actualizaciones.
//
// SEGURIDAD: El token de acceso para repos privados se lee desde wp-config.php
// (constante RM_GITHUB_TOKEN) para no quedar versionado en el código.
// En wp-config.php agregar:
//   define( 'RM_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxx' );
// ============================================================================
require_once RM_DIR . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$rm_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/felipemelo720/registro-mantenimiento/',
    __FILE__,
    'registro-mantenimientos'
);

// Usa "Releases" de GitHub como fuente (sube un .zip al crear un release).
$rm_update_checker->getVcsApi()->enableReleaseAssets();

// Para repos privados: autenticación con Personal Access Token.
if ( defined( 'RM_GITHUB_TOKEN' ) && RM_GITHUB_TOKEN ) {
    $rm_update_checker->setAuthentication( RM_GITHUB_TOKEN );
}

// Rama desde la que leer si no hay releases. Cambiar a 'main' si corresponde.
$rm_update_checker->setBranch( 'main' );

// Al activar el plugin, crea la tabla en la base de datos si no existe.
register_activation_hook( __FILE__, [ 'RM_Database', 'create_table' ] );

// Inicializa las clases una vez que todos los plugins están cargados.
add_action( 'plugins_loaded', function () {
    // Ejecuta migraciones de esquema si la versión de BD almacenada difiere de la actual.
    // Costo mínimo: solo compara una opción de la BD, no toca la tabla si no hay cambios.
    RM_Database::maybe_update_db();

    new RM_Tracker(); // Engancha los hooks del actualizador de WordPress.
    new RM_Admin();   // Registra el menú en el panel de administración.
} );
