<?php

namespace examples;

$path = dirname(dirname(__FILE__));
require_once($path . '/vendor/autoload.php');

use Realtor\RealtorClient;

$client = new RealtorClient;
//$client->pullPhotos = true;
//$client->pullMetaData = true;

$listings = [];

try {
	$listings = $client->getListingsByZip(91601);
} catch(Exception $e) {

}

if($listings) {
	foreach($listings['results'] as $listing) {
		try {
			echo $listing['link'] . "\n";
			$res = $client->getInformation($listing['link']);
			print_r($res);
		} catch(Exception $e) {

		}
	}
}