<?php

define( 'COMPLEX_PURGER_PLUGIN_FILE', 'wpcomplex-caching-system/wpcomplex-caching-system.php' );
define( 'COMPLEX_PURGER_PLUGIN_SLUG', 'wpcomplex-caching-system' );
define( 'COMPLEX_PURGER_PLUGIN_VER', '2.0.1' );

define( 'COMPLEX_PURGER_UPDATES_SERVER', 'files.wpcomplex.com' );

class Complex_Purger_Helper {

    private $unwanted_plugins = array('wp-fastest-cache/wpFastestCache.php',
                                        'w3-total-cache/w3-total-cache.php');

    private $textdomain = 'nginx-helper';

    private $plugin_file;
    private $plugin_slug;
    private $plugin_ver;

    public function __construct($plugin_name, $original_version) {

        $this->plugin_file = COMPLEX_PURGER_PLUGIN_FILE;
        $this->plugin_slug = COMPLEX_PURGER_PLUGIN_SLUG;
        $this->plugin_ver = COMPLEX_PURGER_PLUGIN_VER;

        add_action( 'admin_init', array( $this, 'check_unwanted_plugins' ) );
        add_filter( 'plugins_api', array( $this, 'filter_plugin_updates' ), 99, 3);
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_transient_update_plugins' ), 99, 1);
        add_filter( 'rt_nginx_helper_settings_tabs', [ $this, 'remove_support_tab' ], 10, 1 );
        add_filter( 'load_textdomain_mofile', [ $this, 'override_language_file' ], 10, 2 );
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

        return false;
    }

    public function filter_transient_update_plugins($transient) {

        if (empty($transient->last_checked))
            return $transient;

        if (is_object($transient) && isset($transient->response))
        {
            $update_info = $this->check_plugin_updates();

            $plugin_info = new stdClass();

            $plugin_info->slug = $this->plugin_slug;
            $plugin_info->new_version = $update_info->version;
            $plugin_info->url = $update_info->url;
            $plugin_info->package = $update_info->package;
            $plugin_info->plugin = $this->plugin_file;

            $transient->response[$plugin_info->plugin] = $plugin_info;
        }

        return $transient;
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
        $uri = 'http://' . COMPLEX_PURGER_UPDATES_SERVER . '/v1/';

        switch ($action)
        {
            case 'plugin_update':
                {
                    $uri .= 'plugin/update/' . $this->plugin_slug . '?ver=' . $this->plugin_ver;

                    break;
                }
            case 'plugin_info':
                {
                    $uri .= 'plugin/info/' . $this->plugin_slug . '?ver=' . $this->plugin_ver;

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

        $plugin_message = sprintf(__('Plugin: <b>%s</b> is incompatible with Wpcomplex. Please deactivate it to prevent system errors', $this->textdomain), $plugin);
        $title_message = __('Caching system', $this->textdomain);

        $message = sprintf('<b>%s</b></br>&nbsp&nbsp&nbsp%s', $title_message, $plugin_message);

        printf( '<div class="%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), $message);
    }

    private function log($message) {

        global $nginx_purger;

        if (is_object($nginx_purger))
            $nginx_purger->log($message);
    }
}