<?php
/*
    Plugin Name: AffiliateWP Mailchimp Add-on
    Plugin URI: http://bosun.me/affiliatewp-mailchimp-addon
    Description: Adds a checkbox for new affiliates to subscribe to your MailChimp Newsletter during signup.
    Version: 1.0
    Author: Tunbosun Ayinla
    Author URI: http://www.bosun.me
    License:           GPL-2.0+
    License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
    GitHub Plugin URI: https://github.com/tubiz/affiliatewp-mailchimp-addon
 */


if ( ! defined( 'ABSPATH' ) ) exit;


if( ! class_exists( 'AffiliateWP_MailChimp_Add_on' ) ){

    final class AffiliateWP_MailChimp_Add_on {
        private static $instance = false;

        public static function get_instance() {
            if ( ! self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'admin_init', array( $this, 'activation' ) );
            add_action( 'affwp_settings_integrations', array( $this, 'affwp_mailchimp_settings' ), 10 ,1 );
            add_action( 'affwp_process_register_form', array( $this, 'affwp_mailchimp_add_user_to_list' ) );

            if( !is_admin() ) {
                add_action( 'affwp_register_fields_after', array( $this, 'affwp_mailchimp_subscribe_checkbox' ) );
                add_action( 'affwp_register_fields_before_tos', array( $this, 'affwp_mailchimp_subscribe_checkbox' ) );
            }

            if( is_admin() ){
                add_action( 'affwp_new_affiliate_bottom', array( $this, 'affwp_mailchimp_admin_subscribe_checkbox' ) );
                add_action( 'affwp_add_affiliate', array( $this, 'affwp_mailchimp_admin_add_user_to_list' ) );
            }
        }

        // Checks if AffiliateWP is installed
        public function activation() {
            global $wpdb;

            $affwp_plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/AffiliateWP/affiliate-wp.php', false, false );

            if ( ! class_exists( 'Affiliate_WP' ) ) {

                // is this plugin active?
                if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {

                    // deactivate the plugin
                    deactivate_plugins( plugin_basename( __FILE__ ) );

                    // unset activation notice
                    unset( $_GET[ 'activate' ] );

                    // display notice
                    add_action( 'admin_notices', array( $this, 'admin_notices' ) );
                }

            }
            else {
                add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_link' ), 10, 2 );
            }
        }

        //Shows admin notice if AffiliateWP isn't installed
        public function admin_notices() {

            $affwp_plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/AffiliateWP/affiliate-wp.php', false, false );

            if ( ! class_exists( 'Affiliate_WP' ) ) {
                echo '<div class="error"><p>You must install and activate <strong><a href="https://affiliatewp.com/pricing" title="AffiliateWP" target="_blank">AffiliateWP</a></strong> to use <strong>AffiliateWP MailChimp Add-on</strong></p></div>';
            }

            if ( $affwp_plugin_data['Version'] < '1.1' ) {
                echo '<div class="error"><p><strong>AffiliateWP MailChimp Add-on</strong> requires <strong>AffiliateWP 1.1</strong> or greater. Please update <strong>AffiliateWP</strong>.</p></div>';
            }
        }

        //Plugin Settings Link
        public function settings_link( $links ) {
            $plugin_link = array(
                '<a href="' . admin_url( 'admin.php?page=affiliate-wp-settings&tab=integrations' ) . '">Settings</a>',
            );
            return array_merge( $plugin_link, $links );
        }

        //AffiliateWP Mailchimp Settings
        public function affwp_mailchimp_settings( $settings ) {

            $mailchimp_api_key  = affiliate_wp()->settings->get( 'affwp_mailchimp_api_key' );

            $mailchimp_lists    = $this->affwp_mailchimp_get_lists();

            if ($mailchimp_lists === false ) {
                $mailchimp_lists = array ();
            }

            if( ! empty ( $mailchimp_api_key ) ){
                $mailchimp_lists = array_merge( array( '' => 'Select a list' ), $mailchimp_lists );
            }
            else{
                $mailchimp_lists = array( '' => 'Enter your MailChimp API Key and save to see your lists' );
            }


            $affwp_mailchimp_settings = array(
                'affwp_mailchimp_header' => array(
                    'name' => '<strong>AffiliateWP MailChimp Settings</strong>',
                    'type' => 'header'
                ),
                'affwp_enable_mailchimp' => array(
                    'name' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'desc' => 'Enable MailChimp Subscription. This will add a checkbox to the affiliate registration page.'
                ),
                'affwp_mailchimp_form_label' => array(
                    'name' =>'Checkbox Label',
                    'desc' => 'Enter the form label here',
                    'type' => 'text',
                    'std' => 'Signup for my newsletter'
                ),
                'affwp_mailchimp_api_key' => array(
                    'name' =>'MailChimp API Key',
                    'desc' => '<br />Enter your MailChimp API Key here. Click <a href="https://us2.admin.mailchimp.com/account/api/" target="_blank">here</a> to login to MailChimp and get your API key.',
                    'type' => 'text',
                    'std' => ''
                ),
                'affwp_mailchimp_enable_opt_in' => array(
                    'name' => 'Double Opt-In',
                    'desc' => 'If enabled, affiliates will receive an email with a link to confirm their subscription to the list above',
                    'type' => 'checkbox'
                ),
                'affwp_mailchimp_list' => array(
                    'name' => 'Newsletter List',
                    'desc' => 'Choose the List you want the affiliate to be subscribe to when registered.',
                    'type' => 'select',
                    'options' => $mailchimp_lists
                )
            );

            return array_merge( $settings, $affwp_mailchimp_settings );
        }

        //Add Subscribe Checkbox to the signup page
        public function affwp_mailchimp_subscribe_checkbox(){
            $mailchimp_enabled  = affiliate_wp()->settings->get( 'affwp_enable_mailchimp' );
            $mailchimp_label    = affiliate_wp()->settings->get( 'affwp_mailchimp_form_label' );
            $mailchimp_api_key  = affiliate_wp()->settings->get( 'affwp_mailchimp_api_key' );
            $mailchimp_list     = affiliate_wp()->settings->get( 'affwp_mailchimp_list' );

            ob_start();
                if ( ! empty ( $mailchimp_enabled ) && ! empty ( $mailchimp_api_key )  && ! empty ( $mailchimp_list ) ){ ?>
                <p>
                    <input name="affwp_mailchimp_subscribe" id="affwp_mailchimp_subscribe" type="checkbox" checked="checked"/>
                    <label for="affwp_mailchimp_subscribe">
                        <?php
                            if ( ! empty ( $mailchimp_label ) ){
                                echo $mailchimp_label;
                            }
                            else{
                                echo 'Signup for our newsletter';
                            }
                        ?>
                    </label>
                </p>
                <?php
            }
            echo ob_get_clean();
        }

        //Add Subscribe Checkbox to the Add New Affiliate Page In the WordPress backend
        public function affwp_mailchimp_admin_subscribe_checkbox(){
            $mailchimp_enabled  = affiliate_wp()->settings->get( 'affwp_enable_mailchimp' );
            $mailchimp_label    = affiliate_wp()->settings->get( 'affwp_mailchimp_form_label' );
            $mailchimp_api_key  = affiliate_wp()->settings->get( 'affwp_mailchimp_api_key' );
            $mailchimp_list     = affiliate_wp()->settings->get( 'affwp_mailchimp_list' );

            ob_start();
                if ( ! empty ( $mailchimp_enabled ) && ! empty ( $mailchimp_api_key )  && ! empty ( $mailchimp_list ) ){ ?>
                <p>
                    <input name="affwp_mailchimp_subscribe" id="affwp_mailchimp_subscribe" type="checkbox" checked="checked"/>
                    <label for="affwp_mailchimp_subscribe">Add Affiliate to Newsletter</label>
                </p>
                <?php
            }
            echo ob_get_clean();
        }

        //Add New Affiliate to Newsletter List
        public function affwp_mailchimp_add_user_to_list(){

            $mailchimp_api_key  = affiliate_wp()->settings->get( 'affwp_mailchimp_api_key' );

            if( ! empty( $_POST['affwp_mailchimp_subscribe'] ) && ! empty( $mailchimp_api_key ) ) {

                $name               = explode( ' ', sanitize_text_field( $_POST['affwp_user_name'] ) );

                $first_name         = $name[0];
                $last_name          = isset( $name[1] ) ? $name[1] : '';
                $email              = sanitize_text_field( $_POST['affwp_user_email'] );

                $mailchimp_list     = affiliate_wp()->settings->get( 'affwp_mailchimp_list' );

                $mailchimp_api_key  = trim( $mailchimp_api_key );

                $check_opt_in       = affiliate_wp()->settings->get( 'affwp_mailchimp_enable_opt_in' );

                if( ! empty ( $check_opt_in ) ){
                    $optin = true;
                }else{
                    $optin = false;
                }

                require_once( '/classes/api/MailChimp.php' );

                $MailChimp = new \Drewm\MailChimp( $mailchimp_api_key );

                $result = $MailChimp->call('lists/subscribe', array(
                    'id'                => $mailchimp_list,
                    'email'             => array( 'email'=> $email ),
                    'merge_vars'        => array( 'FNAME'=> $first_name, 'LNAME'=> $last_name ),
                    'double_optin'      => $optin,
                    'update_existing'   => true,
                    'replace_interests' => false,
                    'send_welcome'      => false,
                ));

                if ( 'error' == $result['status'] ){
                    return false;
                }

                return true;

            }

            return false;
        }

        //Add New Affiliate to NewsLetter List from the Admin Add New Affiliate Page
        public function affwp_mailchimp_admin_add_user_to_list( $add ){
            global $wpdb;

            $affiliate  = affiliate_wp()->affiliates->get_by( 'affiliate_id', $add );
            $user_id    = $affiliate->user_id;

            $email      = $wpdb->get_var( $wpdb->prepare( "SELECT user_email FROM $wpdb->users WHERE ID = '%d'", $user_id ) );
            $name       = $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM $wpdb->users WHERE ID = '%d'", $user_id ) );

            $mailchimp_api_key  = affiliate_wp()->settings->get( 'affwp_mailchimp_api_key' );

            if( ! empty( $_POST['affwp_mailchimp_subscribe'] ) && ! empty( $mailchimp_api_key ) ) {

                $name               = explode( $name );

                $first_name         = $name[0];
                $last_name          = isset( $name[1] ) ? $name[1] : '';

                $mailchimp_list     = affiliate_wp()->settings->get( 'affwp_mailchimp_list' );

                $mailchimp_api_key  = trim( $mailchimp_api_key );

                $check_opt_in       = affiliate_wp()->settings->get( 'affwp_mailchimp_enable_opt_in' );

                if( ! empty ( $check_opt_in ) ){
                    $optin = true;
                }else{
                    $optin = false;
                }

                require_once( '/classes/api/MailChimp.php' );

                $MailChimp = new \Drewm\MailChimp( $mailchimp_api_key );

                $result = $MailChimp->call('lists/subscribe', array(
                    'id'                => $mailchimp_list,
                    'email'             => array( 'email'=> $email ),
                    'merge_vars'        => array( 'FNAME'=> $first_name, 'LNAME'=> $last_name ),
                    'double_optin'      => $optin,
                    'update_existing'   => true,
                    'replace_interests' => false,
                    'send_welcome'      => false,
                ));

                if ( 'error' == $result['status'] ){
                    return false;
                }

                return true;

            }

            return false;
        }

        //Get MailChimp Lists
        public function affwp_mailchimp_get_lists(){

            $mailchimp_api_key      = affiliate_wp()->settings->get( 'affwp_mailchimp_api_key' );
            $mailchimp_api_key      = trim( $mailchimp_api_key );

            if ( ! empty( $mailchimp_api_key ) ) {

                $mailchimp_lists        = array();

                if ( ! class_exists( 'MailChimp' ) )
                    require_once( '/classes/api/MailChimp.php' );

                $mailchimp = new \Drewm\MailChimp( $mailchimp_api_key );
                $lists = $mailchimp->call('lists/list');

                $lists_count =  $lists['total'];

                foreach ($lists['data'] as $list) {
                    $mailchimp_lists[ $list ['id'] ]  = $list['name'];
                }

                return $mailchimp_lists;
            }
            return false;
        }

    }

}


function tbz_affwp_mailchimp_addon() {
    return AffiliateWP_MailChimp_Add_on::get_instance();
}
add_action( 'plugins_loaded', 'tbz_affwp_mailchimp_addon' );
