<?php
	require_once 'NbaSitemap.php';
	
	$nba = new \NbaSitemap();
	
	// Server settings
	$nba->setOutputDir('/opt/git/bioportal_sitemap_generator/sitemaps/');
	$nba->setLogPath('/var/log/bioportal-sitemap.log');
	$nba->setBioportalClientDir('/opt/git/bioportal-php-client/');
	
	$nba->run();


?>