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

	/**
	 * Get recommendation post IDs for a given post.
	 */
	public static function get_recommendations( int $post_id, int $count, string $template_id ) {
		$settings = get_option( 'pishtop_ai_settings', [] );
		$cache_ttl = isset( $settings['cache_ttl'] ) ? intval( $settings['cache_ttl'] ) * 3600 : 12 * 3600; // default 12 hours in seconds

		$transient_key = "pishtop_rec_{$post_id}_{$template_id}";
		$cached_ids    = get_transient( $transient_key );

		if ( false !== $cached_ids ) {
			return is_array( $cached_ids ) ? $cached_ids : [];
		}

		// Mutex Cache Stampede Protection
		$lock_key = "pishtop_lock_{$post_id}";
		$is_locked = get_transient( $lock_key );

		if ( $is_locked ) {
			// Return native fallback immediately
			\pishtop_log( 'INFO', "Mutex lock active for post {$post_id}. Returning native fallback." );
			return self::get_native_fallback( $post_id, $count );
		}

		// Acquire Mutex Lock (dynamic TTL from settings)
		$lock_ttl = isset( $settings['mutex_lock_ttl'] ) ? intval( $settings['mutex_lock_ttl'] ) : 60;
		set_transient( $lock_key, true, $lock_ttl );

		// Run retrieval process
		$ids = self::retrieve_and_rank( $post_id, $count );

		// Release Lock
		delete_transient( $lock_key );

		if ( ! is_wp_error( $ids ) && is_array( $ids ) ) {
			// Cache the result
			set_transient( $transient_key, $ids, $cache_ttl );
			return $ids;
		}

		// Return native fallback if error occurred
		return self::get_native_fallback( $post_id, $count );
	}

	/**
	 * Internal logic to retrieve, score, and re-rank candidate recommendations.
	 */
	private static function retrieve_and_rank( int $post_id, int $count ) {
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
		$candidates = Database::get_candidates( $post_id, $lang, $emb_model, $sql_ceiling );
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
		// Prepare candidate data (Title and Excerpt) for prompt context
		$candidates_data = [];
		foreach ( $top_ids as $cand_id ) {
			$cand_post = get_post( $cand_id );
			if ( $cand_post ) {
				$candidates_data[] = [
					'id'      => $cand_id,
					'title'   => get_the_title( $cand_post ),
					'excerpt' => get_the_excerpt( $cand_post ),
				];
			}
		}

		$current_post = get_post( $post_id );
		$current_data = [
			'title'   => get_the_title( $current_post ),
			'excerpt' => get_the_excerpt( $current_post ),
		];

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
		return wp_strip_all_tags( $concatenated );
	}

	/**
	 * Fallback native recommendations logic.
	 */
	public static function get_native_fallback( int $post_id, int $count ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$settings = get_option( 'pishtop_ai_settings', [] );
		$fallback_behavior = $settings['default_fallback'] ?? 'category';

		if ( 'hide' === $fallback_behavior ) {
			return [];
		}

		$args = [
			'post_type'      => $post->post_type,
			'posts_per_page' => $count,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			'post__not_in'   => [ $post_id ],
			'fields'         => 'ids',
			'post_status'    => 'publish',
		];

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
			$taxonomies = get_object_taxonomies( $post->post_type );
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
		return $query->posts;
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
}
