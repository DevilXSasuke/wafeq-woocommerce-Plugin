# Wafeq WooCommerce Plugin

This plugin integrates WooCommerce with Wafeq to automatically create contacts and invoices.

## Setup Instructions:

1. **Download the `Wafeq-Plugin-Create-Invoices.php` file**.
2. **Before zipping the file, open it in a text editor** and:
   - Replace `PUT_YOUR_API_KEY_HERE` with your Wafeq API key.
   - Replace `PUT_YOUR_ACCOUNT_ID_HERE` with your Wafeq account ID.
3. **Zip the file** after making the replacements.
4. Go to your WordPress admin dashboard.
5. Navigate to **Plugins > Add New**.
6. Click on the **Upload Plugin** button.
7. Choose the zipped file you downloaded and click **Install Now**.
8. Once installed, click **Activate Plugin**.

## How It Works:

- The plugin automatically creates invoices in Wafeq when an order in WooCommerce is marked as "Completed".
- It collects customer details from the order (name, email, phone, address) and checks if the customer already exists in Wafeq. If not, a new contact is created.
- The plugin then generates an invoice with the order details (items, quantities, amounts) and creates a draft invoice in Wafeq.
- Activity logs are recorded to track the creation of contacts, invoices, and any errors encountered during the process.

Ensure your Wafeq account and API credentials are set up before using the plugin.

For more information, visit [Wafeq Developer Documentation](https://developer.wafeq.com/docs/use-case-for-e-commerce-1).
