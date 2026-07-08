<?php
namespace PishTop\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles frontend rendering, shortcodes, and HTML template placeholders parser.
 */
class Frontend {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'pishtop_suggestions', [ $this, 'render_suggestions_shortcode' ] );
		add_shortcode( 'ai_related_posts', [ $this, 'render_suggestions_shortcode' ] );
		add_action( 'init', [ $this, 'register_gutenberg_block' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX frontend suggestions retrievals
		add_action( 'wp_ajax_pishtop_get_suggestions', [ $this, 'ajax_get_suggestions' ] );
		add_action( 'wp_ajax_nopriv_pishtop_get_suggestions', [ $this, 'ajax_get_suggestions' ] );
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'pishtop-frontend-css', PISHTOP_AI_URL . 'assets/frontend.css', [], PISHTOP_AI_VERSION );
		wp_enqueue_script( 'pishtop-frontend-js', PISHTOP_AI_URL . 'assets/frontend.js', [ 'jquery' ], PISHTOP_AI_VERSION, true );

		wp_localize_script( 'pishtop-frontend-js', 'pishtopFrontend', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pishtop_frontend_action' ),
		] );

		// Inject custom CSS from all registered templates
		$templates = get_option( 'pishtop_ai_templates', [] );
		$combined_css = '';
		foreach ( $templates as $tpl ) {
			if ( ! empty( $tpl['custom_css'] ) ) {
				$combined_css .= "\n" . $tpl['custom_css'];
			}
		}

		if ( ! empty( $combined_css ) ) {
			wp_add_inline_style( 'pishtop-frontend-css', $combined_css );
		}
	}

	/**
	 * Register Gutenberg block.
	 */
	public function register_gutenberg_block() {
		register_block_type( 'pishtop-content-suggestion-with-ai/suggestions', [
			'render_callback' => [ $this, 'render_block' ],
			'attributes'      => [
				'post_type' => [ 'type' => 'string', 'default' => 'post' ],
				'limit'     => [ 'type' => 'number', 'default' => 5 ],
				'template'  => [ 'type' => 'string', 'default' => 'default_list' ],
			],
		] );
	}

	/**
	 * Gutenberg block render callback.
	 */
	public function render_block( $attributes ) {
		return $this->render_suggestions_shortcode( $attributes );
	}

	/**
	 * Render the suggestions shortcode instantly with loading skeleton.
	 */
	public function render_suggestions_shortcode( $atts ) {
		$settings = get_option( 'pishtop_ai_settings', [] );
		$default_count = isset( $settings['max_recommendation_count'] ) ? intval( $settings['max_recommendation_count'] ) : 5;

		$a = shortcode_atts( [
			'post_id'   => 0,
			'count'     => $default_count,
			'limit'     => 0,
			'template'  => 'default_list',
			'post_type' => 'post',
		], $atts );

		$post_id = intval( $a['post_id'] );
		if ( $post_id <= 0 ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return '';
		}

		$limit = $a['limit'] > 0 ? intval( $a['limit'] ) : intval( $a['count'] );
		$limit = max( 1, $limit );
		$template = sanitize_key( $a['template'] );
		$post_type = sanitize_key( $a['post_type'] );

		ob_start();
		?>
		<div class="pishtop-suggestions-container" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-limit="<?php echo esc_attr( $limit ); ?>" data-template="<?php echo esc_attr( $template ); ?>" data-post-type="<?php echo esc_attr( $post_type ); ?>">
			<!-- Responsive Skeleton Loading Shimmer Preset -->
			<div class="pishtop-skeleton-wrapper">
				<div class="pishtop-skeleton-line shimmer"></div>
				<div class="pishtop-skeleton-line shimmer"></div>
				<div class="pishtop-skeleton-line shimmer"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX endpoint to retrieve recommendations dynamically.
	 */
	public function ajax_get_suggestions() {
		check_ajax_referer( 'pishtop_frontend_action', 'nonce' );

		$post_id     = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$limit       = isset( $_POST['limit'] ) ? max( 1, intval( $_POST['limit'] ) ) : 5;
		$template_id = isset( $_POST['template'] ) ? sanitize_key( $_POST['template'] ) : 'default_list';

		if ( ! $post_id ) {
			wp_send_json_error( 'Missing post ID' );
		}

		// Fetch recommendations
		$rec_ids = Matching::get_recommendations( $post_id, $limit, $template_id );
		if ( empty( $rec_ids ) ) {
			wp_send_json_success( '' );
		}

		// Get template markup
		$templates = get_option( 'pishtop_ai_templates', [] );
		$tpl = isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : null;

		if ( ! $tpl ) {
			$tpl = [
				'wrapper_html' => '<ul class="pishtop-suggestions-list">{{items}}</ul>',
				'item_html'    => '<li><a href="{{permalink}}">{{title}}</a></li>',
			];
		}

		$item_markup_list = [];
		foreach ( $rec_ids as $rec_id ) {
			$rec_post = get_post( $rec_id );
			if ( ! $rec_post || 'publish' !== $rec_post->post_status ) {
				continue;
			}
			$item_markup_list[] = $this->parse_placeholders( $tpl['item_html'], $rec_id, $rec_post );
		}

		if ( empty( $item_markup_list ) ) {
			wp_send_json_success( '' );
		}

		$output_html = str_replace( '{{items}}', implode( "\n", $item_markup_list ), $tpl['wrapper_html'] );
		wp_send_json_success( $output_html );
	}

	/**
	 * Parse template placeholder variables.
	 */
	private function parse_placeholders( string $html, int $id, \WP_Post $post ) {
		// Basic fields
		$title     = esc_html( get_the_title( $post ) );
		$permalink = esc_url( get_permalink( $post ) );
		
		$image_url = get_the_post_thumbnail_url( $post, 'medium' );
		if ( ! $image_url ) {
			$image_url = PISHTOP_AI_URL . 'assets/placeholder.png';
		}
		$image_url = esc_url( $image_url );

		$excerpt   = esc_html( get_the_excerpt( $post ) );
		$post_date = esc_html( get_the_date( '', $post ) );

		$html = str_replace(
			[ '{{title}}', '{{permalink}}', '{{image_url}}', '{{excerpt}}', '{{post_date}}' ],
			[ $title, $permalink, $image_url, $excerpt, $post_date ],
			$html
		);

		// Handle WooCommerce price: {{price:key_name}}
		if ( preg_match_all( '/\{\{price:([a-zA-Z0-9_\-]+)\}\}/', $html, $matches ) ) {
			$is_wc_active = class_exists( 'WooCommerce' );
			foreach ( $matches[1] as $idx => $meta_key ) {
				$raw_val = get_post_meta( $id, $meta_key, true );
				$formatted_price = '';
				if ( $is_wc_active && is_numeric( $raw_val ) ) {
					$formatted_price = wc_price( floatval( $raw_val ) );
				}
				$html = str_replace( $matches[0][ $idx ], $formatted_price, $html );
			}
		}

		// Handle general metadata: {{meta:key_name}}
		if ( preg_match_all( '/\{\{meta:([a-zA-Z0-9_\-]+)\}\}/', $html, $matches ) ) {
			foreach ( $matches[1] as $idx => $meta_key ) {
				$meta_val = get_post_meta( $id, $meta_key, true );
				$html = str_replace( $matches[0][ $idx ], esc_html( $meta_val ), $html );
			}
		}

		return $html;
	}
}
