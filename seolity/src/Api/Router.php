<?php
/**
 * REST API (namespace seolity/v1).
 *
 * Security model:
 *  - Cookie auth + X-WP-Nonce (core) or Application Passwords for headless/SaaS use.
 *  - Every route declares a capability tier (view / ai / apply / manage);
 *    object-level writes additionally check edit_post / edit_term etc.
 *  - All params are sanitized here; services re-validate domain rules.
 *  - Secrets are write-only: no endpoint ever returns them.
 *
 * @package SEOlity
 */

namespace SEOlity\Api;

use SEOlity\Core\Capabilities;
use SEOlity\Core\Settings;
use SEOlity\Plugin;
use SEOlity\Queue\Scheduler;
use WP_REST_Request as Req;

defined( 'ABSPATH' ) || exit;

final class Router {

	const NS = 'seolity/v1';

	const TIERS = array(
		'view'   => Capabilities::VIEW,
		'ai'     => Capabilities::AI,
		'apply'  => Capabilities::APPLY,
		'manage' => Capabilities::MANAGE,
	);

	/** @var Plugin */
	private $p;

	public function __construct( Plugin $plugin ) {
		$this->p = $plugin;
	}

	private function route( $methods, $path, $tier, callable $cb ) {
		$cap = self::TIERS[ $tier ];
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'permission_callback' => static function () use ( $cap ) {
					return current_user_can( $cap );
				},
				'callback'            => function ( Req $r ) use ( $cb ) {
					try {
						$res = $cb( $r );
					} catch ( \SEOlity\AI\Deferred $d ) {
						// Subscription AI is working on it: the admin UI waits and re-sends the same request.
						return new \WP_REST_Response(
							array(
								'code'    => 'seolity_ai_deferred',
								'message' => __( 'Your AI subscription is working on this request on GitHub…', 'seolity' ),
								'data'    => array( 'status' => 202, 'job' => $d->job_id(), 'retry_after' => $d->retry_after() ),
							),
							202
						);
					} catch ( \Throwable $e ) {
						return new \WP_Error( 'seolity_error', defined( 'WP_DEBUG' ) && WP_DEBUG ? $e->getMessage() : __( 'Unexpected error.', 'seolity' ), array( 'status' => 500 ) );
					}
					if ( is_array( $res ) ) {
						foreach ( $res as $value ) {
							if ( is_wp_error( $value ) ) {
								$res = $value; // e.g. array( 'id' => save() ) where save() failed validation.
								break;
							}
						}
					}
					if ( is_wp_error( $res ) ) {
						$data = $res->get_error_data();
						if ( ! is_array( $data ) || empty( $data['status'] ) ) {
							// Service-level errors are expected conditions (not configured, provider refused…), not server faults.
							$res->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => false !== strpos( $res->get_error_code(), 'disconnected' ) ? 409 : 400 ) ) );
						}
						return $res;
					}
					return rest_ensure_response( $res );
				},
			)
		);
	}

	private static function int( Req $r, $key, $default = 0 ) {
		return null === $r->get_param( $key ) ? $default : (int) $r->get_param( $key );
	}

	private static function str( Req $r, $key, $default = '' ) {
		$v = $r->get_param( $key );
		return null === $v ? $default : sanitize_text_field( wp_unslash( (string) $v ) );
	}

	/**
	 * URL parameter. Never use str() for URLs: sanitize_text_field() strips
	 * percent-encoded octets and would mangle non-ASCII (e.g. Persian) URLs.
	 */
	private static function url( Req $r, $key, $default = '' ) {
		$v = $r->get_param( $key );
		return null === $v ? $default : esc_url_raw( trim( wp_unslash( (string) $v ) ) );
	}

	private static function key( Req $r, $key, $default = '' ) {
		$v = $r->get_param( $key );
		return null === $v ? $default : sanitize_key( (string) $v );
	}

	private static function days( Req $r ) {
		return max( 1, min( 480, self::int( $r, 'days', 28 ) ) );
	}

	/** Filters for list endpoints. */
	private static function filters( Req $r, array $keys ) {
		$out = array();
		foreach ( $keys as $k ) {
			if ( null !== $r->get_param( $k ) && '' !== $r->get_param( $k ) ) {
				$out[ $k ] = in_array( $k, array( 'page', 'per_page', 'limit', 'object_id', 'type', 'status_code' ), true ) ? (int) $r->get_param( $k ) : sanitize_text_field( (string) $r->get_param( $k ) );
			}
		}
		return $out;
	}

	public function register_routes() {
		$p = $this->p;

		/* ---------------- Bootstrap / dashboard ---------------- */
		$this->route( 'GET', '/bootstrap', 'view', function () use ( $p ) {
			$user = wp_get_current_user();
			return array(
				'version'   => SEOLITY_VERSION,
				'brand'     => array( 'name' => $p->settings->get( 'brand_name' ), 'logo' => $p->settings->get( 'brand_logo' ), 'color' => $p->settings->get( 'brand_color' ) ),
				'caps'      => array_combine( array_keys( self::TIERS ), array_map( 'current_user_can', array_values( self::TIERS ) ) ),
				'user'      => array( 'id' => $user->ID, 'name' => $user->display_name ),
				'ai_ready'  => $p->ai->is_ready(),
				'ai_label'  => ( $prov = \SEOlity\AI\ProviderRegistry::get( $p, (string) $p->settings->get( 'ai_provider' ) ) ) ? $prov->label() : '',
				'gsc_property' => (string) $p->settings->get( 'gsc_property' ),
				'gsc'       => $p->gsc_oauth->is_connected(),
				'github'    => array( 'token' => $p->github->has_token(), 'login' => is_array( get_transient( 'seolity_gh_whoami' ) ) && ! empty( get_transient( 'seolity_gh_whoami' )['login'] ) ? get_transient( 'seolity_gh_whoami' )['login'] : '' ),
				'cloudflare' => $p->cloudflare->is_deployed(),
				'subscription' => in_array( $p->settings->get( 'ai_provider' ), array( 'sub_claude', 'sub_codex' ), true ),
				'woo'       => class_exists( 'WooCommerce' ),
				'serp'      => $p->serp->available(),
				'engine'    => $p->meta->engine(),
				'mode'      => $p->settings->get( 'approval_mode' ),
				'unread_alerts' => $p->alerts->unread(),
				'pending_changes' => (int) $p->db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'pending'", array( $p->db->project_id() ) ),
				'home'      => home_url( '/' ),
				'admin'     => admin_url(),
			);
		} );
		$this->route( 'GET', '/dashboard', 'view', function () use ( $p ) {
			$db    = $p->db;
			$pid   = $db->project_id();
			$audit = $p->crawler->current_audit();
			$out   = array(
				'health'       => $p->health->cached(),
				'action'       => $p->recommendations->action_center(),
				'audit'        => $audit,
				'indexed'      => array(
					'crawled'   => (int) $db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND crawl_status = 'done'", array( $pid ) ),
					'indexable' => (int) $db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND crawl_status = 'done' AND status_code = 200 AND indexable = 1", array( $pid ) ),
				),
				'errors'       => $db->rows( "SELECT severity, COUNT(*) c FROM {issues} WHERE project_id = %d AND status = 'open' GROUP BY severity", array( $pid ) ),
				'experiments'  => $p->experiments->query( array() )['stats'],
				'ai_actions'   => array(
					'applied_30d' => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'applied' AND applied_at >= %s", array( $pid, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ),
					'pending'     => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'pending'", array( $pid ) ),
				),
				'alerts'       => array_slice( $p->alerts->query( array( 'status' => 'new' ) )['items'], 0, 6 ),
				'competitors'  => array_map( static function ( $c ) {
					return array( 'id' => $c['id'], 'name' => $c['name'], 'domain' => $c['domain'], 'pages' => isset( $c['summary']['pages'] ) ? $c['summary']['pages'] : null, 'new_pages' => isset( $c['summary']['new_pages'] ) ? $c['summary']['new_pages'] : null, 'last' => $c['last_analyzed'] );
				}, $p->competitors->query() ),
				'briefing'     => $p->briefing->latest(),
				'gsc'          => null,
			);
			if ( $p->gsc_oauth->is_connected() ) {
				$out['gsc'] = $p->gsc_analytics->overview( 28 );
			}
			return $out;
		} );
		$this->route( 'GET', '/action-center', 'view', function () use ( $p ) {
			return $p->recommendations->action_center();
		} );
		$this->route( 'GET', '/health', 'view', function ( Req $r ) use ( $p ) {
			return $r->get_param( 'fresh' ) ? $p->health->compute() : $p->health->cached();
		} );
		$this->route( 'GET', '/doctor', 'view', function () use ( $p ) {
			return $p->doctor->diagnose();
		} );

		/* ---------------- Recommendations ---------------- */
		$this->route( 'GET', '/recommendations', 'view', function ( Req $r ) use ( $p ) {
			return $p->recommendations->query( self::filters( $r, array( 'status', 'priority', 'type', 'limit', 'object_type', 'object_id' ) ) );
		} );
		$this->route( 'POST', '/recommendations/refresh', 'ai', function () use ( $p ) {
			$p->queue->push( 'recommendations_refresh', array(), array( 'unique' => true, 'priority' => 1 ) );
			Scheduler::kick();
			return array( 'queued' => true );
		} );
		$this->route( 'GET', '/recommendations/(?P<id>\d+)/why', 'view', function ( Req $r ) use ( $p ) {
			return $p->recommendations->explain( self::int( $r, 'id' ) );
		} );
		$this->route( 'POST', '/recommendations/(?P<id>\d+)/execute', 'ai', function ( Req $r ) use ( $p ) {
			return $p->recommendations->execute( self::int( $r, 'id' ) );
		} );
		$this->route( 'POST', '/recommendations/(?P<id>\d+)/status', 'apply', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->recommendations->mark( self::int( $r, 'id' ), self::key( $r, 'status' ) ) );
		} );

		/* ---------------- Content optimization & changes ---------------- */
		$this->route( 'POST', '/analyze', 'view', function ( Req $r ) use ( $p ) {
			return $p->optimizer->analyze(
				array(
					'object_type'      => self::key( $r, 'object_type', 'post' ),
					'object_id'        => self::int( $r, 'object_id' ),
					'field'            => self::key( $r, 'field' ),
					'content'          => null === $r->get_param( 'content' ) ? null : wp_kses_post( wp_unslash( (string) $r->get_param( 'content' ) ) ),
					'title'            => self::str( $r, 'title' ),
					'focus_keyword'    => self::str( $r, 'focus_keyword' ),
					'meta_title'       => null === $r->get_param( 'meta_title' ) ? null : self::str( $r, 'meta_title' ),
					'meta_description' => null === $r->get_param( 'meta_description' ) ? null : self::str( $r, 'meta_description' ),
				)
			);
		} );
		$this->route( 'POST', '/optimize', 'ai', function ( Req $r ) use ( $p ) {
			$type = self::key( $r, 'object_type', 'post' );
			$id   = self::int( $r, 'object_id' );
			if ( $id && ! Capabilities::can_edit_object( $type, $id ) ) {
				return new \WP_Error( 'seolity_forbidden', __( 'You are not allowed to edit this item.', 'seolity' ), array( 'status' => 403 ) );
			}
			return $p->optimizer->optimize(
				array(
					'object_type'   => $type,
					'object_id'     => $id,
					'field'         => self::key( $r, 'field', 'post_content' ),
					'content'       => null === $r->get_param( 'content' ) ? null : wp_kses_post( wp_unslash( (string) $r->get_param( 'content' ) ) ),
					'title'         => self::str( $r, 'title' ),
					'focus_keyword' => self::str( $r, 'focus_keyword' ),
					'goal'          => self::str( $r, 'goal' ),
				)
			);
		} );
		$this->route( 'POST', '/meta', 'ai', function ( Req $r ) use ( $p ) {
			return $p->optimizer->meta( self::key( $r, 'object_type', 'post' ), self::int( $r, 'object_id' ) );
		} );
		$this->route( 'POST', '/faq', 'ai', function ( Req $r ) use ( $p ) {
			$cid = $p->optimizer->faq( self::int( $r, 'object_id' ) );
			return is_wp_error( $cid ) ? $cid : array( 'change' => $p->changes->get( $cid ) );
		} );
		$this->route( 'POST', '/eeat', 'ai', function ( Req $r ) use ( $p ) {
			$type = self::key( $r, 'object_type', 'post' );
			$id   = self::int( $r, 'object_id' );
			if ( ! Capabilities::can_edit_object( $type, $id ) ) {
				return new \WP_Error( 'seolity_forbidden', __( 'You are not allowed to edit this item.', 'seolity' ), array( 'status' => 403 ) );
			}
			return $p->optimizer->eeat( $type, $id );
		} );
		$this->route( 'GET', '/changes', 'view', function ( Req $r ) use ( $p ) {
			return $p->changes->query( self::filters( $r, array( 'status', 'object_type', 'field', 'source', 'risk', 'batch_id', 'page', 'per_page' ) ) );
		} );
		$this->route( 'GET', '/changes/(?P<id>\d+)', 'view', function ( Req $r ) use ( $p ) {
			$c = $p->changes->get( self::int( $r, 'id' ) );
			return $c ? $c : new \WP_Error( 'seolity_change', __( 'Change not found.', 'seolity' ), array( 'status' => 404 ) );
		} );
		$this->route( 'POST', '/changes/(?P<id>\d+)/apply', 'apply', function ( Req $r ) use ( $p ) {
			$value = $r->get_param( 'value' );
			return $p->changes->apply( self::int( $r, 'id' ), null === $value ? null : wp_unslash( (string) $value ) );
		} );
		$this->route( 'POST', '/changes/(?P<id>\d+)/editor-applied', 'apply', function ( Req $r ) use ( $p ) {
			return $p->changes->mark_applied_in_editor( self::int( $r, 'id' ) );
		} );
		$this->route( 'POST', '/changes/(?P<id>\d+)/reject', 'apply', function ( Req $r ) use ( $p ) {
			return $p->changes->reject( self::int( $r, 'id' ), self::str( $r, 'note' ) );
		} );
		$this->route( 'POST', '/changes/(?P<id>\d+)/revert', 'apply', function ( Req $r ) use ( $p ) {
			return $p->changes->revert( self::int( $r, 'id' ) );
		} );
		$this->route( 'POST', '/changes/(?P<id>\d+)/restore', 'apply', function ( Req $r ) use ( $p ) {
			return $p->changes->restore_version( self::int( $r, 'id' ) );
		} );
		$this->route( 'POST', '/changes/batch/(?P<batch>[a-z0-9_]+)/(?P<action>apply|reject)', 'apply', function ( Req $r ) use ( $p ) {
			$ids = $p->db->column( "SELECT id FROM {changes} WHERE batch_id = %s AND status = 'pending'", array( self::key( $r, 'batch' ) ) );
			$out = array();
			foreach ( $ids as $id ) {
				$res        = 'apply' === $r['action'] ? $p->changes->apply( (int) $id ) : $p->changes->reject( (int) $id );
				$out[ $id ] = is_wp_error( $res ) ? $res->get_error_message() : 'ok';
			}
			return array( 'results' => $out );
		} );
		$this->route( 'GET', '/history', 'view', function ( Req $r ) use ( $p ) {
			return $p->changes->history( self::key( $r, 'object_type', 'post' ), self::int( $r, 'object_id' ), self::key( $r, 'field' ) );
		} );
		$this->route( 'POST', '/bulk', 'ai', function ( Req $r ) use ( $p ) {
			return $p->optimizer->queue_bulk( self::key( $r, 'task' ), array_map( 'intval', (array) $r->get_param( 'ids' ) ), self::key( $r, 'object_type', 'post' ) );
		} );
		$this->route( 'GET', '/bulk/(?P<batch>[a-z0-9_]+)', 'view', function ( Req $r ) use ( $p ) {
			return $p->optimizer->bulk_status( self::key( $r, 'batch' ) );
		} );
		$this->route( 'GET', '/content', 'view', function ( Req $r ) use ( $p ) {
			return ( new ContentList( $p ) )->query( self::filters( $r, array( 'type', 'filter', 'search', 'page', 'per_page', 'post_type', 'taxonomy' ) ) + array( 'kind' => self::key( $r, 'kind', 'post' ) ) );
		} );
		$this->route( 'GET', '/search', 'view', function ( Req $r ) {
			$q   = self::str( $r, 'q' );
			$out = array();
			foreach ( get_posts( array( 'post_type' => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ), 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 10 ) ) as $post ) {
				$out[] = array( 'type' => 'post', 'id' => $post->ID, 'label' => $post->post_title, 'sub' => $post->post_type, 'url' => get_permalink( $post ) );
			}
			foreach ( get_terms( array( 'taxonomy' => get_taxonomies( array( 'public' => true ) ), 'search' => $q, 'number' => 5, 'hide_empty' => false ) ) as $term ) {
				if ( is_object( $term ) ) {
					$out[] = array( 'type' => 'term', 'id' => $term->term_id, 'label' => $term->name, 'sub' => $term->taxonomy, 'url' => get_term_link( $term ) );
				}
			}
			return $out;
		} );

		/* ---------------- Audit ---------------- */
		$this->route( 'GET', '/audit', 'view', function () use ( $p ) {
			return array(
				'audit'    => $p->crawler->current_audit(),
				'history'  => $p->db->rows( 'SELECT id, type, status, urls_total, urls_done, started_at, finished_at FROM {audits} WHERE project_id = %d ORDER BY id DESC LIMIT 10', array( $p->db->project_id() ) ),
				'checks'   => array_map( static function ( $code ) {
					return array( 'code' => $code ) + \SEOlity\Crawler\Checks::meta( $code );
				}, array_keys( \SEOlity\Crawler\Checks::catalog() ) ),
				'counts'   => $p->db->rows( "SELECT check_code, category, severity, COUNT(*) c FROM {issues} WHERE project_id = %d AND status = 'open' GROUP BY check_code, category, severity ORDER BY c DESC", array( $p->db->project_id() ) ),
			);
		} );
		$this->route( 'POST', '/audit/start', 'manage', function ( Req $r ) use ( $p ) {
			$p->queue->push( 'crawl_start', array( 'type' => 'incremental' === self::key( $r, 'type' ) ? 'incremental' : 'full' ), array( 'unique' => true, 'priority' => 1 ) );
			Scheduler::kick();
			return array( 'queued' => true );
		} );
		$this->route( 'GET', '/issues', 'view', function ( Req $r ) use ( $p ) {
			return $p->crawler->issues( self::filters( $r, array( 'status', 'check_code', 'category', 'severity', 'page', 'per_page' ) ) );
		} );
		$this->route( 'POST', '/issues/(?P<id>\d+)/status', 'apply', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->crawler->set_issue_status( self::int( $r, 'id' ), self::key( $r, 'status' ) ) );
		} );
		$this->route( 'GET', '/urls', 'view', function ( Req $r ) use ( $p ) {
			return $p->crawler->urls( self::filters( $r, array( 'search', 'status_code', 'orderby', 'page', 'per_page' ) ) );
		} );
		$this->route( 'GET', '/pagespeed', 'view', function () use ( $p ) {
			return $p->pagespeed->summary();
		} );
		$this->route( 'POST', '/pagespeed/test', 'manage', function ( Req $r ) use ( $p ) {
			$url = self::url( $r, 'url' );
			if ( ! \SEOlity\Crawler\PageParser::same_site( wp_parse_url( $url, PHP_URL_HOST ), wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return new \WP_Error( 'seolity_url', __( 'Only URLs of this site can be tested.', 'seolity' ), array( 'status' => 400 ) );
			}
			return $p->pagespeed->test( $url, 'desktop' === self::key( $r, 'strategy' ) ? 'desktop' : 'mobile' );
		} );

		/* ---------------- Google Search Console ---------------- */
		$this->route( 'GET', '/gsc/status', 'view', function () use ( $p ) {
			return $p->gsc_oauth->status() + array( 'client_id' => $p->settings->get( 'gsc_client_id' ), 'has_secret' => $p->settings->has_secret( 'gsc_client_secret' ) );
		} );
		$this->route( 'POST', '/gsc/credentials', 'manage', function ( Req $r ) use ( $p ) {
			$p->settings->update( array( 'gsc_client_id' => self::str( $r, 'client_id' ) ) );
			if ( '' !== self::str( $r, 'client_secret' ) ) {
				$p->settings->set_secret( 'gsc_client_secret', self::str( $r, 'client_secret' ) );
			}
			$p->audit_log->log( 'gsc.credentials_saved', 'integration' );
			return $p->gsc_oauth->status();
		} );
		$this->route( 'GET', '/gsc/auth-url', 'manage', function () use ( $p ) {
			$url = $p->gsc_oauth->auth_url();
			return is_wp_error( $url ) ? $url : array( 'url' => $url );
		} );
		$this->route( 'GET', '/gsc/sites', 'manage', function () use ( $p ) {
			return $p->gsc_client->sites();
		} );
		$this->route( 'POST', '/gsc/property', 'manage', function ( Req $r ) use ( $p ) {
			$property = self::str( $r, 'property' );
			$sites    = $p->gsc_client->sites();
			if ( is_wp_error( $sites ) ) {
				return $sites;
			}
			if ( ! in_array( $property, wp_list_pluck( $sites, 'site_url' ), true ) ) {
				return new \WP_Error( 'seolity_gsc_property', __( 'This property is not available for the connected account.', 'seolity' ), array( 'status' => 400 ) );
			}
			$changed = $property !== $p->settings->get( 'gsc_property' );
			$p->settings->update( array( 'gsc_property' => $property ) );
			if ( $changed ) {
				$p->db->query( 'DELETE FROM {gsc_page_query} WHERE project_id = %d', array( $p->db->project_id() ) );
				$p->db->query( 'DELETE FROM {gsc_daily} WHERE project_id = %d', array( $p->db->project_id() ) );
				$p->db->query( 'DELETE FROM {gsc_breakdown} WHERE project_id = %d', array( $p->db->project_id() ) );
			}
			$p->gsc_sync->sync( null );
			Scheduler::kick();
			$p->audit_log->log( 'gsc.property_selected', 'integration', null, array( 'property' => $property ) );
			return $p->gsc_oauth->status();
		} );
		$this->route( 'POST', '/gsc/disconnect', 'manage', function () use ( $p ) {
			$p->gsc_oauth->disconnect();
			return $p->gsc_oauth->status();
		} );
		$this->route( 'POST', '/gsc/sync', 'manage', function ( Req $r ) use ( $p ) {
			$res = $p->gsc_sync->sync( $r->get_param( 'days' ) ? self::days( $r ) : null );
			Scheduler::kick();
			return is_wp_error( $res ) ? $res : array( 'queued' => true );
		} );
		$this->route( 'GET', '/gsc/overview', 'view', function ( Req $r ) use ( $p ) {
			return $p->gsc_analytics->overview( self::days( $r ) );
		} );
		$this->route( 'GET', '/gsc/table', 'view', function ( Req $r ) use ( $p ) {
			return $p->gsc_analytics->grouped( 'query' === self::key( $r, 'by' ) ? 'query' : 'page', self::days( $r ), max( 1, min( 1000, self::int( $r, 'limit', 100 ) ) ), self::key( $r, 'order', 'clicks' ), array_filter( array( 'search' => self::str( $r, 'search' ), 'page' => self::url( $r, 'page_url' ), 'query' => self::str( $r, 'query' ) ) ) );
		} );
		$this->route( 'GET', '/gsc/insights', 'view', function ( Req $r ) use ( $p ) {
			$a    = $p->gsc_analytics;
			$days = self::days( $r );
			return array(
				'distribution'           => $a->ranking_distribution( $days ),
				'pages'                  => $a->movers( 'page', $days, 15 ),
				'queries'                => $a->movers( 'query', $days, 15 ),
				'keywords'               => $a->keyword_changes( $days, 30 ),
				'trending'               => $a->trending_pages( $days, 15 ),
				'new_pages'              => $a->new_pages( $days, 15 ),
				'ctr_opportunities'      => $a->ctr_opportunities( $days, 25 ),
				'position_opportunities' => $a->position_opportunities( $days, 25 ),
			);
		} );
		$this->route( 'GET', '/gsc/breakdown', 'view', function ( Req $r ) use ( $p ) {
			$dim = in_array( $r->get_param( 'dimension' ), array( 'country', 'device', 'searchAppearance' ), true ) ? $r->get_param( 'dimension' ) : 'device';
			return $p->gsc_analytics->breakdown( $dim, self::days( $r ) );
		} );
		$this->route( 'GET', '/gsc/page', 'view', function ( Req $r ) use ( $p ) {
			$url = self::url( $r, 'url' );
			return array( 'metrics' => $p->gsc_analytics->page_metrics( $url, self::days( $r ) ), 'series' => $p->gsc_analytics->page_series( $url, max( 28, self::days( $r ) ) ), 'queries' => $p->gsc_analytics->page_queries( $url, self::days( $r ), 50 ) );
		} );
		$this->route( 'POST', '/gsc/inspect', 'manage', function ( Req $r ) use ( $p ) {
			return $p->gsc_client->inspect( self::url( $r, 'url' ) );
		} );

		/* ---------------- Keywords ---------------- */
		$this->route( 'GET', '/keywords', 'view', function ( Req $r ) use ( $p ) {
			return $p->keywords->query( self::filters( $r, array( 'search', 'intent', 'kw_type', 'source', 'tracked', 'unmapped', 'orderby', 'page', 'per_page' ) ) );
		} );
		$this->route( 'POST', '/keywords', 'apply', function ( Req $r ) use ( $p ) {
			return array( 'added' => $p->keywords->add( array_map( 'sanitize_text_field', (array) $r->get_param( 'keywords' ) ) ) );
		} );
		$this->route( 'PATCH', '/keywords/(?P<id>\d+)', 'apply', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->keywords->update( self::int( $r, 'id' ), (array) $r->get_json_params() ) );
		} );
		$this->route( 'DELETE', '/keywords/(?P<id>\d+)', 'apply', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->keywords->delete( self::int( $r, 'id' ) ) );
		} );
		$this->route( 'POST', '/keywords/research', 'ai', function ( Req $r ) use ( $p ) {
			return $p->keywords->research( (array) $r->get_param( 'seeds' ), self::str( $r, 'market' ) );
		} );
		$this->route( 'POST', '/serp/analyze', 'ai', function ( Req $r ) use ( $p ) {
			return $p->serp->analyze( self::str( $r, 'keyword' ), self::key( $r, 'market' ) );
		} );
		$this->route( 'POST', '/keywords/sync', 'manage', function () use ( $p ) {
			$p->queue->push( 'keywords_sync', array(), array( 'unique' => true, 'priority' => 2 ) );
			Scheduler::kick();
			return array( 'queued' => true );
		} );
		$this->route( 'GET', '/keywords/cannibalization', 'view', function ( Req $r ) use ( $p ) {
			return $p->keywords->cannibalization( self::days( $r ), 100 );
		} );
		$this->route( 'POST', '/keywords/cannibalization/advice', 'ai', function ( Req $r ) use ( $p ) {
			return $p->keywords->cannibalization_advice( self::str( $r, 'query' ) );
		} );
		// GSC-driven focus keywords + Rank Math bridge.
		$this->route( 'GET', '/focus/insights', 'view', function ( Req $r ) use ( $p ) {
			return $p->focus->insights( self::key( $r, 'object_type', 'post' ), self::int( $r, 'object_id' ), max( 7, min( 480, self::int( $r, 'days', 90 ) ) ) );
		} );
		$this->route( 'POST', '/focus/apply', 'apply', function ( Req $r ) use ( $p ) {
			return $p->focus->apply(
				self::key( $r, 'object_type', 'post' ),
				self::int( $r, 'object_id' ),
				array_filter( array_map( 'sanitize_text_field', (array) $r->get_param( 'keywords' ) ) ),
				'',
				array( 'meta_title' => self::str( $r, 'meta_title' ), 'meta_description' => self::str( $r, 'meta_description' ) )
			);
		} );
		$this->route( 'POST', '/focus/plan', 'ai', function ( Req $r ) use ( $p ) {
			return $p->focus->ai_plan( self::key( $r, 'object_type', 'post' ), self::int( $r, 'object_id' ) );
		} );
		$this->route( 'POST', '/focus/bulk', 'apply', function ( Req $r ) use ( $p ) {
			return $p->focus->assign_bulk(
				array(
					'only_empty'      => (bool) $r->get_param( 'only_empty' ),
					'post_types'      => array_map( 'sanitize_key', (array) $r->get_param( 'post_types' ) ),
					'limit'           => self::int( $r, 'limit', 200 ),
					'min_impressions' => self::int( $r, 'min_impressions', 30 ),
					'dry_run'         => (bool) $r->get_param( 'dry_run' ),
				)
			);
		} );
		$this->route( 'GET', '/rankmath', 'view', function () use ( $p ) {
			return $p->rankmath->status();
		} );
		$this->route( 'POST', '/rankmath/import-redirects', 'manage', function () use ( $p ) {
			return $p->rankmath->import_redirections();
		} );
		$this->route( 'POST', '/rankmath/sync-pillars', 'apply', function () use ( $p ) {
			return $p->rankmath->sync_pillars();
		} );
		$this->route( 'GET', '/keywords/mapping', 'view', function () use ( $p ) {
			return $p->keywords->mapping();
		} );

		/* ---------------- Products / images / links ---------------- */
		$this->route( 'GET', '/products', 'view', function ( Req $r ) use ( $p ) {
			return $p->products->query( self::filters( $r, array( 'search', 'filter', 'page', 'per_page' ) ) );
		} );
		$this->route( 'POST', '/products/(?P<id>\d+)/optimize', 'ai', function ( Req $r ) use ( $p ) {
			if ( ! current_user_can( 'edit_post', self::int( $r, 'id' ) ) ) {
				return new \WP_Error( 'seolity_forbidden', __( 'You are not allowed to edit this product.', 'seolity' ), array( 'status' => 403 ) );
			}
			$fields = array_values( array_intersect( (array) $r->get_param( 'fields' ), \SEOlity\Woo\ProductSeo::DEFAULT_FIELDS ) );
			return $p->products->optimize( self::int( $r, 'id' ), $fields ? array( 'fields' => $fields ) : array() );
		} );
		$this->route( 'GET', '/images', 'view', function ( Req $r ) use ( $p ) {
			return $p->images->query( self::filters( $r, array( 'issue', 'post_type', 'all', 'page', 'per_page' ) ) );
		} );
		$this->route( 'POST', '/images/scan', 'manage', function () use ( $p ) {
			$p->queue->push( 'images_scan', array( 'offset' => 0 ), array( 'unique' => true, 'priority' => 3 ) );
			Scheduler::kick();
			return array( 'queued' => true );
		} );
		$this->route( 'POST', '/images/generate', 'ai', function ( Req $r ) use ( $p ) {
			$ids = array_map( 'intval', (array) $r->get_param( 'ids' ) );
			if ( count( $ids ) > 20 ) {
				return $p->optimizer->queue_bulk( 'alt', $ids, 'attachment' );
			}
			return $p->images->generate( $ids );
		} );
		$this->route( 'GET', '/links', 'view', function ( Req $r ) use ( $p ) {
			return $p->links->query( self::filters( $r, array( 'status' ) ) );
		} );
		$this->route( 'POST', '/links/build', 'ai', function () use ( $p ) {
			$p->queue->push( 'links_suggest', array(), array( 'unique' => true, 'priority' => 3 ) );
			Scheduler::kick();
			return array( 'queued' => true );
		} );
		$this->route( 'POST', '/links/suggest', 'ai', function ( Req $r ) use ( $p ) {
			return 'to' === self::key( $r, 'direction' ) ? $p->links->suggest_to( self::int( $r, 'post_id' ) ) : $p->links->suggest_for( self::int( $r, 'post_id' ) );
		} );
		$this->route( 'GET', '/links/(?P<id>\d+)/preview', 'view', function ( Req $r ) use ( $p ) {
			return $p->links->preview( self::int( $r, 'id' ) );
		} );
		$this->route( 'POST', '/links/(?P<id>\d+)/apply', 'apply', function ( Req $r ) use ( $p ) {
			return $p->links->apply( self::int( $r, 'id' ) );
		} );
		$this->route( 'POST', '/links/(?P<id>\d+)/dismiss', 'apply', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->links->dismiss( self::int( $r, 'id' ) ) );
		} );

		/* ---------------- Competitors ---------------- */
		$this->route( 'GET', '/competitors', 'view', function () use ( $p ) {
			return $p->competitors->query();
		} );
		$this->route( 'POST', '/competitors', 'manage', function ( Req $r ) use ( $p ) {
			return $p->competitors->create( self::str( $r, 'name' ), self::str( $r, 'domain' ), self::key( $r, 'frequency', 'weekly' ), self::url( $r, 'sitemap' ) );
		} );
		$this->route( 'DELETE', '/competitors/(?P<id>\d+)', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->competitors->delete( self::int( $r, 'id' ) ) );
		} );
		$this->route( 'POST', '/competitors/(?P<id>\d+)/analyze', 'manage', function ( Req $r ) use ( $p ) {
			$p->queue->push( 'competitor_analyze', array( 'id' => self::int( $r, 'id' ) ), array( 'unique' => true, 'priority' => 3 ) );
			Scheduler::kick();
			return array( 'queued' => true );
		} );
		$this->route( 'GET', '/competitors/(?P<id>\d+)/pages', 'view', function ( Req $r ) use ( $p ) {
			return $p->competitors->pages( self::int( $r, 'id' ), self::str( $r, 'search' ) );
		} );
		$this->route( 'POST', '/competitors/(?P<id>\d+)/gap', 'ai', function ( Req $r ) use ( $p ) {
			$gap = $p->competitors->gap( self::int( $r, 'id' ) );
			if ( ! is_wp_error( $gap ) ) {
				$c       = $p->db->row( 'SELECT summary FROM {competitors} WHERE id = %d', array( self::int( $r, 'id' ) ) );
				$summary = (array) json_decode( (string) $c['summary'], true );
				$summary['gap'] = $gap;
				$p->db->update( 'competitors', array( 'summary' => $summary ), array( 'id' => self::int( $r, 'id' ) ) );
			}
			return $gap;
		} );

		/* ---------------- Experiments / learning ---------------- */
		$this->route( 'GET', '/experiments', 'view', function ( Req $r ) use ( $p ) {
			return $p->experiments->query( self::filters( $r, array( 'status', 'outcome' ) ) ) + array( 'insights' => $p->learning->insights() );
		} );
		$this->route( 'POST', '/experiments/(?P<id>\d+)/revert', 'apply', function ( Req $r ) use ( $p ) {
			return $p->experiments->revert( self::int( $r, 'id' ) );
		} );
		$this->route( 'POST', '/experiments/evaluate', 'manage', function () use ( $p ) {
			$p->queue->push( 'experiments_evaluate', array(), array( 'unique' => true, 'priority' => 2 ) );
			Scheduler::kick();
			return array( 'queued' => true );
		} );

		/* ---------------- Redirects / 404 ---------------- */
		$this->route( 'GET', '/redirects', 'view', function ( Req $r ) use ( $p ) {
			return $p->redirects->query( self::filters( $r, array( 'search', 'type', 'page', 'per_page' ) ) );
		} );
		$this->route( 'POST', '/redirects', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'id' => $p->redirects->create( wp_unslash( (string) $r->get_param( 'source' ) ), esc_url_raw( wp_unslash( (string) $r->get_param( 'target' ) ) ), self::int( $r, 'type', 301 ), (bool) $r->get_param( 'is_regex' ), 'manual', self::str( $r, 'notes' ) ) );
		} );
		$this->route( 'PUT', '/redirects/(?P<id>\d+)', 'manage', function ( Req $r ) use ( $p ) {
			$data = array_intersect_key( (array) $r->get_json_params(), array_flip( array( 'source', 'target', 'type', 'is_regex', 'notes', 'status' ) ) );
			return array( 'id' => $p->redirects->update( self::int( $r, 'id' ), $data ) );
		} );
		$this->route( 'DELETE', '/redirects/(?P<id>\d+)', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->redirects->delete( self::int( $r, 'id' ) ) );
		} );
		$this->route( 'POST', '/redirects/import', 'manage', function ( Req $r ) use ( $p ) {
			return $p->redirects->import( wp_unslash( (string) $r->get_param( 'csv' ) ) );
		} );
		$this->route( 'POST', '/redirects/fix-chains', 'manage', function () use ( $p ) {
			return array( 'fixed' => $p->redirects->fix_chains() );
		} );
		$this->route( 'GET', '/404', 'view', function ( Req $r ) use ( $p ) {
			return $p->not_found->query( self::filters( $r, array( 'status', 'search', 'page', 'per_page' ) ) );
		} );
		$this->route( 'POST', '/404/(?P<id>\d+)/redirect', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'id' => $p->not_found->redirect( self::int( $r, 'id' ), self::url( $r, 'target' ), self::int( $r, 'type', 301 ) ) );
		} );
		$this->route( 'POST', '/404/(?P<id>\d+)/status', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->not_found->set_status( self::int( $r, 'id' ), self::key( $r, 'status' ) ) );
		} );
		$this->route( 'POST', '/404/suggest', 'ai', function () use ( $p ) {
			$p->queue->push( 'not_found_suggest', array(), array( 'unique' => true, 'priority' => 2 ) );
			Scheduler::kick();
			return array( 'queued' => true );
		} );

		/* ---------------- Schema / sitemap / robots ---------------- */
		$this->route( 'GET', '/schema', 'view', function () use ( $p ) {
			return $p->schema->coverage();
		} );
		$this->route( 'POST', '/schema/check', 'view', function ( Req $r ) use ( $p ) {
			return $p->schema->check_url( self::url( $r, 'url' ) );
		} );
		$this->route( 'POST', '/schema/suggest', 'ai', function ( Req $r ) use ( $p ) {
			return $p->schema->suggest( self::url( $r, 'url' ) );
		} );
		$this->route( 'GET', '/sitemap', 'view', function ( Req $r ) use ( $p ) {
			$out = $p->sitemap->analyze( (bool) $r->get_param( 'fresh' ) );
			if ( $p->gsc_oauth->is_connected() ) {
				$gsc            = $p->gsc_client->sitemaps();
				$out['gsc']     = is_wp_error( $gsc ) ? array( 'error' => $gsc->get_error_message() ) : ( isset( $gsc['sitemap'] ) ? $gsc['sitemap'] : array() );
			}
			return $out;
		} );
		$this->route( 'POST', '/sitemap/submit', 'manage', function ( Req $r ) use ( $p ) {
			return $p->gsc_client->submit_sitemap( self::url( $r, 'url', home_url( '/wp-sitemap.xml' ) ) );
		} );
		$this->route( 'GET', '/robots', 'view', function () use ( $p ) {
			return $p->robots->analyze();
		} );
		$this->route( 'POST', '/robots', 'manage', function ( Req $r ) use ( $p ) {
			$content = sanitize_textarea_field( wp_unslash( (string) $r->get_param( 'content' ) ) );
			$p->settings->update( array( 'robots_custom' => $content, 'robots_override' => (bool) $r->get_param( 'override' ) ) );
			delete_transient( 'seolity_robots_txt' );
			$p->audit_log->log( 'robots.saved', 'site', null, array( 'override' => (bool) $r->get_param( 'override' ) ) );
			return $p->robots->analyze();
		} );
		$this->route( 'POST', '/robots/test', 'view', function ( Req $r ) use ( $p ) {
			return $p->robots->test( self::url( $r, 'url' ), sanitize_textarea_field( wp_unslash( (string) $r->get_param( 'content' ) ) ) );
		} );

		/* ---------------- Reports / alerts / briefing / chat ---------------- */
		$this->route( 'GET', '/reports', 'view', function () use ( $p ) {
			return $p->reports->query();
		} );
		$this->route( 'POST', '/reports/generate', 'view', function ( Req $r ) use ( $p ) {
			return array( 'id' => $p->reports->generate( self::key( $r, 'type', 'weekly' ) ) );
		} );
		$this->route( 'POST', '/reports/(?P<id>\d+)/email', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'sent' => $p->reports->email( self::int( $r, 'id' ) ) );
		} );
		$this->route( 'GET', '/alerts', 'view', function ( Req $r ) use ( $p ) {
			return $p->alerts->query( self::filters( $r, array( 'status' ) ) );
		} );
		$this->route( 'POST', '/alerts/(?P<id>\d+)', 'view', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->alerts->set_status( self::int( $r, 'id' ), self::key( $r, 'status', 'read' ) ) );
		} );
		$this->route( 'POST', '/alerts/read-all', 'view', function () use ( $p ) {
			return array( 'ok' => (bool) $p->alerts->mark_all_read() );
		} );
		$this->route( 'GET', '/briefing', 'view', function () use ( $p ) {
			return $p->briefing->latest();
		} );
		$this->route( 'POST', '/briefing/generate', 'ai', function () use ( $p ) {
			$p->briefing->generate();
			return $p->briefing->latest();
		} );
		$this->route( 'POST', '/chat', 'ai', function ( Req $r ) use ( $p ) {
			return $p->chat->ask( wp_unslash( (string) $r->get_param( 'message' ) ), self::key( $r, 'conversation_id' ) );
		} );
		$this->route( 'GET', '/chat/conversations', 'ai', function () use ( $p ) {
			return $p->chat->conversations();
		} );
		$this->route( 'GET', '/chat/(?P<cid>[a-f0-9]{32})', 'ai', function ( Req $r ) use ( $p ) {
			return $p->chat->history( self::key( $r, 'cid' ) );
		} );

		/* ---------------- Automation ---------------- */
		$this->route( 'GET', '/rules', 'view', function () use ( $p ) {
			return array( 'rules' => $p->rules->all(), 'presets' => \SEOlity\Rules\RulesEngine::presets(), 'fields' => \SEOlity\Rules\RulesEngine::FIELDS, 'actions' => \SEOlity\Rules\RulesEngine::ACTIONS, 'triggers' => \SEOlity\Rules\RulesEngine::TRIGGERS );
		} );
		$this->route( 'POST', '/rules', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'id' => $p->rules->save( (array) $r->get_json_params() ) );
		} );
		$this->route( 'PUT', '/rules/(?P<id>\d+)', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'id' => $p->rules->save( (array) $r->get_json_params(), self::int( $r, 'id' ) ) );
		} );
		$this->route( 'DELETE', '/rules/(?P<id>\d+)', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->rules->delete( self::int( $r, 'id' ) ) );
		} );
		$this->route( 'GET', '/queue', 'manage', function () use ( $p ) {
			return $p->queue->stats();
		} );
		$this->route( 'POST', '/queue/run', 'manage', function () use ( $p ) {
			return array( 'processed' => $p->queue->work( 20 ) );
		} );
		$this->route( 'POST', '/queue/retry', 'manage', function () use ( $p ) {
			return array( 'retried' => $p->queue->retry_failed() );
		} );
		$this->route( 'GET', '/audit-log', 'manage', function ( Req $r ) use ( $p ) {
			return $p->audit_log->recent( max( 1, min( 200, self::int( $r, 'per_page', 50 ) ) ), max( 0, ( self::int( $r, 'page', 1 ) - 1 ) * 50 ), self::key( $r, 'action' ) );
		} );

		/* ---------------- Planner / clusters ---------------- */
		$this->route( 'GET', '/planner', 'view', function () use ( $p ) {
			return $p->planner->query();
		} );
		$this->route( 'POST', '/planner/generate', 'ai', function ( Req $r ) use ( $p ) {
			return $p->planner->generate( self::key( $r, 'cadence', 'weekly' ), self::int( $r, 'items', 8 ), self::str( $r, 'focus' ) );
		} );
		$this->route( 'PATCH', '/planner/(?P<id>\d+)', 'apply', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->planner->update( self::int( $r, 'id' ), (array) $r->get_json_params() ) );
		} );
		$this->route( 'DELETE', '/planner/(?P<id>\d+)', 'apply', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->planner->delete( self::int( $r, 'id' ) ) );
		} );
		$this->route( 'POST', '/planner/(?P<id>\d+)/draft', 'ai', function ( Req $r ) use ( $p ) {
			return $p->planner->create_draft( self::int( $r, 'id' ) );
		} );
		$this->route( 'GET', '/clusters', 'view', function () use ( $p ) {
			return $p->planner->clusters();
		} );
		$this->route( 'POST', '/clusters/build', 'ai', function () use ( $p ) {
			$p->queue->push( 'clusters_build', array(), array( 'unique' => true, 'priority' => 3 ) );
			Scheduler::kick();
			return array( 'queued' => true );
		} );

		/* ---------------- Settings / AI / knowledge / storage ---------------- */
		$this->route( 'GET', '/settings', 'manage', function () use ( $p ) {
			$schema = array_map( static function ( $d ) {
				return array( 'type' => $d[0], 'default' => $d[1], 'options' => isset( $d[2] ) && 'enum' === $d[0] ? $d[2] : null, 'range' => isset( $d[2] ) && in_array( $d[0], array( 'int', 'float' ), true ) ? $d[2] : null );
			}, Settings::schema() );
			$schema['ai_provider']['options'] = array_keys( \SEOlity\AI\ProviderRegistry::all( $p ) );
			return array(
				'settings' => $p->settings->all(),
				'secrets'  => $p->settings->secret_flags(),
				'schema'   => $schema,
				'providers' => array_map( static function ( $pr ) {
					return array( 'id' => $pr->id(), 'label' => $pr->label(), 'configured' => $pr->is_configured(), 'model' => $pr->model() );
				}, array_values( \SEOlity\AI\ProviderRegistry::all( $p ) ) ),
				'gsc_redirect_uri' => \SEOlity\GSC\OAuth::redirect_uri(),
				'encryption' => defined( 'SEOLITY_ENCRYPTION_KEY' ) ? 'custom_key' : ( function_exists( 'sodium_crypto_secretbox' ) ? 'sodium_salts' : 'openssl_salts' ),
			);
		} );
		$this->route( 'POST', '/settings', 'manage', function ( Req $r ) use ( $p ) {
			$values = (array) $r->get_json_params();
			unset( $values['gsc_property'] ); // Only via /gsc/property (validated against the account).
			$saved = $p->settings->update( $values );
			$p->audit_log->log( 'settings.updated', 'settings', null, array( 'keys' => array_keys( $saved ) ) );
			return array( 'settings' => $p->settings->all(), 'saved' => array_keys( $saved ) );
		} );
		$this->route( 'POST', '/settings/secret', 'manage', function ( Req $r ) use ( $p ) {
			$key = self::key( $r, 'key' );
			if ( ! in_array( $key, Settings::SECRET_FIELDS, true ) || in_array( $key, array( 'gsc_tokens', 'sub_shared_secret' ), true ) ) {
				return new \WP_Error( 'seolity_secret', __( 'Unknown secret.', 'seolity' ), array( 'status' => 400 ) );
			}
			$value = trim( (string) wp_unslash( $r->get_param( 'value' ) ) );
			if ( 'codex_auth_json' === $key && '' !== $value && ! is_array( json_decode( $value, true ) ) ) {
				return new \WP_Error( 'seolity_secret', __( 'auth.json must be valid JSON (copy the whole file from ~/.codex/auth.json).', 'seolity' ), array( 'status' => 400 ) );
			}
			$p->settings->set_secret( $key, $value );
			$p->audit_log->log( 'secret.updated', 'settings', null, array( 'key' => $key, 'cleared' => '' === $value ) );
			$synced = $p->subscription->sync_secret( $key ); // Keep the runner repository's secret in step.
			return array( 'secrets' => $p->settings->secret_flags(), 'runner_synced' => is_wp_error( $synced ) ? $synced->get_error_message() : $synced );
		} );
		$this->route( 'POST', '/settings/test-ai', 'manage', function ( Req $r ) use ( $p ) {
			return $p->ai->test( self::key( $r, 'provider' ) ? self::key( $r, 'provider' ) : null );
		} );
		$this->route( 'GET', '/ai/remote/(?P<id>[a-f0-9]{32})', 'ai', function ( Req $r ) use ( $p ) {
			return $p->subscription->job_status( (string) $r->get_param( 'id' ) );
		} );
		$this->route( 'GET', '/subscription', 'manage', function () use ( $p ) {
			return $p->subscription->status();
		} );
		$this->route( 'POST', '/subscription/setup', 'manage', function () use ( $p ) {
			return $p->subscription->setup();
		} );
		$this->route( 'POST', '/subscription/run', 'manage', function () use ( $p ) {
			$res = $p->subscription->dispatch( true );
			return is_wp_error( $res ) ? $res : array( 'dispatched' => true );
		} );
		$this->route( 'GET', '/subscription/check', 'manage', function ( Req $r ) use ( $p ) {
			return $p->subscription->check( self::key( $r, 'engine', 'claude' ) );
		} );
		$this->route( 'POST', '/subscription/account', 'manage', function ( Req $r ) use ( $p ) {
			$engine = 'codex' === self::key( $r, 'engine' ) ? 'codex' : 'claude';
			$value  = trim( wp_unslash( (string) $r->get_param( 'value' ) ) );
			if ( '' !== $value ) {
				if ( 'codex' === $engine && null === json_decode( $value ) ) {
					return new \WP_Error( 'seolity_sub', __( 'This is not the whole auth.json file. Copy everything from { to }.', 'seolity' ), array( 'status' => 400 ) );
				}
				if ( 'claude' === $engine && ! preg_match( '/^sk-ant-[A-Za-z0-9_-]{20,}$/', $value ) ) {
					return new \WP_Error( 'seolity_sub', __( 'This does not look like a Claude token. It starts with sk-ant-oat01- (output of: claude setup-token).', 'seolity' ), array( 'status' => 400 ) );
				}
				$key = 'codex' === $engine ? 'codex_auth_json' : 'claude_oauth_token';
				$p->settings->set_secret( $key, $value );
				$synced = $p->subscription->sync_secret( $key );
				if ( is_wp_error( $synced ) ) {
					return $synced;
				}
			}
			if ( null !== $r->get_param( 'model' ) ) {
				$p->settings->update( array( 'ai_model_sub_' . $engine => self::str( $r, 'model' ) ) );
			}
			$p->audit_log->log( 'subscription.account', 'site', null, array( 'engine' => $engine ) );
			return $p->subscription->check( $engine );
		} );
		$this->route( 'POST', '/subscription/activate', 'manage', function ( Req $r ) use ( $p ) {
			$p->settings->update( array( 'ai_provider' => 'codex' === self::key( $r, 'engine' ) ? 'sub_codex' : 'sub_claude' ) );
			return array( 'provider' => $p->settings->get( 'ai_provider' ) );
		} );
		$this->route( 'POST', '/subscription/test', 'manage', function ( Req $r ) use ( $p ) {
			return $p->ai->test( 'codex' === self::key( $r, 'engine' ) ? 'sub_codex' : 'sub_claude' );
		} );
		$this->route( 'POST', '/settings/custom-models', 'manage', function () use ( $p ) {
			$custom = \SEOlity\AI\ProviderRegistry::get( $p, 'custom' );
			$models = $custom ? $custom->list_models() : array();
			return is_wp_error( $models ) ? $models : array( 'models' => $models );
		} );
		$this->route( 'GET', '/settings/custom-describe', 'manage', function () use ( $p ) {
			$custom = \SEOlity\AI\ProviderRegistry::get( $p, 'custom' );
			return $custom ? $custom->describe() : array();
		} );

		// GitHub hub: account + one-click repositories.
		$this->route( 'GET', '/github', 'manage', function ( Req $r ) use ( $p ) {
			return $p->githubhub->status( (bool) $r->get_param( 'fresh' ) );
		} );
		$this->route( 'POST', '/github/connect', 'manage', function ( Req $r ) use ( $p ) {
			return $p->githubhub->connect( wp_unslash( (string) $r->get_param( 'token' ) ) );
		} );
		$this->route( 'POST', '/github/disconnect', 'manage', function () use ( $p ) {
			return $p->githubhub->disconnect();
		} );
		$this->route( 'POST', '/github/runner', 'manage', function ( Req $r ) use ( $p ) {
			return $p->githubhub->setup_runner( self::str( $r, 'repo' ) );
		} );
		$this->route( 'POST', '/github/knowledge', 'manage', function ( Req $r ) use ( $p ) {
			return $p->githubhub->setup_knowledge( self::str( $r, 'repo' ), (bool) $r->get_param( 'share_key' ) );
		} );
		$this->route( 'POST', '/github/knowledge/shared', 'manage', function () use ( $p ) {
			return $p->githubhub->use_shared_knowledge();
		} );
		$this->route( 'POST', '/github/knowledge/sync', 'manage', function () use ( $p ) {
			return $p->githubhub->sync_knowledge();
		} );
		$this->route( 'POST', '/github/knowledge/key', 'manage', function () use ( $p ) {
			return $p->githubhub->share_builder_key();
		} );
		$this->route( 'POST', '/github/knowledge/build', 'manage', function () use ( $p ) {
			return $p->githubhub->run_builder();
		} );
		$this->route( 'POST', '/github/storage', 'manage', function ( Req $r ) use ( $p ) {
			return $p->githubhub->setup_storage( self::str( $r, 'repo' ) );
		} );

		// Cloudflare relay.
		$this->route( 'GET', '/cloudflare', 'manage', function () use ( $p ) {
			return $p->cloudflare->status();
		} );
		$this->route( 'GET', '/cloudflare/accounts', 'manage', function () use ( $p ) {
			return $p->cloudflare->accounts();
		} );
		$this->route( 'POST', '/cloudflare/connect', 'manage', function ( Req $r ) use ( $p ) {
			return $p->cloudflare->connect( wp_unslash( (string) $r->get_param( 'token' ) ), self::str( $r, 'account_id' ) );
		} );
		$this->route( 'POST', '/cloudflare/deploy', 'manage', function () use ( $p ) {
			return $p->cloudflare->deploy();
		} );
		$this->route( 'POST', '/cloudflare/remove', 'manage', function () use ( $p ) {
			return $p->cloudflare->remove();
		} );
		$this->route( 'POST', '/cloudflare/disconnect', 'manage', function () use ( $p ) {
			return $p->cloudflare->disconnect();
		} );
		$this->route( 'POST', '/cloudflare/diagnose', 'manage', function () use ( $p ) {
			return $p->cloudflare->diagnose();
		} );
		$this->route( 'POST', '/cloudflare/pull', 'manage', function () use ( $p ) {
			return array( 'stored' => $p->cloudflare->pull() );
		} );
		$this->route( 'GET', '/ai/usage', 'manage', function () use ( $p ) {
			return $p->ai->usage_summary();
		} );
		$this->route( 'GET', '/knowledge', 'view', function () use ( $p ) {
			return $p->knowledge->documents();
		} );
		$this->route( 'GET', '/knowledge/doc', 'view', function ( Req $r ) use ( $p ) {
			return $p->knowledge->document( self::key( $r, 'source' ), sanitize_text_field( (string) $r->get_param( 'path' ) ) );
		} );
		$this->route( 'POST', '/knowledge/sync', 'manage', function () use ( $p ) {
			return $p->knowledge->sync( false );
		} );
		$this->route( 'POST', '/knowledge/note', 'manage', function ( Req $r ) use ( $p ) {
			return $p->knowledge->add_note( self::str( $r, 'title' ), wp_unslash( (string) $r->get_param( 'content' ) ), self::key( $r, 'topic', 'general' ), (bool) $r->get_param( 'push' ) );
		} );
		$this->route( 'DELETE', '/knowledge/note', 'manage', function ( Req $r ) use ( $p ) {
			return array( 'ok' => $p->knowledge->delete_note( sanitize_text_field( (string) $r->get_param( 'path' ) ) ) );
		} );
		$this->route( 'GET', '/storage', 'manage', function () use ( $p ) {
			return $p->archive->status();
		} );
		$this->route( 'POST', '/storage/test', 'manage', function ( Req $r ) use ( $p ) {
			return $p->github->test( self::str( $r, 'repo', (string) $p->settings->get( 'storage_repo' ) ) );
		} );
		$this->route( 'POST', '/storage/run', 'manage', function () use ( $p ) {
			return $p->archive->run();
		} );
		$this->route( 'POST', '/storage/restore', 'manage', function ( Req $r ) use ( $p ) {
			$start = self::str( $r, 'start' );
			$end   = self::str( $r, 'end' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
				return new \WP_Error( 'seolity_date', __( 'Dates must be YYYY-MM-DD.', 'seolity' ), array( 'status' => 400 ) );
			}
			return $p->archive->restore_gsc( $start, $end );
		} );

		// Subscription runner callback: public route, authenticated by an HMAC signature
		// made with the shared secret only SEOlity and the private runner repository know.
		register_rest_route(
			self::NS,
			'/remote/callback',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( Req $r ) use ( $p ) {
					return $p->subscription->handle_callback( $r );
				},
			)
		);
		// Cloudflare relay cron: signed heartbeat that runs the background queue on time.
		register_rest_route(
			self::NS,
			'/remote/heartbeat',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( Req $r ) use ( $p ) {
					return $p->subscription->handle_heartbeat( $r );
				},
			)
		);
		// Reachability probe for diagnostics (reveals nothing but "SEOlity is here").
		register_rest_route(
			self::NS,
			'/remote/ping',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return array( 'ok' => true, 'service' => 'seolity' );
				},
			)
		);
		do_action( 'seolity_rest_routes', $this );
	}
}
