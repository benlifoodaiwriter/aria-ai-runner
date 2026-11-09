<?php
/**
 * AI Image SEO (section 4): crawls the media library, detects missing/bad/
 * generic/duplicate/stuffed ALT and filename problems, and generates
 * context-aware ALT text (page topic, product, category, nearby text).
 *
 * @package SEOlity
 */

namespace SEOlity\Images;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ImageSeo {

	const GENERIC_RE  = '/^(image|img|photo|picture|pic|untitled|screenshot|dsc|dcim|whatsapp|تصویر|عکس|بدون عنوان|logo|banner|placeholder)[\s_\-]*\d*$/iu';
	const FILENAME_RE = '/^(img|dsc|dcim|image|photo|screenshot|whatsapp|pxl|mvimg|unnamed|download|untitled|scan)[\s_\-]*[\d_\-]*$/i';
	const CHUNK       = 200;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Background scan of the media library in chunks. */
	public function scan( $offset = 0 ) {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => self::CHUNK,
				'offset'         => (int) $offset,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		if ( 0 === $offset ) {
			update_option( 'seolity_images_alt_seen', array(), false );
		}
		$seen = (array) get_option( 'seolity_images_alt_seen', array() );
		_prime_post_caches( $ids, false, true );
		foreach ( $ids as $id ) {
			$this->index( $id, $seen );
		}
		update_option( 'seolity_images_alt_seen', $seen, false );
		if ( count( $ids ) === self::CHUNK ) {
			$this->plugin->queue->push( 'images_scan', array( 'offset' => $offset + self::CHUNK ), array( 'priority' => 30 ) );
		} else {
			$this->mark_duplicates();
			delete_option( 'seolity_images_alt_seen' );
			update_option( 'seolity_images_scanned', time(), false );
		}
		return true;
	}

	private function index( $id, array &$seen ) {
		$post     = get_post( $id );
		$alt      = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$file     = (string) get_post_meta( $id, '_wp_attached_file', true );
		$meta     = wp_get_attachment_metadata( $id );
		$filename = pathinfo( $file, PATHINFO_FILENAME );
		$parent   = (int) $post->post_parent;
		$issues   = $this->detect( $alt, $filename, $parent );
		$norm     = Text::normalize( $alt );
		if ( '' !== $norm ) {
			$seen[ md5( $norm ) ] = isset( $seen[ md5( $norm ) ] ) ? $seen[ md5( $norm ) ] + 1 : 1;
		}
		$this->plugin->db->upsert(
			'images',
			array(
				'project_id'    => $this->plugin->db->project_id(),
				'attachment_id' => $id,
				'parent_id'     => $parent,
				'url'           => wp_get_attachment_url( $id ),
				'filename'      => basename( $file ),
				'alt'           => $alt,
				'title'         => $post->post_title,
				'caption'       => $post->post_excerpt,
				'filesize'      => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0,
				'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
				'issues'        => implode( ',', $issues ),
				'last_checked'  => Database::now(),
			),
			array( 'parent_id', 'url', 'filename', 'alt', 'title', 'caption', 'filesize', 'width', 'height', 'issues', 'last_checked' )
		);
	}

	/** @return string[] Issue codes. */
	public function detect( $alt, $filename, $parent_id = 0 ) {
		$issues = array();
		if ( '' === $alt ) {
			$issues[] = 'missing_alt';
		} else {
			$words = Text::words( $alt );
			if ( preg_match( self::GENERIC_RE, $alt ) || count( $words ) < 2 ) {
				$issues[] = 'generic_alt';
			}
			if ( mb_strlen( $alt ) > 125 ) {
				$issues[] = 'long_alt';
			}
			if ( preg_match( '/\.(jpe?g|png|webp|gif)$/i', $alt ) || preg_match( '/^[\w\-]+$/', $alt ) && false !== strpos( $alt, '-' ) ) {
				$issues[] = 'filename_alt';
			}
			$tf = array_count_values( $words );
			arsort( $tf );
			if ( $words && reset( $tf ) >= 3 ) {
				$issues[] = 'stuffed_alt';
			}
			if ( substr_count( $alt, ',' ) >= 3 ) {
				$issues[] = 'stuffed_alt';
			}
			if ( preg_match( '/^(image|picture|photo) of|^تصویر\s/iu', $alt ) ) {
				$issues[] = 'redundant_prefix';
			}
		}
		if ( preg_match( self::FILENAME_RE, $filename ) || preg_match( '/^[a-f0-9]{16,}$/i', $filename ) ) {
			$issues[] = 'bad_filename';
		}
		return array_values( array_unique( $issues ) );
	}

	private function mark_duplicates() {
		$db   = $this->plugin->db;
		$dups = $db->rows( "SELECT alt FROM {images} WHERE project_id = %d AND alt <> '' GROUP BY alt HAVING COUNT(*) > 2 LIMIT 500", array( $db->project_id() ) );
		foreach ( $dups as $d ) {
			$db->query( "UPDATE {images} SET issues = TRIM(BOTH ',' FROM CONCAT(issues, ',duplicate_alt')) WHERE project_id = %d AND alt = %s AND issues NOT LIKE %s", array( $db->project_id(), $d['alt'], '%duplicate_alt%' ) );
		}
	}

	public function query( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'i.project_id = %d' );
		$args  = array( $db->project_id() );
		if ( ! empty( $f['issue'] ) ) {
			$where[] = 'i.issues LIKE %s';
			$args[]  = '%' . $db->wpdb->esc_like( sanitize_key( $f['issue'] ) ) . '%';
		} elseif ( empty( $f['all'] ) ) {
			$where[] = "i.issues <> ''";
		}
		if ( ! empty( $f['post_type'] ) ) {
			$where[] = 'p.post_type = %s';
			$args[]  = sanitize_key( $f['post_type'] );
		}
		$per   = isset( $f['per_page'] ) ? max( 1, min( 200, (int) $f['per_page'] ) ) : 40;
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$join  = "LEFT JOIN {$db->wpdb->posts} p ON p.ID = i.parent_id";
		$rows  = $db->rows( "SELECT i.*, p.post_title parent_title, p.post_type parent_type FROM {images} i $join WHERE " . implode( ' AND ', $where ) . ' ORDER BY i.attachment_id DESC LIMIT %d OFFSET %d', array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) );
		foreach ( $rows as &$r ) {
			$r['thumb'] = wp_get_attachment_image_url( (int) $r['attachment_id'], 'thumbnail' );
		}
		$stats = array();
		foreach ( array( 'missing_alt', 'generic_alt', 'duplicate_alt', 'stuffed_alt', 'filename_alt', 'long_alt', 'bad_filename', 'redundant_prefix' ) as $code ) {
			$stats[ $code ] = (int) $db->value( 'SELECT COUNT(*) FROM {images} WHERE project_id = %d AND issues LIKE %s', array( $db->project_id(), '%' . $code . '%' ) );
		}
		$stats['total'] = (int) $db->value( 'SELECT COUNT(*) FROM {images} WHERE project_id = %d', array( $db->project_id() ) );
		return array(
			'items'   => $rows,
			'total'   => (int) $db->value( "SELECT COUNT(*) FROM {images} i $join WHERE " . implode( ' AND ', $where ), $args ),
			'stats'   => $stats,
			'scanned' => get_option( 'seolity_images_scanned' ),
		);
	}

	/** Context for one attachment (page topic, product data, nearby text). */
	private function context( $attachment_id ) {
		$att    = get_post( $attachment_id );
		$parent = $att && $att->post_parent ? get_post( $att->post_parent ) : null;
		$ctx    = array(
			'id'       => (int) $attachment_id,
			'filename' => basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ),
			'current_alt' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'title'    => $att ? $att->post_title : '',
			'caption'  => $att ? $att->post_excerpt : '',
		);
		if ( $parent ) {
			$ctx['page_title']    = $parent->post_title;
			$ctx['page_type']     = $parent->post_type;
			$ctx['focus_keyword'] = $this->plugin->meta->get_post( $parent->ID, 'focus_keyword' );
			$content              = (string) $parent->post_content;
			$pos                  = false;
			$url                  = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				$pos = strpos( $content, basename( $url ) );
			}
			$ctx['nearby_text'] = false !== $pos ? Text::truncate( Text::strip( substr( $content, max( 0, $pos - 1200 ), 2400 ) ), 500 ) : Text::truncate( Text::strip( $content ), 400 );
			if ( 'product' === $parent->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $parent->ID );
				if ( $product ) {
					$ctx['product'] = array(
						'name'       => $product->get_name(),
						'categories' => wp_strip_all_tags( wc_get_product_category_list( $parent->ID, ', ' ) ),
						'attributes' => array_map(
							static function ( $a ) {
								return is_object( $a ) ? implode( ', ', $a->get_options() ) : (string) $a;
							},
							$product->get_attributes()
						),
						'gallery_position' => array_search( (int) $attachment_id, array_map( 'intval', $product->get_gallery_image_ids() ), true ),
						'is_main_image'    => (int) $product->get_image_id() === (int) $attachment_id,
					);
				}
			}
		}
		return $ctx;
	}

	/**
	 * Generate ALT/title/caption for attachments (pending changes; auto-applied
	 * in Semi/Autonomous mode because ALT is low-risk).
	 *
	 * @param int[] $ids Attachment ids (max 20 per AI call).
	 * @return array|\WP_Error
	 */
	public function generate( array $ids, $auto = false, $batch_id = null ) {
		$ids = array_slice( array_values( array_filter( array_map( 'intval', $ids ) ) ), 0, 20 );
		if ( ! $ids ) {
			return new \WP_Error( 'seolity_images', __( 'No images selected.', 'seolity' ), array( 'status' => 400 ) );
		}
		$items = array_map( array( $this, 'context' ), $ids );
		$res   = $this->plugin->ai->run( 'image_alt', array( 'images' => $items, 'site_language' => get_locale() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$batch   = $batch_id ? $batch_id : 'alt_' . wp_generate_password( 10, false, false );
		$changes = array();
		foreach ( $res['data']['items'] as $item ) {
			if ( ! in_array( (int) $item['id'], $ids, true ) ) {
				continue; // Ignore hallucinated ids.
			}
			$issues = $this->detect( $item['alt'], 'ok' );
			if ( array_intersect( $issues, array( 'stuffed_alt', 'generic_alt' ) ) ) {
				continue; // Guardrail: never apply a bad ALT.
			}
			$cid = $this->plugin->changes->propose(
				array(
					'object_type'        => 'attachment',
					'object_id'          => (int) $item['id'],
					'field'              => 'alt',
					'after'              => sanitize_text_field( $item['alt'] ),
					'reason'             => $item['reason'],
					'ai'                 => $res['meta'],
					'batch_id'           => $batch,
					'auto_apply_allowed' => $auto,
				)
			);
			if ( ! is_wp_error( $cid ) ) {
				$changes[ (int) $item['id'] ] = array( 'change_id' => $cid, 'alt' => $item['alt'], 'title' => $item['title'], 'caption' => $item['caption'] );
			}
		}
		return array( 'batch_id' => $batch, 'changes' => $changes, 'meta' => $res['meta'] );
	}

	/**
	 * ALT, title, caption, description and a filename slug for one attachment (media library,
	 * upload screen, attachment page). Returns suggestions only — the screen fills its own fields.
	 *
	 * @param int    $id      Attachment id.
	 * @param int    $post_id Post the media modal was opened from (0 = parent / none).
	 * @param string $hint    Optional words from the uploader ("red mug on a wooden desk").
	 * @return array|\WP_Error
	 */
	public function suggest( $id, $post_id = 0, $hint = '' ) {
		$id = (int) $id;
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'seolity_images', __( 'No images selected.', 'seolity' ), array( 'status' => 400 ) );
		}
		$ctx = $this->context( $id );
		if ( $post_id && empty( $ctx['page_title'] ) ) {
			$post = get_post( (int) $post_id );
			if ( $post && current_user_can( 'edit_post', $post->ID ) ) {
				$ctx['page_title']    = $post->post_title;
				$ctx['page_type']     = $post->post_type;
				$ctx['focus_keyword'] = $this->plugin->meta->get_post( $post->ID, 'focus_keyword' );
				$ctx['nearby_text']   = Text::truncate( Text::strip( (string) $post->post_content ), 400 );
			}
		}
		$att = get_post( $id );
		$ctx['description'] = $att ? Text::truncate( Text::strip( $att->post_content ), 300 ) : '';
		$ctx['mime']        = (string) get_post_mime_type( $id );
		$meta               = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['width'] ) ) {
			$ctx['size'] = (int) $meta['width'] . 'x' . (int) $meta['height'];
		}
		$hint = trim( sanitize_text_field( (string) $hint ) );
		if ( '' !== $hint ) {
			$ctx['uploader_hint'] = $hint;
		}
		$query = trim( ( isset( $ctx['focus_keyword'] ) ? $ctx['focus_keyword'] : '' ) . ' ' . ( isset( $ctx['page_title'] ) ? $ctx['page_title'] : $ctx['title'] ) );
		$res   = $this->plugin->ai->run( 'media_seo', array( 'image' => $ctx, 'site_language' => get_locale() ), array( 'knowledge_query' => $query ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$d    = $res['data'];
		$alt  = sanitize_text_field( $d['alt'] );
		$warn = array_values( array_intersect( $this->detect( $alt, 'ok' ), array( 'stuffed_alt', 'generic_alt', 'long_alt' ) ) );
		return array(
			'id'          => $id,
			'alt'         => $alt,
			'title'       => sanitize_text_field( $d['title'] ),
			'caption'     => sanitize_textarea_field( $d['caption'] ),
			'description' => sanitize_textarea_field( $d['description'] ),
			'filename'    => sanitize_title( $d['filename'] ),
			'reason'      => (string) $d['reason'],
			'warnings'    => $warn,
			'meta'        => $res['meta'],
		);
	}

	/** Refresh a single row after ALT changes. */
	public function reindex( $attachment_id ) {
		$seen = array();
		$this->index( (int) $attachment_id, $seen );
	}
}
