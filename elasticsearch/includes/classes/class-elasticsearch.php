<?php

namespace Automattic\VIP\Elasticsearch;

use \WP_CLI;

class Elasticsearch {
	public $healthcheck;

	/**
	 * Initialize the VIP Search plugin
	 */
	public function init() {
		$this->setup_constants();
		$this->setup_hooks();
		$this->load_dependencies();
		$this->load_commands();
		$this->setup_healthchecks();
	}

	protected function load_dependencies() {
		/**
		 * Load ES Health command class
		 */
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/commands/class-healthcommand.php';
		}

		// Load ElasticPress
		require_once __DIR__ . '/../../elasticpress/elasticpress.php';

		// Load health check cron job
		require_once __DIR__ . '/class-health-job.php';

		// Load our custom dashboard
		require_once __DIR__ . '/class-dashboard.php';
	}

	protected function setup_constants() {
		// Ensure we limit bulk indexing chunk size to a reasonable number (no limit by default)
		if ( ! defined( 'EP_SYNC_CHUNK_LIMIT' ) ) {
			define( 'EP_SYNC_CHUNK_LIMIT', 500 );
		}

		if ( ! defined( 'EP_HOST' ) && defined( 'VIP_ELASTICSEARCH_ENDPOINTS' ) && is_array( VIP_ELASTICSEARCH_ENDPOINTS ) ) {
			$host = VIP_ELASTICSEARCH_ENDPOINTS[ 0 ];

			define( 'EP_HOST', $host );
		}

		if ( ! defined( 'ES_SHIELD' ) && ( defined( 'VIP_ELASTICSEARCH_USERNAME' ) && defined( 'VIP_ELASTICSEARCH_PASSWORD' ) ) ) {
			define( 'ES_SHIELD', sprintf( '%s:%s', VIP_ELASTICSEARCH_USERNAME, VIP_ELASTICSEARCH_PASSWORD ) );
		}

		// Do not allow sync via Dashboard (WP-CLI is preferred for indexing).
		// The Dashboard is hidden anyway but just in case.
		if ( ! defined( 'EP_DASHBOARD_SYNC' ) ) {
			define( 'EP_DASHBOARD_SYNC', false );
		}
	}

	protected function setup_hooks() {
		add_action( 'plugins_loaded', [ $this, 'action__plugins_loaded' ] );

		add_filter( 'ep_index_name', [ $this, 'filter__ep_index_name' ], PHP_INT_MAX, 3 ); // We want to enforce the naming, so run this really late.

		// Override default per page value set in elasticpress/includes/classes/Indexable.php
		add_filter( 'ep_bulk_items_per_page', [ $this, 'filter__ep_bulk_items_per_page' ], PHP_INT_MAX );

		// Network layer replacement to use VIP helpers (that handle slow/down upstream server)
		add_filter( 'ep_intercept_remote_request', '__return_true', 9999 );
		add_filter( 'ep_do_intercept_request', [ $this, 'filter__ep_do_intercept_request' ], 9999, 4 );
		add_filter( 'jetpack_active_modules', [ $this, 'filter__jetpack_active_modules' ], 9999 );

		// Filter jetpack widgets
		add_filter( 'jetpack_widgets_to_include', [ $this, 'filter__jetpack_widgets_to_include' ], 10 );

		// Disable query integration by default
		add_filter( 'ep_skip_query_integration', array( __CLASS__, 'ep_skip_query_integration' ), 5 );
		add_filter( 'ep_skip_user_query_integration', array( __CLASS__, 'ep_skip_query_integration' ), 5 );
	}

	protected function load_commands() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'vip-es health', __NAMESPACE__ . '\Commands\HealthCommand' );
		}
	}

	protected function setup_healthchecks() {
		$this->healthcheck = new HealthJob();
	
		// Hook into init action to ensure cron-control has already been loaded
		add_action( 'init', [ $this->healthcheck, 'init' ] );
	}

	public function action__plugins_loaded() {
		// Conditionally load only if either/both Query Monitor and Debug Bar are loaded and enabled
		// NOTE - must hook in here b/c the wp_get_current_user function required for checking if debug bar is enabled isn't loaded earlier
		if ( apply_filters( 'debug_bar_enable', false ) || apply_filters( 'wpcom_vip_qm_enable', false ) ) {
			// Load ElasticPress Debug Bar
			require_once __DIR__ . '/../../debug-bar-elasticpress/debug-bar-elasticpress.php';

			// And ensure the logging has been setup (since it also hooks on plugins_loaded)
			if ( function_exists( 'ep_setup_query_log' ) ) {
				ep_setup_query_log();
			}
		}
	}

	/**
	 * Filter ElasticPress index name if using VIP ES infrastructure
	 */
	public function filter__ep_index_name( $index_name, $blog_id, $indexables ) {
		// TODO: Use FILES_CLIENT_SITE_ID for now as VIP_GO_ENV_ID is not ready yet. Should replace once it is.
		$index_name = sprintf( 'vip-%s-%s', FILES_CLIENT_SITE_ID, $indexables->slug );

		// $blog_id won't be present on global indexes (such as users)
		if ( $blog_id ) {
			$index_name .= sprintf( '-%s', $blog_id );
		}

		return $index_name;
	}

	/**
	 * Filter to set ep_bulk_items_per_page to 500
	 */
	public function filter__ep_bulk_items_per_page() {
		return 500;
	}

	public function filter__ep_do_intercept_request( $request, $query, $args, $failures ) {
		$fallback_error = new \WP_Error( 'vip-elasticsearch-upstream-request-failed', 'There was an error connecting to the upstream Elasticsearch server' );

		$timeout = $this->get_http_timeout_for_query( $query );

		// Add custom headers to identify authorized traffic
		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = [];
		}
		$args['headers'] = array_merge( $args['headers'], array( 'X-Client-Site-ID' => FILES_CLIENT_SITE_ID, 'X-Client-Env' => VIP_GO_ENV ) );
		$request = vip_safe_wp_remote_request( $query['url'], $fallback_error, 3, $timeout, 20, $args );
	
		return $request;
	}

	public function get_http_timeout_for_query( $query ) {
		$timeout = 2;

		// If query url ends with '_bulk'
		$query_path = wp_parse_url( $query[ 'url' ], PHP_URL_PATH );

		if ( wp_endswith( $query_path, '_bulk' ) ) {
			// Bulk index request so increase timeout
			$timeout = 5;
		}

		return $timeout;
	}

	public function filter__jetpack_active_modules( $modules ) {
		// Filter out 'search' from the active modules. We use array_filter() to get _all_ instances, as it could be present multiple times
		$filtered = array_filter ( $modules, function( $module ) {
			if ( 'search' === $module ) {
				return false;
			}
			return true;
		} );

		// array_filter() preserves keys, so to get a clean / flat array we must pass it through array_values()
		return array_values( $filtered );
	}

	public function filter__jetpack_widgets_to_include( $widgets ) {
		if ( ! is_array( $widgets ) ) {
			return $widgets;
		}

		foreach( $widgets as $index => $file ) {
			// If the Search widget is included and it's active on a site, it will automatically re-enable the Search module,
			// even though we filtered it to off earlier, so we need to prevent it from loading
			if( wp_endswith( $file, '/jetpack/modules/widgets/search.php' ) ) {
				unset( $widgets[ $index ] );
			}
		}

		// Flatten the array back down now that may have removed values from the middle (to keep indexes correct)
		$widgets = array_values( $widgets );

		return $widgets;
	}

	/**
	 * Separate plugin enabled and querying the index
	 *
	 * The index can be tested at any time by setting an `es` query argument.
	 * When we're ready to use the index in production, the `vip_enable_elasticsearch`
	 * option will be set to `true`, which will enable querying for everyone.
	 */
	static function ep_skip_query_integration( $skip ) {
		if ( isset( $_GET[ 'es' ] ) ) {
			return false;
		}

		/**
		 * Honor filters that skip query integration
		 *
		 * It may be desirable to skip query integration for specific
		 * queries. We should honor those other filters. Since this
		 * defaults to false, it will only kick in if someone specifically
		 * wants to bypass ES in addition to what we're doing here.
		 */
		if ( $skip ) {
			return true;
		}

		$query_integration_enabled = defined( 'VIP_ENABLE_ELASTICSEARCH_QUERY_INTEGRATION' ) && true === VIP_ENABLE_ELASTICSEARCH_QUERY_INTEGRATION;
		// The filter is checking if we should _skip_ query integration
		return ! $query_integration_enabled;
	}
}
