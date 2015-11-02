<?php
/*
Faircoin Payments for WooCommerce

*/

// Include everything
include (dirname(__FILE__) . '/fwwc-include-all.php');

//===========================================================================
function FWWC__render_general_settings_page ()   { FWWC__render_settings_page   ('general'); }
function FWWC__render_advanced_settings_page ()  { FWWC__render_settings_page   ('advanced'); }
//===========================================================================

//===========================================================================
function FWWC__render_settings_page ($menu_page_name)
{
   if (isset ($_POST['button_update_fwwc_settings']))
      {
      FWWC__update_settings ("", false);
echo <<<HHHH
<div align="center" style="background-color:#FFFFE0;padding:5px;font-size:120%;border: 1px solid #E6DB55;margin:5px;border-radius:3px;">
Settings updated!
</div>
HHHH;
      }
   else if (isset($_POST['button_reset_fwwc_settings']))
      {
      FWWC__reset_all_settings (false);
echo <<<HHHH
<div align="center" style="background-color:#FFFFE0;padding:5px;font-size:120%;border: 1px solid #E6DB55;margin:5px;border-radius:3px;">
All settings reverted to all defaults
</div>
HHHH;
      }
   else if (isset($_POST['button_reset_partial_fwwc_settings']))
      {
      FWWC__reset_partial_settings (false);
echo <<<HHHH
<div align="center" style="background-color:#FFFFE0;padding:5px;font-size:120%;border: 1px solid #E6DB55;margin:5px;border-radius:3px;">
Settings on this page reverted to defaults
</div>
HHHH;
      }
   else if (isset($_POST['validate_fwwc-license']))
      {
      FWWC__update_settings ("", false);
      }

   // Output full admin settings HTML
   echo '<div class="wrap">';

   switch ($menu_page_name)
      {
      case 'general'     :
        echo     FWWC__GetPluginNameVersionEdition(true);
        FWWC__render_general_settings_page_html();
        break;

      case 'advanced'    :
        echo     FWWC__GetPluginNameVersionEdition(false);
        FWWC__render_advanced_settings_page_html();
        break;

      default            :
        break;
      }

   echo '</div>'; // wrap
}
//===========================================================================

//===========================================================================
function FWWC__render_general_settings_page_html ()
{
  $fwwc_settings = FWWC__get_settings ();
  global $g_FWWC__cron_script_url;

?>

    <form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
      <p class="submit">
          <input type="submit" class="button-primary"    name="button_update_fwwc_settings"        value="<?php _e('Save Changes') ?>"             />
          <input type="submit" class="button-secondary"  style="color:red;" name="button_reset_partial_fwwc_settings" value="<?php _e('Reset settings') ?>" onClick="return confirm('Are you sure you want to reset settings on this page?');" />
      </p>
      <table class="form-table">


        <tr valign="top">
            <th scope="row">Delete all plugin-specific settings, database tables and data on uninstall:</th>
            <td>
              <input type="hidden" name="delete_db_tables_on_uninstall" value="0" /><input type="checkbox" name="delete_db_tables_on_uninstall" value="1" <?php if ($fwwc_settings['delete_db_tables_on_uninstall']) echo 'checked="checked"'; ?> />
              <p class="description">If checked - all plugin-specific settings, database tables and data will be removed from Wordpress database upon plugin uninstall (but not upon deactivation or upgrade).</p>
            </td>
        </tr>

        <tr valign="top">
            <th scope="row">Extreme privacy mode enabled?</th>
            <td>


              <select name="PRO_EDITION_ONLY" class="select">
                <option selected="selected" disabled="disabled" value="0">No (default)</option>
              </select> <?php echo FWWC__GetProLabel(); ?>


              <p class="description">
                <b>No</b> (default, recommended) - will allow to recycle faircoin addresses that been generated for each placed order but never received any payments. The drawback of this approach is that potential snoop can generate many fake (never paid for) orders to discover sequence of faircoin addresses that belongs to the wallet of this store and then track down sales through blockchain analysis. The advantage of this option is that it very efficiently reuses empty (zero-balance) faircoin addresses within the wallet, allowing only 1 sale per address without growing the wallet size (Electrum "gap" value).
                <br />
                <b>Yes</b> - this will guarantee to generate unique faircoin address for every order (real, accidental or fake). This option will provide the most anonymity and privacy to the store owner's wallet. The drawback is that it will likely leave a number of addresses within the wallet never used (and hence will require setting very high 'gap limit' within the Electrum wallet much sooner).
                <br />It is recommended to regenerate new wallet after number of used faircoin addresses reaches 1000. Wallets with very high gap limits (>1000) are very slow to sync with blockchain and they put an extra load on the network. <br />
                Extreme privacy mode offers the best anonymity and privacy to the store albeit with the drawbacks of potentially flooding your Electrum wallet with expired and zero-balance addresses. Hence, for vast majority of cases (where you just need a secure way to operate faircoin based store) it is suggested to set this option to 'No').<br />
                <b>Note</b>: It is possible to change this option at any time and it will take effect immediately.
              </p>
            </td>
        </tr>

        <tr valign="top">
            <th scope="row">Cron job type:</th>
            <td>
              <select name="enable_soft_cron_job" class="select ">
                <option <?php if ($fwwc_settings['enable_soft_cron_job'] == '1') echo 'selected="selected"'; ?> value="1">Soft Cron (Wordpress-driven)</option>
                <option <?php if ($fwwc_settings['enable_soft_cron_job'] != '1') echo 'selected="selected"'; ?> value="0">Hard Cron (Cpanel-driven)</option>
              </select>
              <p class="description">
                <?php if ($fwwc_settings['enable_soft_cron_job'] != '1') echo '<p style="background-color:#FFC;color:#2A2;"><b>NOTE</b>: Hard Cron job is enabled: make sure to follow instructions below to enable hard cron job at your hosting panel.</p>'; ?>
                Cron job will take care of all regular faircoin payment processing tasks, like checking if payments are made and automatically completing the orders.<br />
                <b>Soft Cron</b>: - Wordpress-driven (runs on behalf of a random site visitor).
                <br />
                <b>Hard Cron</b>: - Cron job driven by the website hosting system/server (usually via CPanel). <br />
                When enabling Hard Cron job - make this script to run every 5 minutes at your hosting panel cron job scheduler:<br />
                <?php echo '<tt style="background-color:#FFA;color:#B00;padding:0px 6px;">wget -O /dev/null ' . $g_FWWC__cron_script_url . '?hardcron=1</tt>'; ?>
                <br /><u>Note:</u> You will need to deactivate/reactivate plugin after changing this setting for it to have effect.<br />
                "Hard" cron jobs may not be properly supported by all hosting plans (many shared hosting plans has restrictions in place).
                <br />For secure, fast hosting service optimized for wordpress and 100% compatibility with WooCommerce and Faircoin payments we recommend <b><a href="http://sareanweb.net" target="_blank">Punto0 Hosting</a></b>.
              </p>
            </td>
        </tr>

      </table>

      <p class="submit">
          <input type="submit" class="button-primary"    name="button_update_fwwc_settings"        value="<?php _e('Save Changes') ?>"             />
          <input type="submit" class="button-secondary"  style="color:red;" name="button_reset_partial_fwwc_settings" value="<?php _e('Reset settings') ?>" onClick="return confirm('Are you sure you want to reset settings on this page?');" />
      </p>
    </form>
<?php
}
//===========================================================================

//===========================================================================
function FWWC__render_advanced_settings_page_html ()
{
   $fwwc_settings = FWWC__get_settings ();


?>
<?php
}
//===========================================================================
