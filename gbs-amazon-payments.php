<?php
/*
 * Plugin Name: Group Buying Payment Gateway - Amazon
 * Version: 1
 * Plugin URI: http://sproutventure.com/wordpress/group-buying
 * Description: Amazon payment gateway for group-buying.
 * Author: Sprout Venture
 * Author URI: http://sproutventure.com/wordpress
 * Plugin Author: Jordan Lovato
 * Contributors: Jordan Lovato
 * Text Domain: group-buying
*/

if ( !function_exists('load_gbs_amazon_payments') ) {
	function load_gbs_amazon_payments() {
		if ( check_deps() ) {
			set_include_path( get_include_path() . PATH_SEPARATOR . './Amazon' );
			spl_autoload_register('gbs_amazon_payments_autoload');
		}
	}

	function check_deps() {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		if ( !is_plugin_active( 'user-groups/user-groups.php' ) ) {

			// Deactivate this plugin if user-groups isn't active
			$error = new WP_Error( 'dependencies', __( sprintf(
				'Please activate User Groups before activating User Groups Extender (%s)',
				"<a href='".admin_url('plugins.php')."'>return</a>"
			) ) );

			wp_die( $error->get_error_message() );
		}

		return TRUE;
	}

	function gbs_amazon_payments_autoload( $class_name ) {
		$c_dir = dirname(__FILE__);
		$include_paths = array(
			$c_dir.DIRECTORY_SEPARATOR,
		);

		foreach ( $include_paths as $path ) {
			if ( file_exists($path.$class_name.'.php') ) {
				include_once($path.$class_name.'.php');
			}
		}
	}

	load_gbs_amazon_payments(); // Main screen turn on.
} else {
	$error = new WP_Error( 'init', __( sprintf(
		'Could not activate: plugin namespace ambiguity',
		"<a href='".admin_url('plugins.php')."'>return</a>"
	) ) );

	wp_die( $error->get_error_message() );
}