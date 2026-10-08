<?php
/**
 * Plugin Name: UiCore Updates
 * Plugin URI:  https://github.com/davidzoque/uicore-updates
 * Description: Shows UiCore Pro theme updates (including white-label themes) as regular WordPress updates, so they appear in Dashboard > Updates and in Modular DS. After the theme updates, it installs the bundled UiCore Framework, rebuilds the theme CSS over HTTPS and clears the caches.
 * Version:     1.0.8
 * Author:      Dox Studio
 * Author URI:  https://doxstudio.com
 * License:     GPL-2.0+
 * Update URI:  https://github.com/davidzoque/uicore-updates
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOX_UU_VERSION', '1.0.8' );
define( 'DOX_UU_FILE', __FILE__ );

// ─── Auto-actualizaciones desde GitHub (Plugin Update Checker) ────────────────
// El plugin se actualiza desde las releases del repo público, con el ZIP limpio
// que adjunta el workflow. El filtro del nombre evita que PUC coja otro adjunto
// si algún día la release lleva más de uno.
$dox_uu_puc = __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $dox_uu_puc ) ) {
	require_once $dox_uu_puc;
	// Hide My WP trae su propia copia de esta misma versión del actualizador, pero
	// sin Parsedown. Si la suya se carga antes, leer una release con notas da un
	// error fatal ("Class Parsedown not found"). Cargamos la nuestra por si acaso.
	if ( ! class_exists( 'Parsedown', false ) && file_exists( __DIR__ . '/vendor/plugin-update-checker/vendor/Parsedown.php' ) ) {
		require_once __DIR__ . '/vendor/plugin-update-checker/vendor/Parsedown.php';
	}
	$dox_uu_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/davidzoque/uicore-updates/',
		__FILE__,
		'uicore-updates'
	);
	$dox_uu_update_checker->setBranch( 'main' );
	$dox_uu_update_checker->getVcsApi()->enableReleaseAssets( '/^uicore-updates\.zip$/' );
}

// La versión publicada de UiCore Pro. La API de cada web lleva el nombre de su
// marca blanca (/v1/<nombre-del-tema>) y ahí /updates devuelve
// siempre 1.0.0; la de verdad está en la ruta de UiCore Pro.
define( 'DOX_UU_LATEST_URL', 'https://api.uicore.co/v1/uicore-pro/updates' );

// En la lista de actualizaciones va este marcador en vez del enlace real de
// descarga, que lleva el token de la licencia. Modular DS sincroniza esa lista
// con sus servidores y el token no tiene por qué salir de la web.
define( 'DOX_UU_PACKAGE', 'uicore-updates://theme' );

// Plugins que vienen dentro del tema (inc/plugins/<slug>.zip) y que el botón
// Update de UiCore reinstala después del tema. Element Pack y MetForm Pro solo
// si están activos, igual que hace UiCore.
const DOX_UU_BUNDLED = array(
	'bdthemes-element-pack/bdthemes-element-pack.php' => array( 'slug' => 'bdthemes-element-pack', 'only_if_active' => true ),
	'metform-pro/metform-pro.php'                     => array( 'slug' => 'metform-pro', 'only_if_active' => true ),
	'uicore-framework/plugin.php'                     => array( 'slug' => 'uicore-framework', 'only_if_active' => false ),
);

/**
 * El tema padre si es un tema UiCore conectado a su licencia; si no, null.
 *
 * @return WP_Theme|null
 */
function dox_uu_theme() {
	if ( ! defined( 'UICORE_API' ) || ! class_exists( '\UiCore\Helper' ) ) {
		return null;
	}
	$connect = \UiCore\Helper::handle_connect( 'get' );
	if ( empty( $connect['token'] ) ) {
		return null;
	}
	$theme = wp_get_theme( get_template() );
	return $theme->exists() ? $theme : null;
}

/**
 * Última versión publicada, guardada 6 horas (1 hora si la API falla).
 *
 * @param bool $allow_remote false para no llamar a la API si no está en caché.
 * @return string
 */
function dox_uu_latest_version( $allow_remote = true ) {
	$cached = get_site_transient( 'dox_uu_latest' );
	if ( false !== $cached ) {
		return (string) $cached;
	}
	// En una visita normal no se llama a la API: el filtro de lectura corre en
	// cualquier petición y una API lenta retrasaría la página del visitante.
	if ( ! $allow_remote ) {
		return '';
	}
	$latest   = '';
	$response = wp_remote_get( DOX_UU_LATEST_URL, array( 'timeout' => 10 ) );
	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['last_v'] ) && preg_match( '/^\d+(\.\d+)+$/', $body['last_v'] ) ) {
			$latest = $body['last_v'];
		}
	}
	set_site_transient( 'dox_uu_latest', $latest, $latest ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $latest;
}

/**
 * Añade el tema a la lista de actualizaciones de WordPress cuando hay versión nueva.
 * Va en los dos filtros: al guardar la lista y al leerla. El de lectura cubre que
 * la lista se pierda (Redis compartido que otra web vacía) o que la lea alguien
 * que no la refresca, como Modular DS.
 *
 * @param mixed $transient    Lista de actualizaciones de temas.
 * @param bool  $allow_remote false en el filtro de lectura (sin llamar a la API).
 * @return mixed
 */
function dox_uu_inject_update( $transient, $allow_remote = true ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}
	$theme = dox_uu_theme();
	if ( ! $theme ) {
		return $transient;
	}
	$slug    = $theme->get_stylesheet();
	$latest  = dox_uu_latest_version( $allow_remote );
	$current = $theme->get( 'Version' );

	// Sin versión conocida no se toca la lista: no sabemos si sobra o falta.
	if ( '' === $latest ) {
		return $transient;
	}

	if ( version_compare( $current, $latest, '<' ) ) {
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $slug ] = array(
			'theme'        => $slug,
			'new_version'  => $latest,
			'url'          => admin_url( 'admin.php?page=uicore#/updates' ),
			'package'      => DOX_UU_PACKAGE,
			'requires'     => '',
			'requires_php' => '',
		);
		unset( $transient->no_update[ $slug ] );
	} elseif ( isset( $transient->response[ $slug ] ) && DOX_UU_PACKAGE === ( $transient->response[ $slug ]['package'] ?? '' ) ) {
		unset( $transient->response[ $slug ] );
	}
	return $transient;
}
add_filter(
	'pre_set_site_transient_update_themes',
	function ( $transient ) {
		return dox_uu_inject_update( $transient, true );
	}
);
add_filter(
	'site_transient_update_themes',
	function ( $transient ) {
		return dox_uu_inject_update( $transient, false );
	}
);

/**
 * Cambia el marcador por la descarga real, con el mismo enlace que usa el botón
 * Update de UiCore. El zip ya trae la carpeta con el nombre de la marca blanca.
 *
 * También anota qué tema se actualiza con un paquete de UiCore, para que
 * dox_uu_check_source() deje pasar ese y solo ese.
 *
 * @param bool|string|WP_Error $reply      Respuesta previa.
 * @param string               $package    Paquete pedido.
 * @param WP_Upgrader|null     $upgrader   Actualizador.
 * @param array                $hook_extra Datos de la actualización.
 * @return bool|string|WP_Error
 */
function dox_uu_pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
	$updating = isset( $hook_extra['theme'] ) ? (string) $hook_extra['theme'] : '';

	if ( DOX_UU_PACKAGE !== $package ) {
		// Si algún día UiCore publica sus actualizaciones por la vía normal de
		// WordPress, su paquete vendrá de uicore.co y también vale.
		$host = (string) wp_parse_url( (string) $package, PHP_URL_HOST );
		if ( '' !== $updating && ( 'uicore.co' === $host || '.uicore.co' === substr( $host, -10 ) ) ) {
			dox_uu_served_package( $updating );
		}
		return $reply;
	}
	$theme = dox_uu_theme();
	if ( ! $theme ) {
		return new WP_Error( 'dox_uu_not_connected', __( 'The UiCore theme is not connected to its license.', 'uicore-updates' ) );
	}
	// La ruta se arma con el nombre del tema y no con UICORE_API: esa constante
	// depende del momento en que carga UiCore y, dentro de una petición de Modular
	// DS, valía /v1/uicore-pro, que entrega el tema sin marca blanca (carpeta
	// uicore-pro).
	$connect = \UiCore\Helper::handle_connect( 'get' );
	$url     = add_query_arg(
		array(
			'secret'    => $connect['token'],
			'url'       => $connect['url'],
			'local_url' => home_url(),
		),
		'https://api.uicore.co/v1/' . rawurlencode( dox_uu_api_slug( $theme ) ) . '/main/d'
	);
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$file = download_url( $url, 300 );
	if ( ! is_wp_error( $file ) ) {
		dox_uu_served_package( '' !== $updating ? $updating : get_template() );
	}
	return $file;
}
add_filter( 'upgrader_pre_download', 'dox_uu_pre_download', 10, 4 );

/**
 * Temas de esta petición cuyo paquete viene de UiCore. Lo lee
 * dox_uu_check_source() para no dejar que otro origen pise el tema.
 *
 * @param string $mark  Tema a marcar.
 * @param string $check Tema a consultar.
 * @return bool
 */
function dox_uu_served_package( $mark = '', $check = '' ) {
	static $served = array();
	if ( '' !== $mark ) {
		$served[ $mark ] = true;
	}
	return '' !== $check && ! empty( $served[ $check ] );
}

/**
 * Nombre del tema en la API de UiCore: el de la marca blanca, que coincide
 * con la carpeta del tema.
 *
 * @param WP_Theme $theme Tema padre.
 * @return string
 */
function dox_uu_api_slug( $theme ) {
	$slug = (string) $theme->get( 'TextDomain' );
	if ( '' === $slug ) {
		$slug = $theme->get_stylesheet();
	}
	return preg_replace( '/-child$/', '', $slug );
}

/**
 * Seguro: si el zip no trae la carpeta del tema instalado, se cancela antes de
 * que WordPress borre la carpeta vieja. Al actualizar un tema, WordPress borra
 * siempre la carpeta anterior, aunque el zip venga con otro nombre, y el tema
 * hijo se quedaría sin tema padre.
 *
 * @param string|WP_Error $source        Carpeta descomprimida.
 * @param string          $remote_source Carpeta temporal.
 * @param WP_Upgrader     $upgrader      Actualizador.
 * @param array           $hook_extra    Datos de la actualización.
 * @return string|WP_Error
 */
function dox_uu_check_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	if ( is_wp_error( $source ) || ! ( $upgrader instanceof Theme_Upgrader ) ) {
		return $source;
	}
	$template = get_template();
	if ( empty( $hook_extra['theme'] ) || $template !== $hook_extra['theme'] ) {
		return $source;
	}
	// El tema de UiCore no está en WordPress.org, así que una actualización suya
	// solo puede venir de nuestra descarga. Si el nombre de la carpeta coincide
	// con el de un tema de WordPress.org (pasa con marcas blancas tipo "agency"),
	// el check de temas de WordPress ofrecería ese tema ajeno y lo instalaría
	// encima del tema con licencia. Aquí se corta.
	if ( ! dox_uu_served_package( '', $template ) ) {
		return new WP_Error(
			'dox_uu_foreign_package',
			__( 'This theme update did not come from UiCore, so it was cancelled and the installed theme was not touched. Update the theme from Dashboard > Updates or from the theme panel.', 'uicore-updates' )
		);
	}
	if ( basename( untrailingslashit( $source ) ) !== $template ) {
		return new WP_Error(
			'dox_uu_wrong_package',
			/* translators: 1: folder in the package, 2: installed theme folder */
			sprintf( __( 'The update package contains the folder "%1$s" instead of "%2$s". The update was cancelled and the installed theme was not touched.', 'uicore-updates' ), basename( untrailingslashit( $source ) ), $template )
		);
	}
	return $source;
}
add_filter( 'upgrader_source_selection', 'dox_uu_check_source', 5, 4 );

/**
 * Tras actualizar el tema, deja pendiente la instalación del framework y la lanza
 * por cron. Va en otra petición, como hace el panel de UiCore: en esta todavía
 * está cargado el framework viejo en memoria.
 *
 * @param WP_Upgrader $upgrader   Actualizador.
 * @param array       $hook_extra Datos de la actualización.
 */
function dox_uu_after_upgrade( $upgrader, $hook_extra ) {
	if ( empty( $hook_extra['type'] ) || 'theme' !== $hook_extra['type'] ) {
		return;
	}
	$template = get_template();
	$themes   = isset( $hook_extra['themes'] ) ? (array) $hook_extra['themes'] : array();
	if ( isset( $hook_extra['theme'] ) ) {
		$themes[] = $hook_extra['theme'];
	}
	if ( ! in_array( $template, $themes, true ) ) {
		return;
	}
	update_option( 'dox_uu_pending', time(), false );
	delete_site_transient( 'dox_uu_latest' );
	if ( ! wp_next_scheduled( 'dox_uu_finish' ) ) {
		wp_schedule_single_event( time(), 'dox_uu_finish' );
	}
	spawn_cron();
}
add_action( 'upgrader_process_complete', 'dox_uu_after_upgrade', 20, 2 );

/**
 * Instala los plugins que vienen dentro del tema nuevo (el framework siempre).
 */
function dox_uu_finish() {
	// La opción se pudo crear en otro proceso (la consola no siempre comparte el
	// Redis de la web) y aquí seguiría cacheada como inexistente.
	wp_cache_delete( 'dox_uu_pending', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	if ( ! get_option( 'dox_uu_pending' ) ) {
		return;
	}
	// Que dos peticiones a la vez no instalen lo mismo.
	if ( get_transient( 'dox_uu_lock' ) ) {
		return;
	}
	set_transient( 'dox_uu_lock', 1, 10 * MINUTE_IN_SECONDS );

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$dir = get_template_directory() . '/inc/plugins/';
	$log = array();
	if ( ! is_dir( $dir ) ) {
		$log[] = 'missing ' . $dir;
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		$log[] = 'ZipArchive not available';
	}
	foreach ( DOX_UU_BUNDLED as $path => $plugin ) {
		$zip = $dir . $plugin['slug'] . '.zip';
		if ( ! file_exists( $zip ) || ( $plugin['only_if_active'] && ! is_plugin_active( $path ) ) ) {
			continue;
		}
		// El paquete de rollback 2.4.1 de UiCore traía un uicore-framework.zip
		// roto; mejor no intentarlo que dejarlo a medias.
		$check = class_exists( 'ZipArchive' ) ? new ZipArchive() : null;
		if ( ! $check || true !== $check->open( $zip ) ) {
			$log[] = $plugin['slug'] . ': invalid zip';
			continue;
		}
		$check->close();
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $zip, array( 'overwrite_package' => true ) );
		$log[]    = $plugin['slug'] . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : ( $result ? 'ok' : 'failed' ) );
	}
	wp_clean_plugins_cache();

	delete_option( 'dox_uu_pending' );
	delete_transient( 'dox_uu_lock' );
	update_option( 'dox_uu_regenerate', 1, false );
	update_option( 'dox_uu_last', array( 'time' => time(), 'theme' => wp_get_theme( get_template() )->get( 'Version' ), 'log' => $log ), false );
}
add_action( 'dox_uu_finish', 'dox_uu_finish' );

// Si el cron no llega a correr, lo termina la siguiente visita al panel de un
// administrador. admin_init también corre en admin-post.php sin sesión, así que
// sin la comprobación de permisos cualquiera podría lanzar la instalación.
add_action(
	'admin_init',
	function () {
		if ( get_option( 'dox_uu_pending' ) && ! wp_doing_ajax() && current_user_can( 'update_plugins' ) ) {
			dox_uu_finish();
		}
	}
);

/**
 * Regenera el CSS del tema y vacía las cachés, solo en una petición por HTTPS:
 * fuera de HTTPS UiCore escribe la fuente de iconos con http:// y el navegador
 * la bloquea (los iconos salen como cuadritos). Corre después de la
 * regeneración propia de UiCore tras cambiar de versión.
 *
 * Además, cada 12 horas mira si el CSS tiene http:// de este dominio y, si lo
 * tiene, lo vuelve a generar.
 */
function dox_uu_maybe_regenerate() {
	if ( ! is_ssl() || ! class_exists( '\UiCore\Settings' ) || wp_doing_cron() ) {
		return;
	}
	$pending = get_option( 'dox_uu_regenerate' );
	if ( ! $pending && false === get_transient( 'dox_uu_css_checked' ) ) {
		set_transient( 'dox_uu_css_checked', 1, 12 * HOUR_IN_SECONDS );
		$css = wp_upload_dir()['basedir'] . '/uicore-global.css';
		$pending = file_exists( $css ) && false !== strpos( (string) file_get_contents( $css ), 'http://' . wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
	if ( ! $pending ) {
		return;
	}
	delete_option( 'dox_uu_regenerate' );
	\UiCore\Settings::clear_cache( true );
	dox_uu_cleanup();
}
add_action( 'wp_loaded', 'dox_uu_maybe_regenerate', 99 );

/**
 * Borra el zip que deja el botón Update de UiCore (queda público en uploads) y
 * vacía las cachés de página y de CSS.
 */
function dox_uu_cleanup() {
	$zip = wp_upload_dir()['basedir'] . '/uicore-theme-update.zip';
	if ( file_exists( $zip ) ) {
		wp_delete_file( $zip );
	}
	if ( class_exists( 'wps_ic_cache_integrations' ) && method_exists( 'wps_ic_cache_integrations', 'purgeAll' ) ) {
		wps_ic_cache_integrations::purgeAll( false, true, false, true, true );
	}
	do_action( 'litespeed_purge_all' );
}

// El zip también puede quedar si alguien actualiza desde el panel de UiCore.
add_action(
	'upgrader_process_complete',
	function () {
		$zip = wp_upload_dir()['basedir'] . '/uicore-theme-update.zip';
		if ( file_exists( $zip ) ) {
			wp_delete_file( $zip );
		}
	},
	30
);

/**
 * Estado en la fila del plugin: versión publicada y última actualización.
 */
add_filter(
	'plugin_row_meta',
	function ( $meta, $file ) {
		if ( plugin_basename( DOX_UU_FILE ) !== $file ) {
			return $meta;
		}
		$theme = dox_uu_theme();
		if ( ! $theme ) {
			$meta[] = esc_html__( 'No UiCore theme connected to its license on this site.', 'uicore-updates' );
			return $meta;
		}
		$latest = dox_uu_latest_version();
		/* translators: 1: theme name, 2: installed version, 3: published version */
		$meta[] = esc_html( sprintf( __( '%1$s %2$s installed, %3$s published', 'uicore-updates' ), $theme->get( 'Name' ), $theme->get( 'Version' ), $latest ? $latest : '?' ) );
		$last = get_option( 'dox_uu_last' );
		if ( ! empty( $last['time'] ) ) {
			/* translators: %s: date */
			$meta[] = esc_html( sprintf( __( 'Last update: %s', 'uicore-updates' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] ) ) );
		}
		return $meta;
	},
	10,
	2
);

// ─── Plantillas de UiCore fuera de Google ─────────────────────────────────────
// Las plantillas del Theme Builder (cabeceras, pies, popups) y el kit de marca
// son tipos de post públicos, así que salen en el mapa del sitio y se pueden abrir
// sueltas. Se quedan abribles (el editor de Elementor las necesita), pero fuera
// del mapa y con noindex.
const DOX_UU_TEMPLATE_TYPES = array( 'uicore-tb', 'uicore-cd' );

// Mapa del sitio de WordPress.
add_filter(
	'wp_sitemaps_post_types',
	function ( $post_types ) {
		return array_diff_key( $post_types, array_flip( DOX_UU_TEMPLATE_TYPES ) );
	}
);

// Mapas de Yoast SEO y Rank Math.
$dox_uu_exclude_type = function ( $exclude, $post_type ) {
	return in_array( $post_type, DOX_UU_TEMPLATE_TYPES, true ) ? true : $exclude;
};
add_filter( 'wpseo_sitemap_exclude_post_type', $dox_uu_exclude_type, 10, 2 );
add_filter( 'rank_math/sitemap/exclude_post_type', $dox_uu_exclude_type, 10, 2 );

// noindex. Yoast junta su valor con este y deja el noindex; Rank Math quita los
// filtros de wp_robots, así que lleva el suyo.
add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( is_singular( DOX_UU_TEMPLATE_TYPES ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}
);
add_filter(
	'rank_math/frontend/robots',
	function ( $robots ) {
		if ( is_singular( DOX_UU_TEMPLATE_TYPES ) ) {
			$robots['index'] = 'noindex';
		}
		return $robots;
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'dox_uu_finish' );
		delete_site_transient( 'dox_uu_latest' );
		delete_transient( 'dox_uu_css_checked' );
	}
);
