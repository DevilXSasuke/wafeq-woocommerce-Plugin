<?php
/**
 * Plugin Name: Wafeq Plugin Create Invoices
 * Plugin URI: https://developer.wafeq.com/docs/use-case-for-e-commerce-1
 * Description: Automatically create contacts and invoices in Wafeq with detailed activity logging
 * Version: 1.5
 * Author: DevilXSasuke
 * Author URI: https://github.com/DevilXSasuke
 */

if (!defined('ABSPATH')) {
    exit;
}

class WafeqIntegrationEnhanced {
    private $api_key = 'PUT_YOUR_API_KEY_HERE'; // Replace with your Wafeq API Key
    private $log_table;
    private $activity_table;
    private $user_meta_key = 'wafeq_contact_id';

    public function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'wafeq_logs';
        $this->activity_table = $wpdb->prefix . 'wafeq_activity';
        
        // Initialize plugin
        add_action('init', [$this, 'init_plugin']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // WooCommerce integration
        add_action('woocommerce_order_status_completed', [$this, 'handle_order_completed']);
    }

    /**
     * Initialize plugin and create tables
     */
    public function init_plugin() {
        $this->create_tables();
    }

    /**
     * Create necessary database tables
     */
    private function create_tables() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        // Create activity logging table
        $activity_sql = "CREATE TABLE IF NOT EXISTS {$this->activity_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            user_login varchar(255) NOT NULL,
            action varchar(255) NOT NULL,
            details text NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($activity_sql);
    }

    /**
     * Add menu items
     */
    public function add_admin_menu() {
        add_menu_page(
            'Wafeq Activity',
            'Wafeq Activity',
            'manage_options',
            'wafeq-activity',
            [$this, 'activity_page'],
            'dashicons-list-view'
        );
    }

    /**
     * Log activity with user and timestamp
     */
    private function log_activity($action, $details = []) {
        global $wpdb;

        $wpdb->insert(
            $this->activity_table,
            [
                'timestamp' => current_time('mysql', true),
                'user_login' => 'admin',
                'action' => $action,
                'details' => json_encode($details)
            ],
            ['%s', '%s', '%s', '%s']
        );

        return $wpdb->insert_id;
    }

    /**
     * Handle WooCommerce order completion
     */
    public function handle_order_completed($order_id) {
        $this->log_activity('order_processing_started', ['order_id' => $order_id]);
        
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->log_activity('order_processing_failed', [
                'order_id' => $order_id,
                'reason' => 'Invalid order ID'
            ]);
            return;
        }

        // Get customer details
        $user_id = $order->get_user_id();
        $user = get_userdata($user_id);
        $user_email = $user ? $user->user_email : $order->get_billing_email();
        $user_name = $user ? $user->display_name : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $user_phone = $order->get_billing_phone();
        $user_city = $order->get_billing_city();
        $user_country = $order->get_billing_country();

        $this->log_activity('customer_details_collected', [
            'order_id' => $order_id,
            'user_id' => $user_id,
            'email' => $user_email
        ]);

        // Check for existing contact
        $contact_id = get_user_meta($user_id, $this->user_meta_key, true);

        if (!$contact_id) {
            // Create new contact
            $contact_data = [
                'name' => $user_name,
                'email' => $user_email,
                'city' => $user_city,
                'code' => (string)$user_id,
                'country' => $user_country,
                'phone' => $user_phone,
            ];

            $contact_response = $this->send_request('https://api.wafeq.com/v1/contacts/', $contact_data);

            if (!isset($contact_response['id'])) {
                $this->log_activity('contact_creation_failed', [
                    'email' => $user_email,
                    'response' => $contact_response
                ]);
                return;
            }

            $contact_id = $contact_response['id'];
            update_user_meta($user_id, $this->user_meta_key, $contact_id);
            
            $this->log_activity('contact_created', [
                'contact_id' => $contact_id,
                'email' => $user_email
            ]);
        }

        // Prepare line items
        $line_items = [];
        foreach ($order->get_items() as $item) {
            $line_items[] = [
                'account' => 'PUT_YOUR_ACCOUNT_ID_HERE', // Replace with your Wafeq account ID
                'description' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'unit_amount' => $item->get_total(),
            ];
        }

        // Create invoice with due date
        $invoice_data = [
            'currency' => 'AED',
            'language' => 'en',
            'status' => 'DRAFT',
            'contact' => $contact_id,
            'invoice_date' => date('Y-m-d'),
            'invoice_due_date' => date('Y-m-d'),
            'invoice_number' => 'WS-' . $order->get_order_number(),
            'line_items' => $line_items,
        ];

        $this->log_activity('creating_invoice', [
            'order_id' => $order_id,
            'contact_id' => $contact_id,
            'invoice_number' => $invoice_data['invoice_number']
        ]);

        $invoice_response = $this->send_request('https://api.wafeq.com/v1/invoices/', $invoice_data);

        if (!isset($invoice_response['id'])) {
            $this->log_activity('invoice_creation_failed', [
                'order_id' => $order_id,
                'response' => $invoice_response
            ]);
            return;
        }

        $invoice_id = $invoice_response['id'];
        
        $this->log_activity('invoice_created', [
            'invoice_id' => $invoice_id,
            'order_id' => $order_id
        ]);

        $order->add_order_note("Wafeq invoice created successfully. Invoice ID: {$invoice_id}");
    }

    /**
     * Send API request to Wafeq
     */
    private function send_request($url, $data) {
        $response = wp_remote_post($url, [
            'method' => 'POST',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Api-Key ' . $this->api_key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_activity('api_request_failed', [
                'url' => $url,
                'error' => $error_message
            ]);
            return ['error' => $error_message];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Log the complete response
        $this->log_activity('api_request_completed', [
            'url' => $url,
            'response' => $body,
            'time' => current_time('mysql', true)
        ]);

        return $body;
    }

    /**
     * Display activity log page
     */
    public function activity_page() {
        global $wpdb;

        // Get all activities
        $activities = $wpdb->get_results(
            "SELECT * FROM {$this->activity_table} ORDER BY timestamp DESC LIMIT 100"
        );

        ?>
        <style>
            .wafeq-wrap {
                margin: 20px;
                max-width: 100%;
            }
            .wafeq-header {
                background: #fff;
                padding: 15px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .wafeq-header p {
                margin: 5px 0;
                font-size: 13px;
            }
            .wafeq-table {
                border-collapse: collapse;
                width: 100%;
                background: #fff;
                table-layout: fixed;
                margin-top: 20px;
            }
            .wafeq-table th {
                background: #f5f5f5;
                padding: 10px;
                text-align: left;
                border-bottom: 2px solid #e1e1e1;
                font-weight: 600;
            }
            .wafeq-table td {
                padding: 12px 10px;
                border-bottom: 1px solid #f0f0f0;
                vertical-align: top;
                word-wrap: break-word;
            }
            .wafeq-table .col-time { width: 15%; }
            .wafeq-table .col-user { width: 15%; }
            .wafeq-table .col-action { width: 20%; }
            .wafeq-table .col-details { width: 50%; }
            .wafeq-table pre {
                margin: 0;
                white-space: pre-wrap;
                word-wrap: break-word;
                background: #f8f9fa;
                padding: 8px;
                border-radius: 4px;
                font-size: 12px;
                color: #333;
                max-width: 100%;
                overflow-x: auto;
            }
            .wafeq-table tr:hover { background-color: #f8f9fa; }
            .wafeq-timestamp {
                color: #666;
                white-space: nowrap;
            }
            .wafeq-user { color: #135e96; }
            .wafeq-action {
                font-weight: 500;
                color: #2271b1;
            }
            .wafeq-refresh {
                margin-left: 10px;
            }
        </style>

        <div class="wrap wafeq-wrap">
            <div class="wafeq-header">
                <h1>Wafeq Activity Log</h1>
                <p><strong>Current Date and Time (UTC - YYYY-MM-DD HH:MM:SS formatted):</strong> <?php echo current_time('mysql', true); ?></p>
                <p><strong>Current User's Login:</strong> admin</p>
                <button onclick="window.location.reload();" class="button button-primary wafeq-refresh">
                    <span class="dashicons dashicons-update"></span>
                    Refresh Log
                </button>
            </div>

            <table class="wafeq-table widefat">
                <thead>
                    <tr>
                        <th class="col-time">Time (UTC)</th>
                        <th class="col-user">User</th>
                        <th class="col-action">Action</th>
                        <th class="col-details">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($activities): ?>
                        <?php foreach ($activities as $activity): ?>
                            <tr>
                                <td class="wafeq-timestamp">
                                    <?php echo esc_html($activity->timestamp); ?>
                                </td>
                                <td class="wafeq-user">
                                    <?php echo esc_html($activity->user_login); ?>
                                </td>
                                <td class="wafeq-action">
                                    <?php 
                                    echo esc_html(ucwords(str_replace('_', ' ', $activity->action))); 
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $details = json_decode($activity->details, true);
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        echo '<pre>' . esc_html(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                                    } else {
                                        echo '<pre>' . esc_html($activity->details) . '</pre>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4">No activities logged yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                window.location.reload();
            }, 300000);
        });
        </script>
        <?php
    }

    /**
     * Plugin activation hook
     */
    public static function activate() {
        $instance = new self();
        $instance->create_tables();
    }
}

// Register activation hook
register_activation_hook(__FILE__, ['WafeqIntegrationEnhanced', 'activate']);

// Initialize plugin
$wafeq_integration = new WafeqIntegrationEnhanced();
