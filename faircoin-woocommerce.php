<?php
/*


Plugin Name: Faircoin Payments for WooCommerce
Plugin URI: https://github.com/Punto0/faircoin-payments-for-woocommerce
Description: Faircoin Payments for WooCommerce plugin allows you to accept payments in faircoins for physical and digital products at your WooCommerce-powered online store. Forked from Bitcoin Payments for WooCommerce plugin from bitcoinway.com 
Version: 0.01
Author: Punto0 for the Faircoin fork - BitcoinWay for the original Bitcoin plugin
Author URI: https://github.com/Punto0/faircoin-payments-for-woocommerce
License: GNU General Public License 2.0 (GPL) http://www.gnu.org/licenses/gpl.html

*/


// Include everything
include (dirname(__FILE__) . '/fwwc-include-all.php');

//---------------------------------------------------------------------------
// Add hooks and filters

// create custom plugin settings menu
add_action( 'admin_menu',                   'FWWC_create_menu' );

register_activation_hook(__FILE__,          'FWWC_activate');
register_deactivation_hook(__FILE__,        'FWWC_deactivate');
register_uninstall_hook(__FILE__,           'FWWC_uninstall');

add_filter ('cron_schedules',               'FWWC__add_custom_scheduled_intervals');
add_action ('FWWC_cron_action',             'FWWC_cron_job_worker');     // Multiple functions can be attached to 'FWWC_cron_action' action

FWWC_set_lang_file();
//---------------------------------------------------------------------------

//===========================================================================
// activating the default values
function FWWC_activate()
{
    global  $g_FWWC__config_defaults;

    $fwwc_default_options = $g_FWWC__config_defaults;

    // This will overwrite default options with already existing options but leave new options (in case of upgrading to new version) untouched.
    $fwwc_settings = FWWC__get_settings ();

    foreach ($fwwc_settings as $key=>$value)
    	$fwwc_default_options[$key] = $value;

    update_option (FWWC_SETTINGS_NAME, $fwwc_default_options);

    // Re-get new settings.
    $fwwc_settings = FWWC__get_settings ();

    // Create necessary database tables if not already exists...
    FWWC__create_database_tables ($fwwc_settings);
    FWWC__SubIns ();

    //----------------------------------
    // Setup cron jobs

    if ($fwwc_settings['enable_soft_cron_job'] && !wp_next_scheduled('FWWC_cron_action'))
    {
    	$cron_job_schedule_name = strpos($_SERVER['HTTP_HOST'], 'ttt.com')===FALSE ? $fwwc_settings['soft_cron_job_schedule_name'] : 'seconds_30';
    	wp_schedule_event(time(), $cron_job_schedule_name, 'FWWC_cron_action');
    }
    //----------------------------------

}
//---------------------------------------------------------------------------
// Cron Subfunctions
function FWWC__add_custom_scheduled_intervals ($schedules)
{
	$schedules['seconds_30']     = array('interval'=>30,     'display'=>__('Once every 30 seconds'));     // For testing only.
	$schedules['minutes_1']      = array('interval'=>1*60,   'display'=>__('Once every 1 minute'));
	$schedules['minutes_2.5']    = array('interval'=>2.5*60, 'display'=>__('Once every 2.5 minutes'));
	$schedules['minutes_5']      = array('interval'=>5*60,   'display'=>__('Once every 5 minutes'));

	return $schedules;
}
//---------------------------------------------------------------------------
//===========================================================================

//===========================================================================
// deactivating
function FWWC_deactivate ()
{
    // Do deactivation cleanup. Do not delete previous settings in case user will reactivate plugin again...

   //----------------------------------
   // Clear cron jobs
   wp_clear_scheduled_hook ('FWWC_cron_action');
   //----------------------------------
}
//===========================================================================

//===========================================================================
// uninstalling
function FWWC_uninstall ()
{
    $fwwc_settings = FWWC__get_settings();

    if ($fwwc_settings['delete_db_tables_on_uninstall'])
    {
        // delete all settings.
        delete_option(FWWC_SETTINGS_NAME);

        // delete all DB tables and data.
        FWWC__delete_database_tables ();
    }
}
//===========================================================================

//===========================================================================
function FWWC_create_menu()
{

    // create new top-level menu
    // http://www.fileformat.info/info/unicode/char/e3f/index.htm
    add_menu_page (
        __('Woo Faircoin', FWWC_I18N_DOMAIN),                    // Page title
        __('Faircoin', FWWC_I18N_DOMAIN),                        // Menu Title - lower corner of admin menu
        'administrator',                                        // Capability
        'fwwc-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'FWWC__render_general_settings_page',                   // Function

        'https://fair-coin.org/sites/default/files/faircoin_favicon.png'      // Icon URL
        );

    add_submenu_page (
        'fwwc-settings',                                        // Parent
        __("WooCommerce Faircoin Payments Gateway", FWWC_I18N_DOMAIN),                   // Page title
        __("General Settings", FWWC_I18N_DOMAIN),               // Menu Title
        'administrator',                                        // Capability
        'fwwc-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'FWWC__render_general_settings_page'                    // Function
        );
    /*
    add_submenu_page (
        'fwwc-settings',                                        // Parent
        __("Faircoin Plugin Advanced Settings", FWWC_I18N_DOMAIN),       // Page title
        __("Advanced Settings", FWWC_I18N_DOMAIN),                // Menu title
        'administrator',                                        // Capability
        'fwwc-settings-advanced',                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'FWWC__render_advanced_settings_page'            // Function
        ); */
}
//===========================================================================

//===========================================================================
// load language files
function FWWC_set_lang_file()
{
    # set the language file
    $currentLocale = get_locale();
    if(!empty($currentLocale))
    {
        $moFile = dirname(__FILE__) . "/lang/" . $currentLocale . ".mo";
        if (@file_exists($moFile) && is_readable($moFile))
        {
            load_textdomain(FWWC_I18N_DOMAIN, $moFile);
        }

    }
}
//===========================================================================

