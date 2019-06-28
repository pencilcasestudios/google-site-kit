<?php
/**
 * Class Google\Site_Kit\Modules\AdSense
 *
 * @package   Google\Site_Kit
 * @copyright 2019 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Modules;

use Google\Site_Kit\Core\Modules\Module;
use Google\Site_Kit\Core\Modules\Module_With_Screen;
use Google\Site_Kit\Core\Modules\Module_With_Screen_Trait;
use Google\Site_Kit\Core\Modules\Module_With_Scopes;
use Google\Site_Kit\Core\Modules\Module_With_Scopes_Trait;
use Google\Site_Kit\Core\Util\AMP_Trait;
use Google_Client;
use Google_Service;
use Google_Service_Exception;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WP_Error;
use Exception;

/**
 * Class representing the AdSense module.
 *
 * @since 1.0.0
 * @access private
 * @ignore
 */
final class AdSense extends Module implements Module_With_Screen, Module_With_Scopes {
	use Module_With_Screen_Trait, Module_With_Scopes_Trait, AMP_Trait;

	const OPTION = 'googlesitekit_adsense_settings';

	/**
	 * Internal flag for whether the AdSense tag has been printed.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $adsense_tag_printed = false;

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		$this->register_scopes_hook();

		$this->register_screen_hook();

		add_filter(
			'option_' . self::OPTION,
			function( $option ) {
				$option = (array) $option;

				/**
				 * Filters the AdSense account ID to use.
				 *
				 * @since 1.0.0
				 *
				 * @param string $account_id Empty by default, will fall back to the option value if not set.
				 */
				$account_id = apply_filters( 'googlesitekit_adsense_account_id', '' );
				if ( ! empty( $account_id ) ) {
					$option['accountId'] = $account_id;
				}

				return $option;
			}
		);

		add_action( // For non-AMP, plus AMP Native and Transitional.
			'wp_head',
			function() {
				$this->output_adsense_script();
			}
		);

		add_filter( // For AMP Reader, and AMP Native and Transitional (as fallback).
			'the_content',
			function( $content ) {
				return $this->amp_content_add_auto_ads( $content );
			}
		);

		add_filter( // Load amp-auto-ads component for AMP Reader.
			'amp_post_template_data',
			function( $data ) {
				return $this->amp_data_load_auto_ads_component( $data );
			}
		);

		if ( $this->is_connected() ) {
			remove_filter( 'option_googlesitekit_analytics_adsense_linked', '__return_false' );
		} else {
			add_filter(
				'googlesitekit_modules_for_front_end_check',
				function( $modules ) {
					$modules[] = $this->slug;
					return $modules;
				}
			);
		}
	}

	/**
	 * Gets required Google OAuth scopes for the module.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of Google OAuth scopes.
	 */
	public function get_scopes() {
		return array(
			'https://www.googleapis.com/auth/adsense',
		);
	}

	/**
	 * Returns all module information data for passing it to JavaScript.
	 *
	 * @since 1.0.0
	 *
	 * @return array Module information data.
	 */
	public function prepare_info_for_js() {
		$info = parent::prepare_info_for_js();

		$info['provides'] = array(
			__( 'Monetize your website', 'google-site-kit' ),
			__( 'Intelligent, automatic ad placement', 'google-site-kit' ),
		);
		$info['settings'] = (array) $this->options->get( self::OPTION );

		// If adsenseTagEnabled not saved, default tag enabled to true.
		if ( ! isset( $info['settings']['adsenseTagEnabled'] ) ) {
			$info['settings']['adsenseTagEnabled'] = true;
		}

		// Clear datapoints that don't need to be localized.
		$idenfifier_args = array(
			'source' => 'site-kit',
			'url'    => rawurlencode( $this->context->get_reference_site_url() ),
		);

		$signup_args = array(
			'utm_source' => 'site-kit',
			'utm_medium' => 'wordpress_signup',
		);

		$info['accountURL'] = add_query_arg( $idenfifier_args, $this->get_data( 'account-url' ) );
		$info['signupURL']  = add_query_arg( $signup_args, $info['accountURL'] );
		$info['rootURL']    = add_query_arg( $idenfifier_args, 'https://www.google.com/adsense/' );

		return $info;
	}

	/**
	 * Checks whether the module is connected.
	 *
	 * A module being connected means that all steps required as part of its activation are completed.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if module is connected, false otherwise.
	 */
	public function is_connected() {
		$settings = (array) $this->options->get( self::OPTION );

		// TODO: Remove the latter at some point as it's here for back-compat.
		if ( empty( $settings['setupComplete'] ) && empty( $settings['setup_complete'] ) ) {
			return false;
		}

		return parent::is_connected();
	}

	/**
	 * Cleans up when the module is deactivated.
	 *
	 * @since 1.0.0
	 */
	public function on_deactivation() {
		$this->options->delete( self::OPTION );
	}

	/**
	 * Adds the AdSense script tag as soon as the client id is available.
	 *
	 * Used for account verification and ad display.
	 *
	 * @since 1.0.0
	 */
	protected function output_adsense_script() {

		// Bail early if we are checking for the tag presence from the back end.
		$tag_verify = ! empty( $_GET['tagverify'] ) ? true : false; // phpcs:ignore WordPress.Security.NonceVerification.NoNonceVerification
		if ( $tag_verify ) {
			return;
		}

		// Bail if we don't have a client ID.
		$client_id = $this->get_data( 'client-id' );
		if ( is_wp_error( $client_id ) || ! $client_id ) {
			return;
		}

		$tag_enabled = $this->get_data( 'adsense-tag-enabled' );

		// If we have client id default behaviour should be placing the tag unless the user has opted out.
		if ( false === $tag_enabled ) {
			return;
		}

		// On AMP, preferably use the new 'wp_body_open' hook, falling back to 'the_content' below.
		if ( $this->is_amp() ) {
			add_action(
				'wp_body_open',
				function() use ( $client_id ) {
					if ( $this->adsense_tag_printed ) {
						return;
					}

					?>
					<amp-auto-ads type="adsense" data-ad-client="<?php echo esc_attr( $client_id ); ?>"></amp-auto-ads>
					<?php
					$this->adsense_tag_printed = true;
				},
				-9999
			);
			return;
		}

		if ( $this->adsense_tag_printed ) {
			return;
		}

		// If we haven't completed the account connection yet, we still insert the AdSense tag
		// because it is required for account verification.
		?>
<script async src="//pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
<script>
(adsbygoogle = window.adsbygoogle || []).push({
google_ad_client: "<?php echo esc_attr( $client_id ); ?>",
enable_page_level_ads: true,
tag_partner: "site_kit"
});
</script>
		<?php
		$this->adsense_tag_printed = true;
	}

	/**
	 * Adds AMP auto ads script if opted in.
	 *
	 * This only affects AMP Reader mode, the others are automatically covered.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data AMP template data.
	 * @return array Filtered $data.
	 */
	protected function amp_data_load_auto_ads_component( $data ) {
		if ( ! $this->is_connected() ) {
			return $data;
		}

		$tag_enabled = $this->get_data( 'adsense-tag-enabled' );
		if ( is_wp_error( $tag_enabled ) || ! $tag_enabled ) {
			return $data;
		}

		$client_id = $this->get_data( 'client-id' );
		if ( is_wp_error( $client_id ) || ! $client_id ) {
			return $data;
		}

		$data['amp_component_scripts']['amp-auto-ads'] = 'https://cdn.ampproject.org/v0/amp-auto-ads-0.1.js';
		return $data;
	}

	/**
	 * Adds the AMP auto ads tag if opted in.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content The page content.
	 * @return string Filtered $content.
	 */
	protected function amp_content_add_auto_ads( $content ) {
		if ( ! $this->is_amp() ) {
			return $content;
		}

		if ( ! $this->is_connected() ) {
			return $content;
		}

		$tag_enabled = $this->get_data( 'adsense-tag-enabled' );
		if ( is_wp_error( $tag_enabled ) || ! $tag_enabled ) {
			return $content;
		}

		$client_id = $this->get_data( 'client-id' );
		if ( is_wp_error( $client_id ) || ! $client_id ) {
			return $content;
		}

		if ( $this->adsense_tag_printed ) {
			return $content;
		}

		$this->adsense_tag_printed = true;
		return '<amp-auto-ads type="adsense" data-ad-client="' . esc_attr( $client_id ) . '"></amp-auto-ads> ' . $content;
	}

	/**
	 * Returns the mapping between available datapoints and their services.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of $datapoint => $service_identifier pairs.
	 */
	protected function get_datapoint_services() {
		return array(
			// GET / POST.
			'connection'                   => '',
			'account-id'                   => '',
			'client-id'                    => '',
			'adsense-tag-enabled'          => '',
			'account-status'               => '',
			// GET.
			'account-url'                  => '',
			'reports-url'                  => '',
			'notifications'                => '',
			'accounts'                     => 'adsense',
			'alerts'                       => 'adsense',
			'clients'                      => 'adsense',
			'urlchannels'                  => 'adsense',
			'tag'                          => '',
			'earning-today'                => 'adsense',
			'earning-yesterday'            => 'adsense',
			'earning-samedaylastweek'      => 'adsense',
			'earning-7days'                => 'adsense',
			'earning-prev7days'            => 'adsense',
			'earning-this-month'           => 'adsense',
			'earning-this-month-last-year' => 'adsense',
			'earning-28days'               => 'adsense',
			'earning-prev28days'           => 'adsense',
			'earning-daily-this-month'     => 'adsense',
			'earnings-this-period'         => 'adsense',
			// POST.
			'setup-complete'               => '',
		);
	}

	/**
	 * Creates a request object for the given datapoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method    Request method. Either 'GET' or 'POST'.
	 * @param string $datapoint Datapoint to get request object for.
	 * @param array  $data      Optional. Contextual data to provide or set. Default empty array.
	 * @return RequestInterface|callable|WP_Error Request object or callable on success, or WP_Error on failure.
	 */
	protected function create_data_request( $method, $datapoint, array $data = array() ) {
		if ( 'GET' === $method ) {
			switch ( $datapoint ) {
				case 'connection':
					return function() {
						$option = (array) $this->options->get( self::OPTION );
						// TODO: Remove this at some point (migration of old options).
						if ( isset( $option['account_id'] ) || isset( $option['client_id'] ) || isset( $option['account_status'] ) ) {
							if ( isset( $option['account_id'] ) ) {
								if ( ! isset( $option['accountId'] ) ) {
									$option['accountId'] = $option['account_id'];
								}
								unset( $option['account_id'] );
							}
							if ( isset( $option['client_id'] ) ) {
								if ( ! isset( $option['clientId'] ) ) {
									$option['clientId'] = $option['client_id'];
								}
								unset( $option['client_id'] );
							}
							if ( isset( $option['account_status'] ) ) {
								if ( ! isset( $option['accountStatus'] ) ) {
									$option['accountStatus'] = $option['account_status'];
								}
								unset( $option['account_status'] );
							}
							$this->options->set( self::OPTION, $option );
						}
						$defaults = array(
							'accountId'     => '',
							'clientId'      => '',
							'accountStatus' => '',
						);
						return array_intersect_key( array_merge( $defaults, $option ), $defaults );
					};
				case 'account-id':
					return function() {
						$option = (array) $this->options->get( self::OPTION );
						// TODO: Remove this at some point (migration of old option).
						if ( isset( $option['account_id'] ) ) {
							if ( ! isset( $option['accountId'] ) ) {
								$option['accountId'] = $option['account_id'];
							}
							unset( $option['account_id'] );
							$this->options->set( self::OPTION, $option );
						}
						if ( empty( $option['accountId'] ) ) {
							return new WP_Error( 'account_id_not_set', __( 'AdSense account ID not set.', 'google-site-kit' ), array( 'status' => 404 ) );
						}
						return $option['accountId'];
					};
				case 'client-id':
					return function() {
						$option = (array) $this->options->get( self::OPTION );
						// TODO: Remove this at some point (migration of old option).
						if ( isset( $option['client_id'] ) ) {
							if ( ! isset( $option['clientId'] ) ) {
								$option['clientId'] = $option['client_id'];
							}
							unset( $option['client_id'] );
							$this->options->set( self::OPTION, $option );
						}
						if ( empty( $option['clientId'] ) ) {
							return new WP_Error( 'client_id_not_set', __( 'AdSense client ID not set.', 'google-site-kit' ), array( 'status' => 404 ) );
						}
						return $option['clientId'];
					};
				case 'adsense-tag-enabled':
					return function() {
						$option = (array) $this->options->get( self::OPTION );
						return ! isset( $option['adsenseTagEnabled'] ) ? null : ! empty( $option['adsenseTagEnabled'] );
					};
				case 'account-status':
					return function() {
						$option = (array) $this->options->get( self::OPTION );
						// TODO: Remove this at some point (migration of old option).
						if ( isset( $option['account_status'] ) ) {
							if ( ! isset( $option['accountStatus'] ) ) {
								$option['accountStatus'] = $option['account_status'];
							}
							unset( $option['account_status'] );
							$this->options->set( self::OPTION, $option );
						}
						if ( empty( $option['accountStatus'] ) ) {
							return new WP_Error( 'account_status_not_set', __( 'AdSense account status not set.', 'google-site-kit' ), array( 'status' => 404 ) );
						}
						return $option['accountStatus'];
					};
				case 'account-url':
					return function() {
						$account_id = $this->get_data( 'account-id' );
						if ( ! is_wp_error( $account_id ) && $account_id ) {
							return sprintf( 'https://www.google.com/adsense/new/%s/home', $account_id );
						}
						return 'https://www.google.com/adsense/signup/new';
					};
				case 'reports-url':
					return function() {
						$account_id = $this->get_data( 'account-id' );
						if ( ! is_wp_error( $account_id ) && $account_id ) {
							return sprintf( 'https://www.google.com/adsense/new/u/0/%s/main/viewreports', $account_id );
						}
						return 'https://www.google.com/adsense/start';
					};
				case 'notifications':
					return function() {
						$alerts = $this->get_data( 'alerts' );
						if ( is_wp_error( $alerts ) || empty( $alerts ) ) {
							return array();
						}
						$alerts = array_filter(
							$alerts,
							function( \Google_Service_AdSense_Alert $alert ) {
								return 'SEVERE' === $alert->getSeverity();
							}
						);

						// There is no SEVERE alert, return empty.
						if ( empty( $alerts ) ) {
							return array();
						}

						/**
						 * First Alert
						 *
						 * @var \Google_Service_AdSense_Alert $alert
						 */
						$alert = array_shift( $alerts );
						return array(
							'items'     => array(
								array(
									'id'            => 'adsense-notification',
									'title'         => __( 'Alert found!', 'google-site-kit' ),
									/* translators: %d: number of notifications */
									'description'   => $alert->getMessage(),
									'isDismissible' => true,
									'winImage'      => 'sun-small.png',
									'format'        => 'large',
									'severity'      => 'win-info',
								),
							),
							'url'       => $this->get_data( 'account-url' ),
							'ctaLabel'  => __( 'Go to AdSense', 'google-site-kit' ),
							'ctaTarget' => '_blank',
						);
					};
				case 'accounts':
					$service = $this->get_service( 'adsense' );
					return $service->accounts->listAccounts();
				case 'alerts':
					if ( ! isset( $data['accountId'] ) ) {
						$data['accountId'] = $this->get_data( 'account-id' );
						if ( is_wp_error( $data['accountId'] ) || ! $data['accountId'] ) {
							/* translators: %s: Missing parameter name */
							return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'accountId' ), array( 'status' => 400 ) );
						}
					}
					$service = $this->get_service( 'adsense' );
					return $service->accounts_alerts->listAccountsAlerts( $data['accountId'] );
				case 'clients':
					$service = $this->get_service( 'adsense' );
					return $service->adclients->listAdclients();
				case 'urlchannels':
					if ( ! isset( $data['clientId'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'clientId' ), array( 'status' => 400 ) );
					}
					$service = $this->get_service( 'adsense' );
					return $service->urlchannels->listUrlchannels( $data['clientId'] );
				case 'tag':
					return function() {
						$output = $this->get_frontend_hook_output( 'wp_head' ) . $this->get_frontend_hook_output( 'wp_body_open' ) . $this->get_frontend_hook_output( 'wp_footer' );
						// Detect google_ad_client.
						preg_match( '/google_ad_client: ?"(.*?)",/', $output, $matches );
						if ( isset( $matches[1] ) ) {
							return $matches[1];
						}
						// Detect amp-auto-ads tag.
						preg_match( '/<amp-auto-ads [^>]*data-ad-client="([^"]+)"/', $output, $matches );
						if ( isset( $matches[1] ) ) {
							return $matches[1];
						}
						return false;
					};
				case 'earning-today':
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( 'Y-m-d', strtotime( 'today' ) ),
							'end_date'   => date( 'Y-m-d', strtotime( 'today' ) ),
						)
					);
				case 'earning-yesterday':
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( 'Y-m-d', strtotime( 'yesterday' ) ),
							'end_date'   => date( 'Y-m-d', strtotime( 'yesterday' ) ),
						)
					);
				case 'earning-samedaylastweek':
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( 'Y-m-d', strtotime( '7daysAgo' ) ),
							'end_date'   => date( 'Y-m-d', strtotime( '7daysAgo' ) ),
						)
					);
				case 'earning-7days':
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( 'Y-m-d', strtotime( '7daysAgo' ) ),
							'end_date'   => date( 'Y-m-d', strtotime( 'yesterday' ) ),
						)
					);
				case 'earning-prev7days':
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( 'Y-m-d', strtotime( '14daysAgo' ) ),
							'end_date'   => date( 'Y-m-d', strtotime( '8daysAgo' ) ),
						)
					);
				case 'earning-this-month':
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( 'Y-m-01' ),
							'end_date'   => date( 'Y-m-d', strtotime( 'today' ) ),
						)
					);
				case 'earning-this-month-last-year':
					$last_year          = intval( date( 'Y' ) ) - 1;
					$last_date_of_month = date( 't', strtotime( $last_year . '-' . date( 'm' ) . '-01' ) );
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( $last_year . '-m-01' ),
							'end_date'   => date( $last_year . '-m-' . $last_date_of_month ),
						)
					);
				case 'earning-28days':
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( 'Y-m-d', strtotime( '28daysAgo' ) ),
							'end_date'   => date( 'Y-m-d', strtotime( 'yesterday' ) ),
						)
					);
				case 'earning-prev28days':
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( 'Y-m-d', strtotime( '56daysAgo' ) ),
							'end_date'   => date( 'Y-m-d', strtotime( '29daysAgo' ) ),
						)
					);
				case 'earning-daily-this-month':
					return $this->create_adsense_earning_data_request(
						array(
							'dimensions' => array( 'DATE' ),
							'start_date' => date( 'Y-m-01' ),
							'end_date'   => date( 'Y-m-d', strtotime( 'today' ) ),
						)
					);
				case 'earnings-this-period':
					$date_range = ! empty( $data['date_range'] ) ? $data['date_range'] : 'last-28-days';
					switch ( $date_range ) {
						case 'last-7-days':
							$daysago = 7;
							break;
						case 'last-14-days':
							$daysago = 14;
							break;
						case 'last-90-days':
							$daysago = 90;
							break;
						case 'last-28-days':
						default:
							$daysago = 28;
							break;
					}
					return $this->create_adsense_earning_data_request(
						array(
							'start_date' => date( 'Y-m-d', strtotime( '' . $daysago . 'daysAgo' ) ),
							'end_date'   => date( 'Y-m-d', strtotime( 'today' ) ),
						)
					);
			}
		} elseif ( 'POST' === $method ) {
			switch ( $datapoint ) {
				case 'connection':
					return function() use ( $data ) {
						$option = (array) $this->options->get( self::OPTION );
						$keys   = array( 'accountId', 'clientId', 'accountStatus' );
						foreach ( $keys as $key ) {
							if ( isset( $data[ $key ] ) ) {
								$option[ $key ] = $data[ $key ];
							}
						}
						$this->options->set( self::OPTION, $option );
						return true;
					};
				case 'account-id':
					if ( ! isset( $data['accountId'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'accountId' ), array( 'status' => 400 ) );
					}
					return function() use ( $data ) {
						$option              = (array) $this->options->get( self::OPTION );
						$option['accountId'] = $data['accountId'];
						$this->options->set( self::OPTION, $option );
						return true;
					};
				case 'client-id':
					if ( ! isset( $data['clientId'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'clientId' ), array( 'status' => 400 ) );
					}
					return function() use ( $data ) {
						$option             = (array) $this->options->get( self::OPTION );
						$option['clientId'] = $data['clientId'];
						$this->options->set( self::OPTION, $option );
						return true;
					};
				case 'adsense-tag-enabled':
					if ( ! isset( $data['adsenseTagEnabled'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'adsenseTagEnabled' ), array( 'status' => 400 ) );
					}
					return function() use ( $data ) {
						$option                      = (array) $this->options->get( self::OPTION );
						$option['adsenseTagEnabled'] = (bool) $data['adsenseTagEnabled'];
						$this->options->set( self::OPTION, $option );
						return true;
					};
				case 'account-status':
					if ( ! isset( $data['accountStatus'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'accountStatus' ), array( 'status' => 400 ) );
					}
					return function() use ( $data ) {
						$option                  = (array) $this->options->get( self::OPTION );
						$option['accountStatus'] = $data['accountStatus'];
						$this->options->set( self::OPTION, $option );
						return true;
					};
				case 'setup-complete':
					if ( ! isset( $data['clientId'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'clientId' ), array( 'status' => 400 ) );
					}
					return function() use ( $data ) {
						$option                  = (array) $this->options->get( self::OPTION );
						$option['setupComplete'] = true;
						$option['clientId']      = $data['clientId'];
						if ( isset( $data['adsenseTagEnabled'] ) ) {
							$option['adsenseTagEnabled'] = (bool) $data['adsenseTagEnabled'];
						}
						$this->options->set( self::OPTION, $option );
						return true;
					};
			}
		}

		return new WP_Error( 'invalid_datapoint', __( 'Invalid datapoint.', 'google-site-kit' ) );
	}

	/**
	 * Parses a response for the given datapoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method    Request method. Either 'GET' or 'POST'.
	 * @param string $datapoint Datapoint to resolve response for.
	 * @param mixed  $response  Response object or array.
	 * @return mixed Parsed response data on success, or WP_Error on failure.
	 */
	protected function parse_data_response( $method, $datapoint, $response ) {
		if ( 'GET' === $method ) {
			switch ( $datapoint ) {
				case 'accounts':
					// Store the matched account as soon as we have it.
					$accounts = $response->getItems();
					if ( ! empty( $accounts ) ) {
						$account_id = $this->get_data( 'account-id' );
						if ( is_wp_error( $account_id ) || ! $account_id ) {
							$this->set_data( 'account-id', array( 'accountId' => $accounts[0]->id ) );
						}
					}
					// TODO: Parse this response to a regular array.
					return $accounts;
				case 'alerts':
					// TODO: Parse this response to a regular array.
					return $response->getItems();
				case 'clients':
					// TODO: Parse this response to a regular array.
					return $response->getItems();
				case 'urlchannels':
					// TODO: Parse this response to a regular array.
					return $response->getItems();
				case 'earning-today':
				case 'earning-yesterday':
				case 'earning-samedaylastweek':
				case 'earning-7days':
				case 'earning-prev7days':
				case 'earning-this-month':
				case 'earning-this-month-last-year':
				case 'earning-28days':
				case 'earning-prev28days':
				case 'earning-daily-this-month':
				case 'earnings-this-period':
					// TODO: Parse this response to a regular array.
					return $response;
			}
		}

		return $response;
	}

	/**
	 * Creates a new AdSense earning request for the current account, site and given arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Optional. Additional arguments.
	 *
	 *     @type array  $dimensions List of request dimensions. Default empty array.
	 *     @type string $start_date Start date in 'Y-m-d' format. Default empty string.
	 *     @type string $end_date   End date in 'Y-m-d' format. Default empty string.
	 *     @type int    $row_limit  Limit of rows to return. Default none (will be skipped).
	 * }
	 * @return RequestInterface|WP_Error AdSense earning request instance.
	 */
	protected function create_adsense_earning_data_request( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dimensions' => array(),
				'start_date' => '',
				'end_date'   => '',
				'row_limit'  => '',
			)
		);

		$account_id = $this->get_data( 'account-id' );
		if ( is_wp_error( $account_id ) ) {
			return $account_id;
		}

		$opt_params = array(
			'locale' => get_locale(),
			'metric' => array( 'EARNINGS', 'PAGE_VIEWS_RPM', 'IMPRESSIONS' ),
		);

		if ( ! empty( $args['dimensions'] ) ) {
			$opt_params['dimension'] = (array) $args['dimensions'];
		}

		if ( ! empty( $args['row_limit'] ) ) {
			$opt_params['maxResults'] = (int) $args['row_limit'];
		}

		$host = wp_parse_url( $this->context->get_reference_site_url(), PHP_URL_HOST );
		if ( ! empty( $host ) ) {
			$opt_params['filter'] = 'DOMAIN_NAME==' . $host;
		}

		$service = $this->get_service( 'adsense' );
		return $service->accounts_reports->generate( $account_id, $args['start_date'], $args['end_date'], $opt_params );
	}

	/**
	 * Sets up information about the module.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of module info.
	 */
	protected function setup_info() {
		$idenfifier_args = array(
			'source' => 'site-kit',
			'url'    => $this->context->get_reference_site_url(),
		);

		return array(
			'slug'        => 'adsense',
			'name'        => __( 'AdSense', 'google-site-kit' ),
			'description' => __( 'Earn money by placing ads on your website. It’s free and easy.', 'google-site-kit' ),
			'cta'         => __( 'Monetize Your Site.', 'google-site-kit' ),
			'order'       => 2,
			'homepage'    => add_query_arg( $idenfifier_args, $this->get_data( 'reports-url' ) ),
			'learn_more'  => __( 'https://www.google.com/intl/en_us/adsense/start/', 'google-site-kit' ),
			'group'       => __( 'Additional Google Services', 'google-site-kit' ),
			'tags'        => array( 'monetize' ),
		);
	}

	/**
	 * Sets up the Google services the module should use.
	 *
	 * This method is invoked once by {@see Module::get_service()} to lazily set up the services when one is requested
	 * for the first time.
	 *
	 * @since 1.0.0
	 *
	 * @param Google_Client $client Google client instance.
	 * @return array Google services as $identifier => $service_instance pairs. Every $service_instance must be an
	 *               instance of Google_Service.
	 */
	protected function setup_services( Google_Client $client ) {
		return array(
			'adsense' => new \Google_Service_AdSense( $client ),
		);
	}
}
