<?php
/**
 * Robots.txt intelligence (section 24): Google-compatible rule matching,
 * analysis of blocked/important URLs, crawl-waste detection and an editable
 * virtual robots.txt.
 *
 * @package SEOlity
 */

namespace SEOlity\Technical;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class RobotsService {

	/** @var Plugin */
	private $plugin;

	/** @var array|null */
	private $parsed = null;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** robots_txt filter (frontend). */
	public function filter_output( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}
		if ( $this->plugin->settings->get( 'robots_override' ) && '' !== trim( (string) $this->plugin->settings->get( 'robots_custom' ) ) ) {
			return (string) $this->plugin->settings->get( 'robots_custom' ) . "\n";
		}
		if ( 'seolity' === $this->plugin->meta->engine() && false === stripos( $output, 'sitemap:' ) && get_option( 'blog_public' ) ) {
			$output .= "\nSitemap: " . home_url( '/wp-sitemap.xml' ) . "\n";
		}
		return $output;
	}

	/** Live robots.txt as served (cached 1h). */
	public function content( $fresh = false ) {
		$cached = get_transient( 'seolity_robots_txt' );
		if ( false !== $cached && ! $fresh ) {
			return $cached;
		}
		$res  = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10, 'redirection' => 3 ) );
		$body = ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) ? (string) wp_remote_retrieve_body( $res ) : '';
		set_transient( 'seolity_robots_txt', $body, HOUR_IN_SECONDS );
		return $body;
	}

	/** Parse into groups: [ [agents[], rules[[allow(bool), path]]] ], sitemaps, errors. */
	public function parse( $content = null ) {
		if ( null === $content && null !== $this->parsed ) {
			return $this->parsed;
		}
		$text     = null === $content ? $this->content() : $content;
		$groups   = array();
		$sitemaps = array();
		$errors   = array();
		$other    = array();
		$current  = null;
		$last_was_agent = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $i => $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m ) ) {
				$errors[] = array( 'line' => $i + 1, 'text' => $line );
				continue;
			}
			$key = strtolower( $m[1] );
			$val = trim( $m[2] );
			if ( 'user-agent' === $key ) {
				if ( ! $last_was_agent || null === $current ) {
					$groups[] = array( 'agents' => array(), 'rules' => array() );
					$current  = count( $groups ) - 1;
				}
				$groups[ $current ]['agents'][] = strtolower( $val );
				$last_was_agent                  = true;
				continue;
			}
			$last_was_agent = false;
			if ( 'sitemap' === $key ) {
				$sitemaps[] = $val;
			} elseif ( in_array( $key, array( 'allow', 'disallow' ), true ) ) {
				if ( null === $current ) {
					$errors[] = array( 'line' => $i + 1, 'text' => $line, 'reason' => 'rule before user-agent' );
					continue;
				}
				if ( '' !== $val ) {
					$groups[ $current ]['rules'][] = array( 'allow' => 'allow' === $key, 'path' => $val );
				}
			} else {
				$other[] = array( 'line' => $i + 1, 'directive' => $key, 'value' => $val );
			}
		}
		$out = compact( 'groups', 'sitemaps', 'errors', 'other' );
		if ( null === $content ) {
			$this->parsed = $out;
		}
		return $out;
	}

	/** Google's algorithm: most specific UA group, longest matching rule wins, allow wins ties. */
	public function is_allowed( $url, $agent = 'googlebot', $parsed = null ) {
		$parsed = $parsed ? $parsed : $this->parse();
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query  = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$target = ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' );
		$agent  = strtolower( $agent );
		$rules  = null;
		$best   = -1;
		foreach ( $parsed['groups'] as $g ) {
			foreach ( $g['agents'] as $a ) {
				$len = ( '*' === $a ) ? 0 : ( 0 === strpos( $agent, $a ) ? strlen( $a ) : -1 );
				if ( $len > $best ) {
					$best  = $len;
					$rules = $g['rules'];
				}
			}
		}
		if ( null === $rules ) {
			return true;
		}
		$match_len = -1;
		$allowed   = true;
		foreach ( $rules as $r ) {
			if ( $this->matches( $r['path'], $target ) ) {
				$len = strlen( $r['path'] );
				if ( $len > $match_len || ( $len === $match_len && $r['allow'] ) ) {
					$match_len = $len;
					$allowed   = $r['allow'];
				}
			}
		}
		return $allowed;
	}

	private function matches( $pattern, $path ) {
		$regex = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $pattern, '#' ) ) . '#';
		return (bool) preg_match( $regex, $path ) || (bool) preg_match( $regex, rawurldecode( $path ) );
	}

	/** Full analysis for the Robots screen. */
	public function analyze( $content = null ) {
		$text   = null === $content ? $this->content( true ) : $content;
		$parsed = $this->parse( $text );
		$issues = array();
		$sugg   = array();

		if ( '' === trim( $text ) ) {
			$issues[] = array( 'severity' => 'low', 'message' => __( 'robots.txt is empty or missing. That allows all crawling, but you lose the chance to block crawl waste and reference your sitemap.', 'seolity' ) );
		}
		if ( ! get_option( 'blog_public' ) ) {
			$issues[] = array( 'severity' => 'critical', 'message' => __( 'WordPress "Discourage search engines" is ON — the whole site is asking not to be indexed.', 'seolity' ) );
		}
		if ( ! $this->is_allowed( home_url( '/' ), 'googlebot', $parsed ) ) {
			$issues[] = array( 'severity' => 'critical', 'message' => __( 'The homepage is blocked for Googlebot.', 'seolity' ) );
		}
		foreach ( array( '/wp-content/uploads/x.jpg' => __( 'uploads (images)', 'seolity' ), '/wp-includes/js/jquery/jquery.min.js' => __( 'core JavaScript', 'seolity' ), '/wp-content/themes/x/style.css' => __( 'theme CSS', 'seolity' ) ) as $path => $label ) {
			if ( ! $this->is_allowed( home_url( $path ), 'googlebot', $parsed ) ) {
				/* translators: %s resource type */
				$issues[] = array( 'severity' => 'high', 'message' => sprintf( __( 'Blocking %s prevents Google from rendering pages correctly.', 'seolity' ), $label ) );
			}
		}
		if ( ! $this->is_allowed( home_url( '/wp-admin/admin-ajax.php' ), 'googlebot', $parsed ) ) {
			$issues[] = array( 'severity' => 'medium', 'message' => __( 'admin-ajax.php is blocked; some themes load content through it. WordPress default allows it.', 'seolity' ) );
		}
		foreach ( $parsed['other'] as $o ) {
			if ( 'crawl-delay' === $o['directive'] ) {
				$issues[] = array( 'severity' => 'info', 'message' => __( 'Crawl-delay is ignored by Google (manage crawl rate in Search Console instead).', 'seolity' ) );
			} elseif ( ! in_array( $o['directive'], array( 'host', 'clean-param' ), true ) ) {
				/* translators: %s directive */
				$issues[] = array( 'severity' => 'low', 'message' => sprintf( __( 'Unknown directive "%s" will be ignored.', 'seolity' ), $o['directive'] ) );
			}
		}
		foreach ( $parsed['errors'] as $e ) {
			/* translators: 1 line 2 text */
			$issues[] = array( 'severity' => 'medium', 'message' => sprintf( __( 'Line %1$d could not be parsed: %2$s', 'seolity' ), $e['line'], $e['text'] ) );
		}
		if ( ! $parsed['sitemaps'] ) {
			$sugg[] = __( 'Add a Sitemap: line pointing to your XML sitemap.', 'seolity' );
		}

		// Crawl waste candidates (WooCommerce & WordPress patterns).
		$waste = array(
			'/?s='              => __( 'internal search results', 'seolity' ),
			'/*?*add-to-cart='  => __( 'add-to-cart URLs', 'seolity' ),
			'/*?*orderby='      => __( 'sorting parameters', 'seolity' ),
			'/*?*filter_'       => __( 'layered-nav filter parameters', 'seolity' ),
			'/cart/'            => __( 'cart page', 'seolity' ),
			'/checkout/'        => __( 'checkout page', 'seolity' ),
			'/my-account/'      => __( 'account pages', 'seolity' ),
		);
		$sample = array(
			'/?s='             => '/?s=test',
			'/*?*add-to-cart=' => '/shop/?add-to-cart=1',
			'/*?*orderby='     => '/shop/?orderby=price',
			'/*?*filter_'      => '/shop/?filter_color=red',
			'/cart/'           => '/cart/',
			'/checkout/'       => '/checkout/',
			'/my-account/'     => '/my-account/',
		);
		$is_woo = class_exists( 'WooCommerce' );
		foreach ( $waste as $rule => $label ) {
			if ( ! $is_woo && in_array( $rule, array( '/*?*add-to-cart=', '/*?*orderby=', '/*?*filter_', '/cart/', '/checkout/', '/my-account/' ), true ) ) {
				continue;
			}
			if ( $this->is_allowed( home_url( $sample[ $rule ] ), 'googlebot', $parsed ) ) {
				/* translators: 1 label 2 rule */
				$sugg[] = sprintf( __( 'Consider blocking %1$s to save crawl budget: Disallow: %2$s', 'seolity' ), $label, $rule );
			}
		}

		// Important URLs blocked (published content from the crawl table).
		$blocked = array();
		foreach ( $this->plugin->db->rows( "SELECT url FROM {urls} WHERE project_id = %d AND object_id IS NOT NULL AND status_code = 200 ORDER BY inlinks DESC LIMIT 500", array( $this->plugin->db->project_id() ) ) as $r ) {
			if ( ! $this->is_allowed( $r['url'], 'googlebot', $parsed ) ) {
				$blocked[] = $r['url'];
			}
		}
		if ( $blocked ) {
			/* translators: %d count */
			$issues[] = array( 'severity' => 'high', 'message' => sprintf( _n( '%d published page is blocked by robots.txt.', '%d published pages are blocked by robots.txt.', count( $blocked ), 'seolity' ), count( $blocked ) ) );
		}
		return array(
			'content'         => $text,
			'groups'          => $parsed['groups'],
			'sitemaps'        => $parsed['sitemaps'],
			'issues'          => $issues,
			'suggestions'     => $sugg,
			'blocked_important' => array_slice( $blocked, 0, 50 ),
			'override'        => (bool) $this->plugin->settings->get( 'robots_override' ),
			'custom'          => (string) $this->plugin->settings->get( 'robots_custom' ),
			'suggested'       => $this->suggested(),
			'physical_file'   => file_exists( ABSPATH . 'robots.txt' ),
		);
	}

	/** A sane, WooCommerce-aware robots.txt proposal. */
	public function suggested() {
		$lines = array( 'User-agent: *', 'Disallow: /wp-admin/', 'Allow: /wp-admin/admin-ajax.php', 'Disallow: /?s=', 'Disallow: /search/' );
		if ( class_exists( 'WooCommerce' ) ) {
			$lines = array_merge( $lines, array( 'Disallow: /*?*add-to-cart=', 'Disallow: /*?*orderby=', 'Disallow: /cart/', 'Disallow: /checkout/', 'Disallow: /my-account/' ) );
		}
		$lines[] = '';
		$lines[] = 'Sitemap: ' . home_url( '/wp-sitemap.xml' );
		return implode( "\n", $lines );
	}

	/** Test a URL against a draft robots.txt. */
	public function test( $url, $content ) {
		$parsed = $this->parse( $content );
		return array(
			'url'       => $url,
			'googlebot' => $this->is_allowed( $url, 'googlebot', $parsed ),
			'bingbot'   => $this->is_allowed( $url, 'bingbot', $parsed ),
			'any'       => $this->is_allowed( $url, '*', $parsed ),
		);
	}
}
