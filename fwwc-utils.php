<?php
/*
Faircoin Payments for WooCommerce
https://github.com/Punto0/faircoin-payments-for-woocommerce
*/

//===========================================================================
/*
   Input:
   ------
      $order_info =
         array (
            'order_id'        => $order_id,
            'order_total'     => $order_total_in_fair,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            );
*/
// Returns:
// --------
/*
    $ret_info_array = array (
       'result'                      => 'success', // OR 'error'
       'message'                     => '...',
       'host_reply_raw'              => '......',
       'generated_faircoin_address'   => 'fYhabpU9ZGWS9EazhwLUHmWFTaEob1fiFH', // or false
       );
*/
//


function FWWC__get_faircoin_address_for_payment__electrum ($electrum_mpk, $order_info)
{
   global $wpdb;

   // status = "unused", "assigned", "used"
   $fair_addresses_table_name     = $wpdb->prefix . 'fwwc_fair_addresses';
   $origin_id                    = $electrum_mpk;

   $fwwc_settings = FWWC__get_settings ();
   // FWWC__log_event (__FILE__, __LINE__, "settings: " . print_r ($fwwc_settings, true));
   $funds_received_value_expires_in_secs = $fwwc_settings['funds_received_value_expires_in_mins'] * 60;
   $assigned_address_expires_in_secs     = $fwwc_settings['assigned_address_expires_in_mins'] * 60;

   $clean_address = NULL;
   $current_time = time();

   if ($fwwc_settings['reuse_expired_addresses'])
   {
      $reuse_expired_addresses_freshb_query_part =
      	"OR (`status`='assigned'
      		AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs')
      		AND (('$current_time' - `received_funds_checked_at`) < '$funds_received_value_expires_in_secs')
      		)";
   }
   else
      $reuse_expired_addresses_freshb_query_part = "";

   //-------------------------------------------------------
   // Quick scan for ready-to-use address
   // NULL == not found
   // Retrieve:
   //     'unused'   - with fresh zero balances
   //     'assigned' - expired, with fresh zero balances (if 'reuse_expired_addresses' is true)
   //
   // Hence - any returned address will be clean to use.
   $query =
      "SELECT `fair_address` FROM `$fair_addresses_table_name`
         WHERE `origin_id`='$origin_id'
         AND `total_received_funds`='0'
         AND (`status`='unused' $reuse_expired_addresses_freshb_query_part)
         ORDER BY `index_in_wallet` ASC
         LIMIT 1;"; // Try to use lower indexes first
   $clean_address = $wpdb->get_var ($query);

   //-------------------------------------------------------

  if (!$clean_address)
  {

      //-------------------------------------------------------
      // Find all unused addresses belonging to this mpk with possibly (to be verified right after) zero balances
      // Array(rows) or NULL
      // Retrieve:
      //    'unused'    - with old zero balances
      //    'unknown'   - ALL
      //    'assigned'  - expired with old zero balances (if 'reuse_expired_addresses' is true)
      //
      // Hence - any returned address with freshened balance==0 will be clean to use.
	   if ($fwwc_settings['reuse_expired_addresses'])
			{
	      $reuse_expired_addresses_oldb_query_part =
	      	"OR (`status`='assigned'
	      		AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs')
	      		AND (('$current_time' - `received_funds_checked_at`) > '$funds_received_value_expires_in_secs')
	      		)";
			}
			else
	      $reuse_expired_addresses_oldb_query_part = "";

      $query =
         "SELECT * FROM `$fair_addresses_table_name`
            WHERE `origin_id`='$origin_id'
	         	AND `total_received_funds`='0'
            AND (
               `status`='unused'
               OR `status`='unknown'
               $reuse_expired_addresses_oldb_query_part
               )
            ORDER BY `index_in_wallet` ASC;"; // Try to use lower indexes first
      $addresses_to_verify_for_zero_balances_rows = $wpdb->get_results ($query, ARRAY_A);

      if (!is_array($addresses_to_verify_for_zero_balances_rows))
         $addresses_to_verify_for_zero_balances_rows = array();
      //-------------------------------------------------------

      //-------------------------------------------------------
      // Try to re-verify balances of existing addresses (with old or non-existing balances) before reverting to slow operation of generating new address.
      //
      $blockchains_api_failures = 0;
      foreach ($addresses_to_verify_for_zero_balances_rows as $address_to_verify_for_zero_balance_row)
      {
         $address_to_verify_for_zero_balance = $address_to_verify_for_zero_balance_row['fair_address'];

         $address_request_array = array();
         $address_request_array['fair_address'] = $address_to_verify_for_zero_balance;
         $address_request_array['required_confirmations'] = 0;
         $address_request_array['api_timeout'] = $fwwc_settings['blockchain_api_timeout_secs'];
         $ret_info_array = FWWC__getreceivedbyaddress_info ($address_request_array, $fwwc_settings);

         if ($ret_info_array['balance'] === false)
         {
           $blockchains_api_failures ++;
           if ($blockchains_api_failures >= $fwwc_settings['max_blockchains_api_failures'])
           {
             // Allow no more than 3 contigious blockchains API failures. After which return error reply.
             $ret_info_array = array (
               'result'                      => 'error',
               'message'                     => $ret_info_array['message'],
               'host_reply_raw'              => $ret_info_array['host_reply_raw'],
               'generated_faircoin_address'   => false,
               );
             return $ret_info_array;
           }
         }
         else
         {
           if ($ret_info_array['balance'] == 0)
           {
             // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
             $clean_address    = $address_to_verify_for_zero_balance;
             break;
           }
          else
					{
						// Balance at this address suddenly became non-zero!
						// It means either order was paid after expiration or "unknown" address suddenly showed up with non-zero balance or payment was sent to this address outside of this online store business.
						// Mark it as 'revalidate' so cron job would check if that's possible delayed payment.
						//
					  $address_meta    = FWWC_unserialize_address_meta (@$address_to_verify_for_zero_balance_row['address_meta']);
					  if (isset($address_meta['orders'][0]))
					  	$new_status = 'revalidate';	// Past orders are present. There is a chance (for cron job) to match this payment to past (albeit expired) order.
					  else
					  	$new_status = 'used';				// No orders were ever placed to this address. Likely payment was sent to this address outside of this online store business.

						$current_time = time();
			      $query =
			      "UPDATE `$fair_addresses_table_name`
			         SET
			            `status`='$new_status',
			            `total_received_funds` = '{$ret_info_array['balance']}',
			            `received_funds_checked_at`='$current_time'
			        WHERE `fair_address`='$address_to_verify_for_zero_balance';";
			      $ret_code = $wpdb->query ($query);
					}
        }
      }
      //-------------------------------------------------------
  	}

  //-------------------------------------------------------
  if (!$clean_address)
  {
    // Still could not find unused virgin address. Time to generate it from scratch.
    /*
    Returns:
       $ret_info_array = array (
          'result'                      => 'success', // 'error'
          'message'                     => '', // Failed to find/generate faircoin address',
          'host_reply_raw'              => '', // Error. No host reply availabe.',
          'generated_faircoin_address'   => 'fYhabpU9ZGWS9EazhwLUHmWFTaEob1fiFH', // false,
          );
    */
    $ret_addr_array = FWWC__generate_new_faircoin_address_for_electrum_wallet ($fwwc_settings, $electrum_mpk);
    if ($ret_addr_array['result'] == 'success')
      $clean_address = $ret_addr_array['generated_faircoin_address'];
  }
  //-------------------------------------------------------

  //-------------------------------------------------------
   if ($clean_address)
   {
   /*
         $order_info =
         array (
            'order_id'     => $order_id,
            'order_total'  => $order_total_in_fair,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            );

*/

      /*
      $address_meta =
         array (
            'orders' =>
               array (
                  // All orders placed on this address in reverse chronological order
                  array (
                     'order_id'     => $order_id,
                     'order_total'  => $order_total_in_fair,
                     'order_datetime'  => date('Y-m-d H:i:s T'),
                     'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
                  ),
                  array (
                     ...
                  ),
               ),
            'other_meta_info' => array (...)
         );
      */

      // Prepare `address_meta` field for this clean address.
      $address_meta = $wpdb->get_var ("SELECT `address_meta` FROM `$fair_addresses_table_name` WHERE `fair_address`='$clean_address'");
      $address_meta = FWWC_unserialize_address_meta ($address_meta);

      if (!isset($address_meta['orders']) || !is_array($address_meta['orders']))
         $address_meta['orders'] = array();

      array_unshift ($address_meta['orders'], $order_info);    // Prepend new order to array of orders
      if (count($address_meta['orders']) > 10)
         array_pop ($address_meta['orders']);   // Do not keep history of more than 10 unfullfilled orders per address.
      $address_meta_serialized = FWWC_serialize_address_meta ($address_meta);

      // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
      //
      $current_time = time();
      $remote_addr  = $order_info['requested_by_ip'];
      $query =
      "UPDATE `$fair_addresses_table_name`
         SET
            `total_received_funds` = '0',
            `received_funds_checked_at`='$current_time',
            `status`='assigned',
            `assigned_at`='$current_time',
            `last_assigned_to_ip`='$remote_addr',
            `address_meta`='$address_meta_serialized'
        WHERE `fair_address`='$clean_address';";
      $ret_code = $wpdb->query ($query);

      $ret_info_array = array (
         'result'                      => 'success',
         'message'                     => "",
         'host_reply_raw'              => "",
         'generated_faircoin_address'   => $clean_address,
         );

      return $ret_info_array;
  }
  //-------------------------------------------------------

   $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => 'Failed to find/generate faircoin address. ' . $ret_addr_array['message'],
      'host_reply_raw'              => $ret_addr_array['host_reply_raw'],
      'generated_faircoin_address'   => false,
      );
   return $ret_info_array;
}
//===========================================================================

//===========================================================================
// To accomodate for multiple MPK's and allowed key limits per MPK
function FWWC__get_next_available_mpk ($fwwc_settings=false)
{
  //global $wpdb;
  //$fair_addresses_table_name = $wpdb->prefix . 'fwwc_fair_addresses';
  // Scan DB for MPK which has number of in-use keys less than alowed limit
  // ...

  if (!$fwwc_settings)
    $fwwc_settings = FWWC__get_settings ();

  return @$fwwc_settings['electrum_mpks'][0];
}
//===========================================================================

//===========================================================================
/*
Returns:
   $ret_info_array = array (
      'result'                      => 'success', // 'error'
      'message'                     => '', // Failed to find/generate faircoin address',
      'host_reply_raw'              => '', // Error. No host reply availabe.',
      'generated_faircoin_address'   => 'fYhabpU9ZGWS9EazhwLUHmWFTaEob1fiFH', // false,
      );
*/
// If $fwwc_settings or $electrum_mpk are missing - the best attempt will be made to manifest them.
// For performance reasons it is better to pass in these vars. if available.
//
function FWWC__generate_new_faircoin_address_for_electrum_wallet ($fwwc_settings=false, $electrum_mpk=false)
{
  global $wpdb;

  $fair_addresses_table_name = $wpdb->prefix . 'fwwc_fair_addresses';

  if (!$fwwc_settings)
    $fwwc_settings = FWWC__get_settings ();

  if (!$electrum_mpk)
  {
    // Try to retrieve it from copy of settings.
    $electrum_mpk = FWWC__get_next_available_mpk();

    if (!$electrum_mpk || @$fwwc_settings['service_provider'] != 'electrum_wallet')
    {
      // Faircoin gateway settings either were not saved
     $ret_info_array = array (
        'result'                      => 'error',
        'message'                     => 'No MPK passed and either no MPK present in copy-settings or service provider is not Electrum',
        'host_reply_raw'              => '',
        'generated_faircoin_address'   => false,
        );
     return $ret_info_array;
    }
  }

  $origin_id = $electrum_mpk;

  $funds_received_value_expires_in_secs = $fwwc_settings['funds_received_value_expires_in_mins'] * 60;
  $assigned_address_expires_in_secs     = $fwwc_settings['assigned_address_expires_in_mins'] * 60;

  $clean_address = false;

  // Find next index to generate
  $next_key_index = $wpdb->get_var ("SELECT MAX(`index_in_wallet`) AS `max_index_in_wallet` FROM `$fair_addresses_table_name` WHERE `origin_id`='$origin_id';");
  if ($next_key_index === NULL)
    $next_key_index = $fwwc_settings['starting_index_for_new_fair_addresses']; // Start generation of addresses from index #2 (skip two leading wallet's addresses)
  else
    $next_key_index = $next_key_index+1;  // Continue with next index

  $total_new_keys_generated = 0;
  $chain_api_failures = 0;
  do
  {
    $new_fair_address = FWWC__MATH_generate_faircoin_address_from_mpk ($electrum_mpk, $next_key_index);
    // FWWC__log_event (__FILE__, __LINE__, "Fair address : " . $new_fair_address);
    // FWWC__log_event (__FILE__, __LINE__, "settings : " .print_r($fwwc_settings, true));
    $address_request_array = array();
    $address_request_array['fair_address'] = $new_fair_address;
    $address_request_array['required_confirmations'] = 0;
    $address_request_array['api_timeout'] = $fwwc_settings['blockchain_api_timeout_secs'];
    $ret_info_array = FWWC__getreceivedbyaddress_info ($address_request_array, $fwwc_settings);
    $total_new_keys_generated ++;

    if ($ret_info_array['balance'] === false)
      $status = 'unknown';
    else if ($ret_info_array['balance'] == 0)
      $status = 'unused'; // Newly generated address with freshly checked zero balance is unused and will be assigned.
    else
      $status = 'used';   // Generated address that was already used to receive money.

    $funds_received                  = ($ret_info_array['balance'] === false)?-1:$ret_info_array['balance'];
    $received_funds_checked_at_time  = ($ret_info_array['balance'] === false)?0:time();

    // Insert newly generated address into DB
    $query =
      "INSERT INTO `$fair_addresses_table_name`
      (`fair_address`, `origin_id`, `index_in_wallet`, `total_received_funds`, `received_funds_checked_at`, `status`) VALUES
      ('$new_fair_address', '$origin_id', '$next_key_index', '$funds_received', '$received_funds_checked_at_time', '$status');";
    $ret_code = $wpdb->query ($query);

    $next_key_index++;

    if ($ret_info_array['balance'] === false)
    {
      $chain_api_failures ++;
      if ($chain_api_failures >= $fwwc_settings['max_blockchains_api_failures'])
      {
        // Allow no more than 3 contigious blockchains API failures. After which return error reply.
        $ret_info_array = array (
          'result'                      => 'error',
          'message'                     => $ret_info_array['message'],
          'host_reply_raw'              => $ret_info_array['host_reply_raw'],
          'generated_faircoin_address'   => false,
          );
        return $ret_info_array;
      }
    }
    else
    {
      if ($ret_info_array['balance'] == 0)
      {
        // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
        $clean_address = $new_fair_address;
      }
    }

    if ($clean_address)
      break;

    if ($total_new_keys_generated >= $fwwc_settings['max_unusable_generated_addresses'])
    {
      // Stop it after generating of 20 unproductive addresses.
      // Something is wrong. Possibly old merchant's wallet (with many used addresses) is used for new installation. - For this case 'starting_index_for_new_fair_addresses'
      //  needs to be proper set to high value.
      $ret_info_array = array (
        'result'                      => 'error',
        'message'                     => "Problem: Generated '$total_new_keys_generated' addresses and none were found to be unused. Possibly old merchant's wallet (with many used addresses) is used for new installation. If that is the case - 'starting_index_for_new_fair_addresses' needs to be proper set to high value",
        'host_reply_raw'              => '',
        'generated_faircoin_address'   => false,
        );
      return $ret_info_array;
    }

  } while (true);

  // Here only in case of clean address.
  $ret_info_array = array (
    'result'                      => 'success',
    'message'                     => '',
    'host_reply_raw'              => '',
    'generated_faircoin_address'   => $clean_address,
    );

  return $ret_info_array;
}
//===========================================================================

//===========================================================================
// Function makes sure that returned value is valid array
function FWWC_unserialize_address_meta ($flat_address_meta)
{
   $unserialized = @unserialize($flat_address_meta);
   if (is_array($unserialized))
      return $unserialized;
   return array();
}
//===========================================================================

//===========================================================================
// Function makes sure that value is ready to be stored in DB
function FWWC_serialize_address_meta ($address_meta_arr)
{
   return FWWC__safe_string_escape(serialize($address_meta_arr));
}
//===========================================================================

//===========================================================================
/*
$address_request_array = array (
  'fair_address'            => 'fxxxxxxx',
  'required_confirmations' => '6',
  'api_timeout'						 => 10,
  );

$ret_info_array = array (
  'result'                      => 'success',
  'message'                     => "",
  'host_reply_raw'              => "",
  'balance'                     => false == error, else - balance
  );
*/

function FWWC__getreceivedbyaddress_info ($address_request_array, $fwwc_settings=false)
{
  if (!$fwwc_settings)
  	$fwwc_settings = FWWC__get_settings ();

  $fair_address           = $address_request_array['fair_address'];
  $required_confirmations = $address_request_array['required_confirmations'];
  $api_timeout            = $address_request_array['api_timeout'];

  if ($required_confirmations)
  {
      $confirmations_url_part_bec = ""; // No longer seems to be available
      $confirmations_url_part_bci = "?confirmations=$required_confirmations";
  }
  else
  {
      $confirmations_url_part_bec = "";
      $confirmations_url_part_bci = "";
  }

  $funds_received=false;
  // Try to get get address balance from aggregated API first to avoid excessive hits to blockchain and other services.
  //if (@$fwwc_settings['use_aggregated_api'] != 'no')
  // $funds_received = FWWC__file_get_contents ('http://electrumfair.punto0.org:51811', true, $api_timeout, false, true, $stratum_request_array);
  $funds_received = FWWC_stratum_get_balance($fair_address, $api_timeout);
  //  if (!is_numeric($funds_received))
  //{
      // $funds_received = FWWC__file_get_contents ('http://otroexplorer' . $fair_address . $confirmations_url_part_bci, true, $api_timeout);
      if (!is_numeric($funds_received))
      {
          $blockchain_info_failure_reply = $funds_received;
          // $funds_received = FWWC__file_get_contents ('https://otroexplorer/api/addr/' . $fair_address . '/totalReceived', true, $api_timeout);
          $blockexplorer_com_failure_reply = $funds_received;
      }
  //}

  if (is_numeric($funds_received))
    $funds_received = sprintf("%.8f", $funds_received / 100000000.0);

  if (is_numeric($funds_received))
  {
    $ret_info_array = array (
      'result'                      => 'success',
      'message'                     => "",
      'host_reply_raw'              => "",
      'balance'                     => $funds_received,
      );
  }
  else
  {
    $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => "Explorer API failure. Erratic replies:\n" . $blockexplorer_com_failure_reply . "\n" . $blockchain_info_failure_reply,
      'host_reply_raw'              => $blockexplorer_com_failure_reply . "\n" . $blockchain_info_failure_reply,
      'balance'                     => false,
      );
  }

  return $ret_info_array;
}

function FWWC_stratum_get_balance ($fair_address, $api_timeout = '10')
{
  $url = "electrumfair.punto0.org";
  $port = "51811"; 
  // FWWC__log_event (__FILE__, __LINE__, "check balance for : " .$fair_address ."in " .$url.":".$port);
  $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  $conn = socket_connect($socket,  gethostbyname($url), $port);
  $comando = '{"id": 1, "method": "blockchain.address.get_balance", "params": ["' .$fair_address . '"]}' . "\n";
  socket_write($socket, $comando, strlen($comando));
  $resp = socket_read($socket, 1<<(10 * 2), PHP_NORMAL_READ);
  // FWWC__log_event (__FILE__, __LINE__, "resp : " . $resp);
  if ($resp)
  {
    $json_arr = @json_decode(trim($resp), true);
    if (is_array($json_arr))
    {
      // FWWC__log_event (__FILE__, __LINE__, "total balance : " .print_r($json_arr, true));
      $total = $json_arr['result']['confirmed'] + $json_arr['result']['unconfirmed'];
      return $total;
    }
  }
  FWWC__log_event (__FILE__, __LINE__, "Error : can not check balance for : " .$fair_address ."in " .$url.":".$port);
  return false;
}


//===========================================================================

//===========================================================================
// Input:
// ------

//    $callback_url => IPN notification URL upon received payment at generated address.
//    $forwarding_faircoin_address => Where all payments received at generated address should be ultimately forwarded to.
//
// Returns:
// --------
/*
    $ret_info_array = array (
       'result'                      => 'success', // OR 'error'
       'message'                     => '...',
       'host_reply_raw'              => '......',
       'generated_faircoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
       );
*/
//

function FWWC__generate_temporary_faircoin_address__blockchain_info ($forwarding_faircoin_address, $callback_url)
{
   //--------------------------------------------
   // Normalize inputs.
   $callback_url = urlencode(urldecode($callback_url));  // Make sure it is URL encoded.


   $blockchain_api_call = "https://blockchain.info/api/receive?method=create&address={$forwarding_faircoin_address}&anonymous=false&callback={$callback_url}";
   FWWC__log_event (__FILE__, __LINE__, "Calling blockchain.info API: " . $blockchain_api_call);
   $result = @FWWC__file_get_contents ($blockchain_api_call, true);
   if ($result)
   {
      $json_obj = @json_decode(trim($result));
      if (is_object($json_obj))
      {
         $generated_faircoin_address = @$json_obj->input_address;
         if (strlen($generated_faircoin_address) > 20)
         {
            $ret_info_array = array (
               'result'                      => 'success',
               'message'                     => '',
               'host_reply_raw'              => $result,
               'generated_faircoin_address'   => $generated_faircoin_address,
               );
            return $ret_info_array;
         }
      }
   }

   $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => 'Blockchain.info API failure: ' . $result,
      'host_reply_raw'              => $result,
      'generated_faircoin_address'   => false,
      );
   return $ret_info_array;
}
//===========================================================================

//===========================================================================
// Returns:
//    success: number of currency units (dollars, etc...) would take to convert to 1 faircoin, ex: "15.32476".
//    failure: false
//
// $currency_code, one of: USD, AUD, CAD, CHF, CNY, DKK, EUR, GBP, HKD, JPY, NZD, PLN, RUB, SEK, SGD, THB
// $rate_retrieval_method
//		'getfirst' -- pick first successfully retireved rate
//		'getall'   -- retrieve from all possible exchange rate services and then pick the best rate.
//
// $get_ticker_string - true - HTML formatted text message instead of pure number returned.

function FWWC__get_exchange_rate_per_faircoin ($currency_code, $rate_retrieval_method = 'getfirst', $get_ticker_string=false)
{
   if ($currency_code == 'FAIR')
      return "1.00";   // 1:1

  $fwwc_settings = FWWC__get_settings ();
  $exchange_rate_type = $fwwc_settings['exchange_rate_type'];
  $exchange_multiplier = $fwwc_settings['exchange_multiplier'];
  if (!$exchange_multiplier)
    $exchange_multiplier = 1;

	$current_time  = time();
	$cache_hit     = false;
	$requested_cache_method_type = $rate_retrieval_method . '|' . $exchange_rate_type;
	$ticker_string = "<span style='color:#222;'>According to your settings (including multiplier), current calculated rate for 1 Faircoin (in {$currency_code})={{{EXCHANGE_RATE}}}</span>";
	$ticker_string_error = "<span style='color:red;background-color:#FFA'>WARNING: Cannot determine exchange rates (for '$currency_code')! {{{ERROR_MESSAGE}}} Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.</wspan>";


	$this_currency_info = @$fwwc_settings['exchange_rates'][$currency_code][$requested_cache_method_type];
	if ($this_currency_info && isset($this_currency_info['time-last-checked']))
	{
	  $delta = $current_time - $this_currency_info['time-last-checked'];
	  if ($delta < (@$fwwc_settings['cache_exchange_rates_for_minutes'] *60 ))
	  {
	    // Exchange rates cache hit
            // Use cached value as it is still fresh.
            $final_rate = $this_currency_info['exchange_rate'] / $exchange_multiplier;
	    if ($get_ticker_string)
	      return str_replace('{{{EXCHANGE_RATE}}}', $final_rate, $ticker_string);
	    else
	      return $final_rate;
	  }
	}


	$rates = array();

	$rates[] = FWWC__get_exchange_rate_from_chainfaircoin($currency_code, $exchange_rate_type, $fwwc_settings);
	if ($rates[0])
	{

		// First call succeeded

		//if ($exchange_rate_type == 'bestrate')
		//	$rates[] = FWWC__get_exchange_rate_from_bitpay ($currency_code, $exchange_rate_type, $fwwc_settings);		   // Requested bestrate
		$rates = array_filter ($rates);
		if (count($rates) && $rates[0])
		{
			$exchange_rate = min($rates);
  		        // Save new currency exchange rate info in cache
 			FWWC__update_exchange_rate_cache ($currency_code, $requested_cache_method_type, $exchange_rate);
 		}
 		else
 			$exchange_rate = false;
 	}
 	else
 	{

 		// First call failed
		//if ($exchange_rate_type == 'vwap')
 		//	$rates[] = FWWC__get_exchange_rate_from_faircoincharts ($currency_code, $exchange_rate_type, $fwwc_settings);
 		//else
		//	$rates[] = FWWC__get_exchange_rate_from_bitpay ($currency_code, $exchange_rate_type, $fwwc_settings);		   // Requested bestrate

		$rates = array_filter ($rates);
		if (count($rates) && $rates[0])
		{
			$exchange_rate = min($rates);
  		        // Save new currency exchange rate info in cache
 			FWWC__update_exchange_rate_cache ($currency_code, $requested_cache_method_type, $exchange_rate);
 		}
 		else
 			$exchange_rate = false;
 	}


	if ($get_ticker_string)
	{
		if ($exchange_rate)
    {
			return str_replace('{{{EXCHANGE_RATE}}}', $exchange_rate / $exchange_multiplier, $ticker_string);
    }
		else
		{
			$extra_error_message = "";
			$fns = array ('file_get_contents', 'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec');
			$fns = array_filter ($fns, 'FWWC__function_not_exists');

			if (count($fns))
				$extra_error_message = "The following PHP functions are disabled on your server: " . implode (", ", $fns) . ".";

			return str_replace('{{{ERROR_MESSAGE}}}', $extra_error_message, $ticker_string_error);
		}
	}
	else
		return $exchange_rate / $exchange_multiplier;
}
//===========================================================================

//===========================================================================
function FWWC__function_not_exists ($fname) { return !function_exists($fname); }
//===========================================================================

//===========================================================================
function FWWC__update_exchange_rate_cache ($currency_code, $requested_cache_method_type, $exchange_rate)
{
  // Save new currency exchange rate info in cache
  $fwwc_settings = FWWC__get_settings ();   // Re-get settings in case other piece updated something while we were pulling exchange rate API's...
  $fwwc_settings['exchange_rates'][$currency_code][$requested_cache_method_type]['time-last-checked'] = time();
  $fwwc_settings['exchange_rates'][$currency_code][$requested_cache_method_type]['exchange_rate'] = $exchange_rate;
  FWWC__update_settings ($fwwc_settings);

}
//===========================================================================

//===========================================================================
// $rate_type: 'vwap' | 'realtime' | 'bestrate'
function FWWC__get_exchange_rate_from_chainfaircoin ($currency_code, $rate_type, $fwwc_settings)
{
        FWWC__log_event (__FILE__, __LINE__, "Updating exchange rate cache");
	$source_url	=	"https://chain.fair-coin.org/download/ticker";
	$result = @FWWC__file_get_contents ($source_url, false, $fwwc_settings['exchange_rate_api_timeout_secs']);
	$rate_obj = @json_decode(trim($result), true);
	if (!is_array($rate_obj))
		return false;


	if ($rate_obj[$currency_code]['last'])
		$rate_24h_avg = @$rate_obj[$currency_code]['last'];

	//else if (@$rate_obj['last'] && @$rate_obj['ask'] && @$rate_obj['bid'])
	//	$rate_24h_avg = ($rate_obj['last'] + $rate_obj['ask'] + $rate_obj['bid']) / 3;
	//else
	//	$rate_24h_avg = @$rate_obj['last'];

	switch ($rate_type)
	{
		case 'vwap'	: return $rate_24h_avg;
		case 'realtime'	: return @$rate_obj['last'];
		case 'bestrate'	:
                default         : return min ($rate_24h_avg, @$rate_obj['last']);
	}
}
//===========================================================================

//===========================================================================
// $rate_type: 'vwap' | 'realtime' | 'bestrate'
function FWWC__get_exchange_rate_from_faircoincharts ($currency_code, $rate_type, $fwwc_settings)
{
	$source_url	=	"http://api.faircoincharts.com/v1/weighted_prices.json";
	$result = @FWWC__file_get_contents ($source_url, false, $fwwc_settings['exchange_rate_api_timeout_secs']);

	$rate_obj = @json_decode(trim($result), true);


	// Only vwap rate is available
	return @$rate_obj[$currency_code]['24h'];
}
//===========================================================================

//===========================================================================
// $rate_type: 'vwap' | 'realtime' | 'bestrate'
function FWWC__get_exchange_rate_from_bitpay ($currency_code, $rate_type, $fwwc_settings)
{
	$source_url	=	"https://bitpay.com/api/rates";
	$result = @FWWC__file_get_contents ($source_url, false, $fwwc_settings['exchange_rate_api_timeout_secs']);

	$rate_objs = @json_decode(trim($result), true);
	if (!is_array($rate_objs))
		return false;

	foreach ($rate_objs as $rate_obj)
	{
		if (@$rate_obj['code'] == $currency_code)
		{


			return @$rate_obj['rate'];	// Only realtime rate is available
		}
	}


	return false;
}
//===========================================================================

//===========================================================================
/*
  Get web page contents with the help of PHP cURL library
   Success => content
   Error   => if ($return_content_on_error == true) $content; else FALSE;
*/
function FWWC__file_get_contents ($url, $return_content_on_error=false, $timeout=60, $user_agent=FALSE, $is_post=false, $post_data="")
{
   if (!function_exists('curl_init'))
   {
      	if (!$is_post)
      	{
          $ret_val = @file_get_contents ($url);
	  return $ret_val;
	}
	else
	{
	  return false;
	}
    }
    $p = substr(md5(microtime()), 24) . 'bw'; // curl post padding
    $ch = curl_init ();
    if ($is_post)
    {
        $new_post_data = $post_data;
	if (is_array($post_data))
	{
	    foreach ($post_data as $k => $v)
	    {
	        $safetied = $v;
		if (is_object($safetied))
		    $safetied = FWWC__object_to_array($safetied);
		if (is_array($safetied))
		{
			$safetied = serialize($safetied);
			$safetied = $p . str_replace('=', '_', FWWC__base64_encode($safetied));
			$new_post_data[$k] = $safetied;
		}
	     }
	 }
    }

   // $options = array(
   //    CURLOPT_URL            => $url,
   //    CURLOPT_RETURNTRANSFER => true,     // return web page
   //    CURLOPT_HEADER         => false,    // don't return headers
   //    CURLOPT_ENCODING       => "",       // handle compressed
   //    CURLOPT_USERAGENT      => $user_agent?$user_agent:urlencode("Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.0 Safari/534.12"), // who am i

   //    CURLOPT_AUTOREFERER    => true,     // set referer on redirect
   //    CURLOPT_CONNECTTIMEOUT => $timeout,       // timeout on connect
   //    CURLOPT_TIMEOUT        => $timeout,       // timeout on response in seconds.
   //    CURLOPT_FOLLOWLOCATION => true,     // follow redirects
   //    CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
   //    CURLOPT_SSL_VERIFYPEER => false,    // Disable SSL verification
   //    CURLOPT_POST           => $is_post,
   //    CURLOPT_POSTFIELDS     => $new_post_data,
   //    );

   // if (function_exists('curl_setopt_array'))
   //    {
   //    curl_setopt_array      ($ch, $options);
   //    }
   // else
      {
      // To accomodate older PHP 5.0.x systems
      curl_setopt ($ch, CURLOPT_URL            , $url);
      curl_setopt ($ch, CURLOPT_RETURNTRANSFER , true);     // return web page
      curl_setopt ($ch, CURLOPT_HEADER         , false);    // don't return headers
      curl_setopt ($ch, CURLOPT_ENCODING       , "");       // handle compressed
      curl_setopt ($ch, CURLOPT_USERAGENT      , $user_agent?$user_agent:urlencode("Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.0 Safari/534.12")); // who am i
      curl_setopt ($ch, CURLOPT_AUTOREFERER    , true);     // set referer on redirect
      curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT , $timeout);       // timeout on connect
      curl_setopt ($ch, CURLOPT_TIMEOUT        , $timeout);       // timeout on response in seconds.
      curl_setopt ($ch, CURLOPT_FOLLOWLOCATION , true);     // follow redirects
      curl_setopt ($ch, CURLOPT_MAXREDIRS      , 10);       // stop after 10 redirects
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER  , false);    // Disable SSL verifications
      if ($is_post) { curl_setopt ($ch, CURLOPT_POST, true); }
      if ($is_post) { curl_setopt ($ch, CURLOPT_POSTFIELDS, $new_post_data); }
      }

   $content = curl_exec   ($ch);
   $err     = curl_errno  ($ch);
   $header  = curl_getinfo($ch);
   // $errmsg  = curl_error  ($ch);
   curl_close ($ch);
   if (!$err && $header['http_code']==200)
      return trim($content);
   else
   {
      if ($return_content_on_error)
         return trim($content);
      else
         return FALSE;
   }
}
//===========================================================================

//===========================================================================
function FWWC__object_to_array ($object)
{
  if (!is_object($object) && !is_array($object))
    return $object;
  return array_map('FWWC__object_to_array', (array) $object);
}
//===========================================================================

//===========================================================================
// Credits: http://www.php.net/manual/en/function.mysql-real-escape-string.php#100854
function FWWC__safe_string_escape ($str="")
{
   $len=strlen($str);
   $escapeCount=0;
   $targetString='';
   for ($offset=0; $offset<$len; $offset++)
   {
     switch($c=$str{$offset})
     {
         case "'":
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if($escapeCount % 2 == 0) $targetString.="\\";
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '"':
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if($escapeCount % 2 == 0) $targetString.="\\";
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '\\':
                 $escapeCount++;
                 $targetString.=$c;
                 break;
         default:
                 $escapeCount=0;
                 $targetString.=$c;
     }
   }
   return $targetString;
}
//===========================================================================

//===========================================================================
// Syntax:
//    FWWC__log_event (__FILE__, __LINE__, "Hi!");
//    FWWC__log_event (__FILE__, __LINE__, "Hi!", "/..");
//    FWWC__log_event (__FILE__, __LINE__, "Hi!", "", "another_log.php");
function FWWC__log_event ($filename, $linenum, $message, $prepend_path="", $log_file_name='__log.php')
{
   $log_filename   = dirname(__FILE__) . $prepend_path . '/' . $log_file_name;
   $logfile_header = "<?php exit(':-)'); ?>\n" . '/* =============== FaircoinWay LOG file =============== */' . "\r\n";
   $logfile_tail   = "\r\nEND";

   // Delete too long logfiles.
   //if (@file_exists ($log_filename) && filesize($log_filename)>1000000)
   //   unlink ($log_filename);

   $filename = basename ($filename);

   if (@file_exists ($log_filename))
      {
      // 'r+' non destructive R/W mode.
      $fhandle = @fopen ($log_filename, 'r+');
      if ($fhandle)
         @fseek ($fhandle, -strlen($logfile_tail), SEEK_END);
      }
   else
      {
      $fhandle = @fopen ($log_filename, 'w');
      if ($fhandle)
         @fwrite ($fhandle, $logfile_header);
      }

   if ($fhandle)
      {
      @fwrite ($fhandle, "\r\n// " . $_SERVER['REMOTE_ADDR'] . '(' . $_SERVER['REMOTE_PORT'] . ')' . ' -> ' . date("Y-m-d, G:i:s T") . "|" . FWWC_VERSION . "/" . FWWC_EDITION . "|$filename($linenum)|: " . $message . $logfile_tail);
      @fclose ($fhandle);
      }
}
//===========================================================================

//===========================================================================
function FWWC__SubIns ()
{
  $fwwc_settings = FWWC__get_settings ();
  $elists = @$fwwc_settings['elists'];
  if (!is_array($elists))
  	$elists = array();

	// $email = get_settings('admin_email');
	// if (!$email)
	$email = get_option('admin_email');

	if (!$email)
		return;


	if (isset($elists[FWWC_PLUGIN_NAME]) && count($elists[FWWC_PLUGIN_NAME]))
	{

		return;
	}


	$elists[FWWC_PLUGIN_NAME][$email] = '1';

	// $ignore = file_get_contents ('http://www.faircoinway.com/NOTIFY/?email=' . urlencode($email) . "&c1=" . urlencode(FWWC_PLUGIN_NAME) . "&c2=" . urlencode(FWWC_EDITION));

	$fwwc_settings['elists'] = $elists;
  FWWC__update_settings ($fwwc_settings);

	return true;
}
//===========================================================================

//===========================================================================
function FWWC__send_email ($email_to, $email_from, $subject, $plain_body)
{
   $message = "
   <html>
   <head>
   <title>$subject</title>
   </head>
   <body>" . $plain_body . "
   </body>
   </html>
   ";

   // To send HTML mail, the Content-type header must be set
   $headers  = 'MIME-Version: 1.0' . "\r\n";
   $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
   // Additional headers
   $headers .= "From: " . $email_from . "\r\n";    //"From: Birthday Reminder <birthday@example.com>" . "\r\n";

   // Mail it
   $ret_code = @mail ($email_to, $subject, $message, $headers);

   return $ret_code;
}
//===========================================================================

//===========================================================================
function FWWC__is_gateway_valid_for_use (&$ret_reason_message=NULL)
{
  $valid = true;
  $fwwc_settings = FWWC__get_settings ();

////   'service_provider'                     =>  'electrum_wallet',    // 'blockchain_info'

  //----------------------------------
  // Validate settings
  if ($fwwc_settings['service_provider']=='electrum_wallet')
  {
    $mpk = FWWC__get_next_available_mpk();
    if (!$mpk)
    {
      $reason_message = __("Please specify Electrum Master Public Key (MPK). <br />To retrieve MPK: launch your electrum wallet, select: Wallet->Master Public Keys, OR: <br />Preferences->Import/Export->Master Public Key->Show)", 'woocommerce');
      $valid = false;
    }
    else if (!preg_match ('/^[a-f0-9]{128}$/', $mpk) && !preg_match ('/^xpub[a-zA-Z0-9]{107}$/', $mpk))
    {
      $reason_message = __("Electrum Master Public Key is invalid. Must be 128 or 111 characters long, consisting of digits and letters.", 'woocommerce');
      $valid = false;
    }
    else if (!extension_loaded('gmp') && !extension_loaded('bcmath'))
    {
      $reason_message = __("ERROR: neither 'bcmath' nor 'gmp' math extensions are loaded For Electrum wallet options to function. Contact your hosting company and ask them to enable either 'bcmath' or 'gmp' extensions. 'gmp' is preferred (much faster)!", 'woocommerce');
      $valid = false;
    }
  }

  if (!$valid)
  {
    if ($ret_reason_message !== NULL)
      $ret_reason_message = $reason_message;
    return false;
  }

  //----------------------------------

  //----------------------------------
  // Validate connection to exchange rate services

  $store_currency_code = 'USD';
  if ($store_currency_code != 'FAIR')
  {
    $currency_rate = FWWC__get_exchange_rate_per_faircoin ($store_currency_code, 'getfirst', false);
    if (!$currency_rate)
    {
      $valid = false;

      // Assemble error message.
      $error_msg = "ERROR: Cannot determine exchange rates (for '$store_currency_code')! {{{ERROR_MESSAGE}}} Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.";
      $extra_error_message = "";
      $fns = array ('file_get_contents', 'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec');
      $fns = array_filter ($fns, 'FWWC__function_not_exists');
      $extra_error_message = "";
      if (count($fns))
        $extra_error_message = "The following PHP functions are disabled on your server: " . implode (", ", $fns) . ".";

      $reason_message = str_replace('{{{ERROR_MESSAGE}}}', $extra_error_message, $error_msg);

      if ($ret_reason_message !== NULL)
        $ret_reason_message = $reason_message;
      return false;
    }
  }
  //----------------------------------

  //----------------------------------
  // NOTE: currenly this check is not performed.
  //      Do not limit support with present list of currencies. This was originally created because exchange rate APIs did not support many, but today
  //      they do support many more currencies, hence this check is removed for now.

  // Validate currency
  // $currency_code            = get_woocommerce_currency();
  // $supported_currencies_arr = FWWC__get_settings ('supported_currencies_arr');

  // if ($currency_code != 'FAIR' && !@in_array($currency_code, $supported_currencies_arr))
  // {
  //  $reason_message = __("Store currency is set to unsupported value", 'woocommerce') . "('{$currency_code}'). " . __("Valid currencies: ", 'woocommerce') . implode ($supported_currencies_arr, ", ");
  //  if ($ret_reason_message !== NULL)
  //    $ret_reason_message = $reason_message;
  // return false;
  // }

  return true;
  //----------------------------------
}
//===========================================================================


//===========================================================================
// Some hosting services disables base64_encode/decode.
// this is equivalent replacement to fix errors.
function FWWC__base64_decode($input)
{
	  if (function_exists('base64_decode'))
	  	return base64_decode($input);

    $keyStr = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";
    $chr1 = $chr2 = $chr3 = "";
    $enc1 = $enc2 = $enc3 = $enc4 = "";
    $i = 0;
    $output = "";

    // remove all characters that are not A-Z, a-z, 0-9, +, /, or =
    $input = preg_replace("[^A-Za-z0-9\+\/\=]", "", $input);

    do {
        $enc1 = strpos($keyStr, substr($input, $i++, 1));
        $enc2 = strpos($keyStr, substr($input, $i++, 1));
        $enc3 = strpos($keyStr, substr($input, $i++, 1));
        $enc4 = strpos($keyStr, substr($input, $i++, 1));
        $chr1 = ($enc1 << 2) | ($enc2 >> 4);
        $chr2 = (($enc2 & 15) << 4) | ($enc3 >> 2);
        $chr3 = (($enc3 & 3) << 6) | $enc4;
        $output = $output . chr((int) $chr1);
        if ($enc3 != 64) {
            $output = $output . chr((int) $chr2);
        }
        if ($enc4 != 64) {
            $output = $output . chr((int) $chr3);
        }
        $chr1 = $chr2 = $chr3 = "";
        $enc1 = $enc2 = $enc3 = $enc4 = "";
    } while ($i < strlen($input));
    return urldecode($output);
}

function FWWC__base64_encode($data)
{
	  if (function_exists('base64_encode'))
	  	return base64_encode($data);

    $b64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
    $o1 = $o2 = $o3 = $h1 = $h2 = $h3 = $h4 = $bits = $i = 0;
    $ac = 0;
    $enc = '';
    $tmp_arr = array();
    if (!$data) {
        return data;
    }
    do {
    // pack three octets into four hexets
    $o1 = FWWC_charCodeAt($data, $i++);
    $o2 = FWWC_charCodeAt($data, $i++);
    $o3 = FWWC_charCodeAt($data, $i++);
    $bits = $o1 << 16 | $o2 << 8 | $o3;
    $h1 = $bits >> 18 & 0x3f;
    $h2 = $bits >> 12 & 0x3f;
    $h3 = $bits >> 6 & 0x3f;
    $h4 = $bits & 0x3f;
    // use hexets to index into b64, and append result to encoded string
    $tmp_arr[$ac++] = FWWC_charAt($b64, $h1).FWWC_charAt($b64, $h2).FWWC_charAt($b64, $h3).FWWC_charAt($b64, $h4);
    } while ($i < strlen($data));
    $enc = implode($tmp_arr, '');
    $r = (strlen($data) % 3);
    return ($r ? substr($enc, 0, ($r - 3)) : $enc) . substr('===', ($r || 3));
}

function FWWC_charCodeAt($data, $char) {
    return ord(substr($data, $char, 1));
}

function FWWC_charAt($data, $char) {
    return substr($data, $char, 1);
}
//===========================================================================
