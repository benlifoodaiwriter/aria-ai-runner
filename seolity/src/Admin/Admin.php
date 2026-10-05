<?php
/**
 * Admin shell: menu, SPA bootstrap, secure exports, admin bar badge,
 * dashboard widget and activation redirect.
 *
 * @package SEOlity
 */

namespace SEOlity\Admin;

use SEOlity\Core\Capabilities;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Admin {

	/** Sidebar routes: slug => [label, capability]. */
	public static function routes() {
		return array(
			'dashboard'   => array( __( 'Dashboard', 'seolity' ), Capabilities::VIEW ),
			'actions'     => array( __( 'AI Action Center', 'seolity' ), Capabilities::VIEW ),
			'ai'          => array( __( 'AI SEO Chat', 'seolity' ), Capabilities::AI ),
			'audit'       => array( __( 'Site Audit', 'seolity' ), Capabilities::VIEW ),
			'gsc'         => array( __( 'Search Console', 'seolity' ), Capabilities::VIEW ),
			'keywords'    => array( __( 'Keywords', 'seolity' ), Capabilities::VIEW ),
			'content'     => array( __( 'Content', 'seolity' ), Capabilities::VIEW ),
			'products'    => array( __( 'Products', 'seolity' ), Capabilities::VIEW ),
			'images'      => array( __( 'Images', 'seolity' ), Capabilities::VIEW ),
			'links'       => array( __( 'Internal Links', 'seolity' ), Capabilities::VIEW ),
			'competitors' => array( __( 'Competitors', 'seolity' ), Capabilities::VIEW ),
			'experiments' => array( __( 'Experiments', 'seolity' ), Capabilities::VIEW ),
			'changes'     => array( __( 'AI Changes', 'seolity' ), Capabilities::VIEW ),
			'redirects'   => array( __( 'Redirects', 'seolity' ), Capabilities::VIEW ),
			'404'         => array( __( '404 Monitor', 'seolity' ), Capabilities::VIEW ),
			'schema'      => array( __( 'Schema', 'seolity' ), Capabilities::VIEW ),
			'sitemap'     => array( __( 'Sitemap', 'seolity' ), Capabilities::VIEW ),
			'robots'      => array( __( 'Robots.txt', 'seolity' ), Capabilities::VIEW ),
			'reports'     => array( __( 'Reports', 'seolity' ), Capabilities::VIEW ),
			'alerts'      => array( __( 'Alerts', 'seolity' ), Capabilities::VIEW ),
			'automation'  => array( __( 'Automation', 'seolity' ), Capabilities::MANAGE ),
			'knowledge'   => array( __( 'Knowledge Base', 'seolity' ), Capabilities::VIEW ),
			'settings'    => array( __( 'Settings', 'seolity' ), Capabilities::MANAGE ),
		);
	}

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_action( 'admin_post_seolity_export', array( $this, 'export' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
		add_filter( 'plugin_action_links_' . SEOLITY_BASENAME, array( $this, 'action_links' ) );
	}

	private function brand() {
		$name = (string) $this->plugin->settings->get( 'brand_name' );
		return '' !== $name ? $name : 'SEOlity';
	}

	public function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 1.5a8.5 8.5 0 1 0 5.2 15.2l2.6 2.6 1.4-1.4-2.6-2.6A8.5 8.5 0 0 0 10 1.5Zm0 2a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13Zm.8 2.2-.9 2.8-2.8.9 2.8.9.9 2.8.9-2.8 2.8-.9-2.8-.9-.9-2.8Z"/></svg>' ); // phpcs:ignore
		add_menu_page( $this->brand(), $this->brand(), Capabilities::VIEW, 'seolity', array( $this, 'render' ), $icon, 3 );
		foreach ( self::routes() as $slug => $r ) {
			if ( 'products' === $slug && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}
			add_submenu_page( 'seolity', $r[0], $r[0], $r[1], 'dashboard' === $slug ? 'seolity' : 'seolity-' . $slug, array( $this, 'render' ) );
		}
	}

	private function is_our_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return 'seolity' === $page || 0 === strpos( $page, 'seolity-' );
	}

	public function render() {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'seolity' ), 403 );
		}
		$notice = isset( $_GET['seolity_gsc'] ) ? sanitize_key( wp_unslash( $_GET['seolity_gsc'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap seolity-wrap"><div id="seolity-app" data-gsc-notice="' . esc_attr( $notice ) . '">';
		echo '<div class="sl-boot"><div class="sl-spinner"></div><p>' . esc_html( $this->brand() ) . '</p></div>';
		echo '<noscript>' . esc_html__( 'SEOlity requires JavaScript.', 'seolity' ) . '</noscript></div></div>';
	}

	public function assets( $hook ) {
		$deps = array();
		if ( $this->plugin->settings->get( 'admin_webfont' ) ) {
			// Vazirmatn, the same typeface as the ARIAGH AI Writer admin (optional: Settings → General).
			wp_register_style( 'seolity-font', 'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			$deps[] = 'seolity-font';
		}
		wp_register_style( 'seolity-editor', SEOLITY_URL . 'assets/admin/editor.css', $deps, SEOLITY_VERSION );
		if ( ! $this->is_our_screen() ) {
			return;
		}
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'seolity'; // phpcs:ignore WordPress.Security.NonceVerification
		$route = 'seolity' === $page ? 'dashboard' : substr( $page, 8 );
		wp_enqueue_style( 'seolity-app', SEOLITY_URL . 'assets/admin/app.css', array( 'seolity-editor' ), SEOLITY_VERSION );
		wp_enqueue_script( 'seolity-charts', SEOLITY_URL . 'assets/admin/charts.js', array(), SEOLITY_VERSION, true );
		wp_enqueue_script( 'seolity-ui', SEOLITY_URL . 'assets/admin/ui.js', array( 'seolity-charts' ), SEOLITY_VERSION, true );
		wp_enqueue_script( 'seolity-app', SEOLITY_URL . 'assets/admin/app.js', array( 'seolity-ui' ), SEOLITY_VERSION, true );
		foreach ( array( 'pages-seo', 'pages-intel', 'pages-admin' ) as $module ) {
			wp_enqueue_script( 'seolity-' . $module, SEOLITY_URL . 'assets/admin/' . $module . '.js', array( 'seolity-app' ), SEOLITY_VERSION, true );
		}
		wp_localize_script( 'seolity-ui', 'SEOLITY', $this->js_config( $route ) );
		add_filter( 'admin_body_class', static function ( $c ) {
			return $c . ' seolity-screen';
		} );
	}

	/** Shared JS config (admin app + editor integrations). Never contains secrets. */
	public function js_config( $route = 'dashboard' ) {
		$routes = array();
		foreach ( self::routes() as $slug => $r ) {
			if ( current_user_can( $r[1] ) && ( 'products' !== $slug || class_exists( 'WooCommerce' ) ) ) {
				$routes[] = array( 'slug' => $slug, 'label' => $r[0] );
			}
		}
		return array(
			'root'      => esc_url_raw( rest_url( 'seolity/v1/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'exportUrl' => esc_url_raw( wp_nonce_url( admin_url( 'admin-post.php?action=seolity_export' ), 'seolity_export' ) ),
			'route'     => $route,
			'routes'    => $routes,
			'rtl'       => 'fa' === $this->plugin->settings->get( 'ui_language' ) ? true : ( 'en' === $this->plugin->settings->get( 'ui_language' ) ? false : is_rtl() ),
			'locale'    => 'fa' === $this->plugin->settings->get( 'ui_language' ) ? 'fa_IR' : get_user_locale(),
			'brand'     => array( 'name' => $this->brand(), 'logo' => (string) $this->plugin->settings->get( 'brand_logo' ), 'color' => (string) $this->plugin->settings->get( 'brand_color' ) ),
			'adminUrl'  => admin_url(),
			'homeUrl'   => home_url( '/' ),
			'woo'       => class_exists( 'WooCommerce' ),
			'version'   => SEOLITY_VERSION,
			'i18n'      => I18n::strings(),
		);
	}

	public function activation_redirect() {
		if ( get_transient( 'seolity_activation_redirect' ) && current_user_can( Capabilities::MANAGE ) && ! wp_doing_ajax() && ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_transient( 'seolity_activation_redirect' );
			wp_safe_redirect( admin_url( 'admin.php?page=seolity#/settings' ) );
			exit;
		}
	}

	/** Report / redirect / audit exports (file downloads). */
	public function export() {
		check_admin_referer( 'seolity_export' );
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'Forbidden', 'seolity' ), 403 );
		}
		$what   = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : 'report';
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'html';
		if ( 'redirects' === $what ) {
			$file = array( 'mime' => 'text/csv; charset=utf-8', 'filename' => 'seolity-redirects-' . gmdate( 'Y-m-d' ) . '.csv', 'body' => $this->plugin->redirects->export_csv() );
		} elseif ( 'issues' === $what ) {
			$rows = $this->plugin->crawler->issues( array( 'per_page' => 200, 'page' => 1 ) )['items'];
			$out  = fopen( 'php://temp', 'r+' );
			fwrite( $out, "\xEF\xBB\xBF" );
			fputcsv( $out, array( 'url', 'check', 'severity', 'category', 'first_seen', 'last_seen' ) );
			foreach ( $rows as $r ) {
				fputcsv( $out, array( $r['url'], $r['check_code'], $r['severity'], $r['category'], $r['first_seen'], $r['last_seen'] ) );
			}
			rewind( $out );
			$file = array( 'mime' => 'text/csv; charset=utf-8', 'filename' => 'seolity-issues-' . gmdate( 'Y-m-d' ) . '.csv', 'body' => stream_get_contents( $out ) );
			fclose( $out );
		} else {
			$file = $this->plugin->reports->export( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0, in_array( $format, array( 'csv', 'json', 'html' ), true ) ? $format : 'html' );
		}
		if ( is_wp_error( $file ) ) {
			wp_die( esc_html( $file->get_error_message() ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: ' . $file['mime'] );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'html' !== $format || 'report' !== $what ) {
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file['filename'] ) . '"' );
		}
		echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- generated, escaped document / CSV / JSON.
		exit;
	}

	public function admin_bar( \WP_Admin_Bar $bar ) {
		if ( ! current_user_can( Capabilities::VIEW ) || ! is_admin_bar_showing() ) {
			return;
		}
		$count = get_transient( 'seolity_unread_alerts' );
		if ( false === $count ) {
			$count = $this->plugin->alerts->unread();
			set_transient( 'seolity_unread_alerts', $count, 10 * MINUTE_IN_SECONDS );
		}
		$bar->add_node(
			array(
				'id'    => 'seolity',
				'title' => esc_html( $this->brand() ) . ( $count ? ' <span class="ab-label" style="background:#d63638;border-radius:9px;padding:0 6px;margin-inline-start:4px">' . (int) $count . '</span>' : '' ),
				'href'  => admin_url( 'admin.php?page=seolity' ),
			)
		);
		if ( ! is_admin() && is_singular() && current_user_can( 'edit_post', get_queried_object_id() ) ) {
			$bar->add_node(
				array(
					'parent' => 'seolity',
					'id'     => 'seolity-analyze',
					'title'  => esc_html__( 'Analyze this page', 'seolity' ),
					'href'   => admin_url( 'admin.php?page=seolity-content#/content?analyze=' . get_queried_object_id() ),
				)
			);
		}
	}

	public function dashboard_widget() {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'seolity_widget',
			$this->brand() . ' — ' . __( 'SEO Health', 'seolity' ),
			function () {
				$h   = get_transient( 'seolity_health' );
				$rec = $this->plugin->recommendations->action_center();
				echo '<p style="font-size:28px;margin:6px 0"><strong>' . esc_html( $h && null !== $h['score'] ? $h['score'] . '/100' : '—' ) . '</strong></p>';
				/* translators: 1 critical 2 high */
				echo '<p>' . esc_html( sprintf( __( '%1$d critical and %2$d high-priority actions waiting.', 'seolity' ), $rec['counts']['critical'], $rec['counts']['high'] ) ) . '</p>';
				echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=seolity-actions' ) ) . '">' . esc_html__( 'What should I do today?', 'seolity' ) . '</a></p>';
			}
		);
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=seolity-settings' ) ) . '">' . esc_html__( 'Settings', 'seolity' ) . '</a>' );
		return $links;
	}
}
