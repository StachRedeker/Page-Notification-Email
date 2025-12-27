<?php
/*
Plugin Name: Page Notification Email
Description: Put and end to manually informing stakeholders when a page is changed. Page Notification Email allows editors to send an update email directly from the WordPress back-end, with an option to add a custom message. The email text is set globally from a WordPress settings page. Includes global BCC option.
Version: 1.0.0
Author: Studio Stach
Author URI: https://www.studiostach.nl/
License: GPL v3 or later
License URI: https://gnu.org/licenses/gpl-3.0.html
Text Domain: page-notification-email
Requires at least: 5.3
Requires PHP: 7.0
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Global variable for mail errors, now prefixed
global $pagenoemail_mail_errors; // CHANGED: Prefixed this global variable

// --- Meta Box Functions --- (No changes needed in these)

/*------------------------------------------
  Add Meta Box to Selected Post Edit Screens
-------------------------------------------*/
function pagenoemail_add_meta_box() {
    // Get enabled post types from settings; default to pages and posts.
    $enabled_post_types = get_option( 'pagenoemail_enabled_post_types', array( 'page', 'post' ) );
    if ( ! is_array( $enabled_post_types ) ) {
        $enabled_post_types = array( 'page', 'post' );
    }
    foreach ( $enabled_post_types as $post_type ) {
        add_meta_box(
            'pagenoemail_meta_box',
            'Notification Email',
            'pagenoemail_meta_box_callback',
            $post_type,
            'side',
            'default'
        );
    }
}
add_action( 'add_meta_boxes', 'pagenoemail_add_meta_box' );

function pagenoemail_meta_box_callback( $post ) {
    // Security nonce field.
    wp_nonce_field( 'pagenoemail_save_meta_box_data', 'pagenoemail_meta_box_nonce' );

    // Retrieve existing meta values.
    $notification_email = get_post_meta( $post->ID, '_pagenoemail_notification_email', true );
    $custom_message     = get_post_meta( $post->ID, '_pagenoemail_custom_message', true ); // Get custom message

    echo '<p><label for="pagenoemail_notification_email">Notification Email(s):</label></p>';
    echo '<p><input type="text" id="pagenoemail_notification_email" name="pagenoemail_notification_email" value="' . esc_attr( $notification_email ) . '" style="width:100%;" placeholder="email1@example.com, email2@example.com" /></p>';

    // Add Custom Message Textarea
    echo '<p><label for="pagenoemail_custom_message">Custom Message (Optional):</label></p>';
    echo '<p><textarea id="pagenoemail_custom_message" name="pagenoemail_custom_message" rows="4" style="width:100%;" placeholder="Add a custom note for this specific update...">' . esc_textarea( $custom_message ) . '</textarea></p>';
    echo '<p class="description">This message will replace the {custom_message} tag in the email template.</p>';


    // Hidden field with the post ID.
    echo '<input type="hidden" id="pagenoemail_post_id" name="pagenoemail_post_id" value="' . esc_attr( $post->ID ) . '">';

    // Buttons: one to save settings and one to send the notification.
    echo '<p><button type="button" class="button" id="pagenoemail_save_settings_button">Save Email & Message</button></p>';
    echo '<div id="pagenoemail_save_response_message"></div>';
    echo '<p><button type="button" class="button button-primary" id="pagenoemail_send_email_button">Send Notification Email</button></p>';
    echo '<div id="pagenoemail_send_response_message"></div>';
}

/*------------------------------------------
  Save Meta Box Value on Post Save
-------------------------------------------*/
function pagenoemail_save_meta_box_data( $post_id ) {
    // Check if our nonce is set.
    if ( ! isset( $_POST['pagenoemail_meta_box_nonce'] ) ) {
        return;
    }
    // Unsanitize and sanitize the nonce.
    $nonce = sanitize_text_field( wp_unslash( $_POST['pagenoemail_meta_box_nonce'] ) );
    // Verify the nonce.
    if ( ! wp_verify_nonce( $nonce, 'pagenoemail_save_meta_box_data' ) ) {
        return;
    }
    // Skip during autosave.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    // Check user permissions.
    $post_type_object = get_post_type_object( get_post_type( $post_id ) );
    if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
        return;
    }
    // Save the email field if set.
    if ( isset( $_POST['pagenoemail_notification_email'] ) ) {
        $emails = sanitize_text_field( wp_unslash( $_POST['pagenoemail_notification_email'] ) );
        update_post_meta( $post_id, '_pagenoemail_notification_email', $emails );
    }
    // Save the custom message field if set.
    if ( isset( $_POST['pagenoemail_custom_message'] ) ) {
        // Use wp_kses_post for sanitization as it might be included in HTML email
        $custom_message = wp_kses_post( wp_unslash( $_POST['pagenoemail_custom_message'] ) );
        update_post_meta( $post_id, '_pagenoemail_custom_message', $custom_message );
    }
}
add_action( 'save_post', 'pagenoemail_save_meta_box_data' );

// --- Admin Scripts --- (No changes needed here)
/*------------------------------------------
  Enqueue Admin Scripts on Enabled Post Types
-------------------------------------------*/
function pagenoemail_admin_scripts( $hook ) {
    // Load on post-new.php or post.php screens.
    if ( 'post-new.php' === $hook || 'post.php' === $hook ) {
        $screen = get_current_screen();
        $enabled_post_types = get_option( 'pagenoemail_enabled_post_types', array( 'page', 'post' ) );
        if ( isset( $screen->post_type ) && is_array( $enabled_post_types ) && in_array( $screen->post_type, $enabled_post_types, true ) ) {
            wp_enqueue_script( 'pagenoemail_admin_script', plugin_dir_url( __FILE__ ) . 'pagenoemail-admin.js', array( 'jquery' ), '1.4', true ); // Keep version 1.4 unless JS changes
            wp_localize_script( 'pagenoemail_admin_script', 'pagenoemail_ajax_object', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'pagenoemail_ajax_nonce' )
            ) );
        }
    }
}
add_action( 'admin_enqueue_scripts', 'pagenoemail_admin_scripts' );


// --- AJAX Handlers ---

/*------------------------------------------
  AJAX Handler to Send Notification Email (HTML Enabled)
-------------------------------------------*/
function pagenoemail_send_notification_email() {
    // Basic check for required parameters (nonce and post_id are essential)
    if ( ! isset( $_POST['nonce'], $_POST['post_id'] ) ) {
        wp_send_json_error( 'Missing required parameters.' );
    }
    check_ajax_referer( 'pagenoemail_ajax_nonce', 'nonce' );

    $post_id = intval( wp_unslash( $_POST['post_id'] ) );
    $post_type_object = get_post_type_object( get_post_type( $post_id ) );
    if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }

    // Use email address data passed directly if available, otherwise get from meta
    if ( isset( $_POST['notification_email'] ) ) {
        $emails_raw = sanitize_text_field( wp_unslash( $_POST['notification_email'] ) );
    } else {
        $emails_raw = get_post_meta( $post_id, '_pagenoemail_notification_email', true );
    }

    if ( empty( $emails_raw ) ) {
        wp_send_json_error( 'No email address provided.' );
    }

    // Parse addresses
    $emails_array = array_map( 'trim', explode( ',', $emails_raw ) );
    $valid_emails  = array();
    foreach ( $emails_array as $email ) {
        if ( is_email( $email ) ) {
            $valid_emails[] = $email;
        }
    }
    if ( empty( $valid_emails ) ) {
        wp_send_json_error( 'No valid email addresses provided.' );
    }

    // Get the front-end URL of the post.
    $page_url = get_permalink( $post_id );

    // Use custom message passed directly if available, otherwise get from meta
    if ( isset( $_POST['custom_message'] ) ) {
        $custom_message_sanitized = wp_kses_post( wp_unslash( $_POST['custom_message'] ) );
    } else {
        $custom_message = get_post_meta( $post_id, '_pagenoemail_custom_message', true );
        $custom_message_sanitized = wp_kses_post( $custom_message ?: '' );
    }

    // Use settings for the email subject and message template.
    $subject          = get_option( 'pagenoemail_email_subject', 'Please check this page' );
    $message_template = get_option( 'pagenoemail_email_message', "Hello,<br><br>{custom_message}<br><br>You are requested to check the following page:<br>{page_url}<br><br>Thank you." );

    // Replace placeholders.
    $message = str_replace( '{page_url}', esc_url( $page_url ), $message_template );
    $message = str_replace( '{custom_message}', wpautop( $custom_message_sanitized ), $message );

    // *** Prepare Headers ***
    $headers = array();
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    // *** Add BCC Header if set and valid ***
    $bcc_address = get_option( 'pagenoemail_bcc_address', '' );
    if ( ! empty( $bcc_address ) && is_email( $bcc_address ) ) {
        $headers[] = 'Bcc: ' . $bcc_address;
        error_log('[Page Notification Email] Sending with BCC to: ' . $bcc_address); // Optional: Log for debugging
    } else if ( ! empty( $bcc_address ) ) {
        error_log('[Page Notification Email] Invalid BCC address configured: ' . $bcc_address); // Optional: Log invalid config
    }


    // Send email.
    $sent = wp_mail( $valid_emails, $subject, $message, $headers );

    if ( $sent ) {
        wp_send_json_success( 'Notification email sent successfully using the latest data.' );
    } else {
        // Add more detailed error logging if wp_mail fails
        global $pagenoemail_mail_errors; // CHANGED: Used prefixed global variable
        global $phpmailer;
        if (!isset($pagenoemail_mail_errors)) $pagenoemail_mail_errors = array(); // CHANGED: Used prefixed global variable
        if (isset($phpmailer)) {
           $pagenoemail_mail_errors[] = $phpmailer->ErrorInfo; // CHANGED: Used prefixed global variable
        }
        error_log('[Page Notification Email] wp_mail failed. Errors: ' . print_r($pagenoemail_mail_errors, true) ); // CHANGED: Used prefixed global variable
        wp_send_json_error( 'Failed to send the email. Check server logs for details.' );
    }
}
add_action( 'wp_ajax_pagenoemail_send_notification_email', 'pagenoemail_send_notification_email' );

/*------------------------------------------
  AJAX Handler to Save Meta Box Settings (Email & Custom Message)
-------------------------------------------*/
function pagenoemail_save_metabox_settings() {
    if ( ! isset( $_POST['nonce'], $_POST['post_id'], $_POST['notification_email'], $_POST['custom_message'] ) ) {
        wp_send_json_error( 'Missing required parameters.' );
    }
    check_ajax_referer( 'pagenoemail_ajax_nonce', 'nonce' );

    $post_id = intval( wp_unslash( $_POST['post_id'] ) );
    $post_type_object = get_post_type_object( get_post_type( $post_id ) );
    if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }

    // Save Email Addresses
    $emails = sanitize_text_field( wp_unslash( $_POST['notification_email'] ) );
    update_post_meta( $post_id, '_pagenoemail_notification_email', $emails );

    // Save Custom Message
    $custom_message = wp_kses_post( wp_unslash( $_POST['custom_message'] ) ); // Sanitize custom message
    update_post_meta( $post_id, '_pagenoemail_custom_message', $custom_message );

    // Return the saved values along with success message
    wp_send_json_success( array(
        'message' => 'Email address(es) and custom message saved successfully.',
        'notification_email' => $emails,
        'custom_message' => $custom_message
     ) );
}
add_action( 'wp_ajax_pagenoemail_save_metabox_settings', 'pagenoemail_save_metabox_settings' );


// --- Settings Page ---

/*------------------------------------------
  Settings Page SanitiZation Callbacks
-------------------------------------------*/

/**
 * Sanitize the email subject.
 */
function pagenoemail_sanitize_subject( $subject ) {
    return sanitize_text_field( $subject );
}

/**
 * Sanitize the email message (allowing HTML).
 */
function pagenoemail_sanitize_message( $message ) {
    return wp_kses_post( $message );
}

/**
 * Sanitize the enabled post types.
 */
function pagenoemail_sanitize_post_types( $post_types ) {
    if ( ! is_array( $post_types ) ) {
        return array();
    }
    return array_map( 'sanitize_text_field', $post_types );
}

/**
 * Sanitize the BCC email address.
 * Allows an empty string if the user wants to clear the setting.
 */
function pagenoemail_sanitize_email( $email ) { // Changed function name for clarity
    if ( empty( $email ) ) {
        return ''; // Allow empty value
    }
    $sanitized_email = sanitize_email( $email );
    // sanitize_email returns empty string if invalid, which is fine.
    return $sanitized_email;
}


/*------------------------------------------
  Register Settings
-------------------------------------------*/
function pagenoemail_register_settings() {
    register_setting( 'pagenoemail_settings_group', 'pagenoemail_email_subject', array(
        'sanitize_callback' => 'pagenoemail_sanitize_subject',
        'default'           => 'Please check your page',
        'type'              => 'string'
    ) );
    register_setting( 'pagenoemail_settings_group', 'pagenoemail_email_message', array(
        'sanitize_callback' => 'pagenoemail_sanitize_message',
        'default'           => "Hello,<br><br>{custom_message}<br><br>You are requested to check the following page:<br>{page_url}<br><br>Thank you.",
        'type'              => 'string'
    ) );
    register_setting( 'pagenoemail_settings_group', 'pagenoemail_enabled_post_types', array(
        'sanitize_callback' => 'pagenoemail_sanitize_post_types',
        'default'           => array( 'page', 'post' ),
        'type'              => 'array'
    ) );
    // *** Register the new BCC setting ***
    register_setting( 'pagenoemail_settings_group', 'pagenoemail_bcc_address', array(
        'sanitize_callback' => 'pagenoemail_sanitize_email', // Use the email sanitization function
        'default'           => '', // Default to empty
        'type'              => 'string'
    ) );
}
add_action( 'admin_init', 'pagenoemail_register_settings' );


/*------------------------------------------
  Add Settings Page Menu Item
-------------------------------------------*/
function pagenoemail_add_settings_page() {
    add_options_page(
        'Page Notification Email Settings',
        'Page Notification Email',
        'manage_options',
        'pagenoemail-settings',
        'pagenoemail_render_settings_page'
    );
}
add_action( 'admin_menu', 'pagenoemail_add_settings_page' );


/*------------------------------------------
  Render Settings Page Content
-------------------------------------------*/
function pagenoemail_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Page Notification Email Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'pagenoemail_settings_group' ); ?>
            <?php do_settings_sections( 'pagenoemail_settings_group' ); // Note: We aren't using sections/fields API here, just the group ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="pagenoemail_email_subject">Email Subject</label></th>
                    <td>
                        <input type="text" id="pagenoemail_email_subject" name="pagenoemail_email_subject" value="<?php echo esc_attr( get_option( 'pagenoemail_email_subject', 'Please check your page' ) ); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="pagenoemail_email_message">Email Message (HTML Allowed)</label></th>
                    <td>
                        <textarea id="pagenoemail_email_message" name="pagenoemail_email_message" rows="10" cols="50" class="large-text code"><?php echo esc_textarea( get_option( 'pagenoemail_email_message', "Hello,<br><br>{custom_message}<br><br>You are requested to check the following page:<br>{page_url}<br><br>Thank you." ) ); ?></textarea>
                        <p class="description">Use <code>{page_url}</code> for the post URL and <code>{custom_message}</code> for the optional message added on the edit screen.</p>
                    </td>
                </tr>
                 <tr valign="top">
                    <th scope="row"><label for="pagenoemail_bcc_address">Global BCC Address</label></th>
                    <td>
                        <input type="email" id="pagenoemail_bcc_address" name="pagenoemail_bcc_address" value="<?php echo esc_attr( get_option( 'pagenoemail_bcc_address', '' ) ); ?>" class="regular-text" />
                        <p class="description">Optional. If set, all notification emails sent by this plugin will be blind carbon copied to this address. Leave blank to disable.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enabled Post Types</th>
                    <td>
                        <?php
                        $post_types       = get_post_types( array( 'public' => true ), 'objects' );
                        $saved_post_types = get_option( 'pagenoemail_enabled_post_types', array( 'page', 'post' ) );
                        if ( ! is_array( $saved_post_types ) ) {
                            $saved_post_types = array( 'page', 'post' );
                        }
                        foreach ( $post_types as $post_type ) {
                            // Use unique ID for label association
                            $checkbox_id = 'pagenoemail_enabled_post_type_' . esc_attr( $post_type->name );
                            echo '<label for="' . $checkbox_id . '"><input type="checkbox" id="' . $checkbox_id . '" name="pagenoemail_enabled_post_types[]" value="' . esc_attr( $post_type->name ) . '" ' . checked( in_array( $post_type->name, $saved_post_types, true ), true, false ) . ' /> ' . esc_html( $post_type->labels->singular_name ) . '</label><br>';
                        }
                        ?>
                        <p class="description">Select the post types where the Notification Email meta box should appear. Pages and Posts are enabled by default.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}