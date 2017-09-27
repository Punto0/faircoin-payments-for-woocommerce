<?php
/*
Faircoin Payments for WooCommerce
http://www.faircoinway.com/
*/

//---------------------------------------------------------------------------
// Global definitions
if (!defined('FWWC_PLUGIN_NAME'))
  {
  define('FWWC_VERSION',           '4.11');

  //-----------------------------------------------
  define('FWWC_EDITION',           'Standard');    


  //-----------------------------------------------
  define('FWWC_SETTINGS_NAME',     'FWWC-Settings');
  define('FWWC_PLUGIN_NAME',       'Faircoin Payments for WooCommerce');   


  // i18n plugin domain for language files
  define('FWWC_I18N_DOMAIN',       'fwwc');

  if (extension_loaded('gmp') && !defined('USE_EXT'))
    define ('USE_EXT', 'GMP');
  else if (extension_loaded('bcmath') && !defined('USE_EXT'))
    define ('USE_EXT', 'BCMATH');
  }
//---------------------------------------------------------------------------

//------------------------------------------
// Load wordpress for POSTback, WebHook and API pages that are called by external services directly.
if (defined('FWWC_MUST_LOAD_WP') && !defined('WP_USE_THEMES') && !defined('ABSPATH'))
   {
   $g_blog_dir = preg_replace ('|(/+[^/]+){4}$|', '', str_replace ('\\', '/', __FILE__)); // For love of the art of regex-ing
   define('WP_USE_THEMES', false);
   require_once ($g_blog_dir . '/wp-blog-header.php');

   // Force-elimination of header 404 for non-wordpress pages.
   header ("HTTP/1.1 200 OK");
   header ("Status: 200 OK");

   require_once ($g_blog_dir . '/wp-admin/includes/admin.php');
   }
//------------------------------------------


// This loads necessary modules and selects best math library
if (!class_exists ('bcmath_Utils'))
	require_once (dirname(__FILE__) . '/libs/util/bcmath_Utils.php');
if (!class_exists ('gmp_Utils'))
	require_once (dirname(__FILE__) . '/libs/util/gmp_Utils.php');
if (!class_exists ('CurveFp'))
	require_once (dirname(__FILE__) . '/libs/CurveFp.php');
if (!class_exists ('Point'))
	require_once (dirname(__FILE__) . '/libs/Point.php');
if (!class_exists ('NumberTheory'))
	require_once (dirname(__FILE__) . '/libs/NumberTheory.php');
require_once (dirname(__FILE__) . '/libs/ElectrumHelper.php');
require_once (dirname(__FILE__) . '/fwwc-cron.php');
require_once (dirname(__FILE__) . '/fwwc-mpkgen.php');
require_once (dirname(__FILE__) . '/fwwc-utils.php');
require_once (dirname(__FILE__) . '/fwwc-admin.php');
require_once (dirname(__FILE__) . '/fwwc-render-settings.php');
require_once (dirname(__FILE__) . '/fwwc-faircoin-gateway.php');

?>
