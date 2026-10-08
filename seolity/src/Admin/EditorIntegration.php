<?php
/**
 * "Optimize with SEOlity AI" everywhere WordPress lets you edit content:
 * Classic Editor (content + WooCommerce short description), Gutenberg sidebar,
 * Elementor panel, taxonomy terms (incl. product categories/tags), author bio,
 * ACF/custom textarea & WYSIWYG fields — plus an SEO meta box.
 *
 * @package SEOlity
 */

namespace SEOlity\Admin;

use SEOlity\Core\Capabilities;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class EditorIntegration {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'gutenberg' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'elementor' ) );
		add_action( 'media_buttons', array( $this, 'classic_button' ), 20 );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'init', array( $this, 'term_hooks' ), 99 );
		add_action( 'show_user_profile', array( $this, 'user_hint' ) );
		add_action( 'edit_user_profile', array( $this, 'user_hint' ) );
		// Media library, upload screen and media modal: AI for ALT / title / caption / description.
		add_filter( 'attachment_fields_to_edit', array( $this, 'media_field' ), 20, 2 );
		add_action( 'add_attachment', array( $this, 'on_upload' ) );
		add_filter( 'bulk_actions-upload', array( $this, 'media_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'media_bulk_handle' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'media_bulk_notice' ) );
	}

	/** "Write with SEOlity AI" row in the attachment details (modal + edit screen). */
	public function media_field( $fields, $post ) {
		if ( ! $this->can() || ! $post || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return $fields;
		}
		$html = '<div class="seolity-media-ai" data-id="' . (int) $post->ID . '">'
			. '<input type="text" class="seolity-media-hint" placeholder="' . esc_attr__( 'What is in the image? (optional hint)', 'seolity' ) . '">'
			. '<button type="button" class="button button-primary seolity-media-btn" data-id="' . (int) $post->ID . '">' . esc_html__( 'Write ALT, title, caption & description with AI', 'seolity' ) . '</button>'
			. '<p class="description">' . esc_html__( 'Uses the page this image belongs to, its focus keyword, the SEO knowledge base and competitor data. Review, then the fields save as usual.', 'seolity' ) . '</p>'
			. '<div class="seolity-media-out" aria-live="polite"></div></div>';
		$fields['seolity_ai'] = array(
			'label' => 'SEOlity AI',
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	/** Optional: queue AI ALT text for every new image (follows the automation mode). */
	public function on_upload( $id ) {
		if ( ! $this->plugin->settings->get( 'ai_media_on_upload' ) || ! $this->plugin->ai->is_ready() || 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
			return;
		}
		$this->plugin->optimizer->queue_bulk( 'alt', array( (int) $id ), 'attachment' );
	}

	public function media_bulk_action( $actions ) {
		if ( $this->can() ) {
			$actions['seolity_alt'] = __( 'Write ALT text with SEOlity AI', 'seolity' );
		}
		return $actions;
	}

	public function media_bulk_handle( $redirect, $action, $ids ) {
		if ( 'seolity_alt' !== $action || ! $this->can() ) {
			return $redirect;
		}
		$res = $this->plugin->optimizer->queue_bulk( 'alt', (array) $ids, 'attachment' );
		return add_query_arg( 'seolity_alt_queued', is_wp_error( $res ) ? 0 : count( (array) $ids ), $redirect );
	}

	public function media_bulk_notice() {
		if ( empty( $_GET['seolity_alt_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$n = (int) $_GET['seolity_alt_queued']; // phpcs:ignore WordPress.Security.NonceVerification
		/* translators: %d: number of images */
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'SEOlity AI is writing ALT text for %d images in the background. Review them in AI Center → AI Changes.', 'seolity' ), $n ) ) . '</p></div>';
	}

	/** Display name of an SEO plugin engine. */
	public static function engine_name( $engine ) {
		$names = array( 'rankmath' => 'Rank Math', 'yoast' => 'Yoast SEO', 'seopress' => 'SEOPress', 'aioseo' => 'All in One SEO', 'seolity' => 'SEOlity' );
		return isset( $names[ $engine ] ) ? $names[ $engine ] : ucfirst( $engine );
	}

	private function can() {
		return current_user_can( Capabilities::AI );
	}

	private function config( array $extra = array() ) {
		$admin = new Admin( $this->plugin );
		return array_merge( $admin->js_config( 'editor' ), array( 'engine' => $this->plugin->meta->engine(), 'aiReady' => $this->plugin->ai->is_ready() ), $extra );
	}

	private function enqueue_core( array $extra = array() ) {
		$deps = array();
		if ( $this->plugin->settings->get( 'admin_webfont' ) ) {
			wp_register_style( 'seolity-font', 'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			$deps[] = 'seolity-font';
		}
		wp_enqueue_style( 'seolity-editor', SEOLITY_URL . 'assets/admin/editor.css', $deps, SEOLITY_VERSION );
		wp_enqueue_script( 'seolity-ui', SEOLITY_URL . 'assets/admin/ui.js', array(), SEOLITY_VERSION, true );
		wp_enqueue_script( 'seolity-editor', SEOLITY_URL . 'assets/admin/editor.js', array( 'seolity-ui' ), SEOLITY_VERSION, true );
		wp_localize_script( 'seolity-ui', 'SEOLITY', $this->config( $extra ) );
	}

	public function assets( $hook ) {
		if ( ! $this->can() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$ctx = array();
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			global $post;
			$ctx = array( 'context' => array( 'object_type' => 'post', 'object_id' => $post ? (int) $post->ID : 0, 'post_type' => $screen->post_type, 'block_editor' => method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) );
		} elseif ( 'term.php' === $hook || 'edit-tags.php' === $hook ) {
			$ctx = array( 'context' => array( 'object_type' => 'term', 'object_id' => isset( $_GET['tag_ID'] ) ? (int) $_GET['tag_ID'] : 0, 'taxonomy' => $screen->taxonomy ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( in_array( $hook, array( 'upload.php', 'media-new.php' ), true ) ) {
			$ctx = array( 'context' => array( 'object_type' => 'media', 'object_id' => 0 ) );
		} elseif ( in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			$uid = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : get_current_user_id(); // phpcs:ignore WordPress.Security.NonceVerification
			$ctx = array( 'context' => array( 'object_type' => 'user', 'object_id' => $uid ) );
		} else {
			return;
		}
		$this->enqueue_core( $ctx );
	}

	/** Gutenberg sidebar (wp.plugins + PluginSidebar, no build step required). */
	public function gutenberg() {
		if ( ! $this->can() ) {
			return;
		}
		wp_enqueue_script(
			'seolity-gutenberg',
			SEOLITY_URL . 'assets/admin/gutenberg.js',
			array( 'seolity-editor', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-i18n' ),
			SEOLITY_VERSION,
			true
		);
	}

	public function elementor() {
		if ( ! $this->can() ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$this->enqueue_core( array( 'context' => array( 'object_type' => 'post', 'object_id' => $post_id, 'elementor' => true ) ) );
		wp_enqueue_script( 'seolity-elementor', SEOLITY_URL . 'assets/admin/elementor.js', array( 'seolity-editor' ), SEOLITY_VERSION, true );
	}

	/** Classic editor + WooCommerce short description (editor id "excerpt"). */
	public function classic_button( $editor_id ) {
		if ( ! $this->can() ) {
			return;
		}
		$field = 'excerpt' === $editor_id ? 'post_excerpt' : ( 'content' === $editor_id ? 'post_content' : 'custom' );
		printf(
			'<button type="button" class="button seolity-optimize-btn" data-editor="%1$s" data-field="%2$s">%4$s <span>%3$s</span></button>',
			esc_attr( $editor_id ),
			esc_attr( $field ),
			esc_html__( 'Optimize with SEOlity AI', 'seolity' ),
			Icons::svg( 'sparkles' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
		);
	}

	public function meta_box() {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		add_meta_box( 'seolity-box', 'SEOlity', array( $this, 'render_meta_box' ), $types, 'normal', 'high' );
	}

	public function render_meta_box( \WP_Post $post ) {
		$m      = $this->plugin->meta;
		$engine = $m->engine();
		wp_nonce_field( 'seolity_meta_' . $post->ID, 'seolity_meta_nonce' );
		$title = $m->get_post( $post->ID, 'meta_title' );
		$desc  = $m->get_post( $post->ID, 'meta_description' );
		$kws   = $m->get_post( $post->ID, 'focus_keywords' );
		$own   = 'seolity' === $engine;
		?>
		<div class="seolity-box" data-post="<?php echo (int) $post->ID; ?>">
			<div class="seolity-box__score" aria-live="polite"><span class="seolity-score-ring" data-score="">—</span><span class="seolity-box__score-label"><?php esc_html_e( 'SEO score (live)', 'seolity' ); ?></span></div>
			<?php if ( $own ) : ?>
				<p><label for="seolity_focus_keyword"><strong><?php esc_html_e( 'Focus keywords', 'seolity' ); ?></strong></label><br>
				<input type="text" class="widefat" id="seolity_focus_keyword" name="seolity_focus_keywords" value="<?php echo esc_attr( $kws ); ?>" placeholder="<?php esc_attr_e( 'e.g. espresso machine for home, home espresso machine price', 'seolity' ); ?>">
				<span class="description"><?php esc_html_e( 'Comma separated. The first one is the primary keyword.', 'seolity' ); ?></span></p>
			<?php else : ?>
				<?php /* Third-party SEO plugin owns the keyword field: show it read-only so two editors never overwrite each other. */ ?>
				<input type="hidden" id="seolity_focus_keyword" value="<?php echo esc_attr( $kws ); ?>">
				<p class="seolity-kw-line"><?php Icons::e( 'key' ); ?> <strong><?php esc_html_e( 'Focus keywords', 'seolity' ); ?>:</strong> <span class="seolity-kw-list" dir="auto"><?php echo $kws ? esc_html( $kws ) : esc_html__( 'none', 'seolity' ); ?></span>
				<?php /* translators: %s SEO plugin name */ ?>
				<br><span class="description"><?php echo esc_html( sprintf( __( 'Stored in %s. Use “GSC keywords” to choose them from real Search Console data.', 'seolity' ), self::engine_name( $engine ) ) ); ?></span></p>
			<?php endif; ?>
			<?php if ( 'seolity' === $engine || 'on' === $this->plugin->settings->get( 'frontend_output' ) ) : ?>
				<p><label for="seolity_meta_title"><strong><?php esc_html_e( 'SEO title', 'seolity' ); ?></strong></label> <span class="seolity-count" data-for="seolity_meta_title"></span><br>
				<input type="text" class="widefat" id="seolity_meta_title" name="seolity_meta_title" value="<?php echo esc_attr( $title ); ?>" maxlength="120"></p>
				<p><label for="seolity_meta_description"><strong><?php esc_html_e( 'Meta description', 'seolity' ); ?></strong></label> <span class="seolity-count" data-for="seolity_meta_description"></span><br>
				<textarea class="widefat" rows="3" id="seolity_meta_description" name="seolity_meta_description" maxlength="320"><?php echo esc_textarea( $desc ); ?></textarea></p>
				<p><label><input type="checkbox" name="seolity_noindex" value="1" <?php checked( '1', $m->get_post( $post->ID, 'noindex' ) ); ?>> <?php esc_html_e( 'Noindex this page', 'seolity' ); ?></label></p>
				<div class="seolity-serp" aria-hidden="true"><div class="seolity-serp__url"></div><div class="seolity-serp__title"></div><div class="seolity-serp__desc"></div></div>
			<?php else : ?>
				<?php /* translators: %s SEO plugin */ ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'SEO title & description are managed by %s. SEOlity AI reads and writes them there.', 'seolity' ), self::engine_name( $engine ) ) ); ?></p>
			<?php endif; ?>
			<p class="seolity-box__actions">
				<?php if ( $this->can() ) : ?>
					<button type="button" class="button button-primary seolity-optimize-btn" data-field="post_content"><?php Icons::e( 'sparkles' ); ?> <?php esc_html_e( 'Optimize content', 'seolity' ); ?></button>
					<button type="button" class="button seolity-kw-btn"><?php Icons::e( 'key' ); ?> <?php esc_html_e( 'GSC keywords', 'seolity' ); ?></button>
					<button type="button" class="button seolity-meta-btn"><?php esc_html_e( 'AI title & description', 'seolity' ); ?></button>
					<button type="button" class="button seolity-faq-btn"><?php esc_html_e( 'Generate FAQ', 'seolity' ); ?></button>
					<button type="button" class="button seolity-links-btn"><?php esc_html_e( 'Internal links', 'seolity' ); ?></button>
					<button type="button" class="button seolity-eeat-btn"><?php esc_html_e( 'E-E-A-T analysis', 'seolity' ); ?></button>
					<?php if ( 'product' === $post->post_type ) : ?>
						<button type="button" class="button seolity-product-btn"><?php esc_html_e( 'AI Product Optimizer', 'seolity' ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
				<button type="button" class="button-link seolity-history-btn"><?php esc_html_e( 'Version history', 'seolity' ); ?></button>
			</p>
			<ul class="seolity-box__checks"></ul>
		</div>
		<?php
	}

	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['seolity_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['seolity_meta_nonce'] ) ), 'seolity_meta_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$m = $this->plugin->meta;
		if ( isset( $_POST['seolity_focus_keywords'] ) && 'seolity' === $m->engine() ) {
			$m->set_post( $post_id, 'focus_keywords', sanitize_text_field( wp_unslash( $_POST['seolity_focus_keywords'] ) ) );
		}
		if ( isset( $_POST['seolity_meta_title'] ) ) {
			$m->set_post( $post_id, 'meta_title', sanitize_text_field( wp_unslash( $_POST['seolity_meta_title'] ) ) );
			$m->set_post( $post_id, 'meta_description', sanitize_textarea_field( wp_unslash( isset( $_POST['seolity_meta_description'] ) ? $_POST['seolity_meta_description'] : '' ) ) );
			$m->set_post( $post_id, 'noindex', empty( $_POST['seolity_noindex'] ) ? '' : '1' );
		}
	}

	public function term_hooks() {
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			add_action( "{$tax}_edit_form_fields", array( $this, 'term_fields' ), 20 );
			add_action( "edited_{$tax}", array( $this, 'save_term' ), 10, 1 );
		}
	}

	public function term_fields( $term ) {
		$m = $this->plugin->meta;
		wp_nonce_field( 'seolity_term_' . $term->term_id, 'seolity_term_nonce' );
		?>
		<tr class="form-field seolity-term">
			<th scope="row">SEOlity</th>
			<td>
				<?php if ( $this->can() ) : ?>
					<p><button type="button" class="button button-primary seolity-optimize-btn" data-field="term_description" data-target="#description"><?php Icons::e( 'sparkles' ); ?> <?php esc_html_e( 'Optimize with SEOlity AI', 'seolity' ); ?></button>
					<button type="button" class="button seolity-meta-btn"><?php esc_html_e( 'AI title & description', 'seolity' ); ?></button>
					<button type="button" class="button seolity-kw-btn"><?php Icons::e( 'key' ); ?> <?php esc_html_e( 'GSC keywords', 'seolity' ); ?></button></p>
				<?php endif; ?>
				<?php if ( 'seolity' === $m->engine() ) : ?>
					<p><label><?php esc_html_e( 'Focus keywords', 'seolity' ); ?><br><input type="text" name="seolity_focus_keywords" value="<?php echo esc_attr( $m->get_term( $term->term_id, 'focus_keywords' ) ); ?>"></label></p>
					<p><label><?php esc_html_e( 'SEO title', 'seolity' ); ?><br><input type="text" name="seolity_meta_title" value="<?php echo esc_attr( $m->get_term( $term->term_id, 'meta_title' ) ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Meta description', 'seolity' ); ?><br><textarea name="seolity_meta_description" rows="3"><?php echo esc_textarea( $m->get_term( $term->term_id, 'meta_description' ) ); ?></textarea></label></p>
					<p><label><input type="checkbox" name="seolity_noindex" value="1" <?php checked( '1', $m->get_term( $term->term_id, 'noindex' ) ); ?>> <?php esc_html_e( 'Noindex this archive', 'seolity' ); ?></label></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public function save_term( $term_id ) {
		if ( ! isset( $_POST['seolity_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['seolity_term_nonce'] ) ), 'seolity_term_' . $term_id ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$m = $this->plugin->meta;
		if ( isset( $_POST['seolity_focus_keywords'] ) && 'seolity' === $m->engine() ) {
			$m->set_term( $term_id, 'focus_keywords', sanitize_text_field( wp_unslash( $_POST['seolity_focus_keywords'] ) ) );
		}
		if ( isset( $_POST['seolity_meta_title'] ) ) {
			$m->set_term( $term_id, 'meta_title', sanitize_text_field( wp_unslash( $_POST['seolity_meta_title'] ) ) );
			$m->set_term( $term_id, 'meta_description', sanitize_textarea_field( wp_unslash( isset( $_POST['seolity_meta_description'] ) ? $_POST['seolity_meta_description'] : '' ) ) );
			$m->set_term( $term_id, 'noindex', empty( $_POST['seolity_noindex'] ) ? '' : '1' );
		}
	}

	public function user_hint( $user ) {
		if ( $this->can() && current_user_can( 'edit_user', $user->ID ) ) {
			echo '<p class="description seolity-user-hint">' . esc_html__( 'Tip: an author bio with real credentials and experience strengthens E-E-A-T. Use “Optimize with SEOlity AI” next to the biography field.', 'seolity' ) . '</p>';
		}
	}
}
