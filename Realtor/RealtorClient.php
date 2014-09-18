<?php

/*
 * This file is part of the Realtor package.
 *
 * (c) Vincent Gabriel <vadimg88@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Realtor;

use GuzzleHttp\Client as GuzzleClient;
use Goutte\Client as GoutteClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Realtor\RealtorException;

/**
 * Client
 *
 * @author Vincent Gabriel <vadimg88@gmail.com>
 */
class RealtorClient
{   
    /**
     * @var Realtor endpoint
     */
    const END_POINT = 'http://www.realtor.com';
	/**
	 * @var object GuzzleClient
	 */
	protected $client;

	/**
     * @var int
     */
    protected $errorCode = 0;
	
    /**
     * @var string
     */
    protected $errorMessage = null;

    /**
     * @var array
     */
    protected $response;
    
    /**
     * @var array
     */
    protected $results;

    /**
     * @var array
     */
    protected $goutte;

    /**
     * @var array
     */
    protected $crawler;

    /**
     * @var bool pull the photos from the listing
     */
    public $pullPhotos = false;

    /**
     * @var bool pull the meta data from the listing
     */
    public $pullMetaData = false;

    /**
     * @var bool
     */
    public $isListing = false;

	/**
	 * Set client
     * return GuzzleClient
     */
    public function setClient(GuzzleClientInterface $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * get GuzzleClient, create if it's null
     * return GuzzleClient
     */
    public function getClient()
    {
        if (!$this->client) {
            $this->client = new GuzzleClient(array('defaults' => array('allow_redirects' => true, 'cookies' => true)));
        }

        return $this->client;
    }

    /**
     * Check if the last request was successful
     * @return bool
     */
    public function isSuccessful()
    {
    	return (bool) ((int) $this->errorCode === 0);
    }

    /**
     * return the status code from the last call
     * @return int
     */
    public function getStatusCode()
    {
    	return $this->errorCode;
    }

    /**
     * return the status message from the last call
     * @return string
     */
    public function getStatusMessage()
    {
    	return $this->errorMessage;
    }

    /**
     * return the actual response array from the last call
     * @return array
     */
    public function getResponse()
    {
        return isset($this->response['response']) ? $this->response['response'] : $this->response;
    }

    /**
     * return the results array from the GetSearchResults call
     * @return array
     */
    public function getResults()
    {
        return $this->results;
    }

    public function searchByAddress($address) {
        $this->goutte = new GoutteClient;
        $this->crawler = $this->goutte->request('GET', self::END_POINT . '/propertyrecord-search/' . $address);

        $this->crawler->filter('.property-title')->each(function($node) {
            $this->isListing = true;
        });

        // If we have property-title then there were multiple results
        // otherwise we are viewing the actual listing
        if($this->isListing) {
            return $this->parseListings();
        } else {
            return $this->parseInformation();
        }
    }

    public function getListingsByZip($zip, $perPage=20, $page=1) {
        $this->goutte = new GoutteClient;
        $this->crawler = $this->goutte->request('GET', self::END_POINT . '/propertyrecord-search/' . $zip . '/pg-'.$page.'?pgsz=' . $perPage);

        // Parse listings
        return $this->parseListings();
    }

    public function getListingsByCityAndState($city, $state, $perPage=20, $page=1) {
        // City must be cased and state upper cased
        // spaces in city need to be - and state separated by _
        // ie: Los-Angeles_CA

        $city = str_replace(' ', '-', ucwords(strtolower($city)));
        $state = strtoupper($state);

        $this->goutte = new GoutteClient;
        $this->crawler = $this->goutte->request('GET', self::END_POINT . '/propertyrecord-search/' . $city . '_' . $state . '/pg-'.$page.'?pgsz=' . $perPage);

        // Parse listings
        return $this->parseListings();
    }

    protected function parseListings() {
        $this->response['count'] = 0;
        $this->response['pages'] = 0;
        $this->response['results'] = [];

        $title = trim($this->crawler->filter('#ResultsCount .property-title')->text());
        preg_match('/([\d\,]+) properties found.*/is', $title, $matches);
        if($matches && isset($matches[1])) {
            $this->response['count'] = preg_replace('/[^0-9]/', '', $matches[1]);
        }

        // Get number of pages that we have
        // we basically take the highest number which will be probably
        // be the max page number
        $this->crawler->filter('#ListViewSortTop .pagination li a')->each(function($node) {
            $page = preg_replace('/[^0-9]/', '', $node->text());
            if($page > $this->response['pages']) {
                $this->response['pages'] = $page;
            }
        });

        // Grab all listings
        $this->crawler->filter('.listing-group .listing-wrap')->each(function($node) {
            // Grab address
            $address = $node->filter('.listing-location a')->attr('title');
            $link = $node->filter('.listing-location a')->attr('href');
            $this->response['results'][] = ['address' => $address, 'link' => self::END_POINT . $link];
        });

        return $this->response;
    }

    /**
     * Since zillow does not provide the ability to grab the photos
     * of the properties through the API this little method will scan
     * the property url and grab all the images for that property
     * @param string $uri
     * @return array
     */
    public function getInformation($uri) {
        $this->goutte = new GoutteClient;
        $this->crawler = $this->goutte->request('GET', $uri);

        // Parse info
        return $this->parseInformation();
    }

    /**
     * Parse the listing information
     */
    protected function parseInformation() {
        // Grab address
        $this->getAddress();

        // Grab metadata
        if($this->pullMetaData) {
            $this->getMetaData();
        }

        // Grab sold info
        $this->getSold();

        // Grab estimated values
        $this->getEstimatedValues();

        // If we have photos then pull the photos
        if($this->pullPhotos) {
            $this->getPhotos();
        }

        return $this->response;
    }

    /**
     * Grab estimated values
     */
    protected function getEstimatedValues() {
        $this->response['estimated_values'] = [];

        $estimatedTable = $this->crawler->filter('.span-aside')->filter('.unit-body > table tr');
        if($estimatedTable) {
            $estimatedTable->each(function($node) {
                $title = trim(strtolower($node->filter('td')->eq(0)->filter('img')->attr('alt')));
                $this->response['LastTitle'] = $title;
                $node->filter('td')->eq(1)->filter('.list-data li')->each(function($node) {
                    $price = trim(strtolower(preg_replace('/[^0-9]/', '', $node->filter('span')->eq(0)->text())));
                    $key = trim(strtolower($node->filter('span')->eq(1)->text()));
                    $this->response['estimated_values'][$this->response['LastTitle']][$key] = $price;
                });
            });
        }

        if(isset($this->response['LastTitle'])) {
            unset($this->response['LastTitle']);
        }
    }

    /**
     * Grab address
     */
    protected function getAddress() {
        $this->response['address'] = ['address' => null, 'city' => null, 'state' => null, 'zip' => null];

        $this->crawler->filter('.title-group-headline > span')->each(function ($node) {
            $itemProp = strtolower($node->attr('itemprop'));
            $value = trim($node->text());
            switch ($itemProp) {
                case 'streetaddress':
                    $this->response['address']['address'] = $value;
                    break;
                case 'addresslocality':
                    $this->response['address']['city'] = $value;
                    break;
                case 'addressregion':
                    $this->response['address']['state'] = $value;
                    break;
                case 'postalcode':
                    $this->response['address']['zip'] = $value;
                    break;            
            }
        });

        // Grab description
        $this->response['description'] = null;

        $this->crawler->filter('.property-description')->each(function($node) {
            // Grab all description since sometimes it's split in two spans
            $this->response['description'] = trim(str_ireplace(['read more', 'collapse', '...'], '', $node->text()));
        });
    }

    /**
     * Grab address
     */
    protected function getSold() {
        $this->response['sold']['price'] = 0;
        $this->response['sold']['saleDate'] = null;
        $this->response['sold']['status'] = null;

        $this->crawler->filter('#MetaData > .span-a')->each(function($node) {
            $sold = $node->children();
            if($sold->first()->getNode(0)->tagName == 'p') {
                $this->response['sold']['price'] = trim(preg_replace('/[^0-9]/', '', $sold->filter('span')->eq(0)->text()));
                $this->response['sold']['status'] = trim($sold->filter('span')->eq(1)->html());
                // Check if it was sold
                if(strpos($this->response['sold']['status'], '</i>')!==false) {
                    $this->response['sold']['status'] = trim($sold->filter('span')->eq(1)->filter('i')->text());
                    $this->response['sold']['saleDate'] = trim(str_ireplace(['on', 'sold'], '', $sold->filter('span')->eq(1)->text()));
                } else {
                    $this->response['sold']['status'] = trim($sold->filter('span')->eq(1)->text());
                }
            } else {
                $this->response['sold']['status'] = $sold->filter('span')->text();
            }
        });
    }

    /**
     * Grab meta data
     */
    protected function getMetaData() {
        $this->response['metadata'] = [];

        $this->crawler->filter('#MetaData li.list-sidebyside')->each(function ($node) {
            $key = trim(strtolower($node->filter('span')->eq(0)->text()));
            $value = trim($node->filter('span')->eq(1)->text());
            $this->response['metadata'][$key] = $value;
        });

        $this->crawler->filter('#tab-overview h3.title-section')->each(function ($node) {
            $this->response['LastTitle'] = trim($node->text());
            $next = $node->siblings();
            $next->filter('div')->eq(0)->filter('.list-data > li')->each(function ($node) {
                $key = trim(strtolower($node->filter('span')->eq(0)->text()));
                $value = trim($node->filter('span')->eq(1)->text());
                $this->response['metadata'][$this->response['LastTitle']][$key] = $value;
            });
        });

        // Grab additional info
        $this->crawler->filter('#AdditionalDetails tr')->each(function ($node) {
            $key = trim(strtolower($node->filter('th')->text()));
            $value = trim($node->filter('td')->text());

            switch ($key) {
                case 'mls id':
                    $this->response['listing']['mlsid'] = $value;
                    break;

                case 'listing brokered by':
                    $phone = $node->filter('td')->filter('li')->eq(1)->text();
                    $name = $node->filter('td')->filter('li')->eq(0)->text();

                    if($name && $phone) {
                        $this->response['listing']['broker'] = ['phone' => trim($phone), 'title' => trim($name)];
                    } else {
                        $this->response['listing'][$key] = $value;
                    }
                    break;    
                
                case 'listing agent':
                break;

                default:
                    $this->response['listing'][$key] = $value;
                    break;
            }
        });

        // Public records
        $this->crawler->filter('#tab-overview h2.title-section')->each(function ($node) {
            $this->response['LastTitle'] = trim($node->text());
            $next = $node->siblings();
            $run = false;

            if(strpos($this->response['LastTitle'], 'Public Records')!==false) {
                $this->response['LastTitle'] = 'Public Records';
                $run = true;
            }

            if($run) {
                $next->filter('div')->eq(0)->filter('.list-data > li')->each(function ($node) {
                    $key = trim(strtolower($node->filter('span')->eq(0)->text()));
                    $value = trim($node->filter('span')->eq(1)->text());
                    $this->response[$this->response['LastTitle']][$key] = $value;
                });
            }
        });

        // Similar Homes
        $this->crawler->filter('#tab-overview table.bottom tbody')->filter('tr')->each(function ($node) {
            $address = trim($node->filter('td')->eq(0)->text());
            $link = $node->filter('td')->eq(0)->filter('a')->attr('href');
            $status = trim($node->filter('td')->eq(1)->text());
            $price = trim($node->filter('td')->eq(2)->text());
            $beds = trim($node->filter('td')->eq(3)->text());
            $baths = trim($node->filter('td')->eq(4)->text());
            $sqft = trim($node->filter('td')->eq(5)->text());
            $this->response['similar_props'][] = [
                'address' => $address,
                'link' => self::END_POINT . $link,
                'status' => $status,
                'price' => $price,
                'beds' => $beds,
                'baths' => $baths,
                'sqft' => $sqft,
            ];
        });

        // On Site
        $this->crawler->filter('#OnSite tr')->each(function ($node) {
            $key = trim(strtolower($node->filter('th')->text()));
            $value = trim($node->filter('td')->text());

            switch ($key) {

                default:
                    $this->response['onsite'][$key] = $value;
                    break;
            }
        });

        if(isset($this->response['LastTitle'])) {
            unset($this->response['LastTitle']);
        }
    }

    /**
     * Grab photos
     */
    protected function getPhotos() {
        // Init
        $this->response['photos'] = [
            'hasPhotos' => false,
            'totalPhotos' => 0,
            'results' => [],
        ];

        // Get the latest post in this category and display the titles
        $this->crawler->filter('.tabs a')->each(function ($node) {
            $href = $node->attr('href');
            if($href == '#tab-photos') {
                $this->response['photos']['hasPhotos'] = true;
                $this->response['photos']['totalPhotos'] = preg_replace('/[^0-9]/', '', $node->text());
            }
        });

        // If we have photos then pull the photos
        if($this->response['photos']['hasPhotos'] && $this->response['photos']['totalPhotos'] > 0) {
            // If this will return anything it'll look like this
            // http://p.rdcpix.com/v01/l2e16d244-m0xd-w400_h300_q80.jpg
            // m0xd is the zero based image index 0 -> totalPhotos
            // w400 is width - dynamically changes
            // h300 is the height - dynamically changes
            // q80 is the quality - dynamically changes
            $image = $this->crawler->filter('img.model-gallery-img')->attr('src');

            // Get the part before the first dash
            $r = explode('.', $image);
            $split = explode('-', $image);
            $baseImageLink = $split[0] . '-m%sxd-w%s_h%s.' . end($r);

            // Iterate over the number of times we have total photos of
            for($i=0;$i<=$this->response['photos']['totalPhotos'];$i++) {
                $this->response['photos']['results'][] = ['thumb' => sprintf($baseImageLink, $i, 100, 100), 'large' => sprintf($baseImageLink, $i, 800, 800)];
            }
        }
    }

    /**
     * set the statis code and message of the api call
     * @param int $code
     * @param string $message
     * @return void
     */
    protected function setStatus($code, $message)
    {
        $this->errorCode = $code;
        $this->errorMessage = $message;
    }

    /**
     * Perform the actual request to the zillow api endpoint
     * @param string $name
     * @param array $params
     * @return array
     */
    protected function doRequest($call, array $params) {
    	// Run the call
    	$response = $this->getClient()->get(self::END_POINT.$call, ['query' => $params]);

        $this->response = $response->body();

        // Parse response
        return $this->response;
    }
}