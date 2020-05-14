<?php
/**
* Plugin Name: Woocommerce flow checkout
* Plugin URI: https://github.com/leandroqueiroz/woocommerce-flow-checkout/
* Description: 1) checkout no login redirect to My Acount and return checkout after login or create acount or edit account required. 2) Validate complete account and billing address and return checkout after 3) Disable billing address to checkout and save billing address to order.
* Version: 1.0
* Author: Leandro Queiroz
* Author URI: https://github.com/leandroqueiroz/
**/
//requer enable login pague checkout config woocommerce
if(get_option( 'woocommerce_enable_checkout_login_reminder' ) == 'yes')
    add_action( 'template_redirect', 'woocommerce_modify_checkout_flow', 10 );
//requer enable option shipping By default for the customer's shipping address
if(get_option( 'woocommerce_ship_to_destination' ) == 'shipping') {
    add_filter('woocommerce_checkout_fields', 'billing_address_checkout_disable');
    add_action('woocommerce_checkout_update_order_meta', 'billing_address_update_order_meta', 10, 2 );
    add_action('wp_head', 'billing_address_checkout_disable_stylesheet_to_head' );
    add_action('woocommerce_before_checkout_shipping_form', 'woocommerce_before_checkout_shipping_form_title_ship_to_address');
}
function woocommerce_modify_checkout_flow() {
    global $woocommerce;
    global $wp;
    try {
        // is login and not page logout current
        if (is_user_logged_in() && !isset($wp->query_vars[$woocommerce->query->query_vars['customer-logout']])){
            // is not valid account
            if( (is_checkout() || is_account_page()) && !is_edit_account_page() && !is_valid_account_wc()) 
            {   
                if(is_checkout()) WC()->session->set( 'wc_redirect_checkout','yes');
                wc_add_notice( sprintf( '<strong>%s</strong>: %s.', __( 'Update', 'woocommerce' ),  __( 'Account creation', 'woocommerce' )), 'notice' );
                //url edit account page
                throw new Exception(esc_url( trailingslashit( wc_get_account_endpoint_url( $woocommerce->query->query_vars['edit-account'] ) ) ));
            }
            // valid billing_info
            $is_edit_address_page=$wp->query_vars[$woocommerce->query->query_vars['edit-address']];
            if( (is_checkout() || is_account_page()) && ($is_edit_address_page <> "billing") && !is_valid_billing_info() && is_valid_account_wc()) 
            {   
                if(is_checkout()) WC()->session->set( 'wc_redirect_checkout','yes');
                wc_add_notice( sprintf( '<strong>%s</strong>: %s.', __( 'Update', 'woocommerce' ),  __( 'Billing address.', 'woocommerce' )), 'notice' );
                //redirect edit account page
                throw new Exception( esc_url( trailingslashit( wc_get_account_endpoint_url( $woocommerce->query->query_vars['edit-address']."/".__( 'billing', 'woocommerce' ) ) ) ));
            }
            // redirect checkout 
            if (!is_checkout() && is_valid_account_wc() && is_valid_billing_info()) {
                if ( WC()->session->get( 'wc_redirect_checkout') == 'yes' ) 
                {
                    WC()->session->set( 'wc_redirect_checkout', 'null');
                    throw new Exception(wc_get_checkout_url());
                }
            }
        }else{
            // check login and page checkout
            if ( is_checkout()) {
                // set redirect checkout
                WC()->session->set( 'wc_redirect_checkout','yes');
                //redirect login page
                throw new Exception(esc_url( trailingslashit( wc_get_account_endpoint_url( '' ) ) ));
            }
        }
    } catch (Exception $e) {
        header("Location: ".$e->getMessage());
        exit();
    }
}
function is_valid_account_wc(){
    global $woocommerce;
    try {
        //validate first name and last_name
        if (strlen($woocommerce->customer->first_name) <= 3) throw new Exception('first name');
        if (strlen($woocommerce->customer->last_name) <= 3) throw new Exception('last name');
        return true;
    } catch (Exception $e) {
       return false;
    }
}
function is_valid_billing_info(){
    global $woocommerce;
    $field = get_option( 'wc_fields_billing' );
    try {
        // validate fields plugin woocommerce-extra-checkout-fields-for-brazil.
        if( class_exists( 'Extra_Checkout_Fields_For_Brazil' )){
            // get parameter values
            $settings    = get_option( 'wcbcf_settings' );
            // set array billing info
            foreach ($woocommerce->customer->meta_data as $key => &$value) $billing_info[$value->key]=$value->value;
            //valid info
            // cpf 
            if ($settings['validate_cpf'] == 1 && $billing_info['billing_persontype'] == 1 && strlen($billing_info['billing_cpf']) <> 14) throw new Exception('billing cpf');
            // cnpj
            if ($settings['validate_cnpj'] == 1 && $billing_info['billing_persontype'] == 2 && strlen($billing_info['billing_cnpj']) > 14) throw new Exception('billing cnpj');
            // birthdate
            if ($settings['birthdate_sex'] == 1 && strlen($billing_info['billing_birthdate']) <> 10) throw new Exception('billing birthdate');
            // sex
            if ($settings['birthdate_sex'] == 1 && strlen($billing_info['billing_sex']) < 1) throw new Exception('billing sex');
            // number
            if (strlen($billing_info['billing_number']) < 1) throw new Exception('billing number');
            // neighborhood
            if (strlen($billing_info['billing_neighborhood']) < 1) throw new Exception('billing neighborhood');
        }  
        //validate field billing
        foreach ($woocommerce->customer->billing as $key_billing => $value_billing) 
            if(isset($field['billing_'.$key_billing]['required']))
                if($field['billing_'.$key_billing]['required'] == 1) 
                    if (strlen($value_billing) < 1) throw new Exception($field['billing_'.$key_billing]['label']);
        return true;
    } catch (Exception $e) {
        return false;
    }
}
function billing_address_checkout_disable($fields) {  
    if(is_checkout()) 
        $fields['billing'] = array();
    return $fields;
}
function billing_address_update_order_meta( $order_id ) { 
    global $woocommerce;
    $billing_address_index = '';
    foreach ($woocommerce->customer->billing as $key => &$value) {
        $billing_address_index.=$value.' ';
        update_post_meta($order_id, '_billing_'.$key, $value);
    }
    update_post_meta($order_id, '_billing_address_index', $billing_address_index);

    foreach ($woocommerce->customer->meta_data as $key_extra_field => &$value_extra_field)
        if (substr($value_extra_field->key,0,8) == "billing_") update_post_meta($order_id, '_'.$value_extra_field->key, $value_extra_field->value);
}
function billing_address_checkout_disable_stylesheet_to_head() {
    if(is_checkout())
        wp_enqueue_style('woocommerce-flow-checkout', plugin_dir_url( __FILE__ ) . 'custom-checkout.css');
}
function woocommerce_before_checkout_shipping_form_title_ship_to_address() {
    if(is_checkout()) 
        echo '<h3 id="ship-to-address"><span>'.__( 'Shipping address', 'woocommerce' ).'<span></h3>';
}