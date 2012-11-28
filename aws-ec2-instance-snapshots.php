<?php
/**
 * aws-ec2-instance-snapshots 
 * AWS ec2 script that makes snapshot for each attached volume and rotate it each day, week and month deleting older 
 *
 *
 * This script makes;
 * 1) a snapshot of each volume attached to the selected amazon aws ec2 instance.
 * 2) check for snapshot older than $keepfor seconds and delete it 
 * 3) keep 1 snapshot each week and after a week 1 snapshot each month
 * 4) All snapshot with description start equal to "AutoSnap:" will be rotated by this script
 * 
 * Modified from code by:
 * Michele Marcucci
 * https://github.com/michelem09/AWS-EC2-Manage-Snapshots-Backup
 *
 * WARNING : USE AT YOU OWN RISK!!! This application will delete snapshots unless you use the --noop option
 **/
	$instanceId="i-7ed55c04";
	// Enable full-blown error reporting.
	error_reporting(-1);

	// Set HTML headers
	header("Content-type: text/html; charset=utf-8");

	// Include the SDK
	require_once 'aws-sdk-for-php/sdk.class.php';

	// Instantiate the AmazonEC2 class
	$ec2 = new AmazonEC2();
	
	$ec2->set_region(AmazonEC2::REGION_US_E1);
	

	$volumes = listVolumes($ec2,$instanceId);
	//$snapshots = listSnapshots($ec2);

	$docreate = true;
	$dodelete = true;
	//$keepfor = 7 * 24 * 60 * 60;
	$keepfor = 600;

	if( isset($response->body->Errors->Error)) { //check about auth error
		echo "Error detected: ".$response->body->Errors->Error->Code." ".$response->body->Errors->Error->Message."\n";
		die();
	}
	
	// Create the snapshots
	foreach ( $volumes as $volume ) {
		$instance = listInstances($ec2, $volume['instanceId']);
		
		if ( $volume['status'] == "in-use" OR $volume['status'] == "available" )
		{
			// Set snap counter to zero
			$count = 0;
		
			/*foreach ( $snapshots as $snapshot ) {*/

				/*if ( $snapshot['volumeId'] == $volume['volumeId'] && $snapshot['status'] == "pending" )
				{
					echo "Skipping snapshot for volume[".$volume['volumeId']."] on instance ".$volume['instanceId']." ( ".$instance[0]['tagName']." ) \n";
					echo "There is one still in pending status snapshot[".$snapshot['snapshotId']."]\n\n";
				} 
				elseif ( $snapshot['volumeId'] == $volume['volumeId'] ) 
				{ */
					// Get this only one time, we don't want to create duplicated snap
					if ($count == 0)
					{
						echo "Ready to create snapshot for volume[".$volume['volumeId']."] on instance ".$volume['instanceId']." ( ".$instance[0]['tagName']." ) ";

						// and now really create it
						if ($docreate) 
						{
							$response = createSnapshot($ec2, $volume['volumeId'], $volume['instanceId'], $volume['device']);			
							$response=true;
							if(isset($response)){
								echo "Status: " . $response . "\n";
								if($dodelete){ //new snapshot created, check for delete older
									deleteSnapshot($ec2, $volume['volumeId'], $volume['instanceId'], $volume['device'],$keepfor);
								}
							}else{
								echo "no response status given \n";
							}
						}
					}
	
					$count++;
				/*} */
			/*}	*/	
		}else{
			echo "volume[".$volume['volumeId']."] on instance ".$volume['instanceId']." ( ".$instance[0]['tagName']." ) has status ".$volume['status']." \n";
			
		}
	}


	function deleteSnapshot($obj, $volumeId, $instanceId, $device,$keepfor){

		//get snapshop of this volumeId
		//check creation date of each snapshot
		//delete old snapshot
										
		// Delete old snapshots
		
		// first check we have at least 1 newer snapshot for every vol-id we got
		// we don't want to delete all snapshots of a vol and be left with no snapshots, 
		// this guarantees it. so we build a "go_ahead_volumes" array.
		$now = time();
		$older_than = $now - $keepfor;
		
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
				if (!keepSnapShot($snapshot['startTime'],$keepfor)) {			
					echo "Deleting volume " . $snapshot['volumeId'] . " snapshot " . $snapshot['snapshotId'] . " created on: " . date('Y/m/d \a\t H:i:s e',$snapTimestamp) ."\n";
					
					// and now really delete using EC2 library
					$response = $obj->delete_snapshot($snapshot['snapshotId']);
					echo "Status: " . (string)$response->status . "\n\n";
					
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
	
	function createSnapshot($obj, $volumeId, $instanceId, $device) 
	{
		$instance = listInstances($obj, $instanceId);
		
		$response = $obj->create_snapshot($volumeId, Array( "Description" => "AutoSnap: " . $instance[0]['tagName'] . " ".$instanceId." - " . $device . " (" . $volumeId . ") " . date('Ymd - H:i:s', time()) ));
		
		return (string)$response->body->status;
	}
	
	function listVolumes($obj,$instanceId) 
	{
		$response = $obj->describe_volumes();
		$output=array();
		foreach ( $response->body->volumeSet->item as $item ) {
			if((string)$item->attachmentSet->item->instanceId==$instanceId){
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
	
	function listInstances($obj, $instanceId = null) 
	{
		if (is_null($instanceId))
			$response = $obj->describe_instances();
		else
			$response = $obj->describe_instances(Array("InstanceId" => $instanceId));
	
		if ( $response->body->reservationSet->item )
		{
			foreach ($response->body->reservationSet->item as $instance) {
				$tagName = (string)$instance->instancesSet->item->tagSet->item->value;
				$instanceId = (string)$instance->instancesSet->item->instanceId;
				$blockDevices = $instance->instancesSet->item->blockDeviceMapping->item;
				
				foreach ( $blockDevices as $volume ) {
					if ( preg_match("/sda1/", $volume->deviceName) )
						$ebsVolumeId = (string)$volume->ebs->volumeId;
				}
				
				$output[] = array(
					"tagName" => $tagName, 
					"instanceId" => $instanceId, 
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
