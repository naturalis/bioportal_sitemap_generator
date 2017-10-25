<?php
	class NbaSitemap
	{
		private $baseUrl = 'http://api.biodiversitydata.nl/v0/';
		private $portalUrl = 'http://bioportal.naturalis.nl/nba/result?nba_request=';
				
		private $query;
		private $curlUrl;
		private $curlData;
		private $timeout = 5;
		
		private $sourceSystems = array(
			'Naturalis - Nederlands Soortenregister',
			//'Naturalis - Botany catalogues'
		);
		
		private $services = array (
			'taxon' => 'taxon/search/',
			'specimen' => 'specimen/name-search/',
			'media' => 'multimedia/search'
		);
	
		private $service;
		private $sourceSystem;

		private $taxonMaxResults = 50;
		private	$taxonTotal = 0;
		private	$taxonIterator = 0;
		private $fileIterator = 1;
		private $linkIterator = 0;
		private $taxa;
		
		private $indicator;
		private $indicatorMarkersPerLine = 75;
		private $indicatorIterationsPerMarker = 25;
		private $indicatorBreakLine = "\n";

	    private $xmlWriter;
	    private $outputDir = 'sitemap/';
	    private $fileName;
	    private $linksPerSitemap = 10000;
	    private $files;
	

		public function __construct ()
		{
            $this->bootstrap();
			$this->sourceSystems = (object) $this->sourceSystems;
			$this->services = (object) $this->services;
            $this->initXmlWriter();
            $this->initIndicator();
		}
	
        public function __destruct ()
        {
            if ($this->xmlWriter) {
                unset($this->xmlWriter);
            }
        }

		public function setBaseUrl ($url)
		{
			$this->baseUrl = $url;
		}
		
		public function setPortalUrl ($url)
		{
			$this->portalUrl = $url;
		}

		public function setCurlTimeout ($timer)
		{
			$this->timeout = $timer;
		}
		
		public function run ()
		{
			foreach ($this->sourceSystems as $this->sourceSystem) {
				$this->setTaxonTotal();
				for ($i = 0; $i <= $this->taxonTotal; $i += $this->taxonMaxResults) {
					$this->setTaxonIterator($i);
					$this->writeTaxa();
				}
			}
		}
		
		private function setService ($service)
		{
			$this->service = $service;
		}
		
		private function setQuery ($query)
		{
			$this->query = $query;
		}
		
		private function setTaxonIterator ($i)
		{
			$this->taxonIterator = $i;
		}
		
		private function writeFile ()
		{
        	$this->xmlWriter->startDocument('1.0', 'UTF-8');
        	$this->xmlWriter->startElement('urlset');
        	$this->xmlWriter->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        	$this->xmlWriter->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

        	foreach ($this->taxa as $i => $taxon) {
        		$this->xmlWriter->startElement('url');
        		$this->xmlWriter->writeElement('loc', $this->portalUrl . $this->setNbaRequest($taxon));
                if (!empty($taxon->media)) {
                    foreach ($taxon->media as $media) {
                        $this->xmlWriter->startElement('image:image');
                        $this->xmlWriter->writeElement('image:loc', $media->loc);
                        if (!empty($media->caption)) {
                            $this->xmlWriter->writeElement('image:caption', $media->caption);
                        }
                        $this->xmlWriter->endElement();
                    }
        		}
        	    $this->xmlWriter->endElement();
        	}

        	$this->xmlWriter->endElement();
        	file_put_contents(
        	   $this->outputDir . 'sitemap_' . $this->fileIterator . '.xml',
        	   $this->xmlWriter->flush(true),
        	   FILE_APPEND
        	);
		}
		
		private function setNbaRequest ($taxon)
		{
			return rawurlencode($this->baseUrl . 'taxon/get-taxon?genus=' . $taxon->genus . '&specificEpithet=' . 
				$taxon->specificEpithet . (!empty($taxon->infraspecificEpithet) ? 
				'&infraspecificEpithet=' . $taxon->infraspecificEpithet : ''));
		}
		
		private function setTaxonTotal ()
		{
			$this->setService($this->services->taxon);
			$this->setQuery('sourceSystem=' . urlencode($this->sourceSystem));
			$this->setCurlUrl();
			$this->queryNba();
			if (isset($this->curlData->totalSize)) {
				$this->taxonTotal = $this->curlData->totalSize;
				$this->indicator->init($this->taxonTotal);
			}
		}
		
		private function writeTaxa ()
		{
			$this->setService($this->services->taxon);
			$this->setQuery('sourceSystem=' . urlencode($this->sourceSystem) .
				'&_maxResults=' . $this->taxonMaxResults . '&_offset=' . $this->taxonIterator);
			$this->setCurlUrl();
			$this->queryNba();
			
			$taxonResult = $this->curlData;
			
			if (isset($taxonResult->resultGroups) && count($taxonResult->resultGroups) > 0) {
				foreach ($taxonResult->resultGroups as $d) {
					$this->indicator->iterate();
					$data = $d->searchResults[0]->result;
				
					$taxon = new stdClass();
					$taxon->genus = $data->acceptedName->genusOrMonomial;
					$taxon->specificEpithet = $data->acceptedName->specificEpithet;
					$taxon->infraspecificEpithet = $data->acceptedName->infraspecificEpithet;
					$taxon->media = array();
						
					/* Three checks to qualify for inclusion in sitemap:
					1. Description is not empty
					2. Has specimens
					3. Has media */
					if (!empty($data->descriptions) || 
						$this->taxonHasSpecimens($taxon) || 
						$this->taxonHasMedia($taxon)) {
						$this->taxa[] = $taxon;
						$this->linkIterator++;

						// Buffer until we have a complete document to print
						if ($this->linkIterator % $this->linksPerSitemap == 0) {
							$this->writeFile();
							$this->resetTaxa();
						}
					}
				}
			}
		}
		
		private function taxonHasSpecimens ($taxon)
		{
			$this->setService($this->services->specimen);
			$this->setQuery('genusOrMonomial=' . $taxon->genus . '&specificEpithet=' . 
				$taxon->specificEpithet . '&infraspecificEpithet=' . 
				$taxon->infraspecificEpithet . '&_maxResults=1');
			$this->setCurlUrl();
			$this->queryNba();
			if (isset($this->curlData->totalSize)) {
				return $this->curlData->totalSize > 0;
			} 
			return false;
		}
		
		private function taxonHasMedia (&$taxon)
		{
			$this->setService($this->services->media);
			$this->setQuery('genusOrMonomial=' . $taxon->genus . '&specificEpithet=' . 
				$taxon->specificEpithet . '&infraspecificEpithet=' . 
				$taxon->infraspecificEpithet . '&_maxResults=10');
			$this->setCurlUrl();
			$this->queryNba();
			
			if (isset($this->curlData->searchResults) && count($this->curlData->searchResults) > 0) {
				foreach ($this->curlData->searchResults as $i => $d) {
					$data = $d->result->identifications[0]->scientificName;
				
					// Service in v0 is broken, is IF rather than AND search. Check "manually"
					if ($taxon->genus == $data->genusOrMonomial &&
						$taxon->specificEpithet == strtolower($data->specificEpithet) &&
						$taxon->infraspecificEpithet == strtolower($data->infraspecificEpithet) &&
						$i <= 5) {
						$media = new stdClass();
						$media->loc = $d->result->serviceAccessPoints->MEDIUM_QUALITY->accessUri;
						$media->caption = $d->result->caption;
						$taxon->media[] = $media;	
					// Return upon first incorrect match, assuming that results are sorted by relevance
					} else {
						return !empty($taxon->media) ? $taxon : false;
					}
				}
				return !empty($taxon->media) ? $taxon : false;
			}
			return false;
		}
		
		private function queryNba () 
		{
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $this->curlUrl);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HEADER, false);
			if ($this->timeout) {
				curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
			}
			$this->curlData = json_decode(curl_exec($curl));
			curl_close($curl);
		}
		
		private function setCurlUrl ()
		{
			$this->curlUrl = $this->baseUrl . $this->service . '?' . $this->query;
		}
		
		private function printData ()
		{
			echo '<pre>'; 
			print_r($this->curlData); 
			die('</pre>');
		}

		private function initXmlWriter ()
		{
            $this->xmlWriter = new XMLWriter();
            $this->xmlWriter->openMemory();
            $this->xmlWriter->setIndent(true);
            $this->xmlWriter->setIndentString("   ");
		}
		
		private function initIndicator ()
		{
			$this->indicator = new Indicator();
			$this->indicator->setMarkersPerLine($this->indicatorMarkersPerLine);
			$this->indicator->setIterationsPerMarker($this->indicatorIterationsPerMarker);
			$this->indicator->setBreakLine($this->indicatorBreakLine);
		}

		private function bootstrap ()
		{
            // Need to track progress
            if (file_exists('Indicator.php')) {
				require_once 'Indicator.php';
            } else {
            	die("Indicator class is missing!\n");
            }
            // Test if output directory exists; if not try to create it
            if (!file_exists($this->outputDir)) {
                if (!mkdir($this->outputDir)) {
                    die('Cannot create directory ' . $this->outputDir . "\n");
                }
            }
		    // Test output directory
		    if (!is_writable($this->outputDir)) {
                die($this->outputDir . " is not writable!\n");
		    }
		}

		private function resetTaxa ()
		{
        	$this->fileIterator++;
        	$this->linkIterator = 0;
        	$this->taxa = array();
		}
	}
	

?>