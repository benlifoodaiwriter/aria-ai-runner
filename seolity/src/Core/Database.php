<?php
/**
 * Database access layer: table registry, schema definition and query helpers.
 *
 * All SEOlity data lives in dedicated, indexed tables (never in wp_options,
 * apart from a single settings array). Every table carries a project_id so the
 * same schema can power multi-site agency dashboards and a future SaaS backend.
 *
 * @package SEOlity
 */

namespace SEOlity\Core;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Database {

	const TABLES = array(
		'projects', 'urls', 'audits', 'issues', 'gsc_daily', 'gsc_page_query', 'gsc_breakdown',
		'keywords', 'competitors', 'competitor_pages', 'changes', 'experiments', 'learnings',
		'redirects', 'not_found', 'recommendations', 'content_analysis', 'images', 'internal_links',
		'alerts', 'reports', 'jobs', 'audit_log', 'ai_requests', 'ai_cache', 'rules', 'chat',
		'knowledge', 'content_plan', 'clusters', 'remote_jobs', 'roadmaps', 'activity',
	);

	/** @var \wpdb */
	public $wpdb;

	public function __construct( Plugin $plugin = null ) {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $name Short table name.
	 */
	public function table( $name ) {
		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown table ' . $name );
		}
		return $this->wpdb->prefix . 'seolity_' . $name;
	}

	/** Current project id (multi-project ready; single-site installs use project 1). */
	public function project_id() {
		return (int) apply_filters( 'seolity_current_project_id', 1 );
	}

	public static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Insert a row; arrays/objects are JSON-encoded automatically.
	 *
	 * @param string $table Short table name.
	 * @param array  $data  Column => value.
	 * @return int Insert id (0 on failure).
	 */
	public function insert( $table, array $data ) {
		$ok = $this->wpdb->insert( $this->table( $table ), $this->prepare_row( $data ) );
		return $ok ? (int) $this->wpdb->insert_id : 0;
	}

	public function update( $table, array $data, array $where ) {
		return $this->wpdb->update( $this->table( $table ), $this->prepare_row( $data ), $where );
	}

	public function delete( $table, array $where ) {
		return $this->wpdb->delete( $this->table( $table ), $where );
	}

	/**
	 * Insert or update on unique key (MySQL ON DUPLICATE KEY UPDATE).
	 *
	 * @param string   $table   Short table name.
	 * @param array    $data    Column => value.
	 * @param string[] $update  Columns to overwrite on duplicate.
	 * @param string[] $increment Columns to add on duplicate (counters).
	 */
	public function upsert( $table, array $data, array $update, array $increment = array() ) {
		$data         = $this->prepare_row( $data );
		$columns      = array_keys( $data );
		$placeholders = array();
		$values       = array();
		foreach ( $data as $value ) {
			if ( is_int( $value ) ) {
				$placeholders[] = '%d';
			} elseif ( is_float( $value ) ) {
				$placeholders[] = '%f';
			} elseif ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			} else {
				$placeholders[] = '%s';
			}
			$values[] = $value;
		}
		$sets = array();
		foreach ( $update as $col ) {
			$col    = preg_replace( '/[^a-z0-9_]/', '', $col );
			$sets[] = "`{$col}` = VALUES(`{$col}`)";
		}
		foreach ( $increment as $col ) {
			$col    = preg_replace( '/[^a-z0-9_]/', '', $col );
			$sets[] = "`{$col}` = `{$col}` + VALUES(`{$col}`)";
		}
		$cols = '`' . implode( '`,`', array_map( static function ( $c ) {
			return preg_replace( '/[^a-z0-9_]/', '', $c );
		}, $columns ) ) . '`';
		$sql  = 'INSERT INTO ' . $this->table( $table ) . " ({$cols}) VALUES (" . implode( ',', $placeholders ) . ')';
		if ( $sets ) {
			$sql .= ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $sets );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are whitelisted above.
		return $this->wpdb->query( $values ? $this->wpdb->prepare( $sql, $values ) : $sql );
	}

	/**
	 * Run a prepared SELECT and return rows as associative arrays.
	 *
	 * Use "{table}" tokens (e.g. {urls}) which are replaced with real table names.
	 *
	 * @param string $sql  SQL with %s/%d placeholders.
	 * @param array  $args Values.
	 * @return array[]
	 */
	public function rows( $sql, array $args = array() ) {
		$sql = $this->expand( $sql );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $args ? $this->wpdb->prepare( $sql, $args ) : $sql;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function row( $sql, array $args = array() ) {
		$rows = $this->rows( $sql . ( false === stripos( $sql, ' limit ' ) ? ' LIMIT 1' : '' ), $args );
		return $rows ? $rows[0] : null;
	}

	public function value( $sql, array $args = array() ) {
		$sql = $this->expand( $sql );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_var( $args ? $this->wpdb->prepare( $sql, $args ) : $sql );
	}

	public function column( $sql, array $args = array() ) {
		$sql = $this->expand( $sql );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $this->wpdb->get_col( $args ? $this->wpdb->prepare( $sql, $args ) : $sql );
	}

	public function query( $sql, array $args = array() ) {
		$sql = $this->expand( $sql );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->query( $args ? $this->wpdb->prepare( $sql, $args ) : $sql );
	}

	/** Replace {table} tokens with prefixed table names. */
	public function expand( $sql ) {
		return preg_replace_callback(
			'/\{([a-z_]+)\}/',
			function ( $m ) {
				return in_array( $m[1], self::TABLES, true ) ? $this->table( $m[1] ) : $m[0];
			},
			$sql
		);
	}

	/**
	 * Decode JSON columns in a row.
	 *
	 * @param array|null $row     Row.
	 * @param string[]   $columns JSON columns.
	 */
	public static function decode( $row, array $columns ) {
		if ( ! is_array( $row ) ) {
			return $row;
		}
		foreach ( $columns as $col ) {
			if ( isset( $row[ $col ] ) && is_string( $row[ $col ] ) && '' !== $row[ $col ] ) {
				$decoded     = json_decode( $row[ $col ], true );
				$row[ $col ] = ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $row[ $col ];
			}
		}
		return $row;
	}

	private function prepare_row( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$data[ $key ] = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			} elseif ( is_bool( $value ) ) {
				$data[ $key ] = $value ? 1 : 0;
			}
		}
		return $data;
	}

	/** SHA1 hash used for indexed lookups on long text columns (queries, redirects). */
	public static function hash( $value ) {
		return sha1( (string) $value );
	}

	/** Hash of a URL in canonical form (see PageParser::normalize_url). */
	public static function url_hash( $url ) {
		return sha1( \SEOlity\Crawler\PageParser::normalize_url( (string) $url ) );
	}

	/**
	 * Full schema for dbDelta(). Keep two spaces after PRIMARY KEY (dbDelta quirk).
	 *
	 * @return string[]
	 */
	public function schema() {
		$c = $this->wpdb->get_charset_collate();
		$t = function ( $n ) {
			return $this->table( $n );
		};

		return array(
			"CREATE TABLE {$t('projects')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				domain varchar(190) NOT NULL,
				type varchar(20) NOT NULL DEFAULT 'local',
				settings longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY domain (domain)
			) $c;",
			"CREATE TABLE {$t('urls')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				url text NOT NULL,
				url_hash char(40) NOT NULL,
				object_type varchar(40) NULL,
				object_id bigint(20) unsigned NULL,
				source varchar(20) NOT NULL DEFAULT 'wp',
				status_code smallint(5) NOT NULL DEFAULT 0,
				redirect_to text NULL,
				content_type varchar(100) NULL,
				indexable tinyint(1) NOT NULL DEFAULT 1,
				robots varchar(190) NULL,
				canonical text NULL,
				title text NULL,
				meta_description text NULL,
				h1 text NULL,
				word_count int(10) unsigned NOT NULL DEFAULT 0,
				content_hash char(40) NULL,
				response_ms int(10) unsigned NOT NULL DEFAULT 0,
				page_bytes int(10) unsigned NOT NULL DEFAULT 0,
				depth smallint(5) NOT NULL DEFAULT -1,
				inlinks int(10) unsigned NOT NULL DEFAULT 0,
				outlinks int(10) unsigned NOT NULL DEFAULT 0,
				in_sitemap tinyint(1) NOT NULL DEFAULT 0,
				score tinyint(3) unsigned NULL,
				data longtext NULL,
				crawl_status varchar(20) NOT NULL DEFAULT 'pending',
				last_crawled datetime NULL,
				modified_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY project_url (project_id,url_hash),
				KEY object (object_type,object_id),
				KEY crawl_status (project_id,crawl_status),
				KEY status_code (project_id,status_code)
			) $c;",
			"CREATE TABLE {$t('audits')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				type varchar(20) NOT NULL DEFAULT 'full',
				status varchar(20) NOT NULL DEFAULT 'running',
				urls_total int(10) unsigned NOT NULL DEFAULT 0,
				urls_done int(10) unsigned NOT NULL DEFAULT 0,
				summary longtext NULL,
				started_at datetime NOT NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (id),
				KEY project_status (project_id,status)
			) $c;",
			"CREATE TABLE {$t('issues')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				url_id bigint(20) unsigned NOT NULL DEFAULT 0,
				check_code varchar(60) NOT NULL,
				category varchar(30) NOT NULL,
				severity varchar(10) NOT NULL,
				context longtext NULL,
				status varchar(10) NOT NULL DEFAULT 'open',
				fixable tinyint(1) NOT NULL DEFAULT 0,
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY url_check (project_id,url_id,check_code),
				KEY check_status (project_id,check_code,status),
				KEY severity (project_id,severity,status)
			) $c;",
			"CREATE TABLE {$t('gsc_daily')} (
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				date date NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				impressions int(10) unsigned NOT NULL DEFAULT 0,
				ctr float NOT NULL DEFAULT 0,
				position float NOT NULL DEFAULT 0,
				PRIMARY KEY  (project_id,date)
			) $c;",
			"CREATE TABLE {$t('gsc_page_query')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				date date NOT NULL,
				page_hash char(40) NOT NULL,
				query_hash char(40) NOT NULL,
				page text NOT NULL,
				query varchar(500) NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				impressions int(10) unsigned NOT NULL DEFAULT 0,
				ctr float NOT NULL DEFAULT 0,
				position float NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY row_key (project_id,date,page_hash,query_hash),
				KEY page_date (project_id,page_hash,date),
				KEY query_date (project_id,query_hash,date),
				KEY date (project_id,date)
			) $c;",
			"CREATE TABLE {$t('gsc_breakdown')} (
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				date date NOT NULL,
				dimension varchar(20) NOT NULL,
				dim_value varchar(100) NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				impressions int(10) unsigned NOT NULL DEFAULT 0,
				ctr float NOT NULL DEFAULT 0,
				position float NOT NULL DEFAULT 0,
				PRIMARY KEY  (project_id,date,dimension,dim_value)
			) $c;",
			"CREATE TABLE {$t('keywords')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				keyword varchar(255) NOT NULL,
				keyword_hash char(40) NOT NULL,
				intent varchar(20) NULL,
				kw_type varchar(20) NULL,
				difficulty tinyint(3) unsigned NULL,
				opportunity tinyint(3) unsigned NULL,
				target_url text NULL,
				target_object_type varchar(40) NULL,
				target_object_id bigint(20) unsigned NULL,
				source varchar(20) NOT NULL DEFAULT 'manual',
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tracked tinyint(1) NOT NULL DEFAULT 0,
				data longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY project_kw (project_id,keyword_hash),
				KEY tracked (project_id,tracked)
			) $c;",
			"CREATE TABLE {$t('competitors')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				name varchar(190) NOT NULL,
				domain varchar(190) NOT NULL,
				sitemap_url text NULL,
				frequency varchar(10) NOT NULL DEFAULT 'weekly',
				status varchar(20) NOT NULL DEFAULT 'active',
				summary longtext NULL,
				last_analyzed datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY project (project_id)
			) $c;",
			"CREATE TABLE {$t('competitor_pages')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				competitor_id bigint(20) unsigned NOT NULL,
				url text NOT NULL,
				url_hash char(40) NOT NULL,
				status_code smallint(5) NOT NULL DEFAULT 0,
				title text NULL,
				meta_description text NULL,
				h1 text NULL,
				headings longtext NULL,
				word_count int(10) unsigned NOT NULL DEFAULT 0,
				schema_types varchar(255) NULL,
				internal_links int(10) unsigned NOT NULL DEFAULT 0,
				faq_count smallint(5) unsigned NOT NULL DEFAULT 0,
				topics longtext NULL,
				content_hash char(40) NULL,
				lastmod datetime NULL,
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				crawled_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY comp_url (competitor_id,url_hash),
				KEY crawled (competitor_id,crawled_at)
			) $c;",
			"CREATE TABLE {$t('changes')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				batch_id varchar(40) NULL,
				object_type varchar(40) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				field varchar(60) NOT NULL,
				url text NULL,
				before_value longtext NULL,
				after_value longtext NULL,
				version int(10) unsigned NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'pending',
				source varchar(20) NOT NULL DEFAULT 'ai',
				risk varchar(10) NOT NULL DEFAULT 'medium',
				ai_provider varchar(40) NULL,
				ai_model varchar(100) NULL,
				prompt_version varchar(40) NULL,
				keywords text NULL,
				score_before tinyint(3) unsigned NULL,
				score_after tinyint(3) unsigned NULL,
				gsc_before longtext NULL,
				gsc_after longtext NULL,
				reason text NULL,
				recommendation_id bigint(20) unsigned NULL,
				experiment_id bigint(20) unsigned NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				applied_at datetime NULL,
				reverted_at datetime NULL,
				PRIMARY KEY  (id),
				KEY object_field (object_type,object_id,field),
				KEY status (project_id,status),
				KEY batch (batch_id)
			) $c;",
			"CREATE TABLE {$t('experiments')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				name varchar(255) NOT NULL,
				object_type varchar(40) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				url text NULL,
				page_hash char(40) NULL,
				change_type varchar(60) NOT NULL,
				change_ids text NULL,
				status varchar(20) NOT NULL DEFAULT 'running',
				window_days smallint(5) unsigned NOT NULL DEFAULT 14,
				started_at datetime NOT NULL,
				metrics_before longtext NULL,
				metrics_after longtext NULL,
				outcome varchar(20) NULL,
				confidence tinyint(3) unsigned NULL,
				reason text NULL,
				evaluated_at datetime NULL,
				PRIMARY KEY  (id),
				KEY status (project_id,status),
				KEY page (page_hash)
			) $c;",
			"CREATE TABLE {$t('learnings')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				change_type varchar(60) NOT NULL,
				object_type varchar(40) NOT NULL,
				metric varchar(20) NOT NULL,
				samples int(10) unsigned NOT NULL DEFAULT 0,
				avg_delta float NOT NULL DEFAULT 0,
				positive int(10) unsigned NOT NULL DEFAULT 0,
				negative int(10) unsigned NOT NULL DEFAULT 0,
				neutral int(10) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY learn_key (project_id,change_type,object_type,metric)
			) $c;",
			"CREATE TABLE {$t('redirects')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				source varchar(2000) NOT NULL,
				source_hash char(40) NOT NULL,
				target varchar(2000) NULL,
				type smallint(5) NOT NULL DEFAULT 301,
				is_regex tinyint(1) NOT NULL DEFAULT 0,
				status varchar(10) NOT NULL DEFAULT 'active',
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				last_hit datetime NULL,
				origin varchar(20) NOT NULL DEFAULT 'manual',
				notes text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY source_hash (project_id,source_hash,status),
				KEY regex (project_id,is_regex,status)
			) $c;",
			"CREATE TABLE {$t('not_found')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				url varchar(2000) NOT NULL,
				url_hash char(40) NOT NULL,
				referrer varchar(2000) NULL,
				user_agent varchar(255) NULL,
				is_bot tinyint(1) NOT NULL DEFAULT 0,
				hits bigint(20) unsigned NOT NULL DEFAULT 1,
				suggested_target varchar(2000) NULL,
				suggestion_score tinyint(3) unsigned NULL,
				status varchar(10) NOT NULL DEFAULT 'open',
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY url_hash (project_id,url_hash),
				KEY status_hits (project_id,status,hits)
			) $c;",
			"CREATE TABLE {$t('recommendations')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				dedupe_key char(40) NOT NULL,
				type varchar(60) NOT NULL,
				object_type varchar(40) NULL,
				object_id bigint(20) unsigned NULL,
				url text NULL,
				title text NOT NULL,
				reason text NULL,
				evidence longtext NULL,
				action longtext NULL,
				priority varchar(10) NOT NULL DEFAULT 'medium',
				impact tinyint(3) unsigned NOT NULL DEFAULT 50,
				effort tinyint(3) unsigned NOT NULL DEFAULT 50,
				confidence tinyint(3) unsigned NOT NULL DEFAULT 50,
				score float NOT NULL DEFAULT 0,
				auto_fixable tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'open',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe (project_id,dedupe_key),
				KEY status_score (project_id,status,score)
			) $c;",
			"CREATE TABLE {$t('content_analysis')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				object_type varchar(40) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				focus_keyword varchar(255) NULL,
				score tinyint(3) unsigned NOT NULL DEFAULT 0,
				metrics longtext NULL,
				content_hash char(40) NULL,
				analyzed_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY object (project_id,object_type,object_id),
				KEY score (project_id,score)
			) $c;",
			"CREATE TABLE {$t('images')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				attachment_id bigint(20) unsigned NOT NULL,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				url text NULL,
				filename varchar(255) NULL,
				alt text NULL,
				title text NULL,
				caption text NULL,
				filesize int(10) unsigned NOT NULL DEFAULT 0,
				width int(10) unsigned NOT NULL DEFAULT 0,
				height int(10) unsigned NOT NULL DEFAULT 0,
				issues varchar(255) NULL,
				usage_count int(10) unsigned NOT NULL DEFAULT 0,
				last_checked datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY attachment (project_id,attachment_id),
				KEY issues (project_id,issues(50))
			) $c;",
			"CREATE TABLE {$t('internal_links')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				source_id bigint(20) unsigned NOT NULL,
				target_id bigint(20) unsigned NOT NULL,
				anchor varchar(255) NULL,
				link_type varchar(12) NOT NULL DEFAULT 'existing',
				nofollow tinyint(1) NOT NULL DEFAULT 0,
				relevance tinyint(3) unsigned NULL,
				reason text NULL,
				status varchar(12) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY source (project_id,source_id,link_type),
				KEY target (project_id,target_id,link_type)
			) $c;",
			"CREATE TABLE {$t('alerts')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				type varchar(40) NOT NULL,
				severity varchar(10) NOT NULL DEFAULT 'info',
				title text NOT NULL,
				message text NULL,
				data longtext NULL,
				dedupe_key char(40) NOT NULL,
				status varchar(12) NOT NULL DEFAULT 'new',
				notified tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe (project_id,dedupe_key),
				KEY status (project_id,status,created_at)
			) $c;",
			"CREATE TABLE {$t('reports')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				type varchar(20) NOT NULL,
				title varchar(255) NOT NULL,
				period_start date NOT NULL,
				period_end date NOT NULL,
				data longtext NULL,
				archive_ref varchar(255) NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY type (project_id,type,created_at)
			) $c;",
			"CREATE TABLE {$t('jobs')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hook varchar(100) NOT NULL,
				args longtext NULL,
				unique_key char(40) NULL,
				group_key varchar(60) NULL,
				status varchar(12) NOT NULL DEFAULT 'pending',
				priority tinyint(3) unsigned NOT NULL DEFAULT 10,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				max_attempts tinyint(3) unsigned NOT NULL DEFAULT 3,
				available_at datetime NOT NULL,
				reserved_at datetime NULL,
				finished_at datetime NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY runnable (status,available_at,priority),
				KEY unique_key (unique_key,status),
				KEY group_key (group_key,status)
			) $c;",
			"CREATE TABLE {$t('audit_log')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(60) NOT NULL,
				object_type varchar(40) NULL,
				object_id bigint(20) unsigned NULL,
				details longtext NULL,
				ip_hash char(40) NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY action (project_id,action,created_at),
				KEY created (created_at)
			) $c;",
			"CREATE TABLE {$t('ai_requests')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				provider varchar(40) NOT NULL,
				model varchar(100) NOT NULL,
				task varchar(60) NOT NULL,
				prompt_version varchar(40) NULL,
				tokens_in int(10) unsigned NOT NULL DEFAULT 0,
				tokens_out int(10) unsigned NOT NULL DEFAULT 0,
				cost_usd decimal(10,5) NOT NULL DEFAULT 0,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(12) NOT NULL,
				error text NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY created (created_at),
				KEY task (task,created_at)
			) $c;",
			"CREATE TABLE {$t('ai_cache')} (
				cache_key char(40) NOT NULL,
				task varchar(60) NOT NULL,
				response longtext NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (cache_key),
				KEY expires (expires_at)
			) $c;",
			"CREATE TABLE {$t('rules')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				name varchar(190) NOT NULL,
				trigger_event varchar(60) NOT NULL,
				conditions longtext NULL,
				actions longtext NULL,
				mode varchar(12) NOT NULL DEFAULT 'suggest',
				enabled tinyint(1) NOT NULL DEFAULT 1,
				run_count int(10) unsigned NOT NULL DEFAULT 0,
				last_run datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY trigger_event (project_id,trigger_event,enabled)
			) $c;",
			"CREATE TABLE {$t('chat')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				user_id bigint(20) unsigned NOT NULL,
				conversation_id char(32) NOT NULL,
				role varchar(12) NOT NULL,
				content longtext NOT NULL,
				meta longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation (user_id,conversation_id,id)
			) $c;",
			"CREATE TABLE {$t('knowledge')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(40) NOT NULL DEFAULT 'bundled',
				path varchar(255) NOT NULL,
				sha char(40) NULL,
				title varchar(255) NOT NULL,
				lang varchar(10) NOT NULL DEFAULT 'en',
				topic varchar(60) NULL,
				tags varchar(255) NULL,
				chunk_index smallint(5) unsigned NOT NULL DEFAULT 0,
				content text NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY path_chunk (source,path,chunk_index),
				KEY topic (topic)
			) $c;",
			"CREATE TABLE {$t('content_plan')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				scheduled_date date NOT NULL,
				topic varchar(255) NOT NULL,
				keyword varchar(255) NULL,
				intent varchar(20) NULL,
				title varchar(255) NULL,
				h1 varchar(255) NULL,
				brief longtext NULL,
				priority varchar(10) NOT NULL DEFAULT 'medium',
				status varchar(20) NOT NULL DEFAULT 'planned',
				post_id bigint(20) unsigned NULL,
				cluster_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY schedule (project_id,scheduled_date,status)
			) $c;",
			"CREATE TABLE {$t('clusters')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				name varchar(255) NOT NULL,
				pillar_url text NULL,
				pillar_object_id bigint(20) unsigned NULL,
				members longtext NULL,
				gaps longtext NULL,
				coverage tinyint(3) unsigned NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY project (project_id)
			) $c;",
			"CREATE TABLE {$t('activity')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				kind varchar(20) NOT NULL DEFAULT 'event',
				title varchar(255) NOT NULL DEFAULT '',
				detail text NULL,
				status varchar(20) NOT NULL DEFAULT 'running',
				step varchar(255) NOT NULL DEFAULT '',
				progress tinyint(3) unsigned NOT NULL DEFAULT 0,
				route varchar(20) NOT NULL DEFAULT '',
				ref varchar(80) NOT NULL DEFAULT '',
				meta longtext NULL,
				dock tinyint(1) NOT NULL DEFAULT 0,
				dismissed tinyint(1) NOT NULL DEFAULT 0,
				started_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (id),
				KEY project (project_id,status,id),
				KEY ref (ref),
				KEY dock (user_id,dock,dismissed)
			) $c;",
			"CREATE TABLE {$t('roadmaps')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL DEFAULT 1,
				version int(10) unsigned NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'running',
				stage varchar(40) NOT NULL DEFAULT '',
				summary longtext NULL,
				items longtext NULL,
				changes longtext NULL,
				facts longtext NULL,
				meta longtext NULL,
				error text NULL,
				user_id bigint(20) unsigned NULL,
				started_at datetime NOT NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (id),
				KEY project (project_id,status,id)
			) $c;",
			"CREATE TABLE {$t('remote_jobs')} (
				id char(32) NOT NULL,
				request_hash char(40) NOT NULL,
				engine varchar(20) NOT NULL,
				model varchar(80) NOT NULL DEFAULT '',
				task varchar(60) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'queued',
				payload longtext NOT NULL,
				response longtext NULL,
				error text NULL,
				usage_in int(10) unsigned NOT NULL DEFAULT 0,
				usage_out int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				submitted_at datetime NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (id),
				KEY request (request_hash,created_at),
				KEY status (status,created_at)
			) $c;",
		);
	}
}
