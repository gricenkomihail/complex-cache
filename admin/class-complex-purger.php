<?php
require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-fastcgi-purger.php';

class Complex_Purger extends FastCGI_Purger {

	private $purge_stack = array();

	public function __construct() {

		add_action( 'shutdown', array( $this, 'do_purge' ), 99999 );
	}

	public function purge_all() {

		$this->log( 'True purge all' );

		$this->do_purge( true );
	}

	public function do_purge( $purge_all = false ) {

		if ( $purge_all || ! empty( $this->purge_stack ) ) {
			$this->log( 'Doing purge of ' . count( $this->purge_stack ) . ' urls' );

			$this->purge_stack = array_unique( $this->purge_stack );
			$blog_url          = parse_url( get_site_url( 1 ) );

			if ( is_array( $blog_url ) && isset( $blog_url['host'] ) ) {

				$rest_url = 'https://127.0.0.1:8085/rest/v1/svc0/00000000000000000000000000000000/' . $blog_url['host'] . '/0/cache/purge';

				if ( $purge_all === true ) {
					$request = json_encode( array( 'purge' => 'all' ) );
				} elseif ( ! empty( $this->purge_stack ) ) {
					$request = json_encode( $this->purge_stack );
				} else {
					$request = '';
				}

				if ( ! empty( $request ) ) {
					wp_remote_post( $rest_url, array( 'body' => $request, 'sslverify' => false ) );
				}
			}

			$this->purge_stack = array();
		}
	}

	protected function delete_cache_file_for( $url ) {

		if ( ! empty( $url ) ) {
			$this->add_to_stack( $url );
		}
	}

	private function add_to_stack( $url ) {

		$this->log( 'Adding to stack url: ' . $url );

		if ( ! is_array( $url ) ) {
			$url = array( $url );
		}

		$url = array_unique( $url );

		foreach ( $url as $purge_url ) {

			$url_data = parse_url( $purge_url );

			if ( ! empty( $url_data ) ) {
				$this->purge_stack[] = $url_data['scheme'] . '://' . $url_data['host'] . $url_data['path'];
			}
		}
	}
}