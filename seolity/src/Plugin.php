<?php
/**
 * Plugin kernel: a tiny lazy service container + module bootstrap.
 *
 * Every subsystem is registered as a lazily-instantiated service so a normal
 * front-end page load only constructs the handful of services it really needs
 * (head output, redirects, 404 logging). Everything heavy runs in the queue.
 *
 * @package SEOlity
 */

namespace SEOlity;

defined( 'ABSPATH' ) || exit;

/**
 * @property-read Core\Settings              $settings
 * @property-read Core\Crypto                $crypto
 * @property-read Core\Database              $db
 * @property-read Core\AuditLog              $audit_log
 * @property-read Queue\Queue                $queue
 * @property-read AI\Engine                  $ai
 * @property-read Analysis\ContentAnalyzer   $analyzer
 * @property-read Content\MetaStore          $meta
 * @property-read Content\ChangeManager      $changes
 * @property-read Content\Optimizer          $optimizer
 * @property-read GSC\OAuth                  $gsc_oauth
 * @property-read GSC\Client                 $gsc_client
 * @property-read GSC\Sync                   $gsc_sync
 * @property-read GSC\Analytics              $gsc_analytics
 * @property-read Crawler\Crawler            $crawler
 * @property-read Doctor\Doctor              $doctor
 * @property-read Recommendations\Engine     $recommendations
 * @property-read Redirects\Manager          $redirects
 * @property-read Redirects\NotFoundMonitor  $not_found
 * @property-read Schema\SchemaService       $schema
 * @property-read Images\ImageSeo            $images
 * @property-read Links\InternalLinker       $links
 * @property-read Keywords\KeywordService    $keywords
 * @property-read Keywords\FocusKeywords        $focus
 * @property-read Integrations\RankMath         $rankmath
 * @property-read Integrations\Cloudflare       $cloudflare
 * @property-read Storage\GitHubHub             $githubhub
 * @property-read Competitors\CompetitorService $competitors
 * @property-read Experiments\ExperimentService $experiments
 * @property-read Experiments\Learning       $learning
 * @property-read Health\HealthScore         $health
 * @property-read Alerts\AlertService        $alerts
 * @property-read Briefing\DailyBriefing     $briefing
 * @property-read Chat\ChatService           $chat
 * @property-read Rules\RulesEngine          $rules
 * @property-read Reports\ReportService      $reports
 * @property-read Planner\ContentPlanner     $planner
 * @property-read Knowledge\KnowledgeBase    $knowledge
 * @property-read Storage\GitHubClient       $github
 * @property-read Storage\GitHubArchive      $archive
 * @property-read Woo\ProductSeo             $products
 * @property-read Technical\SitemapService   $sitemap
 * @property-read Technical\RobotsService    $robots
 * @property-read Technical\PageSpeed        $pagespeed
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var array<string, callable> */
	private $factories = array();

	/** @var array<string, object> */
	private $services = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_services();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register a service factory.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Receives the plugin, returns the service.
	 */
	public function set( $id, callable $factory ) {
		$this->factories[ $id ] = $factory;
		unset( $this->services[ $id ] );
	}

	/**
	 * @param string $id Service id.
	 * @return object
	 */
	public function get( $id ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unknown SEOlity service "%s".', $id ) );
			}
			$this->services[ $id ] = call_user_func( $this->factories[ $id ], $this );
		}
		return $this->services[ $id ];
	}

	public function __get( $id ) {
		return $this->get( $id );
	}

	private function register_services() {
		$map = array(
			'settings'        => Core\Settings::class,
			'crypto'          => Core\Crypto::class,
			'db'              => Core\Database::class,
			'audit_log'       => Core\AuditLog::class,
			'queue'           => Queue\Queue::class,
			'ai'              => AI\Engine::class,
			'analyzer'        => Analysis\ContentAnalyzer::class,
			'meta'            => Content\MetaStore::class,
			'changes'         => Content\ChangeManager::class,
			'optimizer'       => Content\Optimizer::class,
			'gsc_oauth'       => GSC\OAuth::class,
			'gsc_client'      => GSC\Client::class,
			'gsc_sync'        => GSC\Sync::class,
			'gsc_analytics'   => GSC\Analytics::class,
			'crawler'         => Crawler\Crawler::class,
			'doctor'          => Doctor\Doctor::class,
			'recommendations' => Recommendations\Engine::class,
			'redirects'       => Redirects\Manager::class,
			'not_found'       => Redirects\NotFoundMonitor::class,
			'schema'          => Schema\SchemaService::class,
			'images'          => Images\ImageSeo::class,
			'links'           => Links\InternalLinker::class,
			'keywords'        => Keywords\KeywordService::class,
			'focus'           => Keywords\FocusKeywords::class,
			'rankmath'        => Integrations\RankMath::class,
			'cloudflare'      => Integrations\Cloudflare::class,
			'githubhub'       => Storage\GitHubHub::class,
			'competitors'     => Competitors\CompetitorService::class,
			'experiments'     => Experiments\ExperimentService::class,
			'learning'        => Experiments\Learning::class,
			'health'          => Health\HealthScore::class,
			'alerts'          => Alerts\AlertService::class,
			'briefing'        => Briefing\DailyBriefing::class,
			'chat'            => Chat\ChatService::class,
			'rules'           => Rules\RulesEngine::class,
			'reports'         => Reports\ReportService::class,
			'planner'         => Planner\ContentPlanner::class,
			'knowledge'       => Knowledge\KnowledgeBase::class,
			'github'          => Storage\GitHubClient::class,
			'archive'         => Storage\GitHubArchive::class,
			'products'        => Woo\ProductSeo::class,
			'sitemap'         => Technical\SitemapService::class,
			'robots'          => Technical\RobotsService::class,
			'pagespeed'       => Technical\PageSpeed::class,
			'serp'            => Serp\SerpService::class,
			'subscription'    => AI\Remote\SubscriptionRunner::class,
		);
		foreach ( $map as $id => $class ) {
			$this->set(
				$id,
				static function ( Plugin $plugin ) use ( $class ) {
					return new $class( $plugin );
				}
			);
		}

		/**
		 * Allow extensions (Pro add-ons, custom AI providers…) to register or replace services.
		 *
		 * @param Plugin $plugin
		 */
		do_action( 'seolity_register_services', $this );
	}

	private function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( Core\Installer::class, 'maybe_upgrade' ), 2 );

		Core\Capabilities::register_hooks();
		Queue\Scheduler::register_hooks( $this );
		Frontend\Frontend::register_hooks( $this );

		add_action( 'rest_api_init', array( new Api\Router( $this ), 'register_routes' ) );

		// Google OAuth callback lands on wp-admin/admin-post.php (no "action" → `admin_post`).
		add_action(
			'admin_post',
			function () {
				$this->gsc_oauth->handle_callback();
			}
		);

		if ( is_admin() ) {
			( new Admin\Admin( $this ) )->register_hooks();
			( new Admin\EditorIntegration( $this ) )->register_hooks();
		}

		// Content lifecycle hooks used by change tracking, rules and incremental crawling.
		( new Content\ContentEvents( $this ) )->register_hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'seolity', new Cli\Command( $this ) );
		}

		do_action( 'seolity_loaded', $this );
	}

	public function load_textdomain() {
		$forced = $this->settings->get( 'ui_language' );
		if ( 'fa' === $forced ) {
			// Persian UI regardless of the site/user locale.
			load_textdomain( 'seolity', SEOLITY_DIR . 'languages/seolity-fa_IR.mo' );
			return;
		}
		if ( 'en' === $forced ) {
			return; // English source strings.
		}
		load_plugin_textdomain( 'seolity', false, dirname( SEOLITY_BASENAME ) . '/languages' );
	}
}
