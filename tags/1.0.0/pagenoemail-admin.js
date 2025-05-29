jQuery(document).ready(function ($) {
    console.log('[PNE Debug] Admin script loaded.');

    // --- Helper Functions ---

    // Function to save the meta box settings via AJAX
    function saveMetaBoxSettings(postId, notificationEmail, customMessage) {
        console.log('[PNE Debug] Saving settings for post ID:', postId);
        return $.ajax({
            url: pagenoemail_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pagenoemail_save_metabox_settings',
                nonce: pagenoemail_ajax_object.nonce,
                post_id: postId,
                notification_email: notificationEmail,
                custom_message: customMessage
            },
            dataType: 'json' // Expect a JSON response
        });
    }

    // Function to send the notification email via AJAX
    // NOW accepts email and message data to pass along
    function sendNotificationEmail(postId, notificationEmail, customMessage) {
        console.log('[PNE Debug] Sending email for post ID:', postId, 'with direct data.');
        return $.ajax({
            url: pagenoemail_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pagenoemail_send_notification_email',
                nonce: pagenoemail_ajax_object.nonce,
                post_id: postId,
                notification_email: notificationEmail, // Pass the emails directly
                custom_message: customMessage      // Pass the message directly
            },
            dataType: 'json' // Expect a JSON response
        });
    }

    // Function to display messages
    function showMessage(container, message, isSuccess) {
        var color = isSuccess ? 'green' : 'red';
        // If message is null or undefined, provide a default. Handle potential object structure in 'data'.
        var displayMessage = message;
        if (typeof message === 'object' && message !== null && message.message) {
            displayMessage = message.message; // Use message property if data is an object like { message: '...' }
        } else if (!message) {
            displayMessage = isSuccess ? 'Operation successful.' : 'An error occurred.';
        }

        container.html('<span style="color: ' + color + ';">' + displayMessage + '</span>');
        console.log('[PNE Debug] Message displayed:', displayMessage, 'Success:', isSuccess);
    }


    // --- Event Handlers ---

    // Handler for "Save Email & Message" button.
    $('#pagenoemail_save_settings_button').on('click', function (e) {
        e.preventDefault();
        console.log('[PNE Debug] Save Settings button clicked.');

        var $button = $(this);
        var post_id = $('#pagenoemail_post_id').val();
        var notification_email = $('#pagenoemail_notification_email').val();
        var custom_message = $('#pagenoemail_custom_message').val();
        var saveResponseContainer = $('#pagenoemail_save_response_message');
        var sendResponseContainer = $('#pagenoemail_send_response_message');

        saveResponseContainer.html('Saving...');
        sendResponseContainer.html(''); // Clear other message area
        $button.prop('disabled', true); // Disable button during save

        saveMetaBoxSettings(post_id, notification_email, custom_message)
            .done(function (response) {
                if (response && typeof response.success !== 'undefined') {
                    showMessage(saveResponseContainer, response.data, response.success);
                } else {
                    console.error('[PNE Debug] Unexpected save response format:', response);
                    showMessage(saveResponseContainer, 'Error: Unexpected response from server during save.', false);
                }
            })
            .fail(function (jqXHR, textStatus, errorThrown) {
                console.error('[PNE Debug] Save AJAX failed:', textStatus, errorThrown, jqXHR.responseText);
                showMessage(saveResponseContainer, 'Error: Could not save settings. ' + textStatus, false);
            })
            .always(function () {
                $button.prop('disabled', false);
                console.log('[PNE Debug] Save AJAX complete.');
            });
    });

    // Handler for "Send Notification Email" button.
    $('#pagenoemail_send_email_button').on('click', function (e) {
        e.preventDefault();
        console.log('[PNE Debug] Send Email button clicked.');

        var $button = $(this);
        var post_id = $('#pagenoemail_post_id').val();
        // Get the *current* values from the form fields
        var current_notification_email = $('#pagenoemail_notification_email').val();
        var current_custom_message = $('#pagenoemail_custom_message').val();
        var sendResponseContainer = $('#pagenoemail_send_response_message');
        var saveResponseContainer = $('#pagenoemail_save_response_message'); // To clear save message

        sendResponseContainer.html('Saving settings...'); // Initial status
        saveResponseContainer.html(''); // Clear separate save message
        $button.prop('disabled', true); // Disable button during operation

        // 1. Save settings first (using current field values)
        saveMetaBoxSettings(post_id, current_notification_email, current_custom_message)
            .then(function (saveResponse) {
                // Check the logical success *within* the response
                if (saveResponse && saveResponse.success) {
                    console.log('[PNE Debug] Save successful, proceeding to send.');
                    var saveMsg = (saveResponse.data && saveResponse.data.message) ? saveResponse.data.message : 'Settings saved.';
                    sendResponseContainer.html('<span style="color: green;">' + saveMsg + '</span> Sending email...');

                    // Use the *just-saved* data returned from the save response for sending
                    var emailToSend = (saveResponse.data && typeof saveResponse.data.notification_email !== 'undefined') ? saveResponse.data.notification_email : current_notification_email;
                    var messageToSend = (saveResponse.data && typeof saveResponse.data.custom_message !== 'undefined') ? saveResponse.data.custom_message : current_custom_message;

                    // 2. Send the email, passing the confirmed data
                    return sendNotificationEmail(post_id, emailToSend, messageToSend);

                } else {
                    console.error('[PNE Debug] Save failed logically:', saveResponse);
                    var saveErrorMessage = 'Could not save settings.'; // Default error
                    if (saveResponse && saveResponse.data) {
                        // Check if data itself is the message string or if it has a message property
                        if (typeof saveResponse.data === 'string') {
                            saveErrorMessage = saveResponse.data;
                        } else if (saveResponse.data.message) {
                            saveErrorMessage = saveResponse.data.message;
                        }
                    }
                    showMessage(sendResponseContainer, 'Error: ' + saveErrorMessage, false);
                    throw new Error('Save operation failed logically.');
                }
            })
            .then(function (sendResponse) {
                // Send AJAX call was successful (got 200 OK)
                if (sendResponse && sendResponse.success) {
                    console.log('[PNE Debug] Send successful.');
                    var finalMsg = 'Settings saved. ';
                    finalMsg += (typeof sendResponse.data === 'string') ? sendResponse.data : 'Email sent successfully.';
                    showMessage(sendResponseContainer, finalMsg, true);
                } else {
                    console.error('[PNE Debug] Send failed logically:', sendResponse);
                    var sendErrorMessage = (sendResponse && typeof sendResponse.data === 'string') ? sendResponse.data : 'Could not send email.';
                    showMessage(sendResponseContainer, 'Settings saved. Error: ' + sendErrorMessage, false);
                }
            })
            .fail(function (jqXHR_or_Error, textStatus, errorThrown) {
                // Catches AJAX failures OR the explicitly thrown 'Save operation failed logically.' error
                if (jqXHR_or_Error instanceof Error && jqXHR_or_Error.message === 'Save operation failed logically.') {
                    console.log('[PNE Debug] Chain stopped due to logical save failure.');
                    // Message already displayed by the first .then() block's error handling
                } else {
                    // Likely an AJAX communication error
                    console.error('[PNE Debug] Send/Save AJAX failed:', textStatus, errorThrown, jqXHR_or_Error.responseText || jqXHR_or_Error);
                    showMessage(sendResponseContainer, 'Error: Request failed. ' + (textStatus || 'Please check console.'), false);
                }
            })
            .always(function () {
                // Re-enable button once the entire chain is complete
                $button.prop('disabled', false);
                console.log('[PNE Debug] Send AJAX chain complete.');
            });
    });
});