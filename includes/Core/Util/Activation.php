<?php
/**
 * Class Google\Site_Kit\Core\Util\Activation
 *
 * @package   Google\Site_Kit
 * @copyright 2019 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Core\Util;

use Google\Site_Kit\Context;
use Google\Site_Kit\Core\Admin\Notice;
use Google\Site_Kit\Core\Assets\Assets;
use Google\Site_Kit\Core\Storage\Options;

/**
 * Class handling plugin activation.
 *
 * @since 1.0.0
 * @access private
 * @ignore
 */
final class Activation {

	const OPTION_SHOW_ACTIVATION_NOTICE = 'googlesitekit_show_activation_notice';
	const OPTION_NEW_SITE_POSTS         = 'googlesitekit_new_site_posts';

	/**
	 * Plugin context.
	 *
	 * @since 1.0.0
	 * @var Context
	 */
	private $context;

	/**
	 * Option API instance.
	 *
	 * @since 1.0.0
	 * @var Options
	 */
	protected $options;

	/**
	 * Assets API instance.
	 *
	 * @since 1.0.0
	 * @var Assets
	 */
	protected $assets;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Context $context Plugin context.
	 * @param Options $options Optional. The Option API instance. Default is a new instance.
	 * @param Assets  $assets  Optional. The Assets API instance. Default is a new instance.
	 */
	public function __construct(
		Context $context,
		Options $options = null,
		Assets $assets = null
	) {
		$this->context = $context;

		if ( ! $options ) {
			$options = new Options( $this->context );
		}
		$this->options = $options;

		if ( ! $assets ) {
			$assets = new Assets( $this->context );
		}
		$this->assets = $assets;
	}

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		add_filter(
			'googlesitekit_admin_notices',
			function( $notices ) {
				$notices[] = $this->get_activation_notice();
				return $notices;
			}
		);

		add_filter(
			'googlesitekit_admin_data',
			function ( $data ) {
				return $this->inline_js_admin_data( $data );
			}
		);

		add_action(
			'googlesitekit_activation',
			function( $network_wide ) {
				// Set activation flag.
				$this->set_activation_flag( $network_wide );

				// Set initial new posts count.
				$this->set_new_site_posts_count();
			}
		);
		add_action(
			'admin_enqueue_scripts',
			function( $hook_suffix ) {
				// Refresh new posts count when accessing the plugin dashboard page.
				if ( 'toplevel_page_googlesitekit-dashboard' !== $hook_suffix ) {
					return;
				}
				$this->set_new_site_posts_count();
			},
			1
		);
		add_action(
			'admin_enqueue_scripts',
			function() {
				if ( ! $this->get_activation_flag( is_network_admin() ) ) {
					return;
				}

				$this->assets->enqueue_fonts();
				$this->assets->enqueue_asset( 'googlesitekit_admin_css' );
			}
		);
	}

	/**
	 * Gets the admin notice indicating that the plugin has just been activated.
	 *
	 * @since 1.0.0
	 *
	 * @return Notice Admin notice instance.
	 */
	private function get_activation_notice() {
		return new Notice(
			'activated',
			array(
				'content'         => function() {
					// Remove the default WordPress "Plugin Activated" notice.
					if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.NoNonceVerification
						unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.NoNonceVerification
					}

					$sitekit_splash_url = $this->context->admin_url( 'splash' );

					ob_start();
					?>
					<script type="text/javascript">
					sendAnalyticsTrackingEvent( 'plugin_setup', 'plugin_activated' );
					</script>
					<div class="googlesitekit-plugin">
						<div class="googlesitekit-activation">
							<div class="mdc-layout-grid">
								<div class="mdc-layout-grid__inner">
									<div class="
										mdc-layout-grid__cell
										mdc-layout-grid__cell--span-7
									">
										<div class="googlesitekit-logo">
											<?php
											echo $this->assets->svg_sprite(
												'logo-g',
												array(
													'height' => '34',
													'width'  => '32',
												)
											); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped

											echo $this->assets->svg_sprite(
												'logo-sitekit-beta',
												array(
													'height' => '28',
													'width'  => '206',
												)
											); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
											?>
										</div>
										<h3 class="googlesitekit-heading-1 googlesitekit-activation__title">
											<?php esc_html_e( 'Congratulations, the Site Kit plugin is now activated.', 'google-site-kit' ); ?>
										</h3>
									</div>
									<div class="
										mdc-layout-grid__cell
										mdc-layout-grid__cell--start-8-desktop
										mdc-layout-grid__cell--offset-1-desktop
										mdc-layout-grid__cell--align-middle
									">
										<a href="#" onClick="javascript:sendAnalyticsTrackingEvent( 'plugin_setup', 'goto_sitekit' );document.location='<?php echo esc_url( $sitekit_splash_url ); ?>';"
											class="googlesitekit-activation__button mdc-button mdc-button--raised">
											<?php esc_html_e( 'Start Setup', 'google-site-kit' ); ?>
										</a>
									</div>
								</div>
							</div>
						</div>
					</div>
					<?php
					return ob_get_clean();
				},
				'type'            => Notice::TYPE_SUCCESS,
				'active_callback' => function( $hook_suffix ) {
					if ( 'plugins.php' !== $hook_suffix ) {
						return false;
					}
					$network_wide = is_network_admin();
					$flag         = $this->get_activation_flag( $network_wide );
					if ( $flag ) {
						// Unset the flag so that the notice only shows once.
						$this->delete_activation_flag( $network_wide );
					}
					return $flag;
				},
				'dismissible'     => true,
			)
		);
	}

	/**
	 * Modifies the admin data to pass to JS.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Inline JS data.
	 * @return array Filtered $data.
	 */
	private function inline_js_admin_data( $data ) {
		$data['newSitePosts'] = $this->options->get( self::OPTION_NEW_SITE_POSTS );

		return $data;
	}

	/**
	 * Queries the posts for a given win limit if it's a new site.
	 *
	 * If the number of posts is above the limit, the count will no longer be recorded. The count is used for the
	 * publisher wins.
	 *
	 * @since 1.0.0
	 *
	 * @param int $win_limit Optional. Limit of posts to consider. Default 5.
	 */
	private function set_new_site_posts_count( $win_limit = 5 ) {
		// Bail early if not a new site.
		if ( '-1' === $this->options->get( self::OPTION_NEW_SITE_POSTS ) ) {
			return;
		}

		$args = array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => $win_limit + 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'fields'                 => 'ids',
		);

		$query = new \WP_Query( $args );

		if ( $query->have_posts() && $win_limit < count( $query->posts ) ) {
			$this->options->set( self::OPTION_NEW_SITE_POSTS, -1, false );
			return;
		}

		$count = count( $query->posts );
		if ( 1 === $count && 1 === $query->posts[0] ) {
			$first_post = get_post( $query->posts[0] );
			if ( __( 'Hello world!' ) === $first_post->post_title ) { // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
				$count = 0;
			}
		}

		$this->options->set( self::OPTION_NEW_SITE_POSTS, $count, false );
	}

	/**
	 * Sets the flag that the plugin has just been activated.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 */
	private function set_activation_flag( $network_wide ) {
		if ( $network_wide ) {
			update_network_option( null, self::OPTION_SHOW_ACTIVATION_NOTICE, '1' );
			return;
		}

		update_option( self::OPTION_SHOW_ACTIVATION_NOTICE, '1', false );
	}

	/**
	 * Gets the flag that the plugin has just been activated.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether to check the flag network-wide.
	 * @return bool True if just activated, false otherwise.
	 */
	private function get_activation_flag( $network_wide ) {
		if ( $network_wide ) {
			return (bool) get_network_option( null, self::OPTION_SHOW_ACTIVATION_NOTICE );
		}

		return (bool) get_option( self::OPTION_SHOW_ACTIVATION_NOTICE );
	}

	/**
	 * Deletes the flag that the plugin has just been activated.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 */
	private function delete_activation_flag( $network_wide ) {
		if ( $network_wide ) {
			delete_network_option( null, self::OPTION_SHOW_ACTIVATION_NOTICE );
			return;
		}

		delete_option( self::OPTION_SHOW_ACTIVATION_NOTICE );
	}
}
