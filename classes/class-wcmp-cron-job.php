<?php

/**
 * WCMp Cron Job Class
 *
 * @version		2.2.0
 * @package		WCMp
 * @author 		WC Marketplace
 */
class WCMp_Cron_Job {

    public function __construct() {
        add_action('masspay_cron_start', array(&$this, 'do_mass_payment'));
        // vendor weekly order stats reports
        add_action('vendor_weekly_order_stats', array(&$this, 'vendor_weekly_order_stats_report'));
        // vendor monthly order stats reports
        add_action('vendor_monthly_order_stats', array(&$this, 'vendor_monthly_order_stats_report'));
        // migrate all products having parent-child concept
        add_action('migrate_spmv_multivendor_table', array(&$this, 'migrate_spmv_multivendor_table'));
        // bind spmv excluded products mapping 
        add_action('wcmp_spmv_excluded_products_map', array(&$this, 'wcmp_spmv_excluded_products_map'));
        // bind spmv excluded products mapping 
        add_action('wcmp_spmv_product_meta_update', array(&$this, 'wcmp_spmv_product_meta_update'));
        $this->wcmp_clear_scheduled_event();
    }

    /**
     * Clear scheduled event
     */
    function wcmp_clear_scheduled_event() {
        $cron_hook_identifier = apply_filters('wcmp_cron_hook_identifier', array(
            'masspay_cron_start',
            'vendor_weekly_order_stats',
            'vendor_monthly_order_stats',
            'migrate_spmv_multivendor_table',
            'wcmp_spmv_excluded_products_map',
            'wcmp_spmv_product_meta_update',
        ));
        if ($cron_hook_identifier) {
            foreach ($cron_hook_identifier as $cron_hook) {
                $timestamp = wp_next_scheduled($cron_hook);
                if ($timestamp && apply_filters('wcmp_unschedule_'. $cron_hook . '_cron_event', false)) {
                    wp_unschedule_event($timestamp, $cron_hook);
                }
            }
        }
    }

    /**
     * Calculate the amount and selete payment method.
     *
     *
     */
    function do_mass_payment() {
        global $WCMp;
        $payment_admin_settings = get_option('wcmp_payment_settings_name');
        if (!isset($payment_admin_settings['wcmp_disbursal_mode_admin'])) {
            return;
        }
        $commission_to_pay = array();
        $commissions = $this->get_query_commission();
        if ($commissions && is_array($commissions)) {
            foreach ($commissions as $commission) {
                $commission_id = $commission->ID;
                $vendor_term_id = get_post_meta($commission_id, '_commission_vendor', true);
                $commission_to_pay[$vendor_term_id][] = $commission_id;
            }
        }
        foreach ($commission_to_pay as $vendor_term_id => $commissions) {
            $vendor = get_wcmp_vendor_by_term($vendor_term_id);
            if ($vendor) {
                $payment_method = get_user_meta($vendor->id, '_vendor_payment_mode', true);
                if ($payment_method && $payment_method != 'direct_bank') {
                    if (array_key_exists($payment_method, $WCMp->payment_gateway->payment_gateways)) {
                        $WCMp->payment_gateway->payment_gateways[$payment_method]->process_payment($vendor, $commissions);
                    }
                }
            }
        }
    }

    /**
     * Get Commissions
     *
     * @return object $commissions
     */
    public function get_query_commission() {
        $args = array(
            'post_type' => 'dc_commission',
            'post_status' => array('publish', 'private'),
            'meta_key' => '_paid_status',
            'meta_value' => 'unpaid',
            'posts_per_page' => 5
        );
        $commissions = get_posts($args);
        return $commissions;
    }

    /**
     * Weekly order stats report
     *
     * 
     */
    public function vendor_weekly_order_stats_report() {
        global $WCMp;
        $vendors = get_wcmp_vendors();
        if ($vendors) {
            foreach ($vendors as $key => $vendor_obj) {
                if ($vendor_obj->user_data->user_email) {
                    $order_data = array();
                    $vendor = get_wcmp_vendor($vendor_obj->id);
                    $email = WC()->mailer()->emails['WC_Email_Vendor_Orders_Stats_Report'];
                    $vendor_weekly_stats = $vendor->get_vendor_orders_reports_of('vendor_stats', array('vendor_id' => $vendor->id));
                    $transaction_details = $WCMp->transaction->get_transactions($vendor->term_id, date('Y-m-d', strtotime('-7 days')), date('Y-m-d'));
                    if (is_array($vendor_weekly_stats)) {
                        $vendor_weekly_stats['total_transaction'] = array_sum(wp_list_pluck($transaction_details, 'total_amount'));
                    }
                    $report_data = array(
                        'period' => __('weekly', 'dc-woocommerce-multi-vendor'),
                        'start_date' => date('Y-m-d', strtotime('-7 days')),
                        'end_date' => @date('Y-m-d'),
                        'stats' => $vendor_weekly_stats,
                    );
                    $attachments = array();
                    $vendor_weekly_orders = $vendor->get_vendor_orders_reports_of('', array('vendor_id' => $vendor->id));
                    if ($vendor_weekly_orders && count($vendor_weekly_orders) > 0) {
                        foreach ($vendor_weekly_orders as $key => $data) {
                            if ($data->commission_id != 0 && $data->commission_id != '') {
                                $order_data[$data->commission_id] = $data->order_id;
                            }
                        }
                        if (count($order_data) > 0) {
                            $report_data['order_data'] = $order_data;
                            $args = array(
                                'filename' => 'OrderReports-' . $report_data['start_date'] . '-To-' . $report_data['end_date'] . '.csv',
                                'action' => 'temp',
                            );
                            $report_csv = $WCMp->vendor_dashboard->generate_csv($order_data, $vendor, $args);
                            if ($report_csv)
                                $attachments[] = $report_csv;
                            if ($email->trigger($vendor, $report_data, $attachments)) {
                                $email->find[] = $vendor->page_title;
                                $email->replace[] = '{STORE_NAME}';
                                if (file_exists($report_csv)) {
                                    @unlink($report_csv);
                                }
                            } else {
                                if (file_exists($report_csv)) {
                                    @unlink($report_csv);
                                }
                            }
                        } else {
                            if (apply_filters('wcmp_send_vendor_weekly_zero_order_stats_report', true, $vendor)) {
                                $report_data['order_data'] = $order_data;
                                if ($email->trigger($vendor, $report_data, $attachments)) {
                                    $email->find[] = $vendor->page_title;
                                    $email->replace[] = '{STORE_NAME}';
                                }
                            }
                        }
                    } else {
                        if (apply_filters('wcmp_send_vendor_weekly_zero_order_stats_report', true, $vendor)) {
                            $report_data['order_data'] = $order_data;
                            if ($email->trigger($vendor, $report_data, $attachments)) {
                                $email->find[] = $vendor->page_title;
                                $email->replace[] = '{STORE_NAME}';
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Monthly order stats report
     *
     * 
     */
    public function vendor_monthly_order_stats_report() {
        global $WCMp;
        $vendors = get_wcmp_vendors();
        if ($vendors) {
            foreach ($vendors as $key => $vendor_obj) {
                if ($vendor_obj->user_data->user_email) {
                    $order_data = array();
                    $vendor = get_wcmp_vendor($vendor_obj->id);
                    $email = WC()->mailer()->emails['WC_Email_Vendor_Orders_Stats_Report'];
                    $vendor_monthly_stats = $vendor->get_vendor_orders_reports_of('vendor_stats', array('vendor_id' => $vendor->id, 'start_date' => date('Y-m-d H:i:s', strtotime('-30 days'))));
                    $transaction_details = $WCMp->transaction->get_transactions($vendor->term_id, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
                    if (is_array($vendor_monthly_stats)) {
                        $vendor_monthly_stats['total_transaction'] = array_sum(wp_list_pluck($transaction_details, 'total_amount'));
                    }
                    $report_data = array(
                        'period' => __('monthly', 'dc-woocommerce-multi-vendor'),
                        'start_date' => date('Y-m-d', strtotime('-30 days')),
                        'end_date' => @date('Y-m-d'),
                        'stats' => $vendor_monthly_stats,
                    );
                    $attachments = array();
                    $vendor_monthly_orders = $vendor->get_vendor_orders_reports_of('', array('vendor_id' => $vendor->id, 'start_date' => date('Y-m-d H:i:s', strtotime('-30 days'))));
                    if ($vendor_monthly_orders && count($vendor_monthly_orders) > 0) {
                        foreach ($vendor_monthly_orders as $key => $data) {
                            if ($data->commission_id != 0 && $data->commission_id != '') {
                                $order_data[$data->commission_id] = $data->order_id;
                            }
                        }
                        if (count($order_data) > 0) {
                            $report_data['order_data'] = $order_data;
                            $args = array(
                                'filename' => 'OrderReports-' . $report_data['start_date'] . '-To-' . $report_data['end_date'] . '.csv',
                                'action' => 'temp',
                            );
                            $report_csv = $WCMp->vendor_dashboard->generate_csv($order_data, $vendor, $args);
                            if ($report_csv)
                                $attachments[] = $report_csv;
                            if ($email->trigger($vendor, $report_data, $attachments)) {
                                $email->find[] = $vendor->page_title;
                                $email->replace[] = '{STORE_NAME}';
                                if (file_exists($report_csv)) {
                                    @unlink($report_csv);
                                }
                            } else {
                                if (file_exists($report_csv)) {
                                    @unlink($report_csv);
                                }
                            }
                        } else {
                            if (apply_filters('wcmp_send_vendor_monthly_zero_order_stats_report', true, $vendor)) {
                                $report_data['order_data'] = $order_data;
                                if ($email->trigger($vendor, $report_data, $attachments)) {
                                    $email->find[] = $vendor->page_title;
                                    $email->replace[] = '{STORE_NAME}';
                                }
                            }
                        }
                    } else {
                        if (apply_filters('wcmp_send_vendor_monthly_zero_order_stats_report', true, $vendor)) {
                            $report_data['order_data'] = $order_data;
                            if ($email->trigger($vendor, $report_data, $attachments)) {
                                $email->find[] = $vendor->page_title;
                                $email->replace[] = '{STORE_NAME}';
                            }
                        }
                    }
                }
            }
        }
    }

    public function migrate_spmv_multivendor_table() {
        global $WCMp, $wpdb;
        $length = apply_filters('wcmp_migrate_spmv_multivendor_table_length', 50);
        $args = apply_filters('wcmp_migrate_spmv_table_products_query_args', array(
            'numberposts' => $length,
            'post_type' => 'product',
            'meta_key' => '_wcmp_child_product',
            'meta_value' => '1',
            'fields' => 'id=>parent',
        ));
        $products = get_posts($args);
        
        if($products){
            foreach ($products as $product_id => $parent_id) {
                if($parent_id){
                    delete_post_meta($product_id, '_wcmp_child_product');
                    wp_update_post(array('ID' => $product_id, 'post_parent' => 0), true);
                    $data = array('product_id' => $product_id);
                    if(get_post_meta($product_id, '_wcmp_spmv_map_id', true) || get_post_meta($parent_id, '_wcmp_spmv_map_id', true)){
                        $product_map_id = (get_post_meta($product_id, '_wcmp_spmv_map_id', true)) ? get_post_meta($product_id, '_wcmp_spmv_map_id', true) : 0;
                        $product_map_id = (get_post_meta($parent_id, '_wcmp_spmv_map_id', true)) ? get_post_meta($parent_id, '_wcmp_spmv_map_id', true) : $product_map_id;
                        $data['product_map_id'] = $product_map_id;
                    }
                    
                    $map_id = wcmp_spmv_products_map($data, 'insert');
                    if($map_id){
                        $data['product_map_id'] = $map_id;
                        $data['product_id'] = $parent_id;
                        wcmp_spmv_products_map($data, 'insert');
                        //update meta
                        update_post_meta($product_id, '_wcmp_spmv_product', true);
                        update_post_meta($parent_id, '_wcmp_spmv_product', true);
                        update_post_meta($product_id, '_wcmp_spmv_map_id', $map_id);
                        update_post_meta($parent_id, '_wcmp_spmv_map_id', $map_id);
                    }
                }
            }
            // SPMV terms object update
            do_wcmp_spmv_set_object_terms();
            $exclude_spmv_products = get_wcmp_spmv_excluded_products_map_data();
            set_transient('wcmp_spmv_exclude_products_data', $exclude_spmv_products);

        }else{
            update_option('spmv_multivendor_table_migrated', true);
            wp_clear_scheduled_hook('migrate_spmv_multivendor_table');
        }
    }

    public function wcmp_spmv_excluded_products_map() {
        do_wcmp_spmv_set_object_terms();
        $exclude_spmv_products = get_wcmp_spmv_excluded_products_map_data();
        set_transient('wcmp_spmv_exclude_products_data', $exclude_spmv_products);
    }
    
    public function wcmp_spmv_product_meta_update() {
        $products_map_data = get_wcmp_spmv_products_map_data();
        if($products_map_data){
            foreach ($products_map_data as $product_map_id => $product_ids) {
                if($product_ids){
                    foreach ($product_ids as $product_id) {
                        $is_wcmp_spmv_product = get_post_meta($product_id, '_wcmp_spmv_product', true);
                        $has_wcmp_spmv_map_id = get_post_meta($product_id, '_wcmp_spmv_map_id', true);
                        if(!$is_wcmp_spmv_product || !$has_wcmp_spmv_map_id){
                            update_post_meta($product_id, '_wcmp_spmv_product', true);
                            update_post_meta($product_id, '_wcmp_spmv_map_id', $product_map_id);
                        }
                    }
                }
            }
            do_wcmp_spmv_set_object_terms();
            $exclude_spmv_products = get_wcmp_spmv_excluded_products_map_data();
            set_transient('wcmp_spmv_exclude_products_data', $exclude_spmv_products);
            update_option('wcmp_spmv_product_meta_migrated', true);
        }
    }

}
