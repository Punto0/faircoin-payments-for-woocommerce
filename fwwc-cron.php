<?php
/*
Faircoin Payments for WooCommerce
http://punto0.net
*/

// Include everything
define('FWWC_MUST_LOAD_WP',  '1');
require_once(dirname(__FILE__) . '/fwwc-include-all.php');
require_once(dirname(__FILE__) . '/fwwc-faircoin-gateway.php');


// Cpanel-scheduled cron job call
if (@$_REQUEST['hardcron']=='1')
  FWWC_cron_job_worker (true);

//===========================================================================
// '$hardcron' == true if job is ran by Cpanel's cron job.

function FWWC_cron_job_worker ($hardcron=false)
{
  global $wpdb;
  global $woocommerce;
  $fwwc_settings = FWWC__get_settings ();

  if (@$fwwc_settings['gateway_settings']['service_provider'] != 'electrum-wallet')
  {
    return; // Only active electrum wallet as a service provider needs cron job
  }

  // status = "unused", "assigned", "used"
  $fai_addresses_table_name     = $wpdb->prefix . 'fwwc_fai_addresses';

  $funds_received_value_expires_in_secs = $fwwc_settings['funds_received_value_expires_in_mins'] * 60;
  $assigned_address_expires_in_secs     = $fwwc_settings['assigned_address_expires_in_mins'] * 60;
  $confirmations_required = $fwwc_settings['gateway_settings']['confirmations'];

  $clean_address = NULL;
  $current_time = time();

  // Search for completed orders (addresses that received full payments for their orders) ...

  // NULL == not found
  // Retrieve:
  //     'assigned'   - unexpired, with old balances (due for revalidation. Fresh balances and still 'assigned' means no [full] payment received yet)
  //     'revalidate' - all
  //        order results by most recently assigned
  $query =
    "SELECT * FROM `$fai_addresses_table_name`
      WHERE
      (
        (`status`='assigned' AND (('$current_time' - `assigned_at`) < '$assigned_address_expires_in_secs'))
        OR
        (`status`='revalidate')
      )
      AND (('$current_time' - `received_funds_checked_at`) > '$funds_received_value_expires_in_secs')
      ORDER BY `received_funds_checked_at` ASC;"; // Check the ones that haven't been checked for longest time
  $rows_for_balance_check = $wpdb->get_results ($query, ARRAY_A);

  if (is_array($rows_for_balance_check))
  	$count_rows_for_balance_check = count($rows_for_balance_check);
  else
  	$count_rows_for_balance_check = 0;

  FWWC__log_event (__FILE__, __LINE__,"Cron checking address : ".$count_rows_for_balance_check); 
  if (is_array($rows_for_balance_check))
  {
  	$ran_cycles = 0;
  	foreach ($rows_for_balance_check as $row_for_balance_check)
  	{
  		  $ran_cycles++;	// To limit number of cycles per soft cron job.

		  // Prepare 'address_meta' for use.
		  $address_meta    = FWWC_unserialize_address_meta (@$row_for_balance_check['address_meta']);
		  $last_order_info = @$address_meta['orders'][0];
		  $row_id       = $row_for_balance_check['id'];

		  // Retrieve current balance at address.
		  $balance_info_array = FWWC__getreceivedbyaddress_info ($row_for_balance_check['fai_address'], $confirmations_required, $fwwc_settings['blockchain_api_timeout_secs']);
		  if ($balance_info_array['result'] == 'success')
		  {
		    /*
		    $balance_info_array = array (
					'result'                      => 'success',
					'message'                     => "",
					'host_reply_raw'              => "",
					'balance'                     => $funds_received,
					);
		    */

        // Refresh 'received_funds_checked_at' field
        $current_time = time();
        $query =
          "UPDATE `$fai_addresses_table_name`
             SET
                `total_received_funds` = '{$balance_info_array['balance']}',
                `received_funds_checked_at`='$current_time'
            WHERE `id`='$row_id';";
        $ret_code = $wpdb->query ($query);

        if ($balance_info_array['balance'] > 0)
        {

          if ($row_for_balance_check['status'] == 'revalidate')
          {
            // Address with suddenly appeared balance. Check if that is matching to previously-placed [likely expired] order
            if (!$last_order_info || !@$last_order_info['order_id'] || !@$balance_info_array['balance'] || !@$last_order_info['order_total'])
            {
              // No proper metadata present. Mark this address as 'xused' (used by unknown entity outside of this application) and be done with it forever.
              $query =
                "UPDATE `$fai_addresses_table_name`
                   SET
                      `status` = 'xused'
                  WHERE `id`='$row_id';";
              $ret_code = $wpdb->query ($query);
              continue;
            }
            else
            {
              // Metadata for this address is present. Mark this address as 'assigned' and treat it like that further down...
              $query =
                "UPDATE `$fai_addresses_table_name`
                   SET
                      `status` = 'assigned'
                  WHERE `id`='$row_id';";
              $ret_code = $wpdb->query ($query);
            }
          }

//          FWWC__log_event (__FILE__, __LINE__, "Cron job: NOTE: Detected non-zero balance at address: '{$row_for_balance_check['fai_address']}, order ID = '{$last_order_info['order_id']}'. Detected balance ='{$balance_info_array['balance']}'.");

          if ($balance_info_array['balance'] < $last_order_info['order_total'])
          {
            FWWC__log_event (__FILE__, __LINE__, "Cron job: NOTE: balance at address: '{$row_for_balance_check['fai_address']}' (FAI '{$balance_info_array['balance']}') is not yet sufficient to complete it's order (order ID = '{$last_order_info['order_id']}'). Total required: '{$last_order_info['order_total']}'. Will wait for more funds to arrive...");
          }
        }
        else
        {

        }

        // Note: to be perfectly safe against late-paid orders, we need to:
        //	Scan '$address_meta['orders']' for first UNPAID order that is exactly matching amount at address.

		    if ($balance_info_array['balance'] >= $last_order_info['order_total'])
		    {
		      // Process full payment event
		      /*
		      $address_meta =
		         array (
		            'orders' =>
		               array (
		                  // All orders placed on this address in reverse chronological order
		                  array (
		                     'order_id'     => $order_id,
		                     'order_total'  => $order_total_in_fai,
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

	        // Last order was fully paid! Complete it...
//	        FWWC__log_event (__FILE__, __LINE__, "Cron job: NOTE: Full payment for order ID '{$last_order_info['order_id']}' detected at address: '{$row_for_balance_check['fai_address']}' (FAI '{$balance_info_array['balance']}'). Total was required for this order: '{$last_order_info['order_total']}'. Processing order ...");

	        // Update order' meta info
	        $address_meta['orders'][0]['paid'] = false;
		// Process and complete the order within WooCommerce (send confirmation emails, etc...)
	        if (!FWWC__process_payment_completed_for_order ($last_order_info['order_id'], $balance_info_array['balance']))
			FWWC__log_event(__FILE__,__LINE__,"Falló el procesamiento de la orden, ¿borrada desde wp?");

	        // Update address' record
	        $address_meta_serialized = FWWC_serialize_address_meta ($address_meta);

          // Note: `total_received_funds` and `received_funds_checked_at` are already updated above.
          //
	        $query =
	          "UPDATE `$fai_addresses_table_name`
	             SET
	                `status`='used',
	                `address_meta`='$address_meta_serialized'
	            WHERE `id`='$row_id';";
	        $ret_code = $wpdb->query ($query);
	        FWWC__log_event (__FILE__, __LINE__, "Cron job: SUCCESS: Order ID '{$last_order_info['order_id']}' successfully completed.");
		    }
		  }
		  else
		  {
		    FWWC__log_event (__FILE__, __LINE__, "Cron job: Warning: Cannot retrieve balance for address: '{$row_for_balance_check['fai_address']}: " . $balance_info_array['message']);
		  }
		}
	}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
  // Search for expired  address



     $assigned_address_expires_in_secs = 130; // Debugging
    $funds_received_value_expires_in_secs = 130;
     $current_time = time();
//   FWWC__log_event (__FILE__, __LINE__,"Current time : ".$current_time);

    $query =
      "SELECT * FROM `$fai_addresses_table_name`
        WHERE ( `status`='used'
        AND ('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs'
        AND ('$current_time' - `received_funds_checked_at`) > '$funds_received_value_expires_in_secs');"; // Check the ones that haven't been checked for longest time
        $rows_for_check = $wpdb->get_results ($query, ARRAY_A);

//  FWWC__log_event (__FILE__, __LINE__,"Query  : ".$query);

    if (is_array($rows_for_check))
  	$count_rows_for_check = count($rows_for_check);
    else {
  	$count_rows_for_check = 0;
//        FWWC__log_event (__FILE__, __LINE__,"ERROR : ".$rows_for_balance_check); 
	}

   FWWC__log_event (__FILE__, __LINE__,"Cron checking for expired orders  : ".$count_rows_for_check); 
    if (is_array($rows_for_check))
    {
  	foreach ($rows_for_check as $row_for_check)
  	{
		if ($row_for_check)
		{
		 	// Prepare 'address_meta' for use.
		  	$address_meta    = FWWC_unserialize_address_meta (@$row_for_check['address_meta']);
		  	$last_order_info = @$address_meta['orders'][0];
		  	$row_id       = $row_for_check['id'];
                   // Process and complete the order within WooCommerce (send confirmation emails, etc...)
                if (!FWWC__process_payment_completed_for_order ($last_order_info['order_id'], false))
                        FWWC__log_event(__FILE__,__LINE__,"Falló el procesamiento de la orden, ¿borrada desde wp?");

	        // Update address' record
	        	$address_meta_serialized = FWWC_serialize_address_meta ($address_meta);

	        // Update DB - mark address as 'unused'.
	        //
          // Mark the address to use it again
	        	$query = "UPDATE `$fai_addresses_table_name`
	             		SET `status`='unused'
                		WHERE `id`='$row_id';";
	        	$ret_code = $wpdb->query ($query);
			FWWC__log_event (__FILE__, __LINE__, "Order expired : ".$last_order_info['order_id']);
		}
	}
  }
  //-----------------------------------------------------
  // Pre-generate new faircoin address for electrum wallet
  // Try to retrieve mpk from copy of settings.
  if ($hardcron)
  {
    $electrum_mpk = @$fwwc_settings['gateway_settings']['electrum_master_public_key'];

    if ($electrum_mpk && @$fwwc_settings['gateway_settings']['service_provider'] == 'electrum-wallet')
    {
      // Calculate number of unused addresses belonging to currently active electrum wallet

      $origin_id = 'electrum.mpk.' . md5($electrum_mpk);

      $current_time = time();
      $assigned_address_expires_in_secs     = $fwwc_settings['assigned_address_expires_in_mins'] * 60;

      if ($fwwc_settings['reuse_expired_addresses'])
        $reuse_expired_addresses_query_part = "OR (`status`='assigned' AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs'))";
      else
        $reuse_expired_addresses_query_part = "";

      // Calculate total number of currently unused addresses in a system. Make sure there aren't too many.

      // NULL == not found
      // Retrieve:
      //     'unused'   - with fresh zero balances
      //     'assigned' - expired, with fresh zero balances (if 'reuse_expired_addresses' is true)
      //
      // Hence - any returned address will be clean to use.
      $query =
        "SELECT COUNT(*) as `total_unused_addresses` FROM `$fai_addresses_table_name`
           WHERE `origin_id`='$origin_id'
           AND `total_received_funds`='0'
           AND (`status`='unused' $reuse_expired_addresses_query_part)
           ";
      $total_unused_addresses = $wpdb->get_var ($query);

      if ($total_unused_addresses < $fwwc_settings['max_unused_addresses_buffer'])
      {
//        FWWC__log_event (__FILE__, __LINE__,"Generating new address ");
        FWWC__generate_new_faircoin_address_for_electrum_wallet ($fwwc_settings, $electrum_mpk);
      }
    }
  }
  //-----------------------------------------------------

}
//===========================================================================
