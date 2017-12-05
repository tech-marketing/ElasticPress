<?php

abstract class EP_Indexable {

	abstract public function setup();

	abstract public function query( $query, $args = array() );

	abstract public function index( $document );

	abstract public function delete( $document_id );

	abstract public function bulk_index( $request_body );

	abstract public function put_mapping();

	abstract public function delete_index();

	/**
	 * Generates the index name for the current site
	 *
	 * @param int $blog_id (optional) Blog ID. Defaults to current blog.
	 * @since 0.9
	 * @return string
	 */
	public function get_index_name( $blog_id = null ) {
		if ( ! $blog_id ) {
			$blog_id = get_current_blog_id();
		}

		$site_url = get_site_url( $blog_id );

		if ( ! empty( $site_url ) ) {
			$index_name = preg_replace( '#https?://(www\.)?#i', '', $site_url );
			$index_name = preg_replace( '#[^\w]#', '', $index_name ) . '-' . $this->type . '-' . $blog_id;
		} else {
			$index_name = false;
		}

		if ( defined( 'EP_INDEX_PREFIX' ) && EP_INDEX_PREFIX ) {
			$index_name = EP_INDEX_PREFIX . $index_name;
		}

		return apply_filters( 'ep_index_name', $index_name, $blog_id, $this->type );
	}
}
