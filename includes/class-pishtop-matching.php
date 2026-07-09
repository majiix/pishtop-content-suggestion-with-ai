<?php
namespace PishTop\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles recommendations engine, candidate selection, cosine similarity, re-ranking, and mutex locks.
 */
class Matching {

	/**
	 * Compute Cosine Similarity between two numeric vectors.
	 */
	public static function cosine_similarity( array $vec1, array $vec2 ) {
		$dot = 0.0;
		$norm1 = 0.0;
		$norm2 = 0.0;
		$count = count( $vec1 );

		if ( $count !== count( $vec2 ) || 0 === $count ) {
			return 0.0;
		}

		for ( $i = 0; $i < $count; $i++ ) {
			$dot   += $vec1[ $i ] * $vec2[ $i ];
			$norm1 += $vec1[ $i ] * $vec1[ $i ];
			$norm2 += $vec2[ $i ] * $vec2[ $i ];
		}

		if ( 0.0 === $norm1 || 0.0 === $norm2 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $norm1 ) * sqrt( $norm2 ) );
	}

	public static function get_recommendations( int $post_id, int $count, string $template_id, string $post_type = '' ) {
		$settings = get_option( 'pishtop_ai_settings', [] );
		$cache_ttl_val = isset( $settings['cache_ttl'] ) ? intval( $settings['cache_ttl'] ) : 12;
		$cache_ttl_unit = isset( $settings['cache_ttl_unit'] ) ? $settings['cache_ttl_unit'] : 'hours';
		$cache_ttl = ( 'days' === $cache_ttl_unit ) ? $cache_ttl_val * DAY_IN_SECONDS : $cache_ttl_val * HOUR_IN_SECONDS;

		// 1. Determine AI cap from settings
		$max_ai_count = isset( $settings['max_recommendation_count'] ) ? intval( $settings['max_recommendation_count'] ) : 5;
		$max_ai_count = max( 1, $max_ai_count );

		$ai_count = min( $count, $max_ai_count );

		// Check if background indexing or ranking is active/enabled (and not in cron worker context)
		$is_cron_ranking = ! empty( $settings['enable_cron_ranking'] ) && ! Cron::$is_running_worker;

		if ( self::has_unindexed_posts() || $is_cron_ranking ) {
			$reason = self::has_unindexed_posts() ? 'Indexing' : 'Background ranking';
			\pishtop_log( 'INFO', "{$reason} in progress. Returning native fallback directly for post {$post_id}." );
			$fallback_ids = self::get_native_fallback( $post_id, $count, $post_type );
			return self::apply_final_sorting( $fallback_ids );
		}

		// 2. Fetch AI suggestions (either from cache or by querying API)
		$ai_ids = [];
		if ( $ai_count > 0 ) {
			$transient_key = "pishtop_rec_{$post_id}_{$template_id}_" . sanitize_key( $post_type );
			$transient_key = apply_filters( 'pishtop_ai_recommendations_transient_key', $transient_key, $post_id, $template_id, $post_type );
			$cached_ids    = get_transient( $transient_key );

			if ( false !== $cached_ids && is_array( $cached_ids ) ) {
				$ai_ids = array_slice( $cached_ids, 0, $ai_count );
			} else {
				// Cache miss.
				// Mutex Cache Stampede Protection
				$lock_key = "pishtop_lock_{$post_id}";
				$is_locked = get_transient( $lock_key );

				if ( $is_locked ) {
					\pishtop_log( 'INFO', "Mutex lock active for post {$post_id}. Returning native fallback." );
					$ai_ids = self::get_native_fallback( $post_id, $ai_count, $post_type );
				} else {
					// Acquire Lock
					$lock_ttl = isset( $settings['mutex_lock_ttl'] ) ? intval( $settings['mutex_lock_ttl'] ) : 60;
					set_transient( $lock_key, true, $lock_ttl );

					// Fetch $max_ai_count posts from AI
					$api_ids = self::retrieve_and_rank( $post_id, $max_ai_count, $post_type );

					// Release Lock
					delete_transient( $lock_key );

					if ( ! is_wp_error( $api_ids ) && is_array( $api_ids ) ) {
						set_transient( $transient_key, $api_ids, $cache_ttl );
						$ai_ids = array_slice( $api_ids, 0, $ai_count );
					} else {
						// Cache failure for 300 seconds to protect site speed
						set_transient( $transient_key, [], 300 );
						$ai_ids = self::get_native_fallback( $post_id, $ai_count, $post_type );
					}
				}
			}
		}

		// 3. Fetch fallback suggestions if requested count exceeds AI or cached results
		$fallback_count = $count - count( $ai_ids );
		$fallback_ids = [];
		if ( $fallback_count > 0 ) {
			// Exclude the current post ID and all IDs already recommended by AI
			$fallback_ids = self::get_native_fallback( $post_id, $fallback_count, $post_type, $ai_ids );
		}

		// 4. Sort each group independently to keep AI recommendations on top
		$sorted_ai_ids       = self::apply_final_sorting( $ai_ids );
		$sorted_fallback_ids = self::apply_final_sorting( $fallback_ids );

		return array_merge( $sorted_ai_ids, $sorted_fallback_ids );
	}

	/**
	 * Internal logic to retrieve, score, and re-rank candidate recommendations.
	 */
	private static function retrieve_and_rank( int $post_id, int $count, string $post_type = '' ) {
		$settings = get_option( 'pishtop_ai_settings', [] );
		$emb_model   = ! empty( $settings['embedding_model'] ) ? $settings['embedding_model'] : 'openai/text-embedding-3-small';
		$rank_model  = ! empty( $settings['ranking_model'] ) ? $settings['ranking_model'] : 'google/gemini-2.5-flash';
		$sql_ceiling = isset( $settings['max_pre_filtered_candidates'] ) ? intval( $settings['max_pre_filtered_candidates'] ) : 500;
		$sim_limit   = isset( $settings['similarity_candidate_count'] ) ? intval( $settings['similarity_candidate_count'] ) : 50;

		$lang = self::get_post_language( $post_id );

		// 1. Get or generate current post embedding vector
		$current_vector = null;
		$stored = Database::get_embedding( $post_id );

		if ( $stored && $stored['model'] === $emb_model ) {
			$current_vector = $stored['embedding'];
		} else {
			// Generate embedding
			$text = self::build_post_text( $post_id );
			if ( empty( $text ) ) {
				return new \WP_Error( 'empty_text', 'No indexable content found.' );
			}
			$vector = API::get_embedding( $text, $emb_model );
			if ( is_wp_error( $vector ) ) {
				return $vector;
			}
			Database::save_embedding( $post_id, $lang, $emb_model, $vector );
			$current_vector = $vector;
		}

		if ( ! is_array( $current_vector ) ) {
			return new \WP_Error( 'no_vector', 'Failed to retrieve or generate vector.' );
		}

		// 2. Fetch candidates matching language, post type, and active model
		$candidates = Database::get_candidates( $post_id, $lang, $emb_model, $sql_ceiling, $post_type );
		if ( empty( $candidates ) ) {
			return [];
		}

		// 3. Compute cosine similarities in PHP
		$scored = [];
		foreach ( $candidates as $candidate ) {
			$score = self::cosine_similarity( $current_vector, $candidate['embedding'] );
			$scored[] = [
				'id'    => $candidate['post_id'],
				'score' => $score,
			];
		}

		// Sort by similarity score descending
		usort( $scored, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		// Slice top similarity candidates
		$top_similarity = array_slice( $scored, 0, $sim_limit );
		$top_ids = array_column( $top_similarity, 'id' );

		// 4. Send top similarity matches to OpenRouter LLM for final re-ranking
		// Prepare candidate data based on selected ranking fields for prompt context
		$rank_fields = $settings['ranking_fields'] ?? [ 'title', 'excerpt' ];
		$candidates_data = [];
		foreach ( $top_ids as $cand_id ) {
			$cand_post = get_post( $cand_id );
			if ( $cand_post ) {
				$cand_item = [ 'id' => $cand_id ];
				if ( in_array( 'title', $rank_fields, true ) ) {
					$cand_item['title'] = get_the_title( $cand_post );
				}
				if ( in_array( 'excerpt', $rank_fields, true ) ) {
					$cand_item['excerpt'] = get_the_excerpt( $cand_post );
				}
				if ( in_array( 'content', $rank_fields, true ) ) {
					$cand_item['content'] = wp_strip_all_tags( $cand_post->post_content );
				}
				$candidates_data[] = $cand_item;
			}
		}

		$current_post = get_post( $post_id );
		$current_data = [];
		if ( in_array( 'title', $rank_fields, true ) ) {
			$current_data['title'] = get_the_title( $current_post );
		}
		if ( in_array( 'excerpt', $rank_fields, true ) ) {
			$current_data['excerpt'] = get_the_excerpt( $current_post );
		}
		if ( in_array( 'content', $rank_fields, true ) ) {
			$current_data['content'] = wp_strip_all_tags( $current_post->post_content );
		}

		// Call re-rank API
		$ranked_ids = API::rerank_candidates( $current_data, $candidates_data, $rank_model, $count );

		if ( is_wp_error( $ranked_ids ) ) {
			// Fallback to top cosine similarity results if LLM re-ranking fails
			return array_slice( $top_ids, 0, $count );
		}

		// 5. Fill remaining slots if API returned less than requested count
		if ( count( $ranked_ids ) < $count ) {
			foreach ( $top_ids as $cand_id ) {
				if ( ! in_array( $cand_id, $ranked_ids, true ) ) {
					$ranked_ids[] = $cand_id;
					if ( count( $ranked_ids ) >= $count ) {
						break;
					}
				}
			}
		}

		return array_slice( $ranked_ids, 0, $count );
	}

	/**
	 * Build concatenated post textual data for embedding generation.
	 */
	public static function build_post_text( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$settings = get_option( 'pishtop_ai_settings', [] );
		$fields = $settings['embedding_fields'] ?? [ 'title', 'excerpt' ];

		$chunks = [];

		if ( in_array( 'title', $fields, true ) ) {
			$chunks[] = get_the_title( $post );
		}

		if ( in_array( 'excerpt', $fields, true ) ) {
			$chunks[] = get_the_excerpt( $post );
		}

		if ( in_array( 'content', $fields, true ) ) {
			$chunks[] = wp_strip_all_tags( $post->post_content );
		}

		if ( in_array( 'taxonomies', $fields, true ) ) {
			$taxonomies = get_object_taxonomies( $post->post_type );
			$tax_chunks = [];
			foreach ( $taxonomies as $taxonomy ) {
				$terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'names' ] );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$tax_chunks[] = $taxonomy . ': ' . implode( ', ', $terms );
				}
			}
			if ( ! empty( $tax_chunks ) ) {
				$chunks[] = implode( ' | ', $tax_chunks );
			}
		}

		if ( in_array( 'custom_fields', $fields, true ) ) {
			$meta = get_post_meta( $post_id );
			$meta_chunks = [];
			// Filter out internal hidden keys
			foreach ( $meta as $key => $values ) {
				if ( strpos( $key, '_' ) !== 0 && ! empty( $values[0] ) ) {
					$meta_chunks[] = $key . ': ' . $values[0];
				}
			}
			if ( ! empty( $meta_chunks ) ) {
				$chunks[] = 'Metadata: ' . implode( ', ', $meta_chunks );
			}
		}

		$concatenated = implode( "\n\n", array_filter( $chunks ) );
		return apply_filters( 'pishtop_ai_post_text', wp_strip_all_tags( $concatenated ), $post_id );
	}

	public static function get_native_fallback( int $post_id, int $count, string $post_type = '', array $exclude_ids = [] ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$settings = get_option( 'pishtop_ai_settings', [] );
		$fallback_behavior = $settings['default_fallback'] ?? 'category';

		if ( 'hide' === $fallback_behavior ) {
			return [];
		}

		$post_type = ! empty( $post_type ) ? sanitize_key( $post_type ) : $post->post_type;

		$args = [
			'post_type'      => $post_type,
			'posts_per_page' => $count,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			'post__not_in'   => array_merge( [ $post_id ], $exclude_ids ),
			'fields'         => 'ids',
			'post_status'    => 'publish',
		];

		// Hide out of stock items in fallback if WooCommerce setting is enabled
		if ( 'product' === $post_type && class_exists( 'WooCommerce' ) && 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = [
				[
					'key'     => '_stock_status',
					'value'   => 'outofstock',
					'compare' => '!=',
				],
			];
		}

		// Polylang/WPML language filtering
		$lang = self::get_post_language( $post_id );
		if ( ! empty( $lang ) ) {
			if ( function_exists( 'pll_get_post_language' ) ) {
				$args['lang'] = $lang;
			} elseif ( class_exists( 'SitePress' ) ) {
				// WPML will automatically filter queries based on language if SitePress is active
			}
		}

		if ( 'category' === $fallback_behavior ) {
			$taxonomies = get_object_taxonomies( $post_type );
			$tax_query = [];
			foreach ( $taxonomies as $taxonomy ) {
				$terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$tax_query[] = [
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $terms,
					];
				}
			}
			if ( ! empty( $tax_query ) ) {
				if ( count( $tax_query ) > 1 ) {
					$tax_query['relation'] = 'OR';
				}
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				$args['tax_query'] = $tax_query;
			}
		}

		$query = new \WP_Query( $args );
		$ids = $query->posts;

		return $ids;
	}

	/**
	 * Retrieve post language code for WPML or Polylang.
	 */
	public static function get_post_language( int $post_id ) {
		// Polylang
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post_id );
			return $lang ? $lang : '';
		}

		// WPML
		if ( function_exists( 'wpml_get_language_information' ) ) {
			$info = wpml_get_language_information( $post_id );
			if ( ! is_wp_error( $info ) && ! empty( $info['language_code'] ) ) {
				return $info['language_code'];
			}
		}

		// Fallback
		return '';
	}

	/**
	 * Clear cached transients for a post.
	 */
	public static function clear_cache( int $post_id ) {
		global $wpdb;
		// Delete all transients matching pishtop_rec_{post_id}_*
		$wildcard = '_transient_pishtop_rec_' . $post_id . '_%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wildcard ) );
	}

	/**
	 * Check if there are any unindexed posts in the database.
	 */
	public static function has_unindexed_posts() {
		global $wpdb;
		$settings = get_option( 'pishtop_ai_settings', [] );
		$allowed_types = ! empty( $settings['indexed_post_types'] ) ? $settings['indexed_post_types'] : [ 'post' ];
		$emb_model = ! empty( $settings['embedding_model'] ) ? $settings['embedding_model'] : 'openai/text-embedding-3-small';
		
		$placeholders = implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) );
		
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->prefix}pishtop_post_embeddings emb ON p.ID = emb.post_id AND emb.embedding_model = %s
			 WHERE p.post_status = 'publish' AND p.post_type IN ($placeholders) AND emb.post_id IS NULL
			 LIMIT 1",
			array_merge( [ $emb_model ], $allowed_types )
		);
		// phpcs:enable
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$unindexed_exists = $wpdb->get_var( $query );
		
		return ! empty( $unindexed_exists );
	}

	/**
	 * Apply configured final output sorting (random, date_desc, date_asc, title_asc) to the recommendation IDs.
	 */
	public static function apply_final_sorting( array $ids ) {
		if ( empty( $ids ) ) {
			return [];
		}

		$settings = get_option( 'pishtop_ai_settings', [] );
		$sort_option = $settings['final_output_sort'] ?? 'similarity';

		if ( 'similarity' === $sort_option ) {
			return $ids;
		}

		if ( 'random' === $sort_option ) {
			shuffle( $ids );
			return $ids;
		}

		$posts_to_sort = get_posts( [
			'post__in'       => $ids,
			'orderby'        => 'post__in',
			'posts_per_page' => -1,
			'post_type'      => 'any',
		] );

		if ( 'date_desc' === $sort_option ) {
			usort( $posts_to_sort, function( $a, $b ) {
				return strcmp( $b->post_date, $a->post_date );
			} );
		} elseif ( 'date_asc' === $sort_option ) {
			usort( $posts_to_sort, function( $a, $b ) {
				return strcmp( $a->post_date, $b->post_date );
			} );
		} elseif ( 'title_asc' === $sort_option ) {
			usort( $posts_to_sort, function( $a, $b ) {
				return strcasecmp( $a->post_title, $b->post_title );
			} );
		}

		return array_map( function( $p ) {
			return $p->ID;
		}, $posts_to_sort );
	}
}
