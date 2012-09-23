<?php
/*
Plugin Name: Uploads by Proxy
Plugin URI: http://brainstormmedia.com
Description: Load images from live site if missing in development environment.
Version: 1.0
Author: Brainstorm Media
Author URI: http://brainstormmedia.com
*/

/**
 * Copyright (c) 2012 Brainstorm Media. All rights reserved.
 *
 * Released under the GPL license
 * http://www.opensource.org/licenses/gpl-license.php
 *
 * This is an add-on for WordPress
 * http://wordpress.org/
 *
 * **********************************************************************
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * **********************************************************************
 */

/*
	Settings & Filters:
	// Set up a proxy in /wp-config.php
	// Disable & Enable plugin after putting in theme or plugin
	define('UBP_PROXY', 'proxy.domain.com' );

	// Set up a proxy in /wp-content/THEME/functions.php
	// Disable & Enable plugin after putting in theme or plugin
	add_filter('ubp_proxy', create_function('', 'return "proxy.website.com";'));

	// Force disable of .htaccess rewrite rules in /wp-content/THEME/functions.php
	// Disable & Enable plugin after putting in theme or plugin
	add_filter('ubp_remove_rewrite_rules', '__return_true');

	// Add a custom source for IP address lookups
	add_filter('ubp_ip_url', 'ubp_ip_url', 10, 2);
	function ubp_ip_url( $url, $domain ) {
		return 'http://website.com/ip.php?domain='.$domain;
	}
*/


/**
 * Handle redirection with Apache rewrite rules
 * Requires UBP_PROXY point to a domain that had mod_rewrite and mod_proxy enabled.
 * Put the contents of htaccess-remote-proxy.txt in the remote root directory as .htaccess
 * Then put the address of your proxy in wp-config.php, like:
 *     define('UBP_PROXY', 'proxy.domain.com' );
 * 
 * Remote Proxy:   On
 * Local Rewrites: On
 * Speed:          Very Fast
 */
if ( !defined('UBP_PROXY') ) define('UBP_PROXY', false); // e.g., proxy.domain.com

$storm_uploads_by_proxy = new Storm_Uploads_by_Proxy();

class Storm_Uploads_by_Proxy {

	var $marker = 'Uploads by Proxy';
	var $expire = 86400;
	var $proxy;
	var $domain;

	function __construct() {

		register_activation_hook(   __FILE__, array($this, 'save_mod_rewrite_rules') ); 
		register_deactivation_hook( __FILE__, array($this, 'remove_rewrite_rules') ); 

		add_action('template_redirect', array($this, 'template_redirect'));
	}

	/**
	 * Handle redirection through WordPress 404
	 * 
	 * Remote Proxy:   On/Off
	 * Local Rewrites: Off
	 */
	function template_redirect(){
		// Only run on 404
		if ( !is_404() ) { return; }

		$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

		// Only run for whitelisted paths
		if ( !$this->allow_path($path) ) { return; }

		$this->redirect( $path );

		$this->stream( $path );
	}

	/**
	 * Redirect files to remote proxy
	 * 
	 * Remote Proxy:   On
	 * Local Rewrites: Off
	 * Speed:          Slow
	 */
	public function redirect( $path ) {
		if ( !$this->get_proxy() ) { return false; }

		global $wp_query;
		status_header( 200 );
		$wp_query->is_404 = false;

		header( 'Location: ' . $this->get_url( $path ), 302 );
		exit;
	}

	/**
	 * Stream files from publicly registered IP address through PHP
	 * 
	 * Remote Proxy:   Off
	 * Local Rewrites: Off
	 * Speed:          Very Slow
	 */
	public function stream( $path ) {
		require dirname(__FILE__).'/class-get-public-ip.php';

		$ip = new Storm_Get_Public_IP( $this->get_domain() );

		// Tell the remote IP that we're loading for a specific domain
		$args = array( 'headers' => array( 'Host' => $this->get_domain() ) );
		$url = 'http://'.$ip.$path;

		$response = wp_remote_get( $url, $args);

		if ( !is_wp_error($response) && 200 == $response['response']['code'] ) {
			global $wp_query;
			status_header( 200 );
			$wp_query->is_404 = false;

			foreach( $response['headers'] as $name => $value ){
				header( "$name: $value" );
			}

			echo $response['body'];

			exit;
		}
	}

	public function get_rewrite_rules() {
		if ( false == $this->get_proxy() ) {
			return false;
		}

		$rules_file = plugin_dir_path(__FILE__) . 'htaccess-rewrite-rules.txt';

		if ( file_exists($rules_file) ) {
			$rules = file_get_contents( $rules_file );
			$rules = str_replace('UPLOADS', $this->uploads_basedir(), $rules);
			$rules = str_replace('PROXY', $this->get_proxy(), $rules);

			return $rules;
		}else {
			return false;
		}
	}

	public function remove_rewrite_rules() {
		$this->save_mod_rewrite_rules(true);
	}

	/**
	 * Only redirect for whitelisted paths
	 */
	public function allow_path( $path ) {
		if ( empty($path) ) { return false; }

		$allowed_paths = array(
			$this->uploads_basedir(),
		);
		$allowed_paths = apply_filters( 'stlu_allowed_paths', $allowed_paths );

		foreach ( $allowed_paths as $value ){
			if ( false !== strpos($path, $value) ) { return true; }
		}

		return false;
	}

	/**
	 * Return path to uploads folder, relative to WordPress root directory
	 * @var string
	 */
	public function uploads_basedir() {
		$uploads = wp_upload_dir();
		return str_replace( ABSPATH, '', $uploads['basedir'] );
	}

	public function get_proxy() {
		if( !isset($this->proxy) ){ $this->proxy = apply_filters( 'ubp_proxy', UBP_PROXY ); }
		return $this->proxy;
	}

	public function get_domain() {
		if( !isset($this->domain) ){ $this->domain = apply_filters( 'ubp_domain', $_SERVER['HTTP_HOST'] ); }
		return $this->domain;
	}

	public function get_url($path) {
		return 'http://' . trailingslashit( $this->get_proxy() ) . $this->get_domain() . $path;
	}

	/**
	 * Updates the htaccess file with the rewrite rules if it is writable.
	 *
	 * Always writes to the file if it exists and is writable to ensure that we
	 * blank out old rules.
	 */
	function save_mod_rewrite_rules( $remove=false ) {
		if ( is_multisite() || !apache_mod_loaded('mod_proxy') || !apache_mod_loaded('mod_rewrite') ) {
			return;
		}

		if ( !function_exists('got_mod_rewrite') ){ include ABSPATH.'/wp-admin/includes/misc.php'; }
		if ( !function_exists('get_home_path')   ){ include ABSPATH.'/wp-admin/includes/file.php'; }

		$remove = apply_filters( 'ubp_remove_rewrite_rules', $remove );
		$home_path = get_home_path();
		$htaccess_file = $home_path.'.htaccess';
		$rules = $this->get_rewrite_rules();

		// If the file doesn't already exist check for write access to the directory and whether we have some rules.
		// else check for write access to the file.
		if ((!file_exists($htaccess_file) && is_writable($home_path)) || is_writable($htaccess_file)) {
			if ( $remove || empty($rules) ) {
				return $this->prepend_with_markers( $htaccess_file, $this->marker, array() );
			}else {
				$rules = explode( "\n", $rules );
				return $this->prepend_with_markers( $htaccess_file, $this->marker, $rules );
			}
		}

		return false;
	}

	/**
	 * Based on WordPress misc.php::insert_with_markers
	 *
	 * Inserts an array of strings into a file (.htaccess ), placing it between
	 * BEGIN and END markers. Replaces existing marked info. Retains surrounding
	 * data. Creates file if none exists.
	 *
	 * @param unknown_type $filename
	 * @param unknown_type $marker
	 * @param unknown_type $insertion
	 * @return bool True on write success, false on failure.
	 */
	function prepend_with_markers( $filename, $marker, $insertion ) {
		if (!file_exists( $filename ) || is_writeable( $filename ) ) {
			if (!file_exists( $filename ) ) {
				$markerdata = '';
			} else {
				$markerdata = explode( "\n", implode( '', file( $filename ) ) );
			}

			if ( !$f = @fopen( $filename, 'w' ) )
				return false;

			$foundit = false;

			// Check if our code already exists
			if ( $markerdata ) {
				$state = true;
				foreach ( $markerdata as $n => $markerline ) {
					if (strpos($markerline, '# BEGIN ' . $marker) !== false)
						$state = false;
					if (strpos($markerline, '# END ' . $marker) !== false) {
						$state = true;
						$foundit = true;
					}
				}
			}

			// Moved this section before if ( $markerdata ) compared to WordPress version
			if (!$foundit) {
				fwrite( $f, "\n# BEGIN {$marker}\n" );
				foreach ( $insertion as $insertline )
					fwrite( $f, "{$insertline}\n" );
				fwrite( $f, "# END {$marker}\n" );
			}

			if ( $markerdata ) {
				$state = true;
				foreach ( $markerdata as $n => $markerline ) {
					if (strpos($markerline, '# BEGIN ' . $marker) !== false)
						$state = false;
					if ( $state ) {
						if ( $n + 1 < count( $markerdata ) )
							fwrite( $f, "{$markerline}\n" );
						else
							fwrite( $f, "{$markerline}" );
					}
					if (strpos($markerline, '# END ' . $marker) !== false) {
						fwrite( $f, "# BEGIN {$marker}\n" );
						if ( is_array( $insertion ))
							foreach ( $insertion as $insertline )
								fwrite( $f, "{$insertline}\n" );
						fwrite( $f, "# END {$marker}\n" );
						$state = true;
						$foundit = true;
					}
				}
			}
			
			fclose( $f );
			return true;
		} else {
			return false;
		}
	}
}