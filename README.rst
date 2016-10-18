Realtor.com Search
================================

Note! This is no longer working as Realtor.com often changes their site. Since this library scans the website and grabs the data based on the elemenets in the source code this stopped working once they changed their website.
=================================

This is a simple PHP Wrapper for searching Realtor.com and viewing listing information. Please read the realtor.com Terms Of Use before using this library.

Requirements
------------

depends on PHP 5.4+, Goutte 2.0+, Guzzle 4+.

Installation
------------

Add `vince/realtor.com`` as a require dependency in your ``composer.json`` file:

.. code-block:: bash

    php composer.phar require vinceg/realtor.com:~1.0

Usage
-----

.. code-block:: php

    use Realtor\RealtorClient;

    $client = new RealtorClient();

Make requests with a specific API call method:

.. code-block:: php

    // Run search by address
    $response = $client->searchByAddress('5400 Tujunga Ave');

.. code-block:: php

    // Run search by zip code
    $response = $client->getListingsByZip(90021, $perPage[10,20,50], $currentPage=1);


.. code-block:: php

    // Run search by city and state
    $response = $client->getListingsByCityAndState('Los Angeles', 'CA', $perPage[10,20,50], $currentPage=1);

.. code-block:: php

    // Get listing information
    $response = $client->getInformation('LISTING FULL URL');        


- See example directory for example usage. The result will always be an array. refer to the RESULT file to see an example result.


License
-------

MIT license.
