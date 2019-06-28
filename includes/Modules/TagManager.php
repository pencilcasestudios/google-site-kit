<?php
/**
 * Class Google\Site_Kit\Modules\TagManager
 *
 * @package   Google\Site_Kit
 * @copyright 2019 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Modules;

use Google\Site_Kit\Core\Modules\Module;
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
 * Class representing the Tag Manager module.
 *
 * @since 1.0.0
 * @access private
 * @ignore
 */
final class TagManager extends Module implements Module_With_Scopes {
	use Module_With_Scopes_Trait, AMP_Trait;

	const OPTION = 'googlesitekit_tagmanager_settings';

	/**
	 * Temporary storage for very specific data for 'list-accounts' datapoint.
	 *
	 * Bad to have, but works for now.
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	private $_list_accounts_data = null;

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		$this->register_scopes_hook();

		add_action( // For non-AMP.
			'wp_head',
			function() {
				$this->print_gtm_js();
			}
		);

		add_action( // For non-AMP.
			'wp_footer',
			function() {
				$this->print_gtm_no_js();
			}
		);

		$print_amp_gtm = function() {
			$this->print_amp_gtm();
		};
		add_action( 'wp_footer', $print_amp_gtm ); // For AMP Native and Transitional.
		add_action( 'amp_post_template_footer', $print_amp_gtm ); // For AMP Reader.

		add_filter( // Load amp-analytics component for AMP Reader.
			'amp_post_template_data',
			function( $data ) {
				return $this->amp_data_load_analytics_component( $data );
			}
		);
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
			'https://www.googleapis.com/auth/tagmanager.readonly',
			'https://www.googleapis.com/auth/tagmanager.edit.containers',
			'https://www.googleapis.com/auth/tagmanager.manage.accounts',
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
			__( 'Create tags without updating code', 'google-site-kit' ),
		);

		$info['settings'] = $this->get_data( 'connection' );

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
		$container_id = $this->get_data( 'container-id' );
		if ( is_wp_error( $container_id ) || ! $container_id ) {
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
	 * Outputs Tag Manager script.
	 *
	 * @since 1.0.0
	 */
	protected function print_gtm_js() {
		// On AMP, do not print the script tag, falling back to 'amp_analytics_entries' below.
		if ( $this->is_amp() ) {
			return;
		}

		$container_id = $this->get_data( 'container-id' );
		if ( is_wp_error( $container_id ) || ! $container_id ) {
			return;
		}

		?>
		<!-- Google Tag Manager added by Site Kit -->
		<script>( function( w, d, s, l, i ) {
				w[l] = w[l] || [];
				w[l].push( {'gtm.start': new Date().getTime(), event: 'gtm.js'} );
				var f = d.getElementsByTagName( s )[0],
					j = d.createElement( s ), dl = l != 'dataLayer' ? '&l=' + l : '';
				j.async = true;
				j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
				f.parentNode.insertBefore( j, f );
			} )( window, document, 'script', 'dataLayer', '<?php echo esc_js( $container_id ); ?>' );
		</script>
		<!-- End Google Tag Manager -->
		<?php
	}

	/**
	 * Outputs Tag Manager iframe for when the browser has JavaScript disabled.
	 *
	 * @since 1.0.0
	 */
	protected function print_gtm_no_js() {
		// On AMP, do not print the script tag.
		if ( $this->is_amp() ) {
			return;
		}

		$container_id = $this->get_data( 'container-id' );
		if ( is_wp_error( $container_id ) || ! $container_id ) {
			return;
		}

		?>
		<!-- Google Tag Manager (noscript) added by Site Kit -->
		<noscript>
			<iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( $container_id ); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe>
		</noscript>
		<!-- End Google Tag Manager (noscript) -->
		<?php
	}

	/**
	 * Outputs Tag Manager <amp-analytics> tag.
	 *
	 * @since 1.0.0
	 */
	protected function print_amp_gtm() {
		if ( ! $this->is_amp() ) {
			return;
		}

		$container_id = $this->get_data( 'container-id' );
		if ( is_wp_error( $container_id ) || ! $container_id ) {
			return;
		}

		?>
		<!-- Google Tag Manager added by Site Kit -->
		<amp-analytics config="https://www.googletagmanager.com/amp.json?id=<?php echo esc_attr( $container_id ); ?>" data-credentials="include"></amp-analytics>
		<!-- End Google Tag Manager -->
		<?php
	}

	/**
	 * Loads AMP analytics script if opted in.
	 *
	 * This only affects AMP Reader mode, the others are automatically covered.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data AMP template data.
	 * @return array Filtered $data.
	 */
	protected function amp_data_load_analytics_component( $data ) {
		if ( isset( $data['amp_component_scripts']['amp-analytics'] ) ) {
			return $data;
		}

		$container_id = $this->get_data( 'container-id' );
		if ( is_wp_error( $container_id ) || ! $container_id ) {
			return;
		}

		$data['amp_component_scripts']['amp-analytics'] = 'https://cdn.ampproject.org/v0/amp-analytics-0.1.js';
		return $data;
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
			'connection'      => '',
			'account-id'      => '',
			'container-id'    => '',
			// GET.
			'list-accounts'   => 'tagmanager',
			'list-containers' => 'tagmanager',
			// POST.
			'save'            => '',
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
						if ( isset( $option['account_id'] ) || isset( $option['container_id'] ) ) {
							if ( isset( $option['account_id'] ) ) {
								if ( ! isset( $option['accountId'] ) ) {
									$option['accountId'] = $option['account_id'];
								}
								unset( $option['account_id'] );
							}
							if ( isset( $option['container_id'] ) ) {
								if ( ! isset( $option['containerId'] ) ) {
									$option['containerId'] = $option['container_id'];
								}
								unset( $option['container_id'] );
							}
							$this->options->set( self::OPTION, $option );
						}
						$defaults = array(
							'accountId'   => '',
							'containerId' => '',
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
							return new WP_Error( 'account_id_not_set', __( 'Tag Manager account ID not set.', 'google-site-kit' ), array( 'status' => 404 ) );
						}
						return $option['accountId'];
					};
				case 'container-id':
					return function() {
						$option = (array) $this->options->get( self::OPTION );
						// TODO: Remove this at some point (migration of old option).
						if ( isset( $option['container_id'] ) ) {
							if ( ! isset( $option['containerId'] ) ) {
								$option['containerId'] = $option['container_id'];
							}
							unset( $option['container_id'] );
							$this->options->set( self::OPTION, $option );
						}
						if ( empty( $option['containerId'] ) ) {
							return new WP_Error( 'container_id_not_set', __( 'Tag Manager container ID not set.', 'google-site-kit' ), array( 'status' => 404 ) );
						}
						return $option['containerId'];
					};
				case 'list-accounts':
					if ( ! empty( $data['accountId'] ) ) {
						$this->_list_accounts_data = $data;
					}
					$service = $this->get_service( 'tagmanager' );
					return $service->accounts->listAccounts();
				case 'list-containers':
					if ( ! isset( $data['accountId'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'accountId' ), array( 'status' => 400 ) );
					}
					$service = $this->get_service( 'tagmanager' );
					return $service->accounts_containers->listAccountsContainers( "accounts/{$data['accountId']}" );
			}
		} elseif ( 'POST' === $method ) {
			switch ( $datapoint ) {
				case 'connection':
					return function() use ( $data ) {
						$option = (array) $this->options->get( self::OPTION );
						$keys   = array( 'accountId', 'containerId' );
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
				case 'container-id':
					if ( ! isset( $data['containerId'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'containerId' ), array( 'status' => 400 ) );
					}
					return function() use ( $data ) {
						$option                = (array) $this->options->get( self::OPTION );
						$option['containerId'] = $data['containerId'];
						$this->options->set( self::OPTION, $option );
						return true;
					};
				case 'save':
					if ( ! isset( $data['accountId'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'accountId' ), array( 'status' => 400 ) );
					}
					if ( ! isset( $data['containerId'] ) ) {
						/* translators: %s: Missing parameter name */
						return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'containerId' ), array( 'status' => 400 ) );
					}
					return function() use ( $data ) {
						if ( '0' === $data['containerId'] ) {
							$client     = $this->get_client();
							$orig_defer = $client->shouldDefer();
							$client->setDefer( false );
							$container = new \Google_Service_TagManager_Container();
							$container->setName( get_bloginfo( 'name' ) );
							$container->setUsageContext( array( 'web' ) );
							try {
								$container = $this->get_service( 'tagmanager' )->accounts_containers->create( "accounts/{$data['accountId']}", $container );
							} catch ( Google_Service_Exception $e ) {
								$client->setDefer( $orig_defer );
								$message = $e->getErrors();
								if ( isset( $message[0] ) && isset( $message[0]['message'] ) ) {
									$message = $message[0]['message'];
								}
								return new WP_Error( $e->getCode(), $message );
							} catch ( Exception $e ) {
								$client->setDefer( $orig_defer );
								return new WP_Error( $e->getCode(), $e->getMessage() );
							}
							$client->setDefer( $orig_defer );
							$data['containerId'] = $container->getPublicId();
						}
						$option = array(
							'accountId'   => $data['accountId'],
							'containerId' => $data['containerId'],
						);
						$this->options->set( self::OPTION, $option );
						return $option;
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
				case 'list-accounts':
					$response = array(
						// TODO: Parse this response to a regular array.
						'accounts'   => $response->getAccount(),
						'containers' => array(),
					);
					if ( 0 === count( $response['accounts'] ) ) {
						return new WP_Error( 'google_tagmanager_account_empty', __( 'We didn’t find an associated Google Tag Manager account, would you like to set it up now? If you’ve just set up an account please re-fetch your account to sync it with Site Kit.', 'google-site-kit' ), array( 'status' => 500 ) );
					}
					if ( is_array( $this->_list_accounts_data ) && isset( $this->_list_accounts_data['accountId'] ) ) {
						$account_id                = $this->_list_accounts_data['accountId'];
						$this->_list_accounts_data = null;
					} else {
						$account_id = $response['accounts'][0]->getAccountId();
					}
					$containers = $this->get_data( 'list-containers', array( 'accountId' => $account_id ) );
					if ( is_wp_error( $containers ) ) {
						return $response;
					}
					return array_merge( $response, $containers );
				case 'list-containers':
					$response = array(
						// TODO: Parse this response to a regular array.
						'containers' => $response->getContainer(),
					);
					if ( 0 === count( $response['containers'] ) ) {
						return new WP_Error( 'google_tagmanager_container_empty', __( 'No Google Tag Manager Containers Found.', 'google-site-kit' ), array( 'status' => 500 ) );
					}
					return $response;
			}
		}

		return $response;
	}

	/**
	 * Sets up information about the module.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of module info.
	 */
	protected function setup_info() {
		return array(
			'slug'        => 'tagmanager',
			'name'        => __( 'Tag Manager', 'google-site-kit' ),
			'description' => __( 'Tag Manager creates an easy to manage way to create tags on your site without updating code.', 'google-site-kit' ),
			'cta'         => __( 'Tag management made simple.', 'google-site-kit' ),
			'order'       => 6,
			'homepage'    => __( 'https://tagmanager.google.com/', 'google-site-kit' ),
			'learn_more'  => __( 'https://marketingplatform.google.com/about/tag-manager/', 'google-site-kit' ),
			'group'       => __( 'Marketing Platform', 'google-site-kit' ),
			'tags'        => array( 'marketing' ),
			'depends_on'  => array( 'analytics' ),
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
			'tagmanager' => new \Google_Service_TagManager( $client ),
		);
	}
}
