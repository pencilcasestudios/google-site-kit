<?php
/**
 * Class Google\Site_Kit\Core\Authentication\Credentials
 *
 * @package   Google\Site_Kit
 * @copyright 2019 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Core\Authentication;

use Google\Site_Kit\Core\Storage\Options;
use Google\Site_Kit\Core\Storage\Encrypted_Options;

/**
 * Class representing the OAuth client ID and secret credentials.
 *
 * @since 1.0.0
 * @access private
 * @ignore
 */
final class Credentials {

	/**
	 * Option key in options table.
	 */
	const OPTION = 'googlesitekit_credentials';

	/**
	 * Encrypted_Options object.
	 *
	 * @since 1.0.0
	 * @var Encrypted_Options
	 */
	private $encrypted_options;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Options $options Options instance.
	 */
	public function __construct( Options $options ) {
		$this->encrypted_options = new Encrypted_Options( $options );
	}

	/**
	 * Retrieves Site Kit credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return array|bool Value set for the credentials, or false if not set.
	 */
	public function get() {
		return $this->encrypted_options->get( self::OPTION );
	}

	/**
	 * Saves encrypted Site Kit credentials.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Client ID and Secret data.
	 * @return bool True on success, false on failure.
	 */
	public function set( $data ) {
		return $this->encrypted_options->set( self::OPTION, $data );
	}

	/**
	 * Checks whether Site Kit has been setup with client ID and secret.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if credentials are set, false otherwise.
	 */
	public function has() {
		$credentials = (array) $this->get();
		if ( ! empty( $credentials ) && ! empty( $credentials['oauth2_client_id'] ) && ! empty( $credentials['oauth2_client_secret'] ) ) {
			return true;
		}

		return false;
	}
}
