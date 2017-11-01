<?php
	require_once 'BioportalSitemap.php';
	
	$bs = new \BioportalSitemap();
	
	// Server settings
	$bs->setOutputDir('/opt/git/bioportal_sitemap_generator/sitemap/');
	$bs->setLogPath('/var/log/bioportal-sitemap.log');
	$bs->setBioportalClientDir('/opt/git/bioportal-php-client/');
	
	$bs->run();


?>