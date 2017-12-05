<?php

class EP_Indexables {
	public $registered_indexables = array();

	public function setup() {
		add_action( 'init', array( $this, 'initialize_indexables'), 1 );
	}

	public function initialize_indexables() {
		foreach ( $this->registered_indexables as $indexable ) {
			$indexable->setup();
		}
	}

	public function register_indexable( EP_Indexable $indexable ) {
		$this->register_indexable[ $indexable->slug ] = $indexable;
	}

    /**
	 * Return singleton instance of class
	 *
	 * @return object
	 * @since 2.5
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance  ) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}
}

function ep_register_indexable( EP_Indexable $indexable ) {
	EP_Indexables::factory()->register_indexable( $indexable );
}

function ep_get_indexable( $slug ) {
	if ( ! empty( EP_Indexables::factory()->registered_indexables[ $slug ] ) ) {
		return EP_Indexables::factory()->registered_indexables[ $slug ];
	}

	return false;
}
