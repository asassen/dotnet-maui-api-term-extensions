<?php
/**
 * Plugin Name:       .NET MAUI API Extensions
 * Plugin URI:        https://yourdomain.com
 * Description:       Exposes, creates, and updates custom term meta data safely for the .NET MAUI mobile application.
 * Version:           1.0.0
 * Author:            Sassen Industries
 * License:           GPLv2 or later
 * Text Domain:       maui-api-ext
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin path constants for clean loading
define( 'MAUI_API_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Core activation logic loader
 */
function run_maui_api_extensions() {
    // Include the file containing the API endpoint classes
    require_once MAUI_API_PATH . 'includes/class-maui-rest-api.php';
    
    // Instantiate the class to register the routes
    if ( class_exists( 'Maui_REST_API_Endpoints' ) ) {
        $maui_api = new Maui_REST_API_Endpoints();
        $maui_api->init();
    }
}
add_action( 'plugins_loaded', 'run_maui_api_extensions' );
