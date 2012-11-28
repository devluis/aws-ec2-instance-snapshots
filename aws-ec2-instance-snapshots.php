<?php
/**
 * aws-ec2-instance-snapshots 
 * AWS ec2 script that makes snapshot for each attached volume and rotate it each day, week and month deleting older 
 *
 *
 * This script makes;
 * 1) a snapshot of each volume attached to the selected amazon aws ec2 instance.
 * 2) check for snapshot older than KEEPFOR option ( -t )  seconds and delete it 
 * 3) keep 1 snapshot each week and after a week 1 snapshot each month
 * 4) All snapshot with description start equal to "AutoSnap:" will be rotated by this script
 * 
 * Modified from code by:
 * Michele Marcucci
 * https://github.com/michelem09/AWS-EC2-Manage-Snapshots-Backup
 *
 * @param v
 *  The Instance ID of ec2 instance which you wish to manage.
 * @param r
 *  (Optional) Defaults to US-EAST-1.
 *  The region where the snapshots are held.
 *  Options include: us-e1, us-w1, us-w2, us-gov1, eu-w1, apac-se1, apac-ne1 AND sa-e1.
 * @param t
 *  (Optional) Default to 604 800 seconds ( 7 days )
 *  The time in second to keep snapshot, snapshot older than will be deleted
 * @param o
 *  (Optional) Defaults to TRUE.
 *  No operation mode, it won't *create* any snapshots. use -n -o to lock any create and delete action
 * @param q
 *  (Optional) Defaults to FALSE.
 *  Quiet mode, no ouput.
 * @param n
 *  (Optional) Defaults to FALSE.
 *  No operation mode, it won't *delete* any snapshots. use -n -o to lock any create and delete action
 *
 * Example usage:
 * @code
 * php aws-ec2-instance-snapshots.php -i=i-7ed55c04 -r=us-e1 -q -n -o -t 600
 * @endcode
 *
 * WARNING : USE AT YOU OWN RISK!!! This application will delete snapshots unless you use the -o option
 **/
	
	// Enable full-blown error reporting.
	error_reporting(-1);
	// Debug overriding mode
	define("DEBUG", FALSE);
	// Set HTML headers
	header("Content-type: text/html; charset=utf-8");

	// Include the SDK
	require_once 'aws-sdk-for-php/sdk.class.php';

	// Instantiate the AmazonEC2 class
	$ec2 = new AmazonEC2();
	
	/*** option selection ***/
	
	// Process paramters and setup constants
	$parameters = getopt('i:r:t::qno');

	if (!isset($parameters['i'])) {
	  exit('EC2 Instance ID required' . "\n");
	} else {
	  define("INSTANCEID", $parameters['i']);
	}
	
	if (!isset($parameters['t'])) {
	  $keepfor=7 * 24 * 60 * 60; //a week
	  define("KEEPFOR",$keepfor);
	} else {
	  define("KEEPFOR",$parameters['t']);
	}

	if (isset($parameters['q']) || DEBUG) {
	  define("QUIET", TRUE);
	} else {
	  define("QUIET", FALSE);
	}

	if (isset($parameters['n']) || DEBUG) {
	  define("DODELETE", FALSE);
	} else {
	  define("DODELETE", TRUE);
	}
	
	if (isset($parameters['o']) || DEBUG) {
	  define("DOCREATE", FALSE);
	} else {
	  define("DOCREATE", TRUE);
	}
	
	define("WEEK", 604800);
	define("MONTH", 2678400);

	// Instantiate the AmazonEC2 class
	$ec2 = new AmazonEC2();

	if (isset($parameters['r'])) {
		switch($parameters['r']) {
			case 'us-e1':
				define("REGION", AmazonEC2::REGION_US_E1);
				break;
			case 'us-w1':
				define("REGION",  AmazonEC2::REGION_US_W1);
				break;
			case 'us-w2':
				define("REGION", AmazonEC2::REGION_US_W2);
				break;
			case 'us-gov1':
				define("REGION", AmazonEC2::REGION_US_GOV1);
				break;
			case 'eu-w1':
				define("REGION", AmazonEC2::REGION_EU_W1);
				break;
			case 'apac-se1':
				define("REGION",  AmazonEC2::REGION_APAC_SE1);
				break;
			case 'apac-ne1':
				define("REGION",  AmazonEC2::REGION_APAC_NE1);
				break;
			case 'sa-e1':
				define("REGION", AmazonEC2::REGION_SA_E1);
				break;

			default:
				define("REGION", AmazonEC2::REGION_US_E1);
		}
	} else {
		define("REGION", AmazonEC2::REGION_US_E1);
	}

	// Set Region
	$ec2->set_region(REGION);	
	
	$volumes = listVolumes($ec2,INSTANCEID);

	// Create the snapshots
	foreach ( $volumes as $volume ) {
		$instance = listInstances($ec2, $volume['instanceId']);
		
		if ( $volume['status'] == "in-use" OR $volume['status'] == "available" )
		{
			// Set snap counter to zero
			$count = 0;

			if ($count == 0)
			{
				echo "Ready to create snapshot for volume[".$volume['volumeId']."] on instance ".$volume['instanceId']." ( ".$instance[0]['tagName']." ) ";

				// and now really create it
					if (DOCREATE) 
					{
						$response = createSnapshot($ec2, $volume['volumeId'], $volume['instanceId'], $volume['device']);			
					}
					
					if(isset($response)){
						echo "Status: " . $response . "\n";
						 //new snapshot created, check for delete older
						deleteSnapshot($ec2, $volume['volumeId'], $volume['instanceId'], $volume['device']);
					}else{
						echo "no response status given \n";
					}
				
			}

			$count++;

		}else{
			echo "volume[".$volume['volumeId']."] on instance ".$volume['instanceId']." ( ".$instance[0]['tagName']." ) has status ".$volume['status']." \n";
			
		}
	}


	function deleteSnapshot($obj, $volumeId, $device){

		//get snapshop of this volumeId
		//check creation date of each snapshot
		//delete old snapshot
										
		// Delete old snapshots
		
		// first check we have at least 1 newer snapshot for every vol-id we got
		// we don't want to delete all snapshots of a vol and be left with no snapshots, 
		// this guarantees it. so we build a "go_ahead_volumes" array.
		$now = time();
		$older_than = $now - KEEPFOR;

		$snapshots = listSnapshots($obj,$volumeId);

		foreach ( $snapshots as $snapshot ) {
		
			$snapTimestamp = strtotime($snapshot['startTime']);
			$snapStatus = $snapshot['status'];
			$snapDesc = substr($snapshot['description'],0,9);
			
			if (($snapTimestamp >= $older_than) && ($snapStatus=="completed") && $snapDesc=="AutoSnap:")
			{
				if ($snapshot['volumeId'] == $volumeId)
				{
					$go_ahead_volumes[] = $volumeId;
					echo "Ready for deletion of snapshots older than ".date("Y/m/d H:i:s e", $older_than). " for volume[".$volumeId."]\n";
					echo ",found newer snapshot [" . $snapshot['snapshotId'] . "] taken on " . date('Y/m/d \a\t H:i:s e',$snapTimestamp) .  "\n";
					break;
				}
			}else{
				//echo "snapshot [" . $snapshot['snapshotId'] . "] taken ".date("Y-m-d H:i:s",$snapTimestamp)." < ".date("Y-m-d H:i:s",$older_than)." status ".$snapStatus."\n ";
			}
		}
		
		if (empty($go_ahead_volumes))  die ("No snapshots found for these volumes\n\n");
		
		echo "\n";
		
		// now go over all snaps, if encounter a snap for a go_ahead_volume which
		// is older than, well, older_than, delete it.
		
		foreach ( $snapshots as $snapshot )
		{
			$snapTimestamp = strtotime($snapshot['startTime']);
			$snapDesc = substr($snapshot['description'],0,9);
			
			if ( (in_array($snapshot['volumeId'], $go_ahead_volumes)) && $snapDesc=="AutoSnap:" )
			{		
				if (!keepSnapShot($snapshot['startTime'],KEEPFOR)) {			
					echo "Deleting volume " . $snapshot['volumeId'] . " snapshot " . $snapshot['snapshotId'] . " created on: " . date('Y/m/d \a\t H:i:s e',$snapTimestamp) ." ";
					
					// and now really delete using EC2 library
					if(DODELETE)
					{
						$response = $obj->delete_snapshot($snapshot['snapshotId']);
						if(isset($response->status)){
							echo "Status: " . (string)$response->status . "\n";
						}else{
							echo "no response status given \n";
						}
					}
					
					
				}
					
			}
		}
		echo "\n\n";
	}


	function  keepSnapShot($creation_date,$keepfor)
	{
		$now = time();
		$older_than = $now - $keepfor;
		$older_than_month = $now - 30 * 24 * 60 * 60;
		
		
		// echo strtotime($creation_date);
		$ts = strtotime($creation_date);
		
	//	echo 'Day of month: '.date("d",$ts)."\n";
	//	echo 'Day of week: '.date("w",$ts)."\n";
		
		echo date('Y-m-d H:i:s',$ts)."\t";
		
		if ($ts>=$older_than) { 
			echo "Recent backup\tKEEP\n" ;
			return(TRUE); 
			} 
		if (date("d",$ts)==1) { 
			echo "1st of month\tKEEP\n" ; 
			return(TRUE); 
			}
		if ((date("w",$ts)==0) && $ts>$older_than_month) { 
			echo "Recent Sunday\tKEEP\n" ;
			return(TRUE); 
			} 
		if ((date("w",$ts)==0) && $ts<=$older_than_month) { 
			echo "Old Sunday\tDELETE\n" ;
			return(FALSE); 
			} 
		if ($ts<$older_than) { 
			echo "Old backup\tDELETE\n" ; 
			return(FALSE); 
			} 
			
		
		echo "Unknown condition on ".date('F d, Y',$ts)."\n"; exit(0);
		return(FALSE); 
	}

	// -------------------------------------------
	//
	// Methods based on AWS API
	//
	// -------------------------------------------
	
	function createSnapshot($obj, $volumeId, $device) 
	{
		$instance = listInstances($obj);
		
		$response = $obj->create_snapshot($volumeId, Array( "Description" => "AutoSnap: " . $instance[0]['tagName'] . " ".INSTANCEID." - " . $device . " (" . $volumeId . ") " . date('Ymd - H:i:s', time()) ));
		
		return (string)$response->body->status;
	}
	
	function listVolumes($obj) 
	{
		$response = $obj->describe_volumes();
		if( isset($response->body->Errors->Error)) { //check about auth error
			echo "Error detected: ".$response->body->Errors->Error->Code." ".$response->body->Errors->Error->Message."\n";
			die();
		}
		$output=array();
		foreach ( $response->body->volumeSet->item as $item ) {
			if((string)$item->attachmentSet->item->instanceId==INSTANCEID){
				$volumeId = (string)$item->volumeId;
				
				$output[] = Array(
					"volumeId" => $volumeId, 
					"device" => (string)$item->attachmentSet->item->device, 
					"instanceId" => (string)$item->attachmentSet->item->instanceId,
					"status" => (string)$item->status
				);
			}
		}
		
		return $output;
	}
	
	function listSnapshots($obj,$volumeId) 
	{
		$response = $obj->describe_snapshots(array(
			'Filter' => array(
				array('Name' => 'volume-id', 'Value' => $volumeId)
			)
		));
		$output=array();
		foreach ( $response->body->snapshotSet->item as $item ) {
			//print_r($response->body->snapshotSet);
			$output[] = Array(
				"snapshotId" => $item->snapshotId, 
				"volumeId" => $item->volumeId, 
				"status" => $item->status, 
				"description" => $item->description, 
				"startTime" => $item->startTime
			);
		}

		return $output;
	}
	
	function listInstances($obj, $instanceid = null) 
	{
		if (is_null($instanceid))
			$response = $obj->describe_instances();
		else
			$response = $obj->describe_instances(Array("InstanceId" => $instanceid));
	
		if ( $response->body->reservationSet->item )
		{
			foreach ($response->body->reservationSet->item as $instance) {
				$tagName = (string)$instance->instancesSet->item->tagSet->item->value;
				$instanceid = (string)$instance->instancesSet->item->instanceId;
				$blockDevices = $instance->instancesSet->item->blockDeviceMapping->item;
				
				foreach ( $blockDevices as $volume ) {
					if ( preg_match("/sda1/", $volume->deviceName) )
						$ebsVolumeId = (string)$volume->ebs->volumeId;
					else
						$ebsVolumeId="";
				}
				
				$output[] = array(
					"tagName" => $tagName, 
					"instanceId" => $instanceid, 
					"ebsVolumeId" => $ebsVolumeId
				);
			}
		}
		else
		{
			$output[] = array(
				"tagName" => "N.A."
			);
		}

		return $output;
	}

?>
