<?php

/*
  Plugin Name: MPower WooCommerce Payment Gateway
  Plugin URI: http://txtghana.com
  Description: Easily integrate credit card, debit card and mobile money payment into your Woocommerce site and start accepting payment from Ghana.
  Version: 1.0.1
  Author: Delu Akin
  Author URI: https://www.facebook.com/deluakin
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

add_action('plugins_loaded', 'woocommerce_mpowerpayment_init', 0);

function woocommerce_mpowerpayment_init() {
    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_MPower extends WC_Payment_Gateway {

        public function __construct() {
            $this->mpower_errors = new WP_Error();

            $this->id = 'mpowerpayment';
            $this->medthod_title = 'MPowerPayment';
            $this->icon = apply_filters('woocommerce_mpowerpayment_icon', plugins_url('assets/images/logo.png', __FILE__));
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];

            $this->live_master_key = $this->settings['master_key'];

            $this->live_private_key = $this->settings['live_private_key'];
            $this->live_token = $this->settings['live_token'];

            $this->test_private_key = $this->settings['test_private_key'];
            $this->test_token = $this->settings['test_token'];

            $this->sandbox = $this->settings['sandbox'];

            if ($this->settings['sandbox'] == "yes") {
                $this->posturl = 'https://app.mpowerpayments.com/sandbox-api/v1/checkout-invoice/create';
                $this->geturl = 'https://app.mpowerpayments.com/sandbox-api/v1/checkout-invoice/confirm/';
            } else {
                $this->posturl = 'https://app.mpowerpayments.com/api/v1/checkout-invoice/create';
                $this->geturl = 'https://app.mpowerpayments.com/api/v1/checkout-invoice/confirm/';
            }

            $this->msg['message'] = "";
            $this->msg['class'] = "";


            if (isset($_REQUEST["mpower"])) {
                wc_add_notice($_REQUEST["mpower"], "error");
            }

            if (isset($_REQUEST["token"]) && $_REQUEST["token"] <> "") {
                $token = trim($_REQUEST["token"]);
                $this->check_mpowerpayment_response($token);
            } else {
                $query_str = $_SERVER['QUERY_STRING'];
                $query_str_arr = explode("?", $query_str);
                foreach ($query_str_arr as $value) {
                    $data = explode("=", $value);
                    if (trim($data[0]) == "token") {
                        $token = isset($data[1]) ? trim($data[1]) : "";
                        if ($token <> "") {
                            $this->check_mpowerpayment_response($token);
                        }
                        break;
                    }
                }
            }

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
        }

        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'mpower'),
                    'type' => 'checkbox',
                    'label' => __('Enable MPower Payment Module.', 'mpower'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'mpower'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'mpower'),
                    'default' => __('MPower Payment', 'mpower')),
                'description' => array(
                    'title' => __('Description:', 'mpower'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'mpower'),
                    'default' => __('Integrate credit card, debit card and mobile money payment into your Woocommerce site.', 'mpower')),
                'master_key' => array(
                    'title' => __('Master Key', 'mpower'),
                    'type' => 'text',
                    'description' => __('This Master Key giving. Given to Merchant by MPower Payment."')),
                'live_private_key' => array(
                    'title' => __('Live Private Key', 'mpower'),
                    'type' => 'text',
                    'description' => __('This Live Private Key giving. Given to Merchant by MPower Payment."')),
                'live_token' => array(
                    'title' => __('Live Token', 'mpower'),
                    'type' => 'text',
                    'description' => __('This Live Token giving. Given to Merchant by MPower Payment."')),
                'test_private_key' => array(
                    'title' => __('Test Private Key', 'mpower'),
                    'type' => 'text',
                    'description' => __('This Test Private Key giving. Given to Merchant by MPower Payment."')),
                'test_token' => array(
                    'title' => __('Test Token', 'mpower'),
                    'type' => 'text',
                    'description' => __('This Test Token giving. Given to Merchant by MPower Payment."')),
                'sandbox' => array(
                    'title' => __('Sandbox', 'mpower'),
                    'type' => 'checkbox',
                    'description' => __('Is API in sandbox mode', 'mpower'))
            );
        }

        public function admin_options() {
            echo '<h3>' . __('MPower Payment Gateway', 'mpower') . '</h3>';
            echo '<p>' . __('MPower is most popular payment gateway for online shopping in Ghana') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        function payment_fields() {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
        }

        protected function get_mpowerpayment_args($order) {
            global $woocommerce;

            //$order = new WC_Order($order_id);
            $txnid = $order->id . '_' . date("ymds");

            $redirect_url = $woocommerce->cart->get_checkout_url();

            $productinfo = "Order: " . $order->id;

            $str = "$this->merchant_id|$txnid|$order->order_total|$productinfo|$order->billing_first_name|$order->billing_email|||||||||||$this->salt";
            $hash = hash('sha512', $str);

            WC()->session->set('mpower_wc_hash_key', $hash);

            $items = $woocommerce->cart->get_cart();
            $mpower_items = array();
            foreach ($items as $item) {
                $mpower_items[] = array(
                    "name" => $item["data"]->post->post_title,
                    "quantity" => $item["quantity"],
                    "unit_price" => $item["line_total"] / (($item["quantity"] == 0) ? 1 : $item["quantity"]),
                    "total_price" => $item["line_total"],
                    "description" => ""
                );
            }
            $mpowerpayment_args = array(
                "invoice" => array(
                    //"items" => $mpower_items,
                    "total_amount" => $order->order_total,
                    "description" => "Payment of GHs" . $order->order_total . " for item(s) bought on " . get_bloginfo("name")
                ), "store" => array(
                    "name" => get_bloginfo("name"),
                    //"logo_url" => "",
                    "website_url" => get_site_url()
                ), "actions" => array(
                    "cancel_url" => $redirect_url,
                    "return_url" => $redirect_url
                ), "custom_data" => array(
                    "order_id" => $order->id,
                    "trans_id" => $txnid,
                    "hash" => $hash
                )
            );


            apply_filters('woocommerce_mpowerpayment_args', $mpowerpayment_args, $order);
            return $mpowerpayment_args;
        }

        function post_to_url($url, $data, $order_id) {
            $json = json_encode($data);
            $ch = curl_init();

            $master_key = $this->live_master_key;
            $private_key = "";
            $token = "";
            if ($this->settings['sandbox'] == "yes") {
                $private_key = $this->test_private_key;
                $token = $this->test_token;
            } else {
                $private_key = $this->live_private_key;
                $token = $this->live_token;
            }

            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_NOBODY => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => array(
                    "MP-Master-Key: $master_key",
                    "MP-Private-Key: $private_key",
                    "MP-Token: $token"
                ),
            ));
            $response = curl_exec($ch);
            $response_decoded = json_decode($response);
            WC()->session->set('mpower_wc_oder_id', $order_id);
            if ($response_decoded->response_code && $response_decoded->response_code == "00") {
                $order = new WC_Order($order_id);
                $order->add_order_note("Mpower Token: " . $response_decoded->token);
                return $response_decoded->response_text;
            } else {
                global $woocommerce;
                $url = $woocommerce->cart->get_checkout_url();
                if (strstr($url, "?")) {
                    return $url . "&mpower=" . $response_decoded->response_text;
                } else {
                    return $url . "?mpower=" . $response_decoded->response_text;
                }
            }
        }

        function process_payment($order_id) {
            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $this->post_to_url($this->posturl, $this->get_mpowerpayment_args($order), $order_id)
            );
        }

        function showMessage($content) {
            return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
        }

        function get_pages($title = false, $indent = true) {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title)
                $page_list[] = $title;
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }

        function check_mpowerpayment_response($mtoken) {
            global $woocommerce;
            if ($mtoken <> "") {
                $wc_order_id = WC()->session->get('mpower_wc_oder_id');
                $hash = WC()->session->get('mpower_wc_hash_key');
                $order = new WC_Order($wc_order_id);
                try {
                    $ch = curl_init();
                    $master_key = $this->live_master_key;
                    $private_key = "";
                    $url = $this->geturl . $mtoken;
                    $token = "";
                    if ($this->settings['sandbox'] == "yes") {
                        $private_key = $this->test_private_key;
                        $token = $this->test_token;
                    } else {
                        $private_key = $this->live_private_key;
                        $token = $this->live_token;
                    }

                    curl_setopt_array($ch, array(
                        CURLOPT_URL => $url,
                        CURLOPT_NOBODY => false,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_HTTPHEADER => array(
                            "MP-Master-Key: $master_key",
                            "MP-Private-Key: $private_key",
                            "MP-Token: $token"
                        ),
                    ));
                    $response = curl_exec($ch);
                    $response_decoded = json_decode($response);
                    $respond_code = $response_decoded->response_code;
                    if ($respond_code == "00") {
                        //payment found
                        $status = $response_decoded->status;
                        $custom_data = $response_decoded->custom_data;
                        $order_id = $custom_data->order_id;
                        if ($wc_order_id <> $order_id) {
                            $message = "Thank you for shopping with us. 
                                Howerever, Your transaction session timed out. 
                                Your Order id is $order_id";
                            $message_type = "notice";
                            $order->add_order_note($message);
                            $redirect_url = $order->get_cancel_order_url();
                        }
                        if ($status == "completed") {
                            //payment was completely processed
                            $message = "Thank you for shopping with us. 
                                Your transaction was succssful, payment was received. 
                                You order is currently beign processed. 
                                Your Order id is $order_id";
                            $message_type = "success";
                            $order->payment_complete();
                            $order->update_status('completed');
                            $order->add_order_note('MPower payment successful<br/>Unnique Id from MPower: ' . $mtoken);
                            $order->add_order_note($this->msg['message']);
                            $woocommerce->cart->empty_cart();
                            $redirect_url = $this->get_return_url($order);
                            
                        } else {
                            //payment is still pending, or user cancelled request
                            $message = "Thank you for shopping with us. However, the transaction could not be completed.";
                            $message_type = "error";
                            $order->add_order_note('Transaction failed or user cancel payment request');
                            $redirect_url = $order->get_cancel_order_url();
                        }
                    } else {
                        //payment not found
                        $message = "Thank you for shopping with us. However, the transaction has been declined.";
                        $message_type = "error";
                        $redirect_url = $order->get_cancel_order_url();
                    }

                    $notification_message = array(
                        'message' => $message,
                        'message_type' => $message_type
                    );
                    if (version_compare(WOOCOMMERCE_VERSION, "2.2") >= 0) {
                        add_post_meta($wc_order_id, '_mpower_hash', $hash, true);
                    }
                    update_post_meta($wc_order_id, '_mpower_wc_message', $notification_message);
                    
                    WC()->session->__unset('mpower_wc_hash_key');
                    WC()->session->__unset('mpower_wc_order_id');

                    wp_redirect($redirect_url);
                    exit;
                } catch (Exception $e) {
                    $order->add_order_note('Error: ' . $e->getMessage());

                    $redirect_url = $order->get_cancel_order_url();
                    wp_redirect($redirect_url);
                    exit;
                }
            }
        }

    }

    add_filter('woocommerce_currencies', 'add_my_currency');

    function add_mpower_my_currency($currencies) {
        $currencies['GHS'] = __('Ghana Cedi', 'woocommerce');
        return $currencies;
    }

    add_filter('woocommerce_currency_symbol', 'add_mpower_my_currency_symbol', 10, 2);

    function add_mpower_my_currency_symbol($currency_symbol, $currency) {
        switch (
        $currency) {
            case 'GHS': $currency_symbol = 'GHS ';
                break;
        }
        return $currency_symbol;
    }

    function woocommerce_add_mpowerpayment_gateway($methods) {
        $methods[] = 'WC_MPower';
        return $methods;
    }

    // Add settings link on plugin page
    function woocommer_add_mpowerpayment_settings_link($links) {
        $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=wc_mpower">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    $plugin = plugin_basename(__FILE__);
    add_filter("plugin_action_links_$plugin", 'woocommer_add_mpowerpayment_settings_link');

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_mpowerpayment_gateway');
}