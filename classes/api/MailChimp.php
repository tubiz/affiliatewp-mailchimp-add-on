<?php
/**
 * Super-simple, minimum abstraction MailChimp API v3 wrapper
 * MailChimp API v3: http://developer.mailchimp.com
 *
 * @author  Based on class by Drew McLellan <drew.mclellan@gmail.com>
 * @version 1.0
 */

class TbzAffWPMailChimp {

	private $api_key;
	private $api_endpoint = 'https://<dc>.api.mailchimp.com/3.0';

	public $verify_ssl = true;

	private $last_response = array();

	/**
	 * Create a new instance
	 *
	 * @param string $api_key Your MailChimp API key
	 *
	 * @throws \Exception
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;

		if ( strpos( $this->api_key, '-' ) === false ) {
			throw new \Exception( "Invalid MailChimp API key `{$api_key}` supplied." );
		}

		list( , $data_center ) = explode( '-', $this->api_key );
		$this->api_endpoint = str_replace( '<dc>', $data_center, $this->api_endpoint );

		$this->last_response = array( 'headers' => null, 'body' => null );
	}

	/**
	 * Create a new instance of a Batch request. Optionally with the ID of an existing batch.
	 *
	 * @param string $batch_id Optional ID of an existing batch, if you need to check its status for example.
	 *
	 * @return Batch            New Batch object.
	 */
	public function new_batch( $batch_id = null ) {
		return new Batch( $this, $batch_id );
	}

	/**
	 * Convert an email address into a 'subscriber hash' for identifying the subscriber in a method URL
	 *
	 * @param   string $email The subscriber's email address
	 *
	 * @return  string          Hashed version of the input
	 */
	public function subscriberHash( $email ) {
		return md5( strtolower( $email ) );
	}

	/**
	 * Get an array containing the HTTP headers and the body of the API response.
	 *
	 * @return array  Assoc array with keys 'headers' and 'body'
	 */
	public function getLastResponse() {
		return $this->last_response;
	}

	/**
	 * Make an HTTP DELETE request - for deleting data
	 *
	 * @param   string $method  URL of the API request method
	 * @param   array  $args    Assoc array of arguments (if any)
	 * @param   int    $timeout Timeout limit for request in seconds
	 *
	 * @return  array|false   Assoc array of API response, decoded from JSON
	 */
	public function delete( $method, $args = array(), $timeout = 10 ) {
		return $this->makeRequest( 'delete', $method, $args, $timeout );
	}

	/**
	 * Make an HTTP GET request - for retrieving data
	 *
	 * @param   string $method  URL of the API request method
	 * @param   array  $args    Assoc array of arguments (usually your data)
	 * @param   int    $timeout Timeout limit for request in seconds
	 *
	 * @return  array|false   Assoc array of API response, decoded from JSON
	 */
	public function get( $method, $args = array(), $timeout = 10 ) {
		return $this->makeRequest( 'get', $method, $args, $timeout );
	}

	/**
	 * Make an HTTP PATCH request - for performing partial updates
	 *
	 * @param   string $method  URL of the API request method
	 * @param   array  $args    Assoc array of arguments (usually your data)
	 * @param   int    $timeout Timeout limit for request in seconds
	 *
	 * @return  array|false   Assoc array of API response, decoded from JSON
	 */
	public function patch( $method, $args = array(), $timeout = 10 ) {
		return $this->makeRequest( 'patch', $method, $args, $timeout );
	}

	/**
	 * Make an HTTP POST request - for creating and updating items
	 *
	 * @param   string $method  URL of the API request method
	 * @param   array  $args    Assoc array of arguments (usually your data)
	 * @param   int    $timeout Timeout limit for request in seconds
	 *
	 * @return  array|false   Assoc array of API response, decoded from JSON
	 */
	public function post( $method, $args = array(), $timeout = 10 ) {
		return $this->makeRequest( 'post', $method, $args, $timeout );
	}

	/**
	 * Make an HTTP PUT request - for creating new items
	 *
	 * @param   string $method  URL of the API request method
	 * @param   array  $args    Assoc array of arguments (usually your data)
	 * @param   int    $timeout Timeout limit for request in seconds
	 *
	 * @return  array|false   Assoc array of API response, decoded from JSON
	 */
	public function put( $method, $args = array(), $timeout = 10 ) {
		return $this->makeRequest( 'put', $method, $args, $timeout );
	}

	/**
	 * Performs the underlying HTTP request. Not very exciting.
	 *
	 * @param  string $http_verb The HTTP verb to use: get, post, put, patch, delete
	 * @param  string $method    The API method to be called
	 * @param  array  $args      Assoc array of parameters to be passed
	 * @param int     $timeout
	 *
	 * @return array|false Assoc array of decoded result
	 * @throws \Exception
	 */
	private function makeRequest( $http_verb, $method, $args = array(), $timeout = 10 ) {
		$this->reset();

		$url = $this->api_endpoint . '/' . $method;

		$headers                  = array();
		$headers['Authorization'] = 'Basic ' . base64_encode( 'affwp:' . $this->api_key );
		$headers['Accept']        = 'application/vnd.api+json';
		$headers['Content-Type']  = 'application/vnd.api+json';
		$headers['User-Agent']    = 'AffiliateWP Mailchimp Add-on; ' . get_bloginfo( 'url' );

		$request_args = array(
			'method'    => $http_verb,
			'headers'   => $headers,
			'timeout'   => $timeout,
			'sslverify' => $this->verify_ssl,
		);

		// attach arguments (in body or URL)
		if ( $http_verb === 'GET' ) {
			$url = add_query_arg( $args, $url );
		} else {
			$request_args['body'] = json_encode( $args );
		}

		// perform request
		$response = wp_remote_request( $url, $request_args );

		$this->last_response = $response;

		$formattedResponse = $this->formatResponse( wp_remote_retrieve_body( $response ) );

		return $formattedResponse;
	}

	/**
	 * Decode the response and format any error messages for debugging
	 *
	 * @param array $response The response from the remote request
	 *
	 * @return array|false    The JSON decoded into an array
	 */
	private function formatResponse( $response ) {
		if ( ! empty( $response ) ) {
			return json_decode( $response, true );
		}

		return false;
	}

	/**
	 * Empties all data from previous response
	 */
	private function reset() {
		$this->last_response = null;
	}

}