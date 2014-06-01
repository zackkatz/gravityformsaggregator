<?php
/*
Plugin Name: Gravity Forms Aggregator
Plugin URI: http://www.stevenhenty.com
Description: Aggregate entries from multiple sites into a single installation
Version: 0.1
Author: Steve Henty
Author URI: http://www.stevenhenty.com
License: GPL-2.0+

------------------------------------------------------------------------
Copyright 2014  Steven Henty  (email: steven@stevenhenty.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


// Make sure Gravity Forms is active and already loaded.
if (class_exists("GFForms")) {

    // The Add-On Framework is not loaded by default.
    // Use the following function to load the appropriate files.
    GFForms::include_addon_framework();

    class GFAggregator extends GFAddOn {

        // The following class variables are used by the Framework.
        // They are defined in GFAddOn and should be overridden.

        // The version number is used for example during add-on upgrades.
        protected $_version = "0.1";

        // The Framework will display an appropriate message on the plugins page if necessary
        protected $_min_gravityforms_version = "1.8.7";

        // A short, lowercase, URL-safe unique identifier for the add-on.
        // This will be used for storing options, filters, actions, URLs and text-domain localization.
        protected $_slug = "gravityformsaggregator";

        // Relative path to the plugin from the plugins folder.
        protected $_path = "gravityformsaggregator/aggregator.php";

        // Full path the the plugin.
        protected $_full_path = __FILE__;

        // Title of the plugin to be used on the settings page, form settings and plugins page.
        protected $_title = "Gravity Forms Aggregator";

        // Short version of the plugin title to be used on menus and other places where a less verbose string is useful.
        protected $_short_title = "Aggregator";

        /**
         * The init_frontend() function is called during the WordPress init hook but only on the front-end.
         * Runs after WordPress has finished loading but before any headers are sent.
         * GFAddOn::init_frontend() handles the loading of the scripts and styles
         * so don't forget to call parent::init_frontend() unless you want to handle the script loading differently.
         */
        public function init_frontend() {
            parent::init_frontend();
            add_action('gform_after_submission', array($this, 'after_submission'), 10, 2);
        }

        /**
         * This is the target for the gform_after_submission action hook.
         * Executed at the end of the submission process (after form validation, notification, and entry creation).
         *
         * @param array $entry The Entry Object containing the submitted form values
         * @param array $form  The Form Object containing all the form and field settings
         *
         * @return bool
         */
        function after_submission($entry, $form) {

            // Make sure the Gravity Forms Web API wrapper can be found in the includes folder of the add-on.
            // Download from here:
            // https://raw.githubusercontent.com/rocketgenius/webapiclient/master/includes/class-gf-web-api-wrapper.php

            require_once("includes/class-gf-web-api-wrapper.php");

            $addon_settings = $this->get_plugin_settings();

            if (!$addon_settings) {
                return;
            }

            $api_url     = $addon_settings["site_url"];
            $public_key  = $addon_settings["site_api_key"];
            $private_key = $addon_settings["site_private_key"];
            $identifier  = !empty($addon_settings["identifier"]) ? $addon_settings["identifier"] : get_bloginfo();

            if (empty($api_url) || empty($public_key) || empty($private_key))
                return;


            // Use the helper method to get the form settings and make sure it's enabled for this form
            $form_settings = $this->get_form_settings($form);

            if (!$form_settings || !$form_settings["isEnabled"]) {
                return;
            }

            $remote_form_id = $form_settings["site_form_id"];

            // Set up the Web API connection to the remote site
            $web_api = new GFWebAPIWrapper($api_url, $public_key, $private_key);

            // Create the form on the central server if no Form ID is currently set
            if (empty($remote_form_id)) {
                $remote_form_id = $web_api->create_form($form);

                // Save the remote Form ID in the settings
                $form_settings["site_form_id"] = $remote_form_id;
                $this->save_form_settings($form, $form_settings);
            }

            // Set the form_id for the entry to the remote Form ID
            $entry["form_id"] = $remote_form_id;

            // Set the entry meta so the remote site can identify that this entry is from this site
            $entry["gf_aggregator_id"] = $identifier;

            // Create the entry on the central server
            // Note: A better approach would be to spin this off into a wp_cron task.
            $web_api->create_entry($entry);

            // If the delete entries setting is activated then delete this entry.
            if ($form_settings["deleteEntries"]) {
                GFAPI::delete_entry($entry["id"]);
            }

        }

        /**
         * Override the form_settings_field() function and return the configuration for the Form Settings.
         * Updating is handled by the Framework.
         *
         * @param array $form The Form object
         *
         * @return array
         */
        public function form_settings_fields($form) {
            return array(
                array(
                    "title"  => __("Aggregator Settings", "gravityformsaggregator"),
                    "fields" => array(
                        array(
                            "name"     => "enableAggregator",
                            "tooltip"  => __("Activate this setting to feed entries from this form to another Gravity Forms installation on a different site."),
                            "label"    => __("Remote Aggregation", "gravitycontacts"),
                            "onchange" => "jQuery(this).parents('form').submit();", // refresh the page so show/hide the settings below. Settings are not saved until the save button is pressed.
                            "type"     => "checkbox",
                            "choices"  => array(
                                array(
                                    "label" => __("Enable Remote Entry Aggregation for this form", "gravityformsaggregator"),
                                    "name"  => "isEnabled"
                                )
                            )
                        ),
                        array(
                            "name"       => "site_form_id",
                            'dependency' => array(
                                "field"  => "isEnabled",
                                "values" => array(1)
                            ),
                            "tooltip"    => __("If you know the Form ID on the central server, enter it here. If this is left blank, then the first time an entry is submitted the Form will be created on the central server and its ID will automatically saved in this setting.", "gravityformsaggregator"),
                            "label"      => __("Remote Form ID", "gravityformsaggregator"),
                            "type"       => "text"
                        ),
                        array(
                            "name"       => "enableDelete",
                            'dependency' => array(
                                "field"  => "isEnabled",
                                "values" => array(1)
                            ),
                            "tooltip"    => __("Activate this setting to delete the entry on this site after storing it on the remote site."),
                            "label"      => __("Delete entries", "gravitycontacts"),
                            "type"       => "checkbox",
                            "choices"    => array(
                                array(
                                    "label" => __("Delete entries on this site", "gravityformsaggregator"),
                                    "name"  => "deleteEntries"
                                )
                            )
                        ),
                        // This field isn't strictly necessary. If you don't include one then a generic update button will be generated for you.
                        array(
                            "type"     => "save",
                            "value"    => __("Update Aggregator Settings", "gravityformsaggregator"),
                            "messages" => array(
                                "success" => __("Aggregator settings updated", "gravityformsaggregator"),
                                "error"   => __("There was an error while saving the Aggregator Settings", "gravityformsaggregator")
                            )
                        )
                    )
                )
            );
        }

        /**
         * Override the plugin_settings_fields() function and return the configuration for the Add-On Settings.
         * Updating is handled by the Framework.
         *
         * @return array
         */
        public function plugin_settings_fields() {
            return array(
                array(
                    "title"       => __("Remote Site Configuration", "gravityformsaggregator"),
                    "description" => "This section should be completed if you want to send entries from this site to a remote site. If this site is the central server and will only receive entries from other sites then this section can be left blank.",
                    "fields"      => array(
                        array(
                            "name"        => "site_url",
                            "tooltip"     => __("Enter the URL of the remote site.", "gravityformsaggregator"),
                            "label"       => __("URL", "gravityformsaggregator"),
                            "type"        => "text",
                            "class"       => "medium",
                            "placeholder" => "http://example.com/gravityformsapi",
                        ),
                        array(
                            "name"    => "site_api_key",
                            "tooltip" => __("Enter the API key of the remote site.", "gravityformsaggregator"),
                            "label"   => __("API Key", "gravityformsaggregator"),
                            "type"    => "text"
                        ),
                        array(
                            "name"    => "site_private_key",
                            "tooltip" => __("Enter the private key for the API of the remote site.", "gravityformsaggregator"),
                            "label"   => __("API Private Key", "gravityformsaggregator"),
                            "type"    => "text"
                        )
                    )
                ),
                array(
                    "title"  => __("Local Site Configuration", "gravityformsaggregator"),
                    "fields" => array(
                        array(
                            "name"        => "identifier",
                            "tooltip"     => __("The remote site will use this value to filter results from this site. The site name will be used if this is left blank.", "gravityformsaggregator"),
                            "label"       => __("Site Identifier", "gravityformsaggregator"),
                            "type"        => "text",
                            "placeholder" => "e.g. Spain / Region 1 / Steve"
                        ),
                        array(
                            "name"    => "enableResults",
                            "tooltip" => __("Activate this setting on the central server to enable the results page."),
                            "label"   => __("Enable Results Page", "gravitycontacts"),
                            "type"    => "checkbox",
                            "choices" => array(
                                array(
                                    "label" => __("Enable Results", "gravityformsaggregator"),
                                    "name"  => "resultsEnabled"
                                )
                            )
                        )
                    )
                )
            );
        }

        /**
         * Define a custom title for the Add-On Settings page
         *
         * @return string
         */
        public function plugin_settings_title() {
            return "Gravity Forms Aggregator Add-On Settings";
        }

        /**
         * Entry meta data is custom data that's stored and retrieved along with the entry object.
         * For example, entry meta data may contain the results of a calculation made at the time of the entry submission.
         *
         * To add entry meta override the get_entry_meta() function and return an associative array with the following keys:
         *
         * label
         * - (string) The label for the entry meta
         * is_numeric
         * - (boolean) Used for sorting
         * is_default_column
         * - (boolean) Default columns appear in the entry list by default. Otherwise the user has to edit the columns and select the entry meta from the list.
         * update_entry_meta_callback
         * - (string | array) The function that should be called when updating this entry meta value
         * filter
         * - (array) An array containing the configuration for the filter used on the results pages, the entry list search and export entries page.
         *           The array should contain one element: operators. e.g. 'operators' => array("is", "isnot", ">", "<")
         *
         *
         * @param array $entry_meta An array of entry meta already registered with the gform_entry_meta filter.
         * @param int   $form_id    The Form ID
         *
         * @return array The filtered entry meta array.
         */
        public function get_entry_meta($entry_meta, $form_id) {
            $entry_meta['gf_aggregator_id'] = array(
                'label'                      => 'Site ID',
                'is_numeric'                 => false,
                'update_entry_meta_callback' => array($this, 'update_entry_meta'),
                'is_default_column'          => true, // default column on the entry list
                'filter'                     => array(
                    'operators' => array("is", "isnot", "contains")
                )
            );

            return $entry_meta;
        }

        /**
         * The target of update_entry_meta_callback.
         *
         * @param string $key   The entry meta key
         * @param array  $entry The Entry Object
         * @param array  $form  The Form Object
         *
         * @return string|void
         */
        public function update_entry_meta($key, $entry, $form) {
            if ($key === "gf_aggregator_id") {
                $add_on_settings = $this->get_plugin_setting('identifier');

                return empty($add_on_settings) ? get_bloginfo() : $add_on_settings;
            }
        }

        /**
         * To add a results page override the get_results_page_config() function and return the configuration array with the following keys:
         *
         * title
         * - (string) The title of the results page
         * capabilities
         * - (array) An array of capabilities that can view the results page. Optional.
         * callbacks
         * - (array) An array of callback functions. Optional.
         * Possible values: fields, markup, calculation
         *
         * @return array|bool
         */
        public function get_results_page_config() {
            $settings = $this->get_plugin_settings();

            return $settings["resultsEnabled"] ? array("title" => "Aggregation Results") : array();
        }
    }
}

new GFAggregator();

