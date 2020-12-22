<?php

namespace Automattic\VIP\Config;

class Site_Details_Index {
	/**
	 * Variable to hold the instance for the singleton.
	 *
	 * @var Site_Details_Index
	 */
	private static $_instance;

	/**
	 * The timestamp that will be used to determine the value of Site_Details_Index::get_current_timestamp().
	 * Used for mocking. Integer values set the timestamp for the instance to that value. Null means it uses the current timestamp.
	 *
	 * @var int|null
	 */
	private $timestamp = null;

	/**
	 * Standard singleton except accept a timestamp for mocking purposes.
	 *
	 * @param mixed $timestamp A fixed point in time to use for mocking.
	 * @return Site_Details_Index A Site_Details_Index object.
	 */
	public static function instance( $timestamp = null ) {
		if ( ! ( static::$_instance instanceof Site_Details_Index ) ) {
			static::$_instance = new Site_Details_Index();
			static::$_instance->set_current_timestamp( $timestamp );
			static::$_instance->init();
		}

		return static::$_instance;
	}

	public function init() {
		add_filter( 'vip_site_details_index_data', [ $this, 'set_env_and_core' ] );
	}

	/**
	 * Given the site details from the vip_site_details_index_data filter, set the environment and core values.
	 *
	 * @param array $site_details The current site details.
	 * @return array The new site details.
	 */
	public function set_env_and_core( $site_details ) {
		if ( ! is_array( $site_details ) ) {
			$site_details = array();
		}
		
		global $wp_version;

		$site_id = 0;
		if ( defined( 'FILES_CLIENT_SITE_ID' ) && FILES_CLIENT_SITE_ID ) {
			$site_id = FILES_CLIENT_SITE_ID;
		}

		$environment_name = '';
		if ( defined( 'VIP_GO_APP_ENVIRONMENT' ) && VIP_GO_APP_ENVIRONMENT ) {
			$environment_name = strval( VIP_GO_APP_ENVIRONMENT );
		}

		$site_details['timestamp'] = $this->get_current_timestamp();
		$site_details['client_site_id'] = $site_id;
		$site_details['environment_name'] = $environment_name;
		$site_details['core']['wp_version'] = strval( $wp_version );
		$site_details['core']['blog_id'] = get_current_blog_id();
		$site_details['core']['site_url'] = get_site_url();
		$site_details['core']['is_multisite'] = is_multisite();

		$site_details['plugins'] = $this->get_plugin_info();

		$site_details['search'] = $this->get_search_info();

		if ( class_exists( 'Jetpack' ) ) {
			$site_details['jetpack'] = $this->get_jetpack_info();
		}
		
		return $site_details;
	}

	/**
	 * Gather up all the information about all the plugins.
	 *
	 * Does not contain anything from vip-go-mu-plugins.
	 */
	public function get_plugin_info() {
		// Needed or get_plugins can't be found in some instances
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		$all_plugins = get_plugins();
		$active_plugins = get_option( 'active_plugins' );
		$plugins_enabled_via_code = wpcom_vip_get_filtered_loaded_plugins();

		$plugin_info = array();

		foreach ( $all_plugins as $key => $value ) {
			$active = in_array( $key, $active_plugins, true );
			$enabled_via_code = in_array( $key, $plugins_enabled_via_code, true );

			$plugin_info[] = array(
				'path' => $key,
				'name' => $value['Name'],
				'version' => $value['Version'],
				'active' => $active,
				'enabled_via_code' => $enabled_via_code,
			);
		}

		return $plugin_info;
	}

	/**
	 * Gather basic information about VIP Search for a site
	 */
	public function get_search_info() {
		$search_info = array();

		$search_info['enabled'] = ( defined( 'USE_VIP_ELASTICSEARCH' ) && USE_VIP_ELASTICSEARCH ) ||
			( defined( 'VIP_ENABLE_VIP_SEARCH' ) && true === VIP_ENABLE_VIP_SEARCH );

		$search_info['query_integration'] = ( defined( 'VIP_ENABLE_ELASTICSEARCH_QUERY_INTEGRATION' ) && true === VIP_ENABLE_ELASTICSEARCH_QUERY_INTEGRATION ) ||
			( defined( 'VIP_ENABLE_VIP_SEARCH_QUERY_INTEGRATION' ) && true === VIP_ENABLE_VIP_SEARCH_QUERY_INTEGRATION );

		return $search_info;
	}

	/**
	 * Gather all the information about Jetpack
	 */
	public function get_jetpack_info() {
		$jetpack_info = array();

		$jetpack_info['active'] = \Jetpack::is_active();
		$jetpack_info['id'] = \Jetpack::get_option( 'id' );
		$jetpack_info['version'] = JETPACK__VERSION;
		if ( defined( 'VIP_JETPACK_LOADED_VERSION' ) ) {
			$jetpack_info['vip_loaded_version'] = VIP_JETPACK_LOADED_VERSION;
		}
		$jetpack_info['active_modules'] = \Jetpack::get_active_modules();

		return $jetpack_info;
	}

	/**
	 * Get the site details for the site the code is running on.
	 */
	public function get_site_details() {
		/**
		 * Filter that can be hooked on to provide information to the Site Details Index.
		 *
		 * Example:
		 *
		 * array(
		 *     'client_site_id' => 4044,
		 *     'environment_id' => 4044,
		 *     'environment_name' => 'Testing Site',
		 *     'plugins' => array(
		 *         'WP-API' => '2.0-beta15',
		 *     ),
		 *     'core' => array(
		 *         'wp_version' => '5.5.1',
		 *         'blog_id' => 1,
		 *         'is_multisite' => false,
		 *     ),
		 *     'search' => array(
		 *         'enabled' => true,
		 *         'integration_enabled' => false,
		 *     ),
		 * )
		 *
		 * @hook vip_site_details_index_data
		 * @param {array} $site_details Default value for site details.
		 * @return {array} A nested array of site details.
		 */
		$site_details = apply_filters( 'vip_site_details_index_data', array() );

		return $site_details;
	}

	/**
	 * Returns the current value of $this->timestamp or time() if null.
	 *
	 * Used for mocking in tests.
	 *
	 * @return int The current timestamp or the value of $this->timestamp.
	 */
	public function get_current_timestamp() {
		return $this->timestamp ?? time();
	}

	/**
	 * Given a value, set the current timestamp to provided value if it's an integer.
	 *
	 * Used for mocking in tests.
	 */
	public function set_current_timestamp( $timestamp ) {
		if ( ! is_int( $timestamp ) ) {
			return;
		}

		$this->timestamp = $timestamp;
	}
}
