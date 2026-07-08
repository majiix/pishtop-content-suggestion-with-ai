<?php
/**
 * Plugin Name: PishTop Content Suggestion with AI
 * Plugin URI:  https://github.com/pishtop/pishtop-content-suggestion-with-ai
 * Description: AI-powered related post recommendations using OpenRouter.ai and local vector embeddings.
 * Version:     1.0.0
 * Author:      PishTop
 * License:     GPL2
 * Text Domain: pishtop-content-suggestion-with-ai
 * Requires PHP: 7.4
 * Requires Plugins: 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PISHTOP_AI_VERSION', '1.0.0' );
define( 'PISHTOP_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'PISHTOP_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'PISHTOP_AI_LOCK_TTL', 60 );

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
