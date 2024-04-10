<?php
/**
 * Plugin Name: SANTS Webhook Integration
 * Plugin URI: https://www.sants.co.za
 * Description: Handles webhooks from Unbounce for integration with SendGrid and sends confirmation emails.
 * Version: 1.0
 * Author: Andries Bester
 * Author URI: https://www.sants.co.za
 */

add_action('admin_menu', 'sants_webhook_settings_menu');

function sants_webhook_settings_menu() {
    add_options_page('SANTS Webhook Settings', 'SANTS Webhook Settings', 'manage_options', 'sants-webhook-settings', 'sants_webhook_settings_page');
}

function sants_webhook_settings_page() {
    ?>
    <div class="wrap">
        <h2>SANTS Webhook Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields('sants-webhook-settings-group'); ?>
            <?php do_settings_sections('sants-webhook-settings-group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">SendGrid API Key</th>
                    <td><input type="text" name="sendgrid_api_key" value="<?php echo esc_attr(get_option('sendgrid_api_key')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Secret Token</th>
                    <td><input type="text" name="secret_token" value="<?php echo esc_attr(get_option('secret_token')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'sants_webhook_settings_init');

function sants_webhook_settings_init() {
    register_setting('sants-webhook-settings-group', 'sendgrid_api_key');
    register_setting('sants-webhook-settings-group', 'secret_token');
}

add_action('rest_api_init', function () {
    register_rest_route('sants-webhooks/v1', '/listener/', array(
        'methods' => 'POST',
        'callback' => 'sants_handle_webhook',
        'permission_callback' => 'sants_webhooks_permissions_check'
    ));
});

function sants_handle_webhook($request) {
    $parameters = $request->get_json_params();
    if (empty($parameters)) {
        $parameters = $request->get_body_params();
    }

    if (isset($parameters['data_json'])) {
        $decoded_data = json_decode($parameters['data_json'], true);
        $parameters = array_merge($parameters, $decoded_data);
    }

    if (WP_DEBUG_LOG) {
        error_log('Webhook received: ' . print_r($parameters, true));
    }

    $email = !empty($parameters['email']) ? $parameters['email'] : '';
    $firstName = !empty($parameters['first_name']) ? $parameters['first_name'] : '';


    $data = [
        "contacts" => [
            [
                "email" => $email,
                "first_name" => $firstName,
            ]
        ]
    ];

    if (WP_DEBUG_LOG) {
        error_log('Payload to SendGrid: ' . print_r($data, true));
    }

    $url = 'https://api.sendgrid.com/v3/marketing/contacts';

    $sendgrid_api_key = get_option('sendgrid_api_key');

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $sendgrid_api_key,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (WP_DEBUG_LOG) {
        error_log('SendGrid response: ' . $response);
        error_log('HTTP Status Code: ' . $httpStatusCode);
    }

    $body = 'A new lead has been received and processed: ' . print_r($parameters, true);
    $body .= "\n\nSendGrid Response:\n" . $response;
    $body .= "\nHTTP Status Code: " . $httpStatusCode;

    $to = 'bester.dries@gmail.com';
    $subject = 'Lead Received and Processed';
    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail($to, $subject, $body, $headers);

    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Webhook received and processed, email sent, and data forwarded to SendGrid.',
    ), 200);
}

function sants_webhooks_permissions_check($request) {
    $provided_token = $request->get_header('X-Secret-Token');
    $expected_token = get_option('secret_token');

    if ($provided_token !== $expected_token) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Unauthorized access',
        ), 403);
    }
    return true;
}
