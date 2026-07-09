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
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );

		// AJAX frontend suggestions retrievals
		add_action( 'wp_ajax_pishtop_get_suggestions', [ $this, 'ajax_get_suggestions' ] );
		add_action( 'wp_ajax_nopriv_pishtop_get_suggestions', [ $this, 'ajax_get_suggestions' ] );

		// WooCommerce dynamic page overrides
		add_filter( 'pishtop_ai_post_text', [ $this, 'override_woocommerce_page_text' ], 10, 2 );
		add_filter( 'pishtop_ai_recommendations_transient_key', [ $this, 'override_woocommerce_transient_key' ], 10, 4 );
	}

	public function register_assets() {
		wp_register_style( 'pishtop-frontend-css', PISHTOP_AI_URL . 'assets/frontend.css', [], PISHTOP_AI_VERSION );
		wp_register_script( 'pishtop-frontend-js', PISHTOP_AI_URL . 'assets/frontend.js', [ 'jquery' ], PISHTOP_AI_VERSION, true );

		wp_localize_script( 'pishtop-frontend-js', 'pishtopFrontend', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pishtop_frontend_action' ),
		] );
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
		// Enqueue assets dynamically when shortcode is active
		wp_enqueue_style( 'pishtop-frontend-css' );
		wp_enqueue_script( 'pishtop-frontend-js' );

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

		$settings = get_option( 'pishtop_ai_settings', [] );
		$default_count = isset( $settings['max_recommendation_count'] ) ? intval( $settings['max_recommendation_count'] ) : 5;

		$a = shortcode_atts( [
			'post_id'  => 0,
			'count'    => $default_count,
			'limit'    => 0,
			'template' => 'default_list',
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

		// Retrieve post type filter configured inside the template
		$templates = get_option( 'pishtop_ai_templates', [] );
		$post_type = '';
		if ( isset( $templates[ $template ]['post_type'] ) ) {
			$post_type = sanitize_key( $templates[ $template ]['post_type'] );
		}

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
		$template_id = isset( $_POST['template'] ) ? sanitize_key( wp_unslash( $_POST['template'] ) ) : 'default_list';
		$post_type   = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';

		if ( ! $post_id ) {
			wp_send_json_error( 'Missing post ID' );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			wp_send_json_error( 'Unauthorized post query' );
		}

		// Fetch recommendations
		$rec_ids = Matching::get_recommendations( $post_id, $limit, $template_id, $post_type );
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
		// Handle general metadata first to allow fallback to other placeholders: {{meta:key_name}} or {{meta:key_name | fallback}}
		$html = $this->parse_meta_placeholders( $html, $id );

		// Basic fields
		$title     = esc_html( get_the_title( $post ) );
		$permalink = esc_url( get_permalink( $post ) );
		
		$settings = get_option( 'pishtop_ai_settings', [] );
		$thumb_size = ! empty( $settings['thumbnail_size'] ) ? sanitize_key( $settings['thumbnail_size'] ) : 'medium';
		$fallback_img = PISHTOP_AI_URL . 'assets/placeholder.svg';

		$image_url = get_the_post_thumbnail_url( $post, $thumb_size );
		if ( ! $image_url ) {
			$image_url = $fallback_img;
		}
		$image_url = esc_url( $image_url );

		$excerpt   = esc_html( get_the_excerpt( $post ) );
		$post_date = esc_html( get_the_date( '', $post ) );

		$html = str_replace(
			[ '{{title}}', '{{permalink}}', '{{image_url}}', '{{excerpt}}', '{{post_date}}', '{{post_id}}', '{{id}}' ],
			[ $title, $permalink, $image_url, $excerpt, $post_date, $id, $id ],
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

		return $html;
	}

	/**
	 * Parse general and conditional metadata placeholders.
	 */
	private function parse_meta_placeholders( string $html, int $id ): string {
		while ( true ) {
			$pos = strpos( $html, '{{meta:' );
			if ( $pos === false ) {
				break;
			}
			
			$len = strlen( $html );
			$brace_count = 2;
			$match_end = -1;
			
			for ( $i = $pos + 7; $i < $len; $i++ ) {
				if ( $html[ $i ] === '{' ) {
					$brace_count++;
				} elseif ( $html[ $i ] === '}' ) {
					$brace_count--;
					if ( $brace_count === 0 ) {
						$match_end = $i;
						break;
					}
				}
			}
			
			if ( $match_end === -1 ) {
				break;
			}
			
			$content = substr( $html, $pos + 2, $match_end - $pos - 3 );
			$content = substr( $content, 5 ); // Strip "meta:"
			
			$pipe_pos = strpos( $content, '|' );
			if ( $pipe_pos !== false ) {
				$meta_key = trim( substr( $content, 0, $pipe_pos ) );
				$fallback = trim( substr( $content, $pipe_pos + 1 ) );
			} else {
				$meta_key = trim( $content );
				$fallback = '';
			}
			
			$meta_val = get_post_meta( $id, $meta_key, true );
			
			if ( is_scalar( $meta_val ) && (string) $meta_val !== '' ) {
				$replacement = esc_html( (string) $meta_val );
			} else {
				$replacement = $fallback;
			}
			
			$html = substr_replace( $html, $replacement, $pos, $match_end - $pos + 1 );
		}
		
		return $html;
	}

	/**
	 * Override matching text for WooCommerce Cart, Checkout, and Thank You pages.
	 */
	public function override_woocommerce_page_text( string $text, int $post_id ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $text;
		}

		$cart_page_id = wc_get_page_id( 'cart' );
		$checkout_page_id = wc_get_page_id( 'checkout' );

		// 1. Cart Page
		if ( $post_id === $cart_page_id || ( is_cart() && get_the_ID() === $post_id ) ) {
			if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
				$cart_items = WC()->cart->get_cart();
				$names = [];
				foreach ( $cart_items as $item ) {
					$product = $item['data'];
					if ( $product ) {
						$names[] = $product->get_name();
					}
				}
				if ( ! empty( $names ) ) {
					return implode( ', ', $names );
				}
			}
		}

		// 2. Checkout / Thank You Page
		if ( $post_id === $checkout_page_id || ( is_checkout() && get_the_ID() === $post_id ) ) {
			// Check if we are on the Thank You (order received) page by parsing referer or URL
			$order_id = 0;
			if ( is_order_received_page() ) {
				global $wp;
				$order_id = isset( $wp->query_vars['order-received'] ) ? intval( $wp->query_vars['order-received'] ) : 0;
			} else {
				$referer = wp_get_referer();
				if ( $referer ) {
					if ( preg_match( '/\/order-received\/(\d+)/', $referer, $matches ) ) {
						$order_id = intval( $matches[1] );
					} else {
						$parsed_url = wp_parse_url( $referer );
						if ( ! empty( $parsed_url['query'] ) ) {
							wp_parse_str( $parsed_url['query'], $query_vars );
							if ( isset( $query_vars['order-received'] ) ) {
								$order_id = intval( $query_vars['order-received'] );
							} elseif ( isset( $query_vars['order'] ) ) {
								$order_id = intval( $query_vars['order'] );
							}
						}
					}
				}
			}

			if ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$names = [];
					foreach ( $order->get_items() as $item ) {
						$names[] = $item->get_name();
					}
					if ( ! empty( $names ) ) {
						return implode( ', ', $names );
					}
				}
			}

			// Fallback: active cart items if checkout page
			if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
				$cart_items = WC()->cart->get_cart();
				$names = [];
				foreach ( $cart_items as $item ) {
					$product = $item['data'];
					if ( $product ) {
						$names[] = $product->get_name();
					}
				}
				if ( ! empty( $names ) ) {
					return implode( ', ', $names );
				}
			}
		}

		return $text;
	}

	/**
	 * Vary the caching transient key for WooCommerce Cart, Checkout, and Thank You pages to prevent cross-user caching leakage.
	 */
	public function override_woocommerce_transient_key( string $key, int $post_id, string $template_id, string $post_type ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $key;
		}

		$cart_page_id = wc_get_page_id( 'cart' );
		$checkout_page_id = wc_get_page_id( 'checkout' );

		// 1. Cart Page (cache unique to cart contents hash)
		if ( $post_id === $cart_page_id ) {
			if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
				$cart_items = WC()->cart->get_cart();
				$ids = [];
				foreach ( $cart_items as $item ) {
					$ids[] = $item['product_id'] . '_' . $item['quantity'];
				}
				if ( ! empty( $ids ) ) {
					sort( $ids );
					$cart_hash = md5( implode( '|', $ids ) );
					return $key . '_cart_' . $cart_hash;
				}
			}
		}

		// 2. Checkout / Thank You Page (cache unique to order or cart contents)
		if ( $post_id === $checkout_page_id ) {
			$order_id = 0;
			if ( is_order_received_page() ) {
				global $wp;
				$order_id = isset( $wp->query_vars['order-received'] ) ? intval( $wp->query_vars['order-received'] ) : 0;
			} else {
				$referer = wp_get_referer();
				if ( $referer ) {
					if ( preg_match( '/\/order-received\/(\d+)/', $referer, $matches ) ) {
						$order_id = intval( $matches[1] );
					} else {
						$parsed_url = wp_parse_url( $referer );
						if ( ! empty( $parsed_url['query'] ) ) {
							wp_parse_str( $parsed_url['query'], $query_vars );
							if ( isset( $query_vars['order-received'] ) ) {
								$order_id = intval( $query_vars['order-received'] );
							} elseif ( isset( $query_vars['order'] ) ) {
								$order_id = intval( $query_vars['order'] );
							}
						}
					}
				}
			}

			if ( $order_id > 0 ) {
				return $key . '_order_' . $order_id;
			}

			if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
				$cart_items = WC()->cart->get_cart();
				$ids = [];
				foreach ( $cart_items as $item ) {
					$ids[] = $item['product_id'] . '_' . $item['quantity'];
				}
				if ( ! empty( $ids ) ) {
					sort( $ids );
					$cart_hash = md5( implode( '|', $ids ) );
					return $key . '_cart_' . $cart_hash;
				}
			}
		}

		return $key;
	}
}
