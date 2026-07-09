<?php
/**
 * Plugin Name: PishTop Content Suggestion with AI
 * Plugin URI:  https://wordpress.org/plugins/pishtop-content-suggestion-with-ai
 * Description: AI-powered related post recommendations using OpenRouter.ai and local vector embeddings.
 * Version:     1.3.0
 * Author:      micromax
 * License:     GPL2
 * Text Domain: pishtop-content-suggestion-with-ai
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PISHTOP_AI_VERSION', '1.3.0' );
define( 'PISHTOP_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'PISHTOP_AI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Verifies that the current request has administrative privileges and a valid nonce.
 *
 * @param string $action Nonce action name.
 * @param string $query_arg Request key for the nonce value.
 * @return bool True if request is verified, false otherwise.
 */
function pishtop_verify_admin_action( string $action = 'pishtop_admin_action', string $query_arg = 'nonce' ): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	$nonce = isset( $_REQUEST[ $query_arg ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ) ) : '';
	return (bool) wp_verify_nonce( $nonce, $action );
}

/**
 * Core helper function for logging.
 *
 * @param string $level   Log level (INFO, WARNING, ERROR, DEBUG).
 * @param string $message Log message.
 * @param mixed  $context Log context metadata.
 */
function pishtop_log( string $level, string $message, $context = null ) {
	\PishTop\AI\Database::add_log( $level, $message, $context );
}

/**
 * Core helper function to request embedding for a given text.
 *
 * @param string $text Input text.
 * @return array|\WP_Error Embedding vector array on success, WP_Error on failure.
 */
function pishtop_get_embedding( string $text ) {
	$settings = get_option( 'pishtop_ai_settings', [] );
	$model = ! empty( $settings['embedding_model'] ) ? $settings['embedding_model'] : 'openai/text-embedding-3-small';
	return \PishTop\AI\API::get_embedding( $text, $model );
}

// Simple Autoloader
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'PishTop\\AI\\' ) !== 0 ) {
		return;
	}
	$relative_class = strtolower( substr( $class, 11 ) );
	$map = [
		'database' => 'pishtop-db',
		'api'      => 'pishtop-api',
		'matching' => 'pishtop-matching',
		'admin'    => 'pishtop-admin',
		'frontend' => 'pishtop-frontend',
		'cron'     => 'pishtop-cron',
	];
	$file_slug = isset( $map[ $relative_class ] ) ? $map[ $relative_class ] : str_replace( '_', '-', $relative_class );
	$file = PISHTOP_AI_PATH . 'includes/class-' . $file_slug . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Activation & Deactivation Hooks
register_activation_hook( __FILE__, [ 'PishTop\\AI\\Database', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PishTop\\AI\\Cron', 'deactivate' ] );

// Init Plugin
add_action( 'plugins_loaded', function () {
	PishTop\AI\Admin::instance();
	PishTop\AI\Frontend::instance();
	PishTop\AI\Cron::instance();
} );
