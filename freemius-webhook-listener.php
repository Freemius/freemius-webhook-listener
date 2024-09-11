<?php
/**
 * Plugin Name:  Simple Freemius WebHooks Listener
 * Description:  WebHook listener for subscribing Freemium users to 3rd party services like MailChimp.
 * Author:       SebeT
 * Contributors: Freemius, PressWizards
 * Version:      1.0
 * Author URI:   https://bruno-carreco.com
 */

/**
 * Notes:
 *
 * . This plugin subscribes/unsubscribes users to specific MailChimp list segments depending on their state: Free or Premium.
 * . The plugin can be extended to use other services.
 *
 */

/**
 * The base classe for listening to WebHooks.
 */
final class Freemius_WebHook_Listener {

    private static $plugin = array(
        'type'       => 'plugin', //  'plugin' or 'theme'
        'id'         => '<YOUR THEME/PLUGIN ID>',
        'public_key' => '<THE PUBLIC KEY>',
        'secret_key' => '<THE SECRET KEY>'
    );

    /**
     * Listens to Freemius WebHooks using a specific query string param 'fwebhook' which informs on the service to use.
	 *
	 * e.g:: http://your-site.com?fwebhook=mailchimp (this is the link you would set on your Freemius dashboard under 'Settings' > 'WebHooks' to use 'MailChimp')
     */
    public static function listen() {

        if ( empty( $_SERVER['QUERY_STRING'] ) || false === strpos( $_SERVER['QUERY_STRING'], 'fwebhook' ) ) {
            return;
        }

        parse_str( $_SERVER['QUERY_STRING'] );

        switch ( $fwebhook ) {

            case 'mailchimp':
                self::mailchimp();
                break;

			// other services here


        }

        http_response_code(200);
    }

    /**
     * Execute Mailchimp related API calls.
     *
     * http://developer.mailchimp.com/documentation/mailchimp/reference
     */
    protected static function mailchimp() {

        // Retrieve the request's body
        $input = @file_get_contents("php://input");

        /**
         * Freemius PHP SDK can be downloaded from GitHub:
         * https://github.com/Freemius/php-sdk
         */
        require_once dirname(__FILE__) . '/includes/freemius/includes/sdk/Freemius.php';

        extract( self::$plugin );

        // Verify the authenticity of the request.
        $hash = hash_hmac('sha256', $input, $secret_key);

        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

        if ( ! hash_equals($hash, $signature))
        {
            // Invalid signature, don't expose any data to attackers.
            http_response_code(200);
            exit;
        }

        // Decode the request.
        $fs_event = json_decode($input);

        $user  = $fs_event->objects->user;

        $email = $user->email;
        $first = $user->first;
        $last  = $user->last;

        $data = array(
            'email_address' => $email,
            'merge_fields'  => array(
                'FNAME' => $first,
                'LNAME' => $last
            ),
        );

        $mc = new PW_Mailchimp_API();

        switch ( $fs_event->type ) {

            case 'install.installed':

				// User installed the plugin.

                $list_id = '<THE MAILCHIMP LIST ID>';

                // Subscribe users to specific Mailchimp list.
                $mc->subscribe( $data, $list_id );

                // Subscribe FREE users to a specific list FREE segment.
                $mc->subscribe( $data, $list_id, $segment_id = '<THE MAILCHIMP FREE LIST SEGMENT ID>' ); // test bfc
                break;

            case 'license.created':

				// User upgraded.

                $list_id = '<THE MAILCHIMP LIST ID>';

                // Subscribe PREMIUM users to the list PREMIUM segment.
                $mc->subscribe( $data, $list_id, $segment_id = '<THE MAILCHIMP PREMIUM LIST SEGMENT ID>' ); // teste bfc

                // Remove the user from the FREE list segment.
                $mc->remove( $email, $list_id, $segment_id = '<THE MAILCHIMP FREE LIST SEGMENT ID>' ); // teste bfc
                break;

            case 'license.expired':
            case 'install.plan.downgraded':

				// User downgraded/license expired.

                $list_id = '<THE MAILCHIMP LIST ID>';

                // Subscribe downgraded user to the MailChimp list FREE segment.
                $mc->subscribe( $data, $list_id, $segment_id = 'THE MAILCHIMP FREE LIST SEGMENT ID>' ); // teste bfc

                // Remove the user from the list PREMIUM segment.
                $mc->remove( $email, $list_id, $segment_id = '<THE MAILCHIMP PREMIUM LIST SEGMENT ID>' ); // teste bfc
                break;

        }

    }

 }


/**
 * Base class for Mailchimp API callbacks.
 */
class PW_Mailchimp_API {

    /**
     * The Mailchimp API key.
     */
    private $api_key = '<YOUR MAILCHIMP API KEY>';

    /**
     * __construct.
     */
    public function __construct( $api_key = ''  ) {

        if ( $api_key ) {
            $this->api_key = $api_key;
        }

    }

    /**
     * Subscribe a user to a specific Mailchimp list ID and segment ID (if provided).
     */
    public function subscribe( $data, $list_id, $segment_id = 0 ) {

        $data['status'] = 'subscribed';

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode( 'user:' . $this->api_key )
            ),
            'body' => json_encode( $data )
        );

        $api_key_parts = explode( '-', $this->api_key );
        $dc            = $api_key_parts[1];

        $url = 'http://' . $dc . '.api.mailchimp.com/3.0';

        if ( $segment_id ) {
            $url .= "/lists/{$list_id}/segments/{$segment_id}/members";
        } else {
            $url .= "/lists/{$list_id}/members";
        }
        return $this->_wp_remote_post( $url, $args );
    }


    /**
     * Unsubscribe a user to a specific Mailchimp list ID and segment ID (if provided).
     */
    public function remove( $email, $list_id, $segment_id = 0 ) {

        $args = array(
            'method'  => 'DELETE',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( 'user:' . $this->api_key )
            ),
        );

        $api_key_parts = explode( '-', $this->api_key );
        $dc            = $api_key_parts[1];

        $url = 'http://' . $dc . '.api.mailchimp.com/3.0';

        $subscriber_hash = md5( strtolower( $email ) );

        if ( $segment_id ) {
            $url .= "/lists/{$list_id}/segments/{$segment_id}/members/{$subscriber_hash}";
        } else {
            $url .= "/lists/{$list_id}/members/{$subscriber_hash}";
        }
        return $this->_wp_remote_post( $url, $args );
    }

    /**
     * Wrapper for the 'wp_remote_post()' calls.
     */
    private function _wp_remote_post( $url, $args ) {
        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {

            $this->log( $response );

            return $response;
        }
        return true;
    }


    /**
     * Log any errors.
     */
    public function log( $log )  {
        if ( is_array( $log ) || is_object( $log ) ) {
            error_log( print_r( $log, true ) );
        } else {
            error_log( $log );
        }
   }

}

Freemius_WebHook_Listener::listen();
