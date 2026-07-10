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

		// 2. Fetch AI suggestions (either from cache or by querying API)
		$ai_ids = [];
		if ( $ai_count > 0 ) {
			$transient_key = "pishtop_rec_{$post_id}_{$template_id}_" . sanitize_key( $post_type );
			$transient_key = apply_filters( 'pishtop_ai_recommendations_transient_key', $transient_key, $post_id, $template_id, $post_type );
			
			$enable_cache  = ! isset( $settings['enable_cache'] ) || ! empty( $settings['enable_cache'] );
			$cached_ids    = $enable_cache ? get_transient( $transient_key ) : false;

			if ( false !== $cached_ids && is_array( $cached_ids ) ) {
				$ai_ids = array_slice( $cached_ids, 0, $ai_count );
			} else {
				// Cache miss.
				if ( self::has_unindexed_posts() ) {
					\pishtop_log( 'INFO', "Index status incomplete: some published posts are missing embedding vectors. Falling back to native sorting for post ID {$post_id}." );
					$ai_ids = self::get_native_fallback( $post_id, $ai_count, $post_type );
				} else {
					// Mutex Cache Stampede Protection
					$lock_key = "pishtop_lock_{$post_id}";
					$is_locked = get_transient( $lock_key );

					if ( $is_locked ) {
						\pishtop_log( 'INFO', "Mutex lock active for post ID {$post_id}: another request is currently generating recommendations for this post. Returning native fallback suggestions to avoid cache stampede." );
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
							if ( $enable_cache ) {
								set_transient( $transient_key, $api_ids, $cache_ttl );
							}
							$ai_ids = array_slice( $api_ids, 0, $ai_count );
						} else {
							// Cache failure for 300 seconds to protect site speed
							if ( $enable_cache ) {
								set_transient( $transient_key, [], 300 );
							}
							$ai_ids = self::get_native_fallback( $post_id, $ai_count, $post_type );
						}
					}
				}
			}
		}

		// 3. Fetch fallback suggestions if requested count exceeds AI or cached results
		$enable_llm = ! isset( $settings['enable_llm_reranking'] ) || ! empty( $settings['enable_llm_reranking'] );
		$shortfall_behavior = $settings['llm_shortfall_behavior'] ?? 'fill_similarity';
		$fallback_count = $count - count( $ai_ids );
		$fallback_ids = [];
		if ( $fallback_count > 0 ) {
			// Only fetch native fallback if LLM is disabled, OR if shortfall behavior is set to fill
			if ( ! $enable_llm || 'fill_similarity' === $shortfall_behavior ) {
				$fallback_ids = self::get_native_fallback( $post_id, $fallback_count, $post_type, $ai_ids );
			}
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
		$is_dynamic = false;
		if ( class_exists( 'WooCommerce' ) ) {
			$cart_page_id = wc_get_page_id( 'cart' );
			$checkout_page_id = wc_get_page_id( 'checkout' );
			if ( $post_id === $cart_page_id || $post_id === $checkout_page_id ) {
				$is_dynamic = true;
			}
		}

		$stored = $is_dynamic ? null : Database::get_embedding( $post_id );

		if ( $stored && $stored['model'] === $emb_model ) {
			$current_vector = $stored['embedding'];
		} else {
			// Generate embedding
			$text = self::build_post_text( $post_id );
			if ( empty( $text ) ) {
				return new \WP_Error( 'empty_text', 'No indexable content found.' );
			}
			$vector = API::get_embedding( $text, $emb_model, $post_id );
			if ( is_wp_error( $vector ) ) {
				\pishtop_log( 'ERROR', "Failed to generate embedding vector for post ID {$post_id} using model {$emb_model}: " . $vector->get_error_message() );
				return $vector;
			}
			if ( ! $is_dynamic ) {
				Database::save_embedding( $post_id, $lang, $emb_model, $vector );
				\pishtop_log( 'INFO', "Generated and saved new embedding vector cache for post ID {$post_id} using model {$emb_model}." );
			} else {
				\pishtop_log( 'INFO', "Generated dynamic embedding vector for temporary context post ID {$post_id} using model {$emb_model}." );
			}
			$current_vector = $vector;
		}

		if ( ! is_array( $current_vector ) ) {
			return new \WP_Error( 'no_vector', 'Failed to retrieve or generate vector.' );
		}

		// 2. Fetch candidates matching language, post type, and active model
		$candidates = Database::get_candidates( $post_id, $lang, $emb_model, $sql_ceiling, $post_type );
		\pishtop_log( 'INFO', sprintf( "Fetched %d matching embedding candidates from database for post ID %d (language: %s, post type filter: %s).", count( $candidates ), $post_id, $lang, $post_type ?: 'any' ) );
		if ( empty( $candidates ) ) {
			return [];
		}

		// 3. Compute cosine similarities in PHP
		$scored = [];
		$enable_llm = ! isset( $settings['enable_llm_reranking'] ) || ! empty( $settings['enable_llm_reranking'] );
		$threshold = ( isset( $settings['similarity_threshold_percent'] ) ? intval( $settings['similarity_threshold_percent'] ) : 40 ) / 100.0;

		foreach ( $candidates as $candidate ) {
			$score = self::cosine_similarity( $current_vector, $candidate['embedding'] );
			if ( $enable_llm || $score >= $threshold ) {
				$scored[] = [
					'id'    => $candidate['post_id'],
					'score' => $score,
				];
			}
		}

		// Sort by similarity score descending
		usort( $scored, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		if ( ! $enable_llm ) {
			// Embedding-only phase: return top sorted candidates directly
			$result_ids = array_column( $scored, 'id' );
			$final_ids = array_slice( $result_ids, 0, $count );
			\pishtop_log( 'INFO', sprintf( "Vector similarity check completed for post ID %d. Found %d candidates above similarity threshold of %d%%. Selected top recommendations: [%s].", $post_id, count( $scored ), intval( $threshold * 100 ), implode( ',', $final_ids ) ) );
			return $final_ids;
		}

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
		\pishtop_log( 'INFO', sprintf( "Sending %d candidate suggestions to OpenRouter LLM (%s) for final re-ranking for post ID %d.", count( $candidates_data ), $rank_model, $post_id ) );
		$ranked_ids = API::rerank_candidates( $current_data, $candidates_data, $rank_model, $count, $post_id );

		if ( is_wp_error( $ranked_ids ) ) {
			$fallback_ids = array_slice( $top_ids, 0, $count );
			\pishtop_log( 'WARNING', sprintf( "LLM re-ranking failed for post ID %d: %s. Falling back to top vector similarity matches: [%s].", $post_id, $ranked_ids->get_error_message(), implode( ',', $fallback_ids ) ) );
			return $fallback_ids;
		}

		\pishtop_log( 'INFO', sprintf( "LLM re-ranking completed successfully for post ID %d. Selected IDs: [%s].", $post_id, implode( ',', $ranked_ids ) ) );

		// 5. Handle shortfall behavior if API returned less than requested count
		$shortfall_behavior = $settings['llm_shortfall_behavior'] ?? 'fill_similarity';

		if ( count( $ranked_ids ) < $count && 'fill_similarity' === $shortfall_behavior ) {
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
		$fallback_behavior = $settings['default_fallback'] ?? 'recent';

		if ( 'hide' === $fallback_behavior ) {
			return [];
		}

		$post_type = ! empty( $post_type ) ? sanitize_key( $post_type ) : $post->post_type;

		$exclude = array_unique( array_merge( [ $post_id ], $exclude_ids ) );
		$args = [
			'post_type'      => $post_type,
			'posts_per_page' => $count,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			'post__not_in'   => $exclude,
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
			}
		}

		$query = new \WP_Query( $args );
		return ! empty( $query->posts ) ? $query->posts : [];
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
		$cached = wp_cache_get( 'pishtop_has_unindexed', 'pishtop_posts' );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

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
		
		$res = ! empty( $unindexed_exists );
		wp_cache_set( 'pishtop_has_unindexed', $res, 'pishtop_posts', 300 );
		return $res;
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
		$allowed_types = ! empty( $settings['indexed_post_types'] ) ? $settings['indexed_post_types'] : [ 'post' ];

		$posts_to_sort = get_posts( [
			'post__in'       => $ids,
			'orderby'        => 'post__in',
			'posts_per_page' => -1,
			'post_type'      => $allowed_types,
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
