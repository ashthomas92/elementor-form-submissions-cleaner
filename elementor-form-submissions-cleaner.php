<?php
/*
Plugin Name: Elementor Form Submissions Cleaner
Description: Adds a setting to Elementor to automatically delete form submissions older than a specified number of days.
Version: 1.0
Author: Fraxinus Studio
Author URI: https://fraxinus.studio
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Elementor_Form_Submissions_Cleaner {

    /**
     * Constructor.
     */
    public function __construct() {
        // Hook into Elementor's initialization
        add_action('elementor/init', [$this, 'init']);

        // Save the setting value
        add_action('admin_init', [$this, 'save_setting']);

        // Schedule the cron job on plugin activation
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Unschedule the cron job on plugin deactivation
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Hook into the cron job to delete old submissions
        add_action('efsc_daily_cleanup', [$this, 'delete_old_submissions']);

        // Add JavaScript to enforce the minimum value
        add_action('admin_footer', [$this, 'efsc_js']);
    }

    /**
     * Initialize the plugin.
     */
    public function init() {
        // Ensure Elementor is loaded
        if (!class_exists('Elementor\Plugin')) {
            return;
        }

        // Add the setting to the "Advanced" tab
        add_action('elementor/admin/after_create_settings/elementor', [$this, 'add_setting']);
    }

    /**
     * Add the "Keep submissions for X days" setting.
     */
    public function add_setting($settings) {
        $settings->add_field('advanced', 'keep_submissions_days', 'keep_submissions_days', [
            'label' => __('Keep submissions for X days', 'elementor'),
            'field_args' => [
                'type' => 'number',
                'std' => null,
                'placeholder' => __('Enter number of days', 'elementor'),
                'desc' => __('Form submissions older than the specified number of days will be deleted automatically.', 'elementor'),
            ],
        ]);
    }

    /**
     * Save the setting value.
     */
    public function save_setting() {
        if (isset($_POST['elementor_keep_submissions_days'])) {
            $value = max(0, intval($_POST['elementor_keep_submissions_days'])); // Ensure the value is not negative
            update_option('elementor_keep_submissions_days', $value);
        }
    }

    /**
     * Activate the plugin.
     */
    public function activate() {
        if (!wp_next_scheduled('efsc_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'efsc_daily_cleanup');
        }
    }

    /**
     * Deactivate the plugin.
     */
    public function deactivate() {
        $timestamp = wp_next_scheduled('efsc_daily_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'efsc_daily_cleanup');
        }
    }

    /**
     * Delete old submissions.
     */
    public function delete_old_submissions() {
        $days_to_keep = get_option('elementor_keep_submissions_days', null);

        if ($days_to_keep === null || $days_to_keep <= 0) {
            return; // Do nothing if no valid value is set
        }

        global $wpdb;

        // Define table names
        $tables = [
            'submissions' => $wpdb->prefix . 'e_submissions',
            'actions_log' => $wpdb->prefix . 'e_submissions_actions_log',
            'values' => $wpdb->prefix . 'e_submissions_values',
        ];

        // Calculate the date threshold
        $date_threshold = date('Y-m-d H:i:s', strtotime("-$days_to_keep days"));

        // Delete from e_submissions_actions_log
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$tables['actions_log']} WHERE submission_id IN (
                    SELECT id FROM {$tables['submissions']} WHERE created_at < %s
                )",
                $date_threshold
            )
        );

        // Delete from e_submissions_values
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$tables['values']} WHERE submission_id IN (
                    SELECT id FROM {$tables['submissions']} WHERE created_at < %s
                )",
                $date_threshold
            )
        );

        // Delete from e_submissions
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$tables['submissions']} WHERE created_at < %s",
                $date_threshold
            )
        );
    }

    /**
     * Add JavaScript to enforce the minimum value.
     */
    public function efsc_js() {
        // Check if we're on the Elementor settings page
        $screen = get_current_screen();
        if ($screen && $screen->id === 'elementor_page_elementor-settings') {
            ?>
            <script>
            jQuery(document).ready(function($) {
                // Move the setting to the right place
                var $settingRow = $('tr.elementor_keep_submissions_days');
                $('tr.elementor_form-submissions').after($settingRow.clone());
                $settingRow.remove();

                // Find the input field for "Keep submissions for X days"
                var $inputField = $('input[name="elementor_keep_submissions_days"]');
                if ($inputField.length) {
                    // Set the min attribute
                    $inputField.attr('min', 0);

                    // Ensure the value is not negative on input
                    $inputField.on('input', function() {
                        if (this.value < 0) {
                            this.value = 0;
                        }
                    });
                }

                // Toggle the setting based on form submissions status
                function toggleSubmissionSettings() {
                    var $settingRow = $('tr.elementor_keep_submissions_days');
                    if ($('.elementor_form-submissions :selected').text() === 'Disable') {
                        $settingRow.find('td, th').slideUp('fast');
                    } else {
                        $settingRow.find('td, th').slideDown('fast');
                    }
                }

                // Initial toggle check
                toggleSubmissionSettings();

                // Toggle on form submissions status change
                $(document).on('change', '.elementor_form-submissions', toggleSubmissionSettings);
            });
            </script>
            <?php
        }
    }
}

// Initialize the plugin
new Elementor_Form_Submissions_Cleaner();
