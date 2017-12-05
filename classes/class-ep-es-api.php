<?php
/**
 * Elasticsearch API
 *
 * @since  2.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class EP_ES_API {
	/**
	 * Placeholder method
	 *
	 * @since 2.5
	 */
	public function __construct() { }

	/**
	 * Logged queries for debugging
	 *
	 * @since  2.5
	 */
	private $queries = array();

	/**
	 * ES plugins
	 *
	 * @var array
	 * @since  2.5
	 */
	public $elasticsearch_plugins = null;

	/**
	 * ES version number
	 *
	 * @var string
	 * @since  2.5
	 */
	public $elasticsearch_version = null;

	/**
	 * Return singleton instance of class
	 *
	 * @return EP_API
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
	 * Return queries for debugging
	 *
	 * @since  1.8
	 * @return array
	 */
	public function get_query_log() {
		return $this->queries;
	}

	public function index_document() {

	}

	public function delete_document() {

	}



	/**
	 * Checks if index exists by index name, returns true or false
	 *
	 * @param null $index_name
	 * @since  2.5
	 * @return bool
	 */
	public function index_exists( $index_name  {

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

	public function query_documents() {

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

					$request = ep_remote_request( '', array( 'method' => 'GET' ) );

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
	 * Get cluster status
	 *
	 * Retrieves cluster stats from Elasticsearch.
	 *
	 * @since 1.9
	 *
	 * @return array Contains the status message or the returned statistics.
	 */
	public function get_cluster_status() {

		if ( is_wp_error( ep_get_host() ) ) {

			return array(
				'status' => false,
				'msg'    => esc_html__( 'Elasticsearch Host is not available.', 'elasticpress' ),
			);

		} else {

			$request = ep_remote_request( '_cluster/stats', array( 'method' => 'GET' ) );

			if ( ! is_wp_error( $request ) ) {

				$response = json_decode( wp_remote_retrieve_body( $request ) );

				return $response;

			}

			return array(
				'status' => false,
				'msg'    => $request->get_error_message(),
			);

		}
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
	 * @param array  $query_args Optional. The query args originally passed to WP_Query
	 * @param string Type of request, used for debugging
	 *
	 * @return WP_Error|array The response or WP_Error on failure.
	 */
	public function remote_request( $path, $args = array(), $query_args = array(), $type = null ) {

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
			'query_args'   => $query_args,
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
	 * @param array $args
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
}
