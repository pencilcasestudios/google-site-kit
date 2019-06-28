<?php
/**
 * Class Google\Site_Kit\Core\REST_API\REST_Routes
 *
 * @package   Google\Site_Kit
 * @copyright 2019 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Core\REST_API;

use Google\Site_Kit\Context;
use Google\Site_Kit\Core\Modules\Modules;
use Google\Site_Kit\Core\Modules\Module;
use Google\Site_Kit\Core\Permissions\Permissions;
use Google\Site_Kit\Core\Storage\User_Options;
use Google\Site_Kit\Core\Authentication\Authentication;
use Google\Site_Kit\Core\Util\Reset;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Class managing REST API routes.
 *
 * @since 1.0.0
 * @access private
 * @ignore
 */
final class REST_Routes {

	const REST_ROOT = 'google-site-kit/v1';

	/**
	 * Plugin context.
	 *
	 * @since 1.0.0
	 * @var Context
	 */
	private $context;

	/**
	 * Authentication instance.
	 *
	 * @since 1.0.0
	 * @var Authentication
	 */
	protected $authentication;

	/**
	 * Modules instance.
	 *
	 * @since 1.0.0
	 * @var Modules
	 */
	protected $modules;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Context        $context        Plugin context.
	 * @param Authentication $authentication Optional. Authentication instance. Default is a new instance.
	 * @param Modules        $modules        Optional. Modules instance. Default is a new instance.
	 */
	public function __construct( Context $context, Authentication $authentication = null, Modules $modules = null ) {
		$this->context = $context;

		if ( ! $authentication ) {
			$authentication = new Authentication( $this->context );
		}
		$this->authentication = $authentication;

		if ( ! $modules ) {
			$modules = new Modules( $this->context, null, null, $this->authentication );
		}
		$this->modules = $modules;
	}

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		add_action(
			'rest_api_init',
			function( $server ) {
				$this->register_routes( $server );
			}
		);
	}

	/**
	 * Registers all REST routes.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Server $server WordPress REST server instance.
	 */
	private function register_routes( WP_REST_Server $server ) {
		$routes = $this->get_routes();

		array_walk(
			$routes,
			function( REST_Route $route ) use ( $server ) {
				$this->register_route( $route, $server );
			}
		);
	}

	/**
	 * Registers the given REST route on the passed server object.
	 *
	 * @since 1.0.0
	 *
	 * @param REST_Route     $route  REST route.
	 * @param WP_REST_Server $server WordPress REST server instance.
	 */
	protected function register_route( REST_Route $route, WP_REST_Server $server ) {
		$route_uri = '/' . self::REST_ROOT . '/' . trim( $route->get_uri(), '/' );
		$args      = $route->get_args();

		$server->register_route( self::REST_ROOT, $route_uri, $args );
	}

	/**
	 * Gets available REST routes.
	 *
	 * TODO: All these routes should be moved to more appropriate classes actually responsible.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of REST_Route instances.
	 */
	private function get_routes() {
		$can_authenticate = function() {
			return current_user_can( Permissions::AUTHENTICATE );
		};

		$can_view_insights_cron = function() {
			// If an internal cron request, simply grant access.
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return true;
			}

			// This accounts for routes that need to be called before user has completed setup flow.
			if ( current_user_can( Permissions::SETUP ) ) {
				return true;
			}

			return current_user_can( Permissions::VIEW_POSTS_INSIGHTS );
		};

		$can_view_insights = function() {
			// This accounts for routes that need to be called before user has completed setup flow.
			if ( current_user_can( Permissions::SETUP ) ) {
				return true;
			}

			return current_user_can( Permissions::VIEW_POSTS_INSIGHTS );
		};

		$can_manage_options = function() {
			// This accounts for routes that need to be called before user has completed setup flow.
			if ( current_user_can( Permissions::SETUP ) ) {
				return true;
			}

			return current_user_can( Permissions::MANAGE_OPTIONS );
		};

		$can_setup = function() {
			return current_user_can( Permissions::SETUP );
		};

		$routes = array(
			// This route is forward-compatible with a potential 'core/(?P<slug>[a-z\-]+)/data/(?P<datapoint>[a-z\-]+)'.
			new REST_Route(
				'core/site/data/reset',
				array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$reset = new Reset( $this->context );
							$reset->all();
							return new WP_REST_Response( true );
						},
						'permission_callback' => $can_setup,
					),
				)
			),
			// This route is forward-compatible with a potential 'core/(?P<slug>[a-z\-]+)/data/(?P<datapoint>[a-z\-]+)'.
			new REST_Route(
				'core/site/data/gcpproject',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$gcp_project = $this->authentication->gcp_project();
							if ( ! $gcp_project->has() ) {
								return new WP_Error( 'gcp_project_not_set', __( 'GCP project not set.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							return new WP_REST_Response( $gcp_project->get() );
						},
						'permission_callback' => $can_setup,
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$data = isset( $request['data'] ) ? $request['data'] : array();
							if ( ! isset( $data['projectID'] ) ) {
								/* translators: %s: Missing parameter name */
								return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'projectID' ), array( 'status' => 400 ) );
							}
							$data = array(
								'id'          => sanitize_text_field( $data['projectID'] ),
								'wp_owner_id' => get_current_user_id(),
							);
							return new WP_REST_Response( $this->authentication->gcp_project()->set( $data ) );
						},
						'permission_callback' => $can_setup,
						'args'                => array(
							'data' => array(
								'type'              => 'object',
								'description'       => __( 'Data to set.', 'google-site-kit' ),
								'validate_callback' => function( $value ) {
									return is_array( $value );
								},
							),
						),
					),
				)
			),
			// This route is forward-compatible with a potential 'core/(?P<slug>[a-z\-]+)/data/(?P<datapoint>[a-z\-]+)'.
			new REST_Route(
				'core/site/data/apikey',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$api_key = $this->authentication->api_key();
							if ( ! $api_key->has() ) {
								return new WP_Error( 'api_key_not_set', __( 'API key not set.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							return new WP_REST_Response( $api_key->get() );
						},
						'permission_callback' => $can_setup,
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$data = isset( $request['data'] ) ? $request['data'] : array();
							if ( ! isset( $data['apikey'] ) ) {
								/* translators: %s: Missing parameter name */
								return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'apikey' ), array( 'status' => 400 ) );
							}
							return new WP_REST_Response( $this->authentication->api_key()->set( $data['apikey'] ) );
						},
						'permission_callback' => $can_setup,
						'args'                => array(
							'data' => array(
								'type'              => 'object',
								'description'       => __( 'Data to set.', 'google-site-kit' ),
								'validate_callback' => function( $value ) {
									return is_array( $value );
								},
							),
						),
					),
				)
			),
			// This route is forward-compatible with a potential 'core/(?P<slug>[a-z\-]+)/data/(?P<datapoint>[a-z\-]+)'.
			new REST_Route(
				'core/site/data/credentials',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$credentials = $this->authentication->credentials();
							if ( ! $credentials->has() ) {
								return new WP_Error( 'credentials_not_set', __( 'Credentials not set.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							return new WP_REST_Response( $credentials->get() );
						},
						'permission_callback' => $can_setup,
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$data = isset( $request['data'] ) ? $request['data'] : array();
							if ( ! isset( $data['clientID'] ) ) {
								/* translators: %s: Missing parameter name */
								return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'clientID' ), array( 'status' => 400 ) );
							}
							if ( ! isset( $data['clientSecret'] ) ) {
								/* translators: %s: Missing parameter name */
								return new WP_Error( 'missing_required_param', sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'clientSecret' ), array( 'status' => 400 ) );
							}
							$data = array(
								'oauth2_client_id'     => sanitize_text_field( $data['clientID'] ),
								'oauth2_client_secret' => sanitize_text_field( $data['clientSecret'] ),
							);
							$credentials = $this->authentication->credentials();
							return new WP_REST_Response( $credentials->set( $data ) );
						},
						'permission_callback' => $can_setup,
						'args'                => array(
							'data' => array(
								'type'              => 'object',
								'description'       => __( 'Data to set.', 'google-site-kit' ),
								'validate_callback' => function( $value ) {
									return is_array( $value );
								},
							),
						),
					),
				)
			),
			// This route is forward-compatible with a potential 'core/(?P<slug>[a-z\-]+)/data/(?P<datapoint>[a-z\-]+)'.
			new REST_Route(
				'core/user/data/authentication',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$oauth_client = $this->authentication->get_oauth_client();
							$access_token = $oauth_client->get_client()->getAccessToken();

							$data = array(
								'isAuthenticated' => ! empty( $access_token ),
								'requiredScopes'  => $oauth_client->get_required_scopes(),
								'grantedScopes'   => ! empty( $access_token ) ? $oauth_client->get_granted_scopes() : array(),
							);

							return new WP_REST_Response( $data );
						},
						'permission_callback' => $can_authenticate,
					),
				)
			),
			// This route is forward-compatible with a potential 'core/(?P<slug>[a-z\-]+)/data/(?P<datapoint>[a-z\-]+)'.
			new REST_Route(
				'core/user/data/disconnect',
				array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							return new WP_REST_Response( $this->authentication->disconnect() );
						},
						'permission_callback' => $can_authenticate,
					),
				)
			),
			new REST_Route(
				'modules/(?P<slug>[a-z\-]+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							try {
								$module = $this->modules->get_module( $slug );
							} catch ( \Exception $e ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							return new WP_REST_Response( $this->prepare_module_data_for_response( $module ) );
						},
						'permission_callback' => $can_manage_options,
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							$modules = $this->modules->get_available_modules();
							if ( ! isset( $modules[ $slug ] ) ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							if ( $request['active'] ) {
								// Prevent activation if one of the dependencies is not active.
								$dependency_slugs = $this->modules->get_module_dependencies( $slug );
								foreach ( $dependency_slugs as $dependency_slug ) {
									if ( ! $this->modules->is_module_active( $dependency_slug ) ) {
										/* translators: %s: module name */
										return new WP_Error( 'inactive_dependencies', sprintf( __( 'Module cannot be activated because of inactive dependency %s.', 'google-site-kit' ), $modules[ $dependency_slug ]->name ), array( 'status' => 500 ) );
									}
								}
								if ( ! $this->modules->activate_module( $slug ) ) {
									return new WP_Error( 'cannot_activate_module', __( 'An internal error occurred while trying to activate the module.', 'google-site-kit' ), array( 'status' => 500 ) );
								}
							} else {
								// Automatically deactivate dependants.
								$dependant_slugs = $this->modules->get_module_dependants( $slug );
								foreach ( $dependant_slugs as $dependant_slug ) {
									if ( $this->modules->is_module_active( $dependant_slug ) ) {
										if ( ! $this->modules->deactivate_module( $dependant_slug ) ) {
											/* translators: %s: module name */
											return new WP_Error( 'cannot_deactivate_dependant', sprintf( __( 'Module cannot be deactivated because deactivation of dependant %s failed.', 'google-site-kit' ), $modules[ $dependant_slug ]->name ), array( 'status' => 500 ) );
										}
									}
								}
								if ( ! $this->modules->deactivate_module( $slug ) ) {
									return new WP_Error( 'cannot_deactivate_module', __( 'An internal error occurred while trying to deactivate the module.', 'google-site-kit' ), array( 'status' => 500 ) );
								}
							}
							return new WP_REST_Response( $this->prepare_module_data_for_response( $modules[ $slug ] ) );
						},
						'permission_callback' => $can_manage_options,
						'args'                => array(
							'active' => array(
								'type'        => 'boolean',
								'description' => __( 'Whether to activate or deactivate the module.', 'google-site-kit' ),
								'required'    => true,
							),
						),
					),
				),
				array(
					'args'   => array(
						'slug' => array(
							'type'              => 'string',
							'description'       => __( 'Idenfier for the module.', 'google-site-kit' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
					'schema' => $this->get_module_schema(),
				)
			),
			new REST_Route(
				'modules/(?P<slug>[a-z\-]+)/data/(?P<datapoint>[a-z\-]+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							try {
								$module = $this->modules->get_module( $slug );
							} catch ( \Exception $e ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							$data = $module->get_data( $request['datapoint'], $request->get_params() );
							if ( is_wp_error( $data ) ) {
								return $data;
							}
							return new WP_REST_Response( $this->parse_google_response_data( $data ) );
						},
						'permission_callback' => $can_view_insights_cron,
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							try {
								$module = $this->modules->get_module( $slug );
							} catch ( \Exception $e ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							$data = isset( $request['data'] ) ? (array) $request['data'] : array();
							$data = $module->set_data( $request['datapoint'], $data );
							if ( is_wp_error( $data ) ) {
								return $data;
							}
							return new WP_REST_Response( $this->parse_google_response_data( $data ) );
						},
						'permission_callback' => $can_manage_options,
						'args'                => array(
							'data' => array(
								'type'              => 'object',
								'description'       => __( 'Data to set.', 'google-site-kit' ),
								'validate_callback' => function( $value ) {
									return is_array( $value );
								},
							),
						),
					),
				),
				array(
					'args' => array(
						'slug'      => array(
							'type'              => 'string',
							'description'       => __( 'Idenfier for the module.', 'google-site-kit' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'datapoint' => array(
							'type'              => 'string',
							'description'       => __( 'Module data point to address.', 'google-site-kit' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			),
			// TODO: This route is super-complex to use and needs to be simplified.
			new REST_Route(
				'data',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$datasets = json_decode( $request['request'] );
							if ( ! $datasets || empty( $datasets ) ) {
								return new WP_Error( 'no_data_requested', __( 'Missing request data.', 'google-site-kit' ), array( 'status' => 400 ) );
							}
							$modules   = $this->modules->get_active_modules();
							$responses = array();
							foreach ( $modules as $module ) {
								$filtered_datasets = array_filter(
									$datasets,
									function( $dataset ) use ( $module ) {
										return 'modules' === $dataset->dataObject && $module->slug === $dataset->identifier; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
									}
								);
								if ( empty( $filtered_datasets ) ) {
									continue;
								}
								$additional_responses = $module->get_batch_data( $filtered_datasets );
								if ( is_array( $additional_responses ) ) {
									$responses = array_merge( $responses, $additional_responses );
								}
							}
							return new WP_REST_Response( $this->parse_google_response_data( $responses ) );
						},
						'permission_callback' => $can_view_insights_cron,
						'args'                => array(
							'request' => array(
								'type'        => 'string',
								'description' => __( 'JSON-encoded list of requests with dataObject keys.', 'google-site-kit' ),
								'required'    => true,
							),
						),
					),
				)
			),
			new REST_Route(
				'modules/(?P<slug>[a-z\-]+)/notifications',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							$modules = $this->modules->get_available_modules();
							if ( ! isset( $modules[ $slug ] ) ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							$notifications = new \stdClass(); // Will force JSON object.
							if ( $this->modules->is_module_active( $slug ) ) {
								$notifications = $modules[ $slug ]->get_data( 'notifications' );
								if ( ! is_wp_error( $notifications ) && ! empty( $notifications ) ) {
									if ( wp_is_numeric_array( $notifications ) ) {
										$notifications = array( 'items' => $notifications );
									}
								} else {
									$notifications = new \stdClass(); // Will force JSON object.
								}
							}
							return new WP_REST_Response( $notifications );
						},
						'permission_callback' => $can_authenticate,
					),
				),
				array(
					'args' => array(
						'slug' => array(
							'type'              => 'string',
							'description'       => __( 'Idenfier for the module.', 'google-site-kit' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			),
			// TODO: Remove this and replace usage with calls to wp/v1/posts.
			new REST_Route(
				'core/search/data/(?P<query>[0-9A-Za-z%.\-]+)',
				array(
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => function( WP_REST_Request $request ) {
							$post_id = false;
							$is_url  = filter_var( $request['query'], FILTER_VALIDATE_URL );
							if ( $is_url ) {
								$post_id = url_to_postid( $request['query'] );
							}
							if ( $post_id ) {
								$posts = array( get_post( $post_id ) );
							} else {
								$args = array(
									'posts_per_page'  => 10,
									'google-site-kit' => 1,
									's'               => $request['query'],
									'no_found_rows'   => true,
									'update_post_meta_cache' => false,
									'update_post_term_cache' => false,
									'post_status'     => array( 'publish' ),
								);
								$query = new \WP_Query( $args );
								$posts = $query->posts;
							}
							if ( empty( $posts ) ) {
								return array();
							}
							foreach ( $posts as $post ) {
								$post->permalink = get_permalink( $post->ID );
							}
							return new WP_REST_Response( $posts );
						},
					),
				),
				array(
					'args' => array(
						'query' => array(
							'type'        => 'string',
							'description' => __( 'Text content to search for.', 'google-site-kit' ),
						),
					),
				)
			),
		);

		return $routes;
	}

	/**
	 * Prepares module data for a REST response according to the schema.
	 *
	 * @since 1.0.0
	 *
	 * @param Module $module Module instance.
	 * @return array Module REST response data.
	 */
	private function prepare_module_data_for_response( Module $module ) {
		$manager = $this->modules;

		return array(
			'slug'         => $module->slug,
			'name'         => $module->name,
			'description'  => $module->description,
			'homepage'     => $module->homepage,
			'active'       => $manager->is_module_active( $module->slug ),
			'connected'    => $manager->is_module_connected( $module->slug ),
			'dependencies' => $manager->get_module_dependencies( $module->slug ),
			'dependants'   => $manager->get_module_dependants( $module->slug ),
		);
	}

	/**
	 * Gets the REST schema for a module.
	 *
	 * @since 1.0.0
	 *
	 * @return array Module REST schema.
	 */
	private function get_module_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'module',
			'type'       => 'object',
			'properties' => array(
				'slug'         => array(
					'type'        => 'string',
					'description' => __( 'Idenfier for the module.', 'google-site-kit' ),
					'readonly'    => true,
				),
				'name'         => array(
					'type'        => 'string',
					'description' => __( 'Name of the module.', 'google-site-kit' ),
					'readonly'    => true,
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Description of the module.', 'google-site-kit' ),
					'readonly'    => true,
				),
				'homepage'     => array(
					'type'        => 'string',
					'description' => __( 'The module homepage.', 'google-site-kit' ),
					'format'      => 'uri',
					'readonly'    => true,
				),
				'active'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the module is active.', 'google-site-kit' ),
				),
				'connected'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the module setup has been completed.', 'google-site-kit' ),
					'readonly'    => true,
				),
				'dependencies' => array(
					'type'        => 'array',
					'description' => __( 'List of slugs of other modules that the module depends on.', 'google-site-kit' ),
					'items'       => array(
						'type' => 'string',
					),
					'readonly'    => true,
				),
				'dependants'   => array(
					'type'        => 'array',
					'description' => __( 'List of slugs of other modules depending on the module.', 'google-site-kit' ),
					'items'       => array(
						'type' => 'string',
					),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Parses Google API response data.
	 *
	 * This is necessary since the Google client returns specific data class instances instead of raw arrays.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Google response data.
	 * @return object|array Parsed response data.
	 */
	private function parse_google_response_data( $data ) {
		if ( is_scalar( $data ) ) {
			return $data;
		}

		// There is an compatibility issue with \Google_Collection object and wp_json_encode in PHP 5.4 only.
		// These lines will encode/decode to deep convert objects, ensuring all data is returned.
		if ( version_compare( PHP_VERSION, '5.5.0', '<' ) ) {
			$data = json_decode( json_encode( $data ) );  // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}

		return $data;
	}
}
