<?php

/**
 * @file classes/sword/OJSSwordDeposit.inc.php
 *
 * Copyright (c) 2013 Simon Fraser University Library
 * Copyright (c) 2003-2013 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class OJSSwordDeposit
 * @ingroup sword
 *
 * @brief Class providing a SWORD deposit wrapper for OJS articles
 */

require_once('./lib/pkp/lib/swordappv2/swordappclient.php');
require_once('./lib/pkp/lib/swordappv2/swordappentry.php');
require_once('./lib/pkp/lib/swordappv2/packager_mets_swap.php');

class OJSSwordDeposit {
	/** @var $package SWORD deposit METS package */
	var $package;

	/** @var $outPath Complete path and directory name to use for package creation files */
	var $outPath;

	/** @var $journal */
	var $journal;

	/** @var $section */
	var $section;

	/** @var $issue */
	var $issue;
	/** @var $language_dic	 */
	var $language_dic = array(
					'pt' => array('portugues','por','pt','ptpt','ptbr'),
					'en' =>array('eng','ing','english','en','ingles','enus') ,
					'es' =>array('esp','es','español','spanish','esar','eses'),
					'it' =>	array('it','ita','italian','italiano','itit'),
					'fr' => array('fr','french','fre','fra','frances','frca')
		);
	

	/**
	 * Constructor.
	 * Create a SWORD deposit object for an OJS article.
	 */
	function OJSSwordDeposit(&$article) {
		// Create a directory for deposit contents
		$this->outPath = tempnam('/tmp', 'sword');
		unlink($this->outPath);
		mkdir($this->outPath);
		mkdir($this->outPath . '/files');

		// Create a package
		$this->package = new PackagerMetsSwap(
			$this->outPath,
			'files',
			$this->outPath,
			'deposit.zip'
		);

		$journalDao =& DAORegistry::getDAO('JournalDAO');
		$this->journal =& $journalDao->getJournal($article->getJournalId());
		/** issue fix**/
		$issueDao = & DAORegistry::getDAO('IssueDAO'); 
		$this->issue = &$issueDao->getIssueById($article->getIssueId()); 
		/**end fix*/

		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		$this->section =& $sectionDao->getSection($article->getSectionId());

		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		$publishedArticle = $publishedArticleDao->getPublishedArticleByArticleId($article->getId());

		$issueDao =& DAORegistry::getDAO('IssueDAO');
		if ($publishedArticle) $this->issue =& $issueDao->getIssueById($publishedArticle->getIssueId());

		$this->article =& $article;
	}

	/**
	 * Register the article's metadata with the SWORD deposit.
	 */
	function setMetadata() {
		/**Journal title and issue number fix*/
		$realLink=$this->journal->getUrl().'/article/view/';
		$realLink.=$this->article->getBestArticleId($this->journal);	
		$this->package->setJtitle($this->journal->getLocalizedTitle());
		$this->package->setIssuenumber($this->issue->getIssueIdentification());
		/**end Fix*/

		//set ISSN, unlp fix
		$printIssn = $this->journal->getSetting('printIssn');
		$onlineIssn = $this->journal->getSetting('onlineIssn');
		if ($printIssn != NULL)	$this->package->addIdentifier("ISSN:".$printIssn);
		if ($onlineIssn != NULL) $this->package->addIdentifier("e-ISSN:".$onlineIssn);

		$this->package->setCustodian($this->journal->getSetting('contactName'));
		//Unlp fix for SEDICI
		$this->package->setType('http://purl.org/eprint/type/JournalArticle');
		//fix to add publication date, Language and realLink
		$date= $this->article->getDatePublished()!=null?$this->article->getDatePublished(): $this->issue->getDatePublished();
		$this->package->setDateAvailable($date);
		$preparedLanguage =$this->prepareLanguage($this->article->getLanguage());
		$this->package->setLanguage($preparedLanguage);
		$this->package->addIdentifier($realLink);
		//endFix
		

		// The article can be published or not. Support either. UNLP fix: support several identifiers
		if (is_a($this->article, 'PublishedArticle')) {
			$doi = $this->article->getPubId('doi');
			if ($doi !== null) $this->package->addIdentifier($doi);
		}

		foreach ($this->article->getAuthors() as $author) {
			$creator = $author->getFullName(true);
			//$affiliation = $author->getAffiliation($this->journal->getPrimaryLocale());
			//if (!empty($affiliation)) $creator .= "; $affiliation";-->dont need for SeDiCi
			$this->package->addCreator($creator);
			//FIX: delete affiliation
		}
		
		//FIX: use several titles 
		foreach($this->journal->getSupportedLocaleNames() as $locale => $localeName){
			$title = html_entity_decode($this->article->getTitle($locale), ENT_QUOTES, 'UTF-8');
			if($title!=""){
				$locale=$this->prepareLanguage($locale);
				$this->package->addTitle($title,$locale);}
			
		}
		//FIX: use several abstracts
		foreach($this->journal->getSupportedLocaleNames() as $locale => $localeName){
		$abstract=html_entity_decode(strip_tags($this->article->getAbstract($locale)), ENT_QUOTES, 'UTF-8');
		if($abstract!=""){
			$locale=$this->prepareLanguage($locale);
			$this->package->addAbstract($abstract,$locale);
			
		}
		}
		

		// The article can be published or not. Support either.
		if (is_a($this->article, 'PublishedArticle')) {
			$plugin =& PluginRegistry::loadPlugin('citationFormats', 'bibtex');
			$this->package->setCitation(html_entity_decode(strip_tags($plugin->fetchCitation($this->article, $this->issue, $this->journal)), ENT_QUOTES, 'UTF-8'));
		}

	}
		
		// ** UNLP fix for lengugage normalization
	function prepareLanguage($key) {
		$newKey = '';
		for($i = 0; $i < strlen ( $key ); $i ++) {
			if ((ord ( $key [$i] ) >= 65 && ord ( $key [$i] ) <= 90) || (ord ( $key [$i] ) <= 122 && ord ( $key [$i] ) >= 97))
				$newKey = $newKey . $key [$i];
		} // char to char handle for special chars
		$newKey = strtolower ( trim ( $newKey ) );
		// Search the apropiate language
		foreach ( $this->language_dic as $lan => $array ) {
			if (in_array ( $newKey, $array ))
				return $lan;
		}
		return 'es';
	}
	 
	/**
	 * Add a file to a package. Used internally.
	 */
	function _addFile(&$file) {
		$targetFilename = $this->outPath . '/files/' . $file->getFilename();
		copy($file->getFilePath(), $targetFilename);
		$this->package->addFile($file->getFilename(), $file->getFileType());
	}

	/**
	 * Add all article galleys to the deposit package.
	 */
	function addGalleys() {
		foreach ($this->article->getGalleys() as $galley) {
			$this->_addFile($galley);
		}
	}

	/**
	 * Add the single most recent editorial file to the deposit package.
	 * @return boolean true iff a file was successfully added to the package
	 */
	function addEditorial() {
		// Move through signoffs in reverse order and try to use them.
		foreach (array('SIGNOFF_LAYOUT', 'SIGNOFF_COPYEDITING_FINAL', 'SIGNOFF_COPYEDITING_AUTHOR', 'SIGNOFF_COPYEDITING_INITIAL') as $signoffName) {
			$file =& $this->article->getFileBySignoffType($signoffName);
			if ($file) {
				$this->_addFile($file);
				return true;
			}
			unset($file);
		}

		// If that didn't work, try the Editor Version.
		$sectionEditorSubmissionDao =& DAORegistry::getDAO('SectionEditorSubmissionDAO');
		$sectionEditorSubmission =& $sectionEditorSubmissionDao->getSectionEditorSubmission($this->article->getId());
		$file =& $sectionEditorSubmission->getEditorFile();
		if ($file) {
			$this->_addFile($file);
			return true;
		}
		unset($file);

		// Try the Review Version.
		$file =& $sectionEditorSubmission->getReviewFile();
		if ($file) {
			$this->_addFile($file);
			return true;
		}
		unset($file);

		// Otherwise, don't add anything (best not to go back to the
		// author version, as it may not be vetted)
		return false;
	}

	/**
	 * Build the package.
	 */
	function createPackage() {
		return $this->package->create();
	}

	/**
	 * Deposit the package.
	 * @param $url string SWORD deposit URL
	 * @param $username string SWORD deposit username (i.e. email address for DSPACE)
	 * @param $password string SWORD deposit password
	 */
	function deposit($url, $username, $password) {
		$client = new SWORDAPPClient();
		$response = $client->deposit(
			$url, $username, $password,
			'',
			$this->outPath . '/deposit.zip',
			//UNLPFIX	
			'http://purl.org/net/sword/package/METSDSpaceSIP',
			'application/zip', false, true
		);
		return $response;
	}

	/**
	 * Clean up after a deposit, i.e. removing all created files.
	 */
	function cleanup() {
		import('lib.pkp.classes.file.FileManager');
		$fileManager = new FileManager();

		$fileManager->rmtree($this->outPath);
	}
}

?>
