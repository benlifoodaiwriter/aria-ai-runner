<?php
/**
 * Internal Linking AI (section 17).
 *
 * Candidate generation is local and cheap (TF-IDF-like term overlap between
 * crawled pages, preferring targets with few inlinks and real GSC queries);
 * AI then picks anchors that already exist verbatim in the source text so a
 * link can be inserted without rewriting. Applying a link is a tracked,
 * revertible post_content change.
 *
 * @package SEOlity
 */

namespace SEOlity\Links;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class InternalLinker {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Pages eligible as link sources/targets. */
	private function pages( $limit = 2000 ) {
		return $this->plugin->db->rows(
			"SELECT id, url, object_id, title, h1, inlinks, data FROM {urls} WHERE project_id = %d AND object_type = 'post' AND status_code = 200 AND indexable = 1 AND crawl_status = 'done' ORDER BY inlinks ASC LIMIT %d",
			array( $this->plugin->db->project_id(), (int) $limit )
		);
	}

	/** Term vector per page (title ×3 + h1 ×2 + h2 + top GSC queries). */
	private function vector( array $row ) {
		$d    = json_decode( (string) $row['data'], true );
		$text = str_repeat( $row['title'] . ' ', 3 ) . str_repeat( (string) $row['h1'] . ' ', 2 ) . implode( ' ', isset( $d['h2'] ) ? (array) $d['h2'] : array() );
		return Text::term_frequencies( $text );
	}

	/** Weekly: build suggestions for orphan / weakly linked pages. */
	public function build_suggestions() {
		$pages = $this->pages( 1500 );
		if ( count( $pages ) < 3 ) {
			return true;
		}
		$vectors = array();
		$df      = array();
		foreach ( $pages as $p ) {
			$vectors[ $p['id'] ] = $this->vector( $p );
			foreach ( array_keys( $vectors[ $p['id'] ] ) as $t ) {
				$df[ $t ] = isset( $df[ $t ] ) ? $df[ $t ] + 1 : 1;
			}
		}
		$n       = count( $pages );
		$targets = array_slice( array_filter( $pages, static function ( $p ) {
			return (int) $p['inlinks'] <= 3;
		} ), 0, 60 );
		$existing = array();
		foreach ( $this->plugin->db->rows( "SELECT source_id, target_id FROM {internal_links} WHERE project_id = %d", array( $this->plugin->db->project_id() ) ) as $e ) {
			$existing[ $e['source_id'] . '-' . $e['target_id'] ] = true;
		}
		$created = 0;
		foreach ( $targets as $t ) {
			$scores = array();
			foreach ( $pages as $s ) {
				if ( $s['id'] === $t['id'] || isset( $existing[ $s['id'] . '-' . $t['id'] ] ) ) {
					continue;
				}
				$score = 0;
				foreach ( $vectors[ $t['id'] ] as $term => $c ) {
					if ( isset( $vectors[ $s['id'] ][ $term ] ) ) {
						$score += min( $c, $vectors[ $s['id'] ][ $term ] ) * log( $n / $df[ $term ] );
					}
				}
				if ( $score > 0 ) {
					$scores[ $s['id'] ] = $score;
				}
			}
			arsort( $scores );
			foreach ( array_slice( $scores, 0, 3, true ) as $sid => $score ) {
				$anchor = $this->find_anchor( (int) $this->row_object( $sid ), $t );
				if ( ! $anchor ) {
					continue;
				}
				$this->plugin->db->insert(
					'internal_links',
					array(
						'project_id' => $this->plugin->db->project_id(),
						'source_id'  => (int) $sid,
						'target_id'  => (int) $t['id'],
						'anchor'     => $anchor,
						'link_type'  => 'suggested',
						'relevance'  => (int) min( 95, 40 + $score * 4 ),
						/* translators: %s title */
						'reason'     => sprintf( __( 'Topically related page that already mentions "%s"; the target has few internal links.', 'seolity' ), $anchor ),
						'status'     => 'pending',
						'created_at' => Database::now(),
					)
				);
				$existing[ $sid . '-' . $t['id'] ] = true;
				$created++;
			}
		}
		return $created;
	}

	private function row_object( $url_id ) {
		return $this->plugin->db->value( 'SELECT object_id FROM {urls} WHERE id = %d', array( (int) $url_id ) );
	}

	/** Find a phrase from the target's title/queries that exists in the source content (not already linked). */
	private function find_anchor( $source_post_id, array $target ) {
		$post = get_post( $source_post_id );
		if ( ! $post ) {
			return null;
		}
		$plain = Text::normalize( Text::strip( preg_replace( '#<a\s[^>]*>.*?</a>#is', ' ', $post->post_content ) ) );
		$phrases = array();
		if ( $this->plugin->gsc_oauth->is_connected() ) {
			foreach ( $this->plugin->gsc_analytics->page_queries( $target['url'], 90, 10 ) as $q ) {
				$phrases[] = $q['query'];
			}
		}
		$phrases[] = $target['title'];
		foreach ( array_keys( Text::top_ngrams( $target['title'] . ' ' . $target['h1'], 6, 3 ) ) as $g ) {
			$phrases[] = $g;
		}
		foreach ( $phrases as $ph ) {
			$ph = trim( $ph );
			if ( count( Text::words( $ph ) ) >= 2 && Text::contains( $plain, $ph ) ) {
				return mb_substr( $ph, 0, 100 );
			}
		}
		return null;
	}

	/** Suggestions where $post_id is the SOURCE (AI-refined). Used by bulk + editor. */
	public function suggest_for( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'seolity_object', __( 'Object not found.', 'seolity' ) );
		}
		$source_url = get_permalink( $post );
		$source_id  = (int) $this->plugin->db->value( 'SELECT id FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->plugin->db->project_id(), Database::url_hash( $source_url ) ) );
		$vec        = Text::term_frequencies( $post->post_title . ' ' . Text::strip( $post->post_content ) );
		$cands      = array();
		foreach ( $this->pages( 1500 ) as $p ) {
			if ( (int) $p['object_id'] === (int) $post_id ) {
				continue;
			}
			$score = 0;
			foreach ( $this->vector( $p ) as $term => $c ) {
				if ( isset( $vec[ $term ] ) ) {
					$score += min( $c, $vec[ $term ] );
				}
			}
			if ( $score > 1 ) {
				$cands[] = array( 'target_id' => (int) $p['id'], 'title' => $p['title'], 'url' => $p['url'], 'inlinks' => (int) $p['inlinks'], 'score' => $score );
			}
		}
		usort( $cands, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		$cands = array_slice( $cands, 0, 15 );
		if ( ! $cands ) {
			return array( 'suggestions' => array() );
		}
		$res = $this->plugin->ai->run(
			'internal_links',
			array(
				'source_title' => $post->post_title,
				'source_text'  => Text::truncate( Text::strip( preg_replace( '#<a\s[^>]*>.*?</a>#is', ' ', $post->post_content ) ), 6000 ),
				'candidates'   => $cands,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$plain = Text::normalize( Text::strip( $post->post_content ) );
		$out   = array();
		$valid = wp_list_pluck( $cands, 'target_id' );
		foreach ( $res['data']['suggestions'] as $s ) {
			if ( ! in_array( (int) $s['target_id'], $valid, true ) || ! Text::contains( $plain, $s['anchor'] ) || ! $source_id ) {
				continue; // Anchor must exist verbatim; target must be a real candidate.
			}
			$id    = $this->plugin->db->insert(
				'internal_links',
				array(
					'project_id' => $this->plugin->db->project_id(),
					'source_id'  => $source_id,
					'target_id'  => (int) $s['target_id'],
					'anchor'     => mb_substr( $s['anchor'], 0, 250 ),
					'link_type'  => 'suggested',
					'relevance'  => max( 0, min( 100, (int) $s['relevance'] ) ),
					'reason'     => $s['reason'],
					'status'     => 'pending',
					'created_at' => Database::now(),
				)
			);
			$out[] = array_merge( $s, array( 'id' => $id ) );
		}
		return array( 'suggestions' => $out, 'meta' => $res['meta'] );
	}

	/** Suggestions where $post_id is the TARGET (for orphan pages). */
	public function suggest_to( $post_id ) {
		$url = get_permalink( (int) $post_id );
		$row = $this->plugin->db->row( 'SELECT id, url, title, h1, inlinks, data FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->plugin->db->project_id(), Database::url_hash( (string) $url ) ) );
		if ( ! $row ) {
			return new \WP_Error( 'seolity_links', __( 'Run a site audit first so SEOlity knows your link graph.', 'seolity' ), array( 'status' => 400 ) );
		}
		$created = array();
		$tvec    = $this->vector( $row );
		$scores  = array();
		foreach ( $this->pages( 1500 ) as $s ) {
			if ( $s['id'] === $row['id'] ) {
				continue;
			}
			$score = 0;
			$v     = $this->vector( $s );
			foreach ( $tvec as $term => $c ) {
				if ( isset( $v[ $term ] ) ) {
					$score += min( $c, $v[ $term ] );
				}
			}
			if ( $score > 0 ) {
				$scores[ $s['id'] ] = array( $score, (int) $s['object_id'] );
			}
		}
		uasort( $scores, static function ( $a, $b ) {
			return $b[0] <=> $a[0];
		} );
		foreach ( array_slice( $scores, 0, 10, true ) as $sid => $sc ) {
			$anchor = $this->find_anchor( $sc[1], $row );
			if ( ! $anchor ) {
				continue;
			}
			$id        = $this->plugin->db->insert(
				'internal_links',
				array(
					'project_id' => $this->plugin->db->project_id(),
					'source_id'  => (int) $sid,
					'target_id'  => (int) $row['id'],
					'anchor'     => $anchor,
					'link_type'  => 'suggested',
					'relevance'  => (int) min( 95, 40 + $sc[0] * 5 ),
					'reason'     => __( 'Related page that mentions the target topic.', 'seolity' ),
					'status'     => 'pending',
					'created_at' => Database::now(),
				)
			);
			$created[] = $this->preview( $id );
			if ( count( $created ) >= 5 ) {
				break;
			}
		}
		return array( 'suggestions' => $created );
	}

	public function query( array $f ) {
		$db   = $this->plugin->db;
		$rows = $db->rows(
			"SELECT l.*, s.url source_url, s.object_id source_post, s.title source_title, t.url target_url, t.title target_title, t.inlinks target_inlinks FROM {internal_links} l JOIN {urls} s ON s.id = l.source_id JOIN {urls} t ON t.id = l.target_id WHERE l.project_id = %d AND l.link_type = 'suggested' AND l.status = %s ORDER BY l.relevance DESC LIMIT 200",
			array( $db->project_id(), ! empty( $f['status'] ) ? $f['status'] : 'pending' )
		);
		return array(
			'items' => $rows,
			'stats' => array(
				'orphans'   => (int) $db->value( "SELECT COUNT(*) FROM {issues} WHERE project_id = %d AND status = 'open' AND check_code = 'orphan_page'", array( $db->project_id() ) ),
				'avg_inlinks' => round( (float) $db->value( "SELECT AVG(inlinks) FROM {urls} WHERE project_id = %d AND status_code = 200 AND crawl_status = 'done'", array( $db->project_id() ) ), 1 ),
				'edges'     => (int) $db->value( "SELECT COUNT(*) FROM {internal_links} WHERE project_id = %d AND link_type = 'existing'", array( $db->project_id() ) ),
				'top_linked' => $db->rows( "SELECT url, title, inlinks FROM {urls} WHERE project_id = %d AND crawl_status = 'done' ORDER BY inlinks DESC LIMIT 10", array( $db->project_id() ) ),
			),
		);
	}

	/** Build the before/after preview for a suggested link. */
	public function preview( $link_id ) {
		$l = $this->plugin->db->row(
			"SELECT l.*, s.object_id source_post, t.url target_url FROM {internal_links} l JOIN {urls} s ON s.id = l.source_id JOIN {urls} t ON t.id = l.target_id WHERE l.id = %d",
			array( (int) $link_id )
		);
		if ( ! $l || ! $l['source_post'] ) {
			return new \WP_Error( 'seolity_link', __( 'Suggestion not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$post  = get_post( (int) $l['source_post'] );
		$after = $this->insert_link( $post->post_content, $l['anchor'], $l['target_url'] );
		return array(
			'id'         => (int) $l['id'],
			'source_id'  => (int) $l['source_post'],
			'source'     => get_the_title( $post ),
			'target_url' => $l['target_url'],
			'anchor'     => $l['anchor'],
			'reason'     => $l['reason'],
			'relevance'  => (int) $l['relevance'],
			'possible'   => null !== $after,
			'snippet'    => null !== $after ? $this->snippet( $after, $l['target_url'] ) : '',
		);
	}

	/** Apply = propose a post_content change (respects approval mode). */
	public function apply( $link_id, $auto = false ) {
		$p = $this->preview( $link_id );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$post  = get_post( $p['source_id'] );
		$after = $this->insert_link( $post->post_content, $p['anchor'], $p['target_url'] );
		if ( null === $after ) {
			$this->plugin->db->update( 'internal_links', array( 'status' => 'invalid' ), array( 'id' => (int) $link_id ) );
			return new \WP_Error( 'seolity_link_anchor', __( 'The anchor text is no longer present in the source content.', 'seolity' ), array( 'status' => 409 ) );
		}
		$cid = $this->plugin->changes->propose(
			array(
				'object_type'        => 'post',
				'object_id'          => $p['source_id'],
				'field'              => 'post_content',
				'after'              => $after,
				'source'             => 'internal_link',
				/* translators: 1 anchor 2 url */
				'reason'             => sprintf( __( 'Add internal link "%1$s" → %2$s', 'seolity' ), $p['anchor'], $p['target_url'] ),
				'auto_apply_allowed' => $auto,
			)
		);
		if ( ! is_wp_error( $cid ) ) {
			$this->plugin->db->update( 'internal_links', array( 'status' => 'proposed' ), array( 'id' => (int) $link_id ) );
		}
		return is_wp_error( $cid ) ? $cid : array( 'change_id' => $cid );
	}

	public function dismiss( $link_id ) {
		return (bool) $this->plugin->db->update( 'internal_links', array( 'status' => 'dismissed' ), array( 'id' => (int) $link_id ) );
	}

	/** Link the first unlinked, text-node occurrence of $anchor (outside headings/links). */
	public function insert_link( $html, $anchor, $url ) {
		$parts   = preg_split( '#(<a\s.*?</a>|<h[1-6][^>]*>.*?</h[1-6]>|<[^>]+>|\[[^\]]+\])#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$pattern = '/(?<![\p{L}\p{N}])(' . preg_quote( $anchor, '/' ) . ')(?![\p{L}\p{N}])/iu';
		foreach ( $parts as $i => $part ) {
			if ( '' === $part || '<' === $part[0] || '[' === $part[0] ) {
				continue;
			}
			if ( preg_match( $pattern, $part ) ) {
				$parts[ $i ] = preg_replace( $pattern, '<a href="' . esc_url( $url ) . '">$1</a>', $part, 1 );
				return implode( '', $parts );
			}
		}
		return null;
	}

	private function snippet( $html, $url ) {
		$pos = strpos( $html, 'href="' . esc_url( $url ) . '"' );
		if ( false === $pos ) {
			return '';
		}
		return wp_kses( substr( $html, max( 0, $pos - 200 ), 420 ), array( 'a' => array( 'href' => array() ), 'strong' => array(), 'em' => array() ) );
	}
}
