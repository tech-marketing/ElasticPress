<?php

/**
 * ElasticPress Elasticsearch
 *
 * @since  1.0
 * @package elasticpress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class EP_Elasticsearch {

	/**
	 * Placeholder method
	 *
	 * @since 0.1.0
	 */
	public function __construct() { }

	/**
	 * Logged queries for debugging
	 *
	 * @since  1.8
	 */
	private $queries = array();

	/**
	 * ES plugins
	 *
	 * @var array
	 * @since  2.2
	 */
	public $elasticsearch_plugins = null;

	/**
	 * ES version number
	 *
	 * @var string
	 * @since  2.2
	 */
	public $elasticsearch_version = null;

	/**
	 * Return singleton instance of class
	 *
	 * @return EP_Elasticsearch
	 * @since 0.1.0
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance  ) {
			$instance = new self();
		}

		return $instance;
	}



	/**
	 * Add appropriate request headers
	 *
	 * @since 1.4
	 * @return array
	 */
	public function format_request_headers() {
		$headers = array();

		// Check for ElasticPress API key and add to header if needed.
		if ( defined( 'EP_Elasticsearch_KEY' ) && EP_Elasticsearch_KEY ) {
			$headers['X-ElasticPress-API-Key'] = EP_Elasticsearch_KEY;
		}

		/**
		 * ES Shield Username & Password
		 * Adds username:password basic authentication headers
		 *
		 * Define the constant ES_SHIELD in your wp-config.php
		 * Format: 'username:password' (colon separated)
		 * Example: define( 'ES_SHIELD', 'es_admin:password' );
		 *
		 * @since 1.9
		 */
		if ( defined( 'ES_SHIELD' ) && ES_SHIELD ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( ES_SHIELD );
		}

		$headers = apply_filters( 'ep_format_request_headers', $headers );

		return $headers;
	}

	/**
	 * Wrapper for wp_remote_request
	 *
	 * This is a wrapper function for wp_remote_request to account for request failures.
	 *
	 * @since 1.6
	 *
	 * @param string $path Site URL to retrieve.
	 * @param array  $args Optional. Request arguments. Default empty array.
	 * @param array  $log_args Optional. Extra arguments to log
	 * @param string Type of request, used for debugging
	 *
	 * @return WP_Error|array The response or WP_Error on failure.
	 */
	public function remote_request( $path, $args = array(), $log_args = array(), $type = null ) {

		if ( empty( $args['method'] ) ) {
			$args['method'] = 'GET';
		}

		$query = array(
			'time_start'   => microtime( true ),
			'time_finish'  => false,
			'args'         => $args,
			'blocking'     => true,
			'failed_hosts' => array(),
			'request'      => false,
			'host'         => ep_get_host(),
			'log_args'     => $log_args,
		);

		//Add the API Header
		$args['headers'] = $this->format_request_headers();

		$request = false;
		$failures = 0;

		// Optionally let us try back up hosts and account for failures
		while ( true ) {
			$query['host'] = apply_filters( 'ep_pre_request_host', $query['host'], $failures, $path, $args );
			$query['url'] = apply_filters( 'ep_pre_request_url', esc_url( trailingslashit( $query['host'] ) . $path ), $failures, $query['host'], $path, $args );

			$request = wp_remote_request( $query['url'], $args ); //try the existing host to avoid unnecessary calls

			$request_response_code = (int) wp_remote_retrieve_response_code( $request );

			$is_valid_res = ( $request_response_code >= 200 && $request_response_code <= 299 );

			if ( false === $request || is_wp_error( $request ) || ! $is_valid_res ) {
				$failures++;

				if ( $failures >= apply_filters( 'ep_max_remote_request_tries', 1, $path, $args ) ) {
					break;
				}
			} else {
				break;
			}
		}

		// Return now if we're not blocking, since we won't have a response yet
		if ( isset( $args['blocking'] ) && false === $args['blocking' ] ) {
			$query['blocking'] = true;
			$query['request']  = $request;
			$this->_add_query_log( $query );

			return $request;
		}

		$query['time_finish'] = microtime( true );
		$query['request'] = $request;
		$this->_add_query_log( $query );

		do_action( 'ep_remote_request', $query, $type );

		return $request;

	}

	/**
	 * Get ES plugins and version, cache everything
	 *
	 * @param  bool $force
	 * @since 2.2
	 * @return array
	 */
	public function get_elasticsearch_info( $force = false ) {

		if ( $force || null === $this->elasticsearch_version || null === $this->elasticsearch_plugins ) {

			// Get ES info from cache if available. If we are forcing, then skip cache check
			if ( $force ) {
				$es_info = false;
			} else {
				if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
					$es_info = get_site_transient( 'ep_es_info' );
				} else {
					$es_info = get_transient( 'ep_es_info' );
				}
			}

			if ( ! empty( $es_info ) ) {
				// Set ES info from cache
				$this->elasticsearch_version = $es_info['version'];
				$this->elasticsearch_plugins = $es_info['plugins'];
			} else {
				$path = '_nodes/plugins';

				$request = ep_remote_request( $path, array( 'method' => 'GET' ) );

				if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
					$this->elasticsearch_version = false;
					$this->elasticsearch_plugins = false;

					/**
					 * Try a different endpoint in case the plugins url is restricted
					 *
					 * @since 2.2.1
					 */

					$request = $this->remote_request( '', array( 'method' => 'GET' ) );

					if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {
						$response_body = wp_remote_retrieve_body( $request );
						$response = json_decode( $response_body, true );

						try {
							$this->elasticsearch_version = $response['version']['number'];
						} catch ( Exception $e ) {
							// Do nothing
						}
					}
				} else {
					$response = json_decode( wp_remote_retrieve_body( $request ), true );

					$this->elasticsearch_plugins = array();
					$this->elasticsearch_version = false;

					if ( isset( $response['nodes'] ) ) {

						foreach ( $response['nodes'] as $node ) {
							// Save version of last node. We assume all nodes are same version
							$this->elasticsearch_version = $node['version'];

							if ( isset( $node['plugins'] ) && is_array( $node['plugins'] ) ) {

								foreach ( $node['plugins'] as $plugin ) {

									$this->elasticsearch_plugins[ $plugin['name'] ] = $plugin['version'];
								}

								break;
							}
						}
					}
				}

				/**
				 * Cache ES info
				 *
				 * @since  2.3.1
				 */
				if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
					set_site_transient(
						'ep_es_info',
						array( 'version' => $this->elasticsearch_version, 'plugins' => $this->elasticsearch_plugins, ),
						apply_filters( 'ep_es_info_cache_expiration', ( 5 * MINUTE_IN_SECONDS ) )
					);
				} else {
					set_transient(
						'ep_es_info',
						array( 'version' => $this->elasticsearch_version, 'plugins' => $this->elasticsearch_plugins, ),
						apply_filters( 'ep_es_info_cache_expiration', ( 5 * MINUTE_IN_SECONDS ) )
					);
				}
			}
		}

		return array(
			'plugins' => $this->elasticsearch_plugins,
			'version' => $this->elasticsearch_version,
		);
	}

	/**
	 * Get Elasticsearch version
	 *
	 * @param  bool $force
	 * @since  2.1.2
	 * @return string|bool
	 */
	public function get_elasticsearch_version( $force = false ) {

		$info = $this->get_elasticsearch_info( $force );

		return apply_filters( 'ep_elasticsearch_version', $info['version'] );
	}

	/**
	 * Get Elasticsearch plugins
	 *
	 * @param  bool $force
	 * @since  2.2
	 * @return string|bool
	 */
	public function get_elasticsearch_plugins( $force = false ) {

		$info = $this->get_elasticsearch_info( $force );

		return apply_filters( 'ep_elasticsearch_plugins', $info['plugins'] );
	}

	/**
	 * Delete the network index alias
	 *
	 * @since 0.9.0
	 * @return bool|array
	 */
	public function delete_network_alias() {

		$path = '*/_alias/' . ep_get_network_alias();

		$request_args = array( 'method' => 'DELETE' );

		$request = ep_remote_request( $path, apply_filters( 'ep_delete_network_alias_request_args', $request_args ), array(), 'delete_network_alias' );

		if ( ! is_wp_error( $request ) && ( 200 >= wp_remote_retrieve_response_code( $request ) && 300 > wp_remote_retrieve_response_code( $request ) ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * Create the network alias from an array of indexes
	 *
	 * @param array $indexes
	 * @since 0.9.0
	 * @return array|bool
	 */
	public function create_network_alias( $indexes ) {

		$path = '_aliases';

		$args = array(
			'actions' => array(),
		);

		$indexes = apply_filters( 'ep_create_network_alias_indexes', $indexes );

		foreach ( $indexes as $index ) {
			$args['actions'][] = array(
				'add' => array(
					'index' => $index,
					'alias' => ep_get_network_alias(),
				),
			);
		}

		$request_args = array(
			'body'    => json_encode( $args ),
			'method'  => 'POST',
			'timeout' => 25,
		);

		$request = ep_remote_request( $path, apply_filters( 'ep_create_network_alias_request_args', $request_args, $args, $indexes ), array(), 'create_network_alias' );

		if ( ! is_wp_error( $request ) && ( 200 >= wp_remote_retrieve_response_code( $request ) && 300 > wp_remote_retrieve_response_code( $request ) ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * Send mapping to ES
	 *
	 * @param  array $mapping
	 * @param  string $index_name
	 * @since  2.5
	 * @return array|bool|mixed
	 */
	public function put_mapping( $mapping, $index_name ) {
		$mapping = apply_filters( 'ep_config_mapping', $mapping );

		$request_args = array(
			'body'    => json_encode( $mapping ),
			'method'  => 'PUT',
			'timeout' => 30,
		);

		$request = ep_remote_request( $index_name, apply_filters( 'ep_put_mapping_request_args', $request_args ), array(), 'put_mapping' );

		$request = apply_filters( 'ep_config_mapping_request', $request, $index, $mapping );

		if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * Bulk index documents
	 *
	 * @since  2.5
	 * @param  array $request_body
	 * @param  string $type
	 * @param  string $index_name
	 * @return array|object|WP_Error
	 */
	public function bulk_index_documents( $request_body, $type, $index_name ) {
		$path = apply_filters( 'ep_bulk_index_documents_request_path', $index_name . '/' . $type . '/_bulk', $request_body );

		$request_args = array(
			'method'  => 'POST',
			'body'    => json_encode( $request_body ),
			'timeout' => 30,
		);

		$request = ep_remote_request( $path, apply_filters( 'ep_bulk_index_documentss_request_args', $request_args, $request_body, $type, $index_name ), array(), 'bulk_index_documents' );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$response = wp_remote_retrieve_response_code( $request );

		if ( 200 !== $response ) {
			return new WP_Error( $response, wp_remote_retrieve_response_message( $request ), $request );
		}

		return json_decode( wp_remote_retrieve_body( $request ), true );
	}


	/**
	 * Get a pipeline
	 *
	 * @param  string $id
	 * @since  2.3
	 * @return WP_Error|bool|array
	 */
	public function get_pipeline( $id ) {
		$path = '_ingest/pipeline/' . $id;

		$request_args = array(
			'method'  => 'GET',
		);

		$request = ep_remote_request( $path, apply_filters( 'ep_get_pipeline_args', $request_args ), array(), 'get_pipeline' );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$response = wp_remote_retrieve_response_code( $request );

		if ( 200 !== $response ) {
			return new WP_Error( $response, wp_remote_retrieve_response_message( $request ), $request );
		}

		$body = json_decode( wp_remote_retrieve_body( $request ), true );

		if ( empty( $body ) ) {
			return false;
		}

		return $body;
	}

	/**
	 * Put a pipeline
	 *
	 * @param  string $id
	 * @param  array $args
	 * @since  2.3
	 * @return WP_Error|bool
	 */
	public function create_pipeline( $id, $args ) {
		$path = '_ingest/pipeline/' . $id;

		$request_args = array(
			'body'    => json_encode( $args ),
			'method'  => 'PUT',
		);

		$request = ep_remote_request( $path, apply_filters( 'ep_get_pipeline_args', $request_args ), array(), 'create_pipeline' );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$response = wp_remote_retrieve_response_code( $request );

		if ( 200 > $response || 300 <= $response ) {
			return new WP_Error( $response, wp_remote_retrieve_response_message( $request ), $request );
		}

		$body = json_decode( wp_remote_retrieve_body( $request ), true );

		if ( empty( $body ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks if index exists by index name, returns true or false
	 *
	 * @param  string $index_name
	 * @since  2.5
	 * @return bool
	 */
	public function index_exists( $index_name ) {

		$request_args = array( 'method' => 'HEAD' );

		$request = ep_remote_request( $index_name, apply_filters( 'ep_index_exists_request_args', $request_args, $index_name ), array(), 'index_exists' );

		// 200 means the index exists
		// 404 means the index was non-existent
		if ( ! is_wp_error( $request ) && ( 200 === wp_remote_retrieve_response_code( $request ) || 404 === wp_remote_retrieve_response_code( $request ) ) ) {

			if ( 404 === wp_remote_retrieve_response_code( $request ) ) {
				return false;
			}

			if ( 200 === wp_remote_retrieve_response_code( $request ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete an index
	 *
	 * @param string $index_name
	 * @since 0.9.0
	 * @return array|bool
	 */
	public function delete_index( $index_name ) {

		$request_args = array( 'method' => 'DELETE', 'timeout' => 30, );

		$request = ep_remote_request( $index_name, apply_filters( 'ep_delete_index_request_args', $request_args, $index_name ), array(), 'delete_index' );

		// 200 means the delete was successful
		// 404 means the index was non-existent, but we should still pass this through as we will occasionally want to delete an already deleted index
		if ( ! is_wp_error( $request ) && ( 200 === wp_remote_retrieve_response_code( $request ) || 404 === wp_remote_retrieve_response_code( $request ) ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * Search for posts under a specific site index or the global index ($site_id = 0).
	 *
	 * @param  array  $args
	 * @param  array  $query_args Strictly for debugging
	 * @param  string $scope
	 * @since  0.1.0
	 * @return array
	 */
	public function query( $query, $type, $index_name ) {
		$path = apply_filters( 'ep_query_request_path', $index_name . '/' . $type . '/_search', $args );

		$request_args = array(
			'body'    => json_encode( apply_filters( 'ep_query_request_args', $query, $type, $index_name ) ),
			'method'  => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		);

		$request = ep_remote_request( $path, apply_filters( 'ep_query_request_args', $request_args, $query, $type, $index_name ), $query_args, 'query' );

		$remote_req_res_code = intval( wp_remote_retrieve_response_code( $request ) );

		$is_valid_res = ( $remote_req_res_code >= 200 && $remote_req_res_code <= 299 );

		if ( ! is_wp_error( $request ) && apply_filters( 'ep_query_response_is_valid_res', $is_valid_res, $request ) ) {

			// Allow for direct response retrieval
			do_action( 'ep_query_retrieve_raw_response', $request, $args, $scope, $query_args );

			$response_body = wp_remote_retrieve_body( $request );

			$response = json_decode( $response_body, true );

			return $response;
		}

		return false;
	}

	/**
	 * Refresh the current index
	 *
	 * @since 0.9.0
	 * @return bool
	 */
	public function refresh_all_indexes() {

		$request_args = array( 'method' => 'POST' );

		$request = ep_remote_request( '_refresh', apply_filters( 'ep_refresh_index_request_args', $request_args ), array(), 'refresh_index' );

		if ( ! is_wp_error( $request ) ) {
			if ( isset( $request['response']['code'] ) && 200 === $request['response']['code'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Index a document in ES
	 *
	 * @param  string $document_id
	 * @param  array $document
	 * @param  string $type
	 * @param  string $index_name
	 * @param  bool $blocking
	 * @since  2.5
	 * @return array|bool|mixed
	 */
	public function index_document( $document_id, $document, $type, $index_name, $blocking = true ) {
		$document = apply_filters( 'ep_pre_index_document', $document, $document_id, $type, $index_name );

		$path = apply_filters( 'ep_index_document_request_path', $index_name . '/' . $type . '/' . $document_id, $document, $document_id, $type, $index_name );

		$request_args = array(
			'body'     => json_encode( $document ),
			'method'   => 'PUT',
			'timeout'  => 15,
			'blocking' => $blocking,
		);

		$request = ep_remote_request( $path, apply_filters( 'ep_index_document_request_args', $request_args, $document, $document_id, $type, $index_name ), array(), 'index_document' );

		do_action( 'ep_index_document_retrieve_raw_response', $request, $document, $document_id, $type, $index_name );

		if ( ! is_wp_error( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			$return = json_decode( $response_body );
		} else {
			$return = false;
		}

		do_action( 'ep_after_index_document', $return, $request, $document, $document_id, $type, $index_name );

		return $return;
	}

	/**
	 * Delete a post from the ES server given a site ID and a host site ID which
	 * is used to determine the index to delete from.
	 *
	 * @param int $document_id
	 * @param bool $blocking
	 * @since 0.1.0
	 * @return bool
	 */
	public function delete_document( $document_id, $type, $index_name, $blocking = true ) {

		$path = $index_name . '/' . $type . '/' . $document_id;

		$request_args = array( 'method' => 'DELETE', 'timeout' => 15, 'blocking' => $blocking );

		$request = ep_remote_request( $path, apply_filters( 'ep_delete_document_request_args', $request_args, $document_id, $type, $index_name ), array(), 'delete_document' );

		if ( ! is_wp_error( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			$response = json_decode( $response_body, true );

			if ( ! empty( $response['found'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a document from the index
	 *
	 * @param  int $document_id
	 * @param  string $type
	 * @since  0.9.0
	 * @return bool
	 */
	public function get_document( $document_id, $type, $index_name ) {

		$path = $index_name . '/' . $type . '/' . $document_id;

		$request_args = array( 'method' => 'GET' );

		$request = ep_remote_request( $path, apply_filters( 'ep_get_document_request_args', $request_args, $document_id, $type, $index_name ), array(), 'get_document' );

		if ( ! is_wp_error( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			$response = json_decode( $response_body, true );

			if ( ! empty( $response['exists'] ) || ! empty( $response['found'] ) ) {
				return $response['_source'];
			}
		}

		return false;
	}

	/**
	 * Query logging. Don't log anything to the queries property when
	 * WP_DEBUG is not enabled. Calls action 'ep_add_query_log' if you
	 * want to access the query outside of the ElasticPress plugin. This
	 * runs regardless of debufg settings.
	 *
	 * @param array $query Query.
	 *
	 * @return void Method does not return.
	 */
	public function _add_query_log( $query ) {
		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_EP_DEBUG' ) && WP_EP_DEBUG ) ) {
			$this->queries[] = $query;
		}

		do_action( 'ep_add_query_log', $query );
	}

	/**
	 * Return queries for debugging
	 *
	 * @since  1.8
	 * @return array
	 */
	public function get_query_log() {
		return $this->queries;
	}
}

EP_Elasticsearch::factory();

function ep_remote_request( $path, $args = array(), $log_args = array(), $type = null ) {
	return EP_Elasticsearch::factory()->remote_request( $path, $args, $log_args, $type );
}

/**
 * Accessor functions for methods in above class. See doc blocks above for function details.
 */

function ep_index_document( $document_id, $document, $type, $index_name, $blocking = true ) {
	return EP_Elasticsearch::factory()->index_document( $document_id, $document, $type, $index_name, $blocking );
}

function ep_query( $query, $type, $index_name ) {
	return EP_Elasticsearch::factory()->query( $query, $type, $index_name );
}

function ep_get_document( $document_id, $type, $index_name ) {
	return EP_Elasticsearch::factory()->get_document( $document_id, $type, $index_name );
}

function ep_delete_document( $document_id, $type, $index_name, $blocking = true ) {
	return EP_Elasticsearch::factory()->delete_document( $document_id, $type, $index_name, $blocking );
}

function ep_put_mapping( $mapping, $index_name ) {
	return EP_Elasticsearch::factory()->put_mapping( $mapping, $index_name );
}

function ep_get_pipeline( $id ) {
	return EP_Elasticsearch::factory()->get_pipeline( $id );
}

function ep_create_pipeline( $id, $args ) {
	return EP_Elasticsearch::factory()->create_pipeline( $id, $args );
}

function ep_delete_index( $index_name ) {
	return EP_Elasticsearch::factory()->delete_index( $index_name );
}

function ep_create_network_alias( $indexes ) {
	return EP_Elasticsearch::factory()->create_network_alias( $indexes );
}

function ep_delete_network_alias() {
	return EP_Elasticsearch::factory()->delete_network_alias();
}

function ep_refresh_index() {
	return EP_Elasticsearch::factory()->refresh_index();
}

function ep_bulk_index_documents( $request_body, $type, $index_name ) {
	return EP_Elasticsearch::factory()->bulk_index_documents( $request_body, $type, $index_name );
}

function ep_elasticpress_enabled( $query ) {
	return EP_Elasticsearch::factory()->elasticpress_enabled( $query );
}

function ep_index_exists( $index_name = null ) {
	return EP_Elasticsearch::factory()->index_exists( $index_name );
}

function ep_get_query_log() {
	return EP_Elasticsearch::factory()->get_query_log();
}

function ep_parse_api_response( $response ) {
	return EP_Elasticsearch::factory()->parse_api_response( $response );
}

function ep_get_elasticsearch_plugins( $force = false ) {
	return EP_Elasticsearch::factory()->get_elasticsearch_plugins();
}

function ep_get_elasticsearch_version( $force = false ) {
	return EP_Elasticsearch::factory()->get_elasticsearch_version( $force );
}
