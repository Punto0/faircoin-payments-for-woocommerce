<?php
/*
Faircoin Payments for WooCommerce

*/

//---------------------------------------------------------------------------
// Global definitions
if (!defined('FWWC_PLUGIN_NAME'))
  {
  define('FWWC_VERSION',           '0.1.0');

  //-----------------------------------------------
  define('FWWC_EDITION',           'Alpha');    


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

// This loads the phpecc modules and selects best math library
//require_once (dirname(__FILE__) . '/phpecc/classes/util/bcmath_Utils.php');
//require_once (dirname(__FILE__) . '/phpecc/classes/util/gmp_Utils.php');
//require_once (dirname(__FILE__) . '/phpecc/classes/interface/CurveFpInterface.php');
//require_once (dirname(__FILE__) . '/phpecc/classes/CurveFp.php');
//require_once (dirname(__FILE__) . '/phpecc/classes/interface/PointInterface.php');
//require_once (dirname(__FILE__) . '/phpecc/classes/Point.php');
//require_once (dirname(__FILE__) . '/phpecc/classes/NumberTheory.php');

require_once (dirname(__FILE__) . '/fwwc-cron.php');
require_once (dirname(__FILE__) . '/fwwc-mpkgen.php');
require_once (dirname(__FILE__) . '/fwwc-utils.php');
require_once (dirname(__FILE__) . '/fwwc-admin.php');
require_once (dirname(__FILE__) . '/fwwc-render-settings.php');
require_once (dirname(__FILE__) . '/fwwc-faircoin-gateway.php');

?>
