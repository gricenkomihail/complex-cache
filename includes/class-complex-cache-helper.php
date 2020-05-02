<?php

define( 'COMPLEX_CACHE_HELPER_PLUGIN_FILE', 'complex-cache-helper/complex-cache-helper.php' );
define( 'COMPLEX_CACHE_HELPER_PLUGIN_SLUG', 'complex-cache-helper' );

define( 'COMPLEX_UPDATES_SERVER', 'files.wpcomplex.com' );

class Complex_Cache_Helper {

    private $unwanted_plugins = array('wp-fastest-cache/wpFastestCache.php',
                                        'w3-total-cache/w3-total-cache.php');

    private $textdomain = 'nginx-helper';

    private $plugin_headers = array();
    private $plugin_file;
    private $plugin_slug;
    private $plugin_ver;

    public function __construct($plugin_name, $original_version) {

    	$this->plugin_headers = $this->parse_plugin_headers( dirname( dirname( dirname( __FILE__ ) ) ) . '/' . COMPLEX_CACHE_HELPER_PLUGIN_FILE );

        $this->plugin_file = COMPLEX_CACHE_HELPER_PLUGIN_FILE;
        $this->plugin_slug = COMPLEX_CACHE_HELPER_PLUGIN_SLUG;

        $this->plugin_ver = $this->plugin_headers['Version'];

        add_action( 'admin_init', array( $this, 'check_unwanted_plugins' ) );
        add_filter( 'plugins_api', array( $this, 'filter_plugin_updates' ), 999, 3);
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_transient_update_plugins' ), 999, 1);
        add_filter( 'rt_nginx_helper_settings_tabs', array( $this, 'remove_support_tab' ), 10, 1 );
        add_filter( 'nginx_asset_path', array( $this, 'override_nginx_asset_path' ), 999, 1 );
        add_filter( 'nginx_asset_url', array( $this, 'override_nginx_asset_url' ), 999, 1 );
        add_filter( 'load_textdomain_mofile', array( $this, 'override_language_file' ), 10, 2 );
        add_filter( 'site_status_tests', array( $this, 'remove_unwanted_tests' ), 10, 1 );
    }

    public function override_nginx_asset_path( $log_path ) {

	    $log_path = WP_CONTENT_DIR . '/uploads/' . $this->plugin_slug . '/';

	    return $log_path;
    }

    public function override_nginx_asset_url( $log_url ) {

	    $log_url = WP_CONTENT_URL . '/uploads/' . $this->plugin_slug . '/';

	    return $log_url;
    }

	public function override_language_file( $mofile, $domain ) {

    	if ( $domain == $this->textdomain ) {

		    $mofile = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/languages/' . $this->plugin_slug . '-' . get_locale() . '.mo';
	    }

	    return $mofile;
	}

	public function remove_support_tab( $settings_tabs ) {

		if ( array_key_exists( 'support', $settings_tabs ) ) {

			unset( $settings_tabs['support'] );
		}

		return $settings_tabs;
	}

    public function check_unwanted_plugins() {

        $total_plugins = get_plugins();

        foreach ($total_plugins as $plugin_file => $plugin_data)
        {
            if (in_array($plugin_file, $this->unwanted_plugins) && is_plugin_active($plugin_file))
                $this->admin_notice_warning_plugin($plugin_data);
        }
    }

    public function filter_plugin_updates($res, $action, $args) {

        if( $action !== 'plugin_information' )
            return $res;

        if( $this->plugin_slug !== $args->slug )
            return $res;

        $plugin_info = $this->transient_remote_request('plugin_info');

        return $plugin_info;
    }

    public function filter_transient_update_plugins($transient) {

        if ( empty($transient->last_checked) ) {

	        return $transient;
        }

        if (is_object($transient) && isset($transient->response)) {

            $update_info = $this->check_plugin_updates();

            if (is_object($update_info) && isset($update_info->slug) && $update_info->slug === COMPLEX_CACHE_HELPER_PLUGIN_SLUG) {

	            $plugin_info = new stdClass();

	            $plugin_info->slug          = $update_info->slug;
	            $plugin_info->new_version   = $update_info->new_version;
	            $plugin_info->plugin        = $update_info->plugin;
	            $plugin_info->url           = $update_info->url;
	            $plugin_info->package       = $update_info->package;

	            $transient->response[$plugin_info->plugin] = $plugin_info;
            }

            if (isset($transient->response[COMPLEX_CACHE_HELPER_PLUGIN_FILE])) {

            	if ($transient->response[COMPLEX_CACHE_HELPER_PLUGIN_FILE]->new_version == $this->plugin_ver) {

            		$transient->no_update[COMPLEX_CACHE_HELPER_PLUGIN_FILE] = $transient->response[COMPLEX_CACHE_HELPER_PLUGIN_FILE];

		            unset($transient->response[COMPLEX_CACHE_HELPER_PLUGIN_FILE]);
	            }
            }
        }

        return $transient;
    }

    public function remove_unwanted_tests($tests) {

    	if (defined('WP_AUTO_UPDATE_CORE') && WP_AUTO_UPDATE_CORE === false)
    		unset($tests['async']['background_updates']);

    	if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true)
    		unset($tests['direct']['scheduled_events']);

    	return $tests;
    }

    private function check_plugin_updates() {

        return $this->transient_remote_request('plugin_update');
    }

    private function transient_remote_request($action) {

        $transient = 'upgrade_plugin_request_' . $this->plugin_slug . '_' . $action;

        if( false == ( $remote = get_transient( $transient ) ) ) {

            $remote = wp_remote_get( $this->get_server_uri($action), array('timeout' => 10,
                                                                                    'headers' => array( 'Accept' => 'application/json')) );

            if ( !is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && !empty( $remote['body'] ) ) {

                $response = json_decode($remote['body'], true);

                if (!empty($response))
                    set_transient( $transient, $response, 43200 );
            }
        }
        else
            $response = $remote;

        return (!empty($response) && is_array($response)) ? (object)$response : null;
    }

    private function get_server_uri($action)
    {
        $uri = 'https://' . COMPLEX_UPDATES_SERVER . '/v1/';

        switch ($action)
        {
            case 'plugin_update':
                {
                    $uri .= 'plugins/update/' . $this->plugin_slug . '?ver=' . $this->plugin_ver;

                    break;
                }
            case 'plugin_info':
                {
                    $uri .= 'plugins/info/' . $this->plugin_slug . '?ver=' . $this->plugin_ver;

                    break;
                }
            default:
                {

                }
        }

        return $uri;
    }

    private function admin_notice_warning_plugin($plugin) {

        $class = 'notice notice-warning';
        $plugin = $plugin['Name'];

        $plugin_message = sprintf(__('Unfortunately plugin <b>%s</b> may cause issues with caching. Please, deactivate it to prevent such errors.', $this->textdomain), $plugin);
        $title_message = __('Complex Cache', $this->textdomain);

        $message = sprintf('<b>%s</b></br>&nbsp&nbsp&nbsp%s', $title_message, $plugin_message);

        printf( '<div class="%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), $message);
    }

    private function log($message) {

        global $nginx_purger;

        if (is_object($nginx_purger))
            $nginx_purger->log($message);
    }

    private function parse_plugin_headers($file) {

	    $default_headers = array (
			    'Name'        => 'Plugin Name',
			    'PluginURI'   => 'Plugin URI',
			    'Version'     => 'Version',
			    'Description' => 'Description',
			    'Author'      => 'Author',
			    'AuthorURI'   => 'Author URI',
			    'TextDomain'  => 'Text Domain',
			    'DomainPath'  => 'Domain Path',
			    'Network'     => 'Network',
			    'RequiresWP'  => 'Requires at least',
			    'RequiresPHP' => 'Requires PHP',
			    // Site Wide Only is deprecated in favor of Network.
			    '_sitewide'   => 'Site Wide Only',
	    );

	    $fp = fopen( $file, 'r' );

	    // Pull only the first 8 KB of the file in.
	    $file_data = fread( $fp, 8 * KB_IN_BYTES );

	    // PHP will close file handle, but we are good citizens.
	    fclose( $fp );

	    // Make sure we catch CR-only line endings.
	    $file_data = str_replace( "\r", "\n", $file_data );

	    // $extra_headers = $context ? apply_filters( "extra_{$context}_headers", array() ) : array();

	    $extra_headers =  array();

	    if ( $extra_headers ) {
		    $extra_headers = array_combine( $extra_headers, $extra_headers ); // keys equal values
		    $all_headers   = array_merge( $extra_headers, (array) $default_headers );
	    } else {
		    $all_headers = $default_headers;
	    }

	    foreach ( $all_headers as $field => $regex ) {
		    if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {

			    $all_headers[ $field ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
		    } else {
			    $all_headers[ $field ] = '';
		    }
	    }

	    return $all_headers;
    }
}