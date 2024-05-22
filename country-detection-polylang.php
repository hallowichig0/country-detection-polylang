<?php
/**
 * Plugin Name: Polylang Country Detection
 * Description: Detects the preferred language according on the country ip
 * Version: 1.0.0
 * Author: Jayson Garcia (Github - hallowichig0)
 * Author URI: https://hallowichig0.github.io
 * Requires at least: 6.0
 * Requires PHP: 7.1
 * Text Domain: polylang-country-detection
 * Domain Path: /languages/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // don't access directly
}

if( !class_exists( 'Polylang_Country_Detection' ) )
{
    class Polylang_Country_Detection
    {
        /**
         * Get the preferred languages according to the IP address
         *
         * @return array the preferred language slugs
         */
        private function get_accept_country_langs()
        {
            $accept_langs = array();
            $country_code = $this->get_client_country_code();
            // $country_code = 'FR'; // France

            error_log( 'Country code: ' . var_export( $country_code, true ) );

            // IP Geolocation API response was successfully
            if ( $country_code !== false )
            {
                // Get all accept languages by country code (flag)
                $accept_langs = wp_list_filter( PLL_Settings::get_predefined_languages(), array(
                    'flag' => strtolower( $country_code )
                ) );
            }

            return $accept_langs;
        }

        /**
         * Return real client IP
         *
         * @return  mixed  $ip  Client IP
         */
        private function get_client_ip()
        {
            // Sanitization of $ip takes place further down.
            $ip = '';

            if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
                $ip = wp_unslash( $_SERVER['HTTP_CLIENT_IP'] );
            } elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $ip = wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] );
            } elseif ( isset( $_SERVER['HTTP_X_FORWARDED'] ) ) {
                $ip = wp_unslash( $_SERVER['HTTP_X_FORWARDED'] );
            } elseif ( isset( $_SERVER['HTTP_FORWARDED_FOR'] ) ) {
                $ip = wp_unslash( $_SERVER['HTTP_FORWARDED_FOR'] );
            } elseif ( isset( $_SERVER['HTTP_FORWARDED'] ) ) {
                $ip = wp_unslash( $_SERVER['HTTP_FORWARDED'] );
            }

            $ip = $this->sanitize_ip( $ip );
            if ( $ip ) {
                return $ip;
            }

            if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
                $ip = wp_unslash( $_SERVER['REMOTE_ADDR'] );
                return $this->sanitize_ip( $ip );
            }

            return '';
        }

        /*
         * Returns the country code from the client.
         */
        private function get_client_country_code()
        {
            $client_ip = $this->get_client_ip();

            error_log( 'Client IP: ' . var_export( $client_ip, true ) );

            $polylang_iplocate_api_key = getenv('POLYLANG_IPLOCATE_API_KEY');

            // Filters the IPLocate API key. With this filter, you can add your own IPLocate API key.
            $apikey = apply_filters( 'pll_country_detection_iplocate_apikey', $polylang_iplocate_api_key );

            $iplocate_url = sprintf( 'https://www.iplocate.io/api/lookup/%s?apikey=%s', $this->anonymize_ip( $client_ip ), $apikey );

            // Do a IP address search by the IP Geolocation API (https://iplocate.io/)
            $response = wp_safe_remote_get( esc_url_raw( $iplocate_url, 'https' ) );

            // Check if there was an request error
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                return false;
            }

            // Retrieve the body from the response
            $json = json_decode( wp_remote_retrieve_body( $response ), true );

            // Check if response is valid json.
            if ( ! is_array( $json ) || empty( $json['country_code'] ) ) {
                return false;
            }

            // IP Geolocation API response was successfully
            $country_code = strtoupper( $json['country_code'] );

            if ( empty( $country_code ) || strlen( $country_code ) !== 2 ) {
                return false;
            }

            return $country_code;
        }

        /**
         * Constructor
         */
        public function __construct()
        {
            /*
             * Plugin initialization
             * Take no action before all plugins are loaded
             */
            add_action( 'plugins_loaded' , array( $this, 'load_plugin_textdomain' ) );
            add_filter( 'pll_preferred_language' , array( $this, 'set_preferred_language' ), 10, 2 );
        }

        /*
         * HOOK
         * Initialize the textdomain
         */
        public function load_plugin_textdomain() {
            load_plugin_textdomain('polylang-country-detection', false, plugin_basename( dirname(__FILE__) ) . '/languages' );
        }

        /**
         * Filter the visitor's preferred language (normally set first by cookie
         * if this is not the first visit, then by the country).
         * If no preferred language has been found or set by this filter,
         * Polylang fallbacks to the default language
         *
         * @param string|bool $language Preferred language code, false if none has been found.
         * @param bool        $cookie   Whether the preferred language has been defined by the cookie.
         */
        public function set_preferred_language( $language, $cookie )
        {
            // Check first if the user was already browsing this site.
            if( $cookie ) {
                return $language;
            }

            // Browser language detetion is disabled
            if( !PLL()->options['browser'] ) {
                return $language;
            }

            // Get the preferred languages according to the IP address
            $accept_langs = $this->get_accept_country_langs();

            if( empty( $accept_langs ) ) {
                return $language;
            }

            $languages = PLL()->model->get_languages_list( array( 'hide_empty' => true ) ); // Hides languages with no post

            // Filter all non active languages
            if ( wp_list_filter( $languages, array( 'active' => false ) ) ) {
                $languages = wp_list_filter( $languages, array( 'active' => false ), 'NOT' );
            }

            // Filter the list of languages to use to match the country
            $languages = apply_filters( 'pll_languages_for_browser_preferences', $languages );

            // Looks through sorted list and use first one that matches our language list
            foreach ( array_keys( $accept_langs ) as $accept_lang )
            {
                // First loop to match the exact locale
                foreach ( $languages as $lang_obj ) {
                    if ( 0 === strcasecmp( $accept_lang, $lang_obj->get_locale() ) ) {
                        return $lang_obj->slug;
                    }
                }

                // Second loop to match the language set
                foreach ( $languages as $lang_obj ) {
                    if ( 0 === stripos( $accept_lang, $lang_obj->slug ) || 0 === stripos( $lang_obj->get_locale(), $accept_lang ) ) {
                        return $lang_obj->slug;
                    }
                }
            }

            return $language;
        }


        /**
         * Sanitize an IP string.
         *
         * @param string $raw_ip The raw IP.
         *
         * @return string The sanitized IP or an empty string.
         */
        private function sanitize_ip( $raw_ip )
        {
            if ( strpos( $raw_ip, ',' ) !== false )
            {
                $ips    = explode( ',', $raw_ip );
                $raw_ip = trim( $ips[0] );
            }

            if ( function_exists( 'filter_var' ) ) {
                return (string) filter_var($raw_ip, FILTER_VALIDATE_IP);
            }

            return (string) preg_replace('/[^0-9a-f:. ]/si', '', $raw_ip);
        }


        /**
         * Anonymize the IP addresses
         *
         * @param   string $ip Original IP.
         * @return  string     Anonymous IP.
         */
        private function anonymize_ip( $ip )
        {
            if ( $this->is_ipv4( $ip ) ) {
                return $this->cut_ip( $ip ) . '.0';
            }

            return $this->cut_ip( $ip, false ) . ':0:0:0:0:0:0:0';
        }

        /**
         * Check for an IPv4 address
         *
         * @param   string $ip  IP to validate.
         * @return  integer       TRUE if IPv4.
         */
        function is_ipv4( $ip )
        {
            if ( function_exists( 'filter_var' ) ) {
                return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false;
            } else {
                return preg_match( '/^\d{1,3}(\.\d{1,3}){3}$/', $ip );
            }
        }

        /**
         * Trim IP addresses
         *
         * @param   string  $ip       Original IP.
         * @param   boolean $cut_end  Shortening the end.
         * @return  string            Shortened IP.
         */
        function cut_ip( $ip, $cut_end = true )
        {
            $separator = ( $this->is_ipv4( $ip ) ? '.' : ':' );

            return str_replace(
                ( $cut_end ? strrchr( $ip, $separator ) : strstr( $ip, $separator ) ),
                '',
                $ip
            );
        }
    }
}

new Polylang_Country_Detection();

