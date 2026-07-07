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
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'pishtop-frontend-css', PISHTOP_AI_URL . 'assets/frontend.css', [], PISHTOP_AI_VERSION );

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
	 * Render the AI related suggestions list.
	 */
	public function render_suggestions_shortcode( $atts ) {
		$settings = get_option( 'pishtop_ai_settings', [] );
		$default_count = isset( $settings['max_recommendation_count'] ) ? intval( $settings['max_recommendation_count'] ) : 5;

		$a = shortcode_atts( [
			'post_id'  => 0,
			'count'    => $default_count,
			'template' => 'default_list',
		], $atts );

		$post_id = intval( $a['post_id'] );
		if ( $post_id <= 0 ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return '';
		}

		$count = max( 1, intval( $a['count'] ) );
		$template_id = sanitize_key( $a['template'] );

		// Fetch suggestions list of IDs
		$rec_ids = Matching::get_recommendations( $post_id, $count, $template_id );
		if ( empty( $rec_ids ) ) {
			return '';
		}

		// Fetch template details
		$templates = get_option( 'pishtop_ai_templates', [] );
		$tpl = isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : null;

		if ( ! $tpl ) {
			// Fallback to default list layout if requested layout is missing
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
			return '';
		}

		$output_html = str_replace( '{{items}}', implode( "\n", $item_markup_list ), $tpl['wrapper_html'] );
		return $output_html;
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
