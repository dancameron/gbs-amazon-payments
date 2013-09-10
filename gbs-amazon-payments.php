<?php
/*
 * Plugin Name: Group Buying Payment Gateway - Amazon
 * Version: 1
 * Plugin URI: http://sproutventure.com/wordpress/group-buying
 * Description: Amazon payment gateway for group-buying.
 * Author: Sprout Venture
 * Author URI: http://sproutventure.com/wordpress
 * Plugin Author: Sprout Venture
 * Contributors: jbrinley
 * Text Domain: group-buying
*/

if ( !function_exists('load_gbs_amazon_payments') ) {
	function load_gbs_amazon_payments() {
		require_once('Group_Buying_Amazon_FPS.php');
		Group_Buying_Amazon_FPS::register();
		//set_include_path( get_include_path() . PATH_SEPARATOR . './Amazon' );
		//spl_autoload_register('gbs_amazon_payments_autoload');
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
	add_action('gb_register_processors', 'load_gbs_amazon_payments');
}
