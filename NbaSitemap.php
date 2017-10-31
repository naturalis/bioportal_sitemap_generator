<?php
	use nl\naturalis\bioportal\Client as Client;
	use nl\naturalis\bioportal\QuerySpec as QuerySpec;
	use nl\naturalis\bioportal\Condition as Condition;
	
	class NbaSitemap
	{
		/* Config */
		// Full path to output directory
	    private $outputDir = '/Users/ruud/Documents/MAMP/htdocs/nba_sitemap/sitemap/';
		// Full path to output directory
	    private $logPath = '/tmp/bioportal-sitemap.log';
	    // Full base path to BioPortal client directory (so not to CLient.php itself!)
	    private $clientDir = '/Users/ruud/Documents/MAMP/htdocs/bp_client/';
	    
	    /* These settings should be quite stable; no setters available! */
	    // Base url to NBA
		private $nbaUrl = 'http://api.biodiversitydata.nl/v2/';
		// NBA timeout
		private $nbaTimeout = 30;
		// Base url to BioPortal specimen detail page
		private $bioportalUrl = 'http://bioportal.naturalis.nl/';
				
		/* Application */
	    private $client;
	    private $nbaMaxResults;
		
	    private $xmlWriter;
	    private $fileName;

	    private $collection;
	    private $genusOrMonomial;
	    private $specificEpithet;
	    private $query;
	    
	    private $batchSize = 50;
		private	$total = 0;
	    

		public function __construct ()
		{
            set_time_limit(0);
		}
	
        public function __destruct ()
        {
            if ($this->xmlWriter) {
                unset($this->xmlWriter);
            }
        }
        
        public function setOutputDir ($dir) 
        {
        	$this->outputDir = $dir;
        }
        
	    public function setLogPath ($path) 
        {
        	$this->logPath = $path;
        }
        
		public function setBioportalClientDir ($dir) 
        {
        	$this->clientDir = $dir;
        }
 
		public function run ()
		{
            $this->bootstrap();
            $this->initXmlWriter();
            $this->setNbaMaxResults();
            
			// Clean up the existing sitemaps first
			$this->deleteAllSitemaps();

			// Create start marker in log file
			$this->writeLog("\n================================================\n" . 
				"Sitemap creation started at " . date("Y-m-d H:i:s") . "\n");
			
			// Write sitemap files for all collections
			$collections = (array) $this->getCollections();
			foreach ($collections as $this->collection => $nrSpecimens) {
				$this->resetGenusAndSpecies();
				// Check if number of specimens in collection exceeds NBA query window;
				// if so, create queries for genera A-Z
				if ($nrSpecimens >= $this->nbaMaxResults) {
					// Loop over genus A-Z
					foreach (range('a', 'z') as $this->genusOrMonomial) {
						// Oh my, still too many records; continue with species A-Z
						if ($this->getNrSpecimens() >= $this->nbaMaxResults) {
							foreach (range('a', 'z') as $this->specificEpithet) {
								if (!empty($this->getNrSpecimens())) {
									$this->writeFile();
								}
							}
						} else {
							//$this->queries[$collection][$genusOrMonomial] = $nrGenera;
							$this->writeFile();
						}
						$this->resetSpecies();
					}
				} else {
					$this->writeFile();
				}
			}
			
			// Write sitemap index that will be submitted to Google etc
			$this->writeSitemapIndex();
			
			// Create end marker in log file
			$this->writeLog("Sitemap creation ended at " . date("Y-m-d H:i:s") . "\n" .
				"================================================\n");
		}
		
		public function getCollections () 
		{
			return json_decode($this->client()->getDistinctValues('collectionType'));
		}
		
		public function getNrSpecimens () 
		{
			return $this->client()->setQuerySpec($this->setQuery())->count();
		}
		
		private function getCollectionQuery () 
		{
			return $this->setQuery(true);
		}
		
		private function setCondition () 
		{
			$condition = new Condition('collectionType', 'EQUALS_IC', $this->collection);
			if ($this->genusOrMonomial) {
				$condition->setAnd('identifications.scientificName.genusOrMonomial', 
					'STARTS_WITH_IC', $this->genusOrMonomial);
			}
			if ($this->specificEpithet) {
				$condition->setAnd('identifications.scientificName.specificEpithet', 
					'STARTS_WITH_IC', $this->specificEpithet);
			}
			return $condition;
		}
		
		private function setQuery () 
		{
			$query = new QuerySpec;
			$query
				->addCondition($this->setCondition())
				->setConstantScore();
			return $query;
		}
		
		private function resetGenusAndSpecies ()
		{
			$this->genusOrMonomial = $this->specificEpithet = false;
		}
		
		private function resetSpecies ()
		{
			$this->specificEpithet = false;
		}
		
		private function writeFile ()
		{
			// Delete file if it has been created previously
			if (file_exists($this->getXmlFilePath())) {
				unlink($this->getXmlFilePath());
			}
			// Get total number of specimens for this file
			$this->query = $this->getCollectionQuery()->setSize(1);
        	$tmp = json_decode($this->client()->setQuerySpec($this->query)->query());
        	// Max cannot be higher than NBA max results
         	$this->total = min($tmp->totalSize, $this->nbaMaxResults);
         	
         	// Start timer for log
         	$start = microtime(true);
         	
         	// Let's go!
			$this->xmlWriter->startDocument('1.0', 'UTF-8');
        	$this->xmlWriter->startElement('urlset');
        	$this->xmlWriter->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        	$this->xmlWriter->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
        	$this->flushXml();
         	
        	// Loop over result in batches
         	for ($n = 0; $n < $this->total; $n += $this->batchSize) {
				// Modify query for current loop
         		$this->query->setFrom($n)->setSize($this->batchSize);
         		$result = json_decode($this->client()->setQuerySpec($this->query)->query());
         		
         		// Write line for each specimen
         		foreach ($result->resultSet as $specimen) {
	        		$this->xmlWriter->startElement('url');
	        		$this->xmlWriter->writeElement('loc', $this->bioportalUrl . 'specimen/' .
	        			rawurlencode($specimen->item->unitID));
	                if (isset($specimen->item->associatedMultiMediaUris)) {
	                    foreach ($specimen->item->associatedMultiMediaUris as $media) {
	                        $this->xmlWriter->startElement('image:image');
	                        $this->xmlWriter->writeElement('image:loc', $media->accessUri);
                            $this->xmlWriter->writeElement('image:caption', 
                            	'Image of specimen ' . $specimen->item->unitID . ' from the Naturalis collection');
	                        $this->xmlWriter->endElement();
	                    }
	        		}
	        	    $this->xmlWriter->endElement();
	        	    $this->flushXml();
	        	}
         	}

        	$this->xmlWriter->endElement();
        	$this->flushXml();
        	
        	// Write progress to log
        	$this->writeLog(date("Y-m-d H:i:s") . ': ' . $this->getXmlFilePath() . ' created in ' .
        		round(microtime(true) - $start, 1) . "s\n");
 		}
 		
 		private function writeSitemapIndex () 
 		{
 			$filePath = $this->outputDir . 'sitemap-index.xml';
 			
 			// Delete file if it has been created previously
			if (file_exists($filePath)) {
				unlink($filePath);
			}
			
 			$this->xmlWriter->startDocument('1.0', 'UTF-8');
        	$this->xmlWriter->startElement('sitemapindex');
        	$this->xmlWriter->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
			$this->flushXml($filePath);
			
			foreach ($this->getSitemapFiles() as $file) {
				$this->xmlWriter->startElement('sitemap');
				$this->xmlWriter->writeElement('loc', $this->bioportalUrl . 'sitemap/' . $file['loc']);
	 			$this->xmlWriter->writeElement('lastmod',  date('c', $file['lastmod']));
	        	$this->xmlWriter->endElement();
	        	$this->flushXml($filePath);
	 		}

        	$this->xmlWriter->endElement();
        	$this->flushXml($filePath);
 		}
 		
 		private function getSitemapFiles ()
 		{
 			$directoryIterator = new \RecursiveDirectoryIterator($this->outputDir, 
				FilesystemIterator::SKIP_DOTS);
			$recursiveIterator = new \RecursiveIteratorIterator($directoryIterator, 
				RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($recursiveIterator as $file) {
			    if ($file->isFile() && $file->getExtension() == 'xml') {
			    	$files[] = [
			    		'loc' => $file->getFilename(),
			    		'lastmod' => $file->getMTime()
			    	];
			    }
			}
 			return isset($files) ? $files : [];
 		}
 		
		private function flushXml ($file = false) 
		{
			$file = !$file ? $this->getXmlFilePath() : $file;
			try {
				file_put_contents($file, $this->xmlWriter->flush(true), FILE_APPEND);
			} catch (Exception $e) {
				die($e->getMessage());
			}
		}
		
		private function deleteAllSitemaps ()
		{
			$directoryIterator = new \RecursiveDirectoryIterator($this->outputDir, 
				FilesystemIterator::SKIP_DOTS);
			$recursiveIterator = new \RecursiveIteratorIterator($directoryIterator, 
				RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($recursiveIterator as $file) {
			    $file->isDir() ? rmdir($file) : unlink($file);
			}
		}
		
		private function writeLog ($message) {
			try {
				file_put_contents($this->logPath, $message,  FILE_APPEND);
			} catch (Exception $e) {
				die($e->getMessage());
			}
		}
		
		private function getXmlFilePath () 
		{
			$path = $this->outputDir . strtolower($this->collection);
			if (!empty($this->genusOrMonomial)) {
				$path .= '_' . $this->genusOrMonomial;
			}
			if (!empty($this->specificEpithet)) {
				$path .= '_' . $this->specificEpithet;
			}
			return $path . '.xml';
		}
		
		private function initXmlWriter ()
		{
            $this->xmlWriter = new \XMLWriter();
            $this->xmlWriter->openMemory();
            $this->xmlWriter->setIndent(true);
            $this->xmlWriter->setIndentString("   ");
		}
		
		private function client ()
		{
			$this->client = new Client;
			return 
				$this->client->setNbaUrl($this->nbaUrl)->setNbaTimeout($this->nbaTimeout)->specimen();
		}
		
		private function setNbaMaxResults ()
		{
			$this->nbaMaxResults =  (int) $this->client()->getIndexMaxResultWindow();
		}

		private function bootstrap ()
		{
            // Load PHP client
            $clientPath = $this->clientDir . 'lib/nl/naturalis/bioportal/Loader.php';
		    if (file_exists($clientPath)) {
				require_once $clientPath;
            } else {
            	throw new Exception("PHP client path $clientPath is incorrect!\n");
            }
            // Test if output directory exists; if not try to create it
            if (!file_exists($this->outputDir)) {
                if (!mkdir($this->outputDir)) {
                    throw new Exception("Cannot create directory $this->outputDir\n");
                }
            }
		    // Test output directory
		    if (!is_writable($this->outputDir)) {
                throw new Exception($this->outputDir . " is not writable!\n");
		    }
		    // Test log
			if (!file_exists($this->logPath)) {
				try {
					$fp = @fopen($this->logPath, 'a');
					if ($fp) {
						fclose($fp);
					}
				} catch (Exception $e) {
				    die('Cannot write to log path ' . $this->logPath);
				}
		    }
		}
		
		private function resetIterator ()
		{
        	$this->iterator = 0;
		}
		
		// Near straight clone of BP method; directly return preferred name
		private function _getSpecimenName ($identifications = []) {
		    foreach ($identifications as $identification) {
		    	$name = $this->_getScientificName($identification);
		    	if (!empty($name)) {
					$output[] = [
						'name' => $name,
						//'url' => _getTaxonUrl($identification),
					    'preferred' => $identification->preferred ? 1 : 0
					];
		    	}
			}
			if (isset($output)) {
		    	usort($output, function($a, $b) {
		            return $b['preferred'] - $a['preferred'];
		        });
			}
			return isset($output) ? $output[0]['name'] : [];
		}
		
		// Clone of BP method
		private function _getScientificName ($object) {
			// Can be used to generate a full italicized name (default) or 
			// a simple non-formatted name without authorship
			$elements = $this->_scientificNameElementsInItalics();
			// Name can be stored in either acceptedName (taxon) or 
			// scientificName (specimen); try both
			foreach (['acceptedName', 'scientificName'] as $path) {
				foreach ($elements as $i => $element) {
					$name[$i] = isset($object->{$path}->{$element}) && 
						!empty(trim($object->{$path}->{$element})) ?
						trim($object->{$path}->{$element}) : null;
					// Subgenus between brackets
					if ($element == 'subgenus' && !empty($name[$i]) && $name[$i][0] != '(' && 
						substr($name[$i], -1) != ')') {
						$name[$i] = '(' . $name[$i] . ')';
					}
				}
				// Some specimens don't have a properly formatted name;
				// revert to fullScientificName
				if (isset($object->{$path}) && empty($name[0])) {
					$name[0] = $object->{$path}->fullScientificName;
					unset($name[1], $name[2], $name[3]);
				}
				if (!empty(array_filter($name))) {
					return implode(' ', array_filter($name));
				}
			}
			return null;
		}
		
		// Clone of BP method
		private function _scientificNameElementsInItalics () { 
			return [
				'genusOrMonomial',
				'subgenus',
				'specificEpithet',
				'infraspecificEpithet',
			];		
		}
			
	}
	

?>