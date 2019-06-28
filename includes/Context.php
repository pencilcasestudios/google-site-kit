<?php
/**
 * Class Google\Site_Kit\Context
 *
 * @package   Google\Site_Kit
 * @copyright 2019 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit;

/**
 * Class representing the context in which the plugin is running.
 *
 * @since 1.0.0
 * @access private
 * @ignore
 */
final class Context {

	/**
	 * Absolute path to the plugin main file.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $main_file;

	/**
	 * Internal storage for whether the plugin is network active or not.
	 *
	 * @since 1.0.0
	 * @var bool|null
	 */
	private $network_active = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $main_file Absolute path to the plugin main file.
	 */
	public function __construct( $main_file ) {
		$this->main_file = $main_file;
	}

	/**
	 * Gets the absolute path for a path relative to the plugin directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path Optional. Relative path. Default '/'.
	 * @return string Absolute path.
	 */
	public function path( $relative_path = '/' ) {
		return plugin_dir_path( $this->main_file ) . ltrim( $relative_path, '/' );
	}
	/**
	 * Gets the full URL for a path relative to the plugin directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path Optional. Relative path. Default '/'.
	 * @return string Full URL.
	 */
	public function url( $relative_path = '/' ) {
		return plugin_dir_url( $this->main_file ) . ltrim( $relative_path, '/' );
	}

	/**
	 * Gets the full URL to an admin screen part of the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug       Optional. Plugin admin screen slug. Default 'dashboard'.
	 * @param array  $query_args Optional. Additional query args. Default empty array.
	 * @return string Full admin screen URL.
	 */
	public function admin_url( $slug = 'dashboard', array $query_args = array() ) {
		$query_args['page'] = Core\Admin\Screens::PREFIX . $slug;

		return add_query_arg( $query_args, self_admin_url( 'admin.php' ) );
	}

	/**
	 * Determines whether the plugin is running in network mode.
	 *
	 * Network mode is active under the following conditions:
	 * * Multisite is enabled.
	 * * The plugin is network-active.
	 * * The site's domain matches the network's domain (which means it is a subdirectory site).
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the plugin is in network mode, false otherwise.
	 */
	public function is_network_mode() {
		// Bail if plugin is not network-active.
		if ( ! $this->is_network_active() ) {
			return false;
		}

		$site    = get_site( get_current_blog_id() );
		$network = get_network( $site->network_id );

		// Use network mode when the site's domain is the same as the network's domain.
		return $network && $site->domain === $network->domain;
	}

	/**
	 * Gets the site URL of the reference site to use for stats.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reference site URL.
	 */
	public function get_reference_site_url() {
		$orig_site_url = home_url();
		$site_url      = $orig_site_url;

		/**
		 * Filters the reference site URL to use for stats.
		 *
		 * This can be used to override the current site URL, for example when using the plugin on a non-public site,
		 * such as in a staging environment.
		 *
		 * @since 1.0.0
		 *
		 * @param string $site_url Reference site URL, typically the WordPress home URL.
		 */
		$site_url = apply_filters( 'googlesitekit_site_url', $site_url );

		// Ensure this is not empty.
		if ( empty( $site_url ) ) {
			$site_url = $orig_site_url;
		}

		return $site_url;
	}

	/**
	 * Gets the current version is beta released.
	 *
	 * @return bool True if the version is in beta mode, false otherwise.
	 */
	public function is_beta() {
		return false !== strpos( GOOGLESITEKIT_VERSION, 'beta' );
	}

	/**
	 * Checks whether the plugin is network active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if plugin is network active, false otherwise.
	 */
	public function is_network_active() {
		// Determine $network_active property just once per request, to not unnecessarily run this complex logic on every call.
		if ( null === $this->network_active ) {
			if ( is_multisite() ) {
				$network_active_plugins = wp_get_active_network_plugins();

				// Consider MU plugins and network-activated plugins as network-active.
				$this->network_active = strpos( wp_normalize_path( __FILE__ ), wp_normalize_path( WPMU_PLUGIN_DIR ) ) === 0
					|| in_array( WP_PLUGIN_DIR . '/' . GOOGLESITEKIT_PLUGIN_BASENAME, $network_active_plugins, true );
			} else {
				$this->network_active = false;
			}
		}

		return $this->network_active;
	}
}
