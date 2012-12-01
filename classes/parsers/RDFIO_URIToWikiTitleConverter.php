<?php

class WikiTitleNotFoundException extends Exception { }

class RDFIOURIToTitleConverter { 

	protected $arc2Triples = null;
	protected $arc2ResourceIndex = null;
	protected $arc2NSPrefixes = null;
	protected $arc2Store = null;

	function __construct( $arc2Triples, $arc2ResourceIndex, $arc2NSPrefixes ) {
		$this->arc2Store = new RDFIOARC2StoreWrapper();

		# Store paramters as class variables
		$this->arc2Triples = $arc2Triples;
		$this->arc2ResourceIndex = $arc2ResourceIndex;
		$this->arc2NSPrefixes = $arc2NSPrefixes;
	}

	/**
	 * The main method, converting from URI:s to wiki titles.
	 * NOTE: Properties are taken care of py a special method below!
	 * @param string $uriToConvert
	 * @return string $wikiTitle
	 */
	public function convert( $uriToConvert ) {

		# Define the conversion functions to try, in 
		# specified order (the first one first).
		# You'll find them defined further below in this file.
		$uriToWikiTitleConversionStrategies = array(
			'getExistingTitleForURI',
			'applyGlobalSettingForPropertiesToUseAsWikiTitle',
			'shortenURINamespaceToAliasInSourceRDF',
			'extractLocalPartFromURI'
		);

		$wikiPageTitle = '';

		foreach ($uriToWikiTitleConversionStrategies as $currentStrategy ) {
			try {
				$wikiPageTitle = $this->$currentStrategy( $uriToConvert );	
				// DEBUG
				echo( "Succeeded to find title for <b>$uriToConvert</b> using " . $currentStrategy . "()<br>");
				return $wikiPageTitle;
			} catch ( WikiTitleNotFoundException $e ) {
				// echo( "Failed to find title for uri <b>$uriToConvert</b>: " . $e->getMessage() . "<br>" );
				// Continue ...
			}
		}

		if ( $wikiPageTitle == '' ) {
			throw new Exception("Failed to convert to Wiki Title: $uriToConvert");
		}
	}

	# CONVERSION STRATEGIES ######################################################################################

	/**
	 * URI to WikiTitle Strategy 1
	 */
	function getExistingTitleForURI( $uri ) {
		# 1. [x] Check if the uri exists as Equiv URI already (Overrides everything)
		$wikiTitle = $this->arc2Store->getWikiTitleByEquivalentURI( $uri );
		if ( $wikiTitle != '' ) {
			return $wikiTitle;
		} else {
			throw new WikiTitleNotFoundException("WikiTitle not found by getExistingTitleForURI()");
		}
	}

	/**
	 * URI to WikiTitle Strategy 2
	 */
	function applyGlobalSettingForPropertiesToUseAsWikiTitle( $uri ) {
		global $rdfiogPropertiesToUseAsWikiTitle;
		$wikiPageTitle = '';

		if ( !$this->globalSettingForPropertiesToUseAsWikiTitleExists() ) {
			$this->setglobalSettingForPropertiesToUseAsWikiTitleToDefult();
		}

		$index = $this->arc2ResourceIndex;
		if ( is_array($index) ) {
			foreach ( $index as $subject => $properties ) {
				if ( $subject === $uri ) {
					foreach ( $properties as $property => $object ) {
						if ( in_array( $property, $rdfiogPropertiesToUseAsWikiTitle ) ) {
							$wikiPageTitle = $object[0];
						}
					}
				}
			}
		}
		if ( $wikiPageTitle != '' ) {
			$wikiPageTitle = $this->removeInvalidChars( $wikiPageTitle );
		}
		if ( $wikiPageTitle != '' ) {
			return $wikiPageTitle;
		} else {
			throw new WikiTitleNotFoundException("WikiTitle not found by applyGlobalSettingForPropertiesToUseAsWikiTitle()");
		}
	}	

	/**
	 * URI to WikiTitle Strategy 3
	 */
	function shortenURINamespaceToAliasInSourceRDF( $uriToConvert ) {
		global $rdfiogBaseURIs;

		// 3. [x] Shorten the Namespace (even for entities, optionally) into an NS Prefix
		// according to mappings from parser (Such as chemInf:Blabla ...)
		$nsPrefixes = $this->arc2NSPrefixes;
		$wikiPageTitle = '';

		// 4. [x] The same, but according to mappings from LocalSettings.php
		if ( is_array( $rdfiogBaseURIs ) ) {
			$nsPrefixes = array_merge( $nsPrefixes, $rdfiogBaseURIs );
		}
		
		// DEPRECATED FOR NOW: 5. [ ] The same, but according to abbreviation screen

		// Collect all the inputs for abbreviation, and apply:
		if ( is_array( $nsPrefixes ) ) {
			$abbreviatedUri = $this->abbreviateParserNSPrefixes( $uriToConvert, $nsPrefixes );
			$wikiPageTitle = $abbreviatedUri;
		}

		if ( $wikiPageTitle != '' ) {
			return $wikiPageTitle;
		} else {
			throw new WikiTitleNotFoundException("WikiTitle not found by shortenURINamespaceToAliasInSourceRDF()");
		}	
	}

	/**
	 * URI to WikiTitle Strategy 4
	 */
	function extractLocalPartFromURI( $uriToConvert ) {
		// 6. [x] As a default, just try to get the local part of the URL
		$parts = $this->splitURI( $uriToConvert );
		if ( $parts[1] != "" ) {
			$wikiPageTitle = $parts[1];
		}

		if ( $wikiPageTitle != '' ) {
			return $wikiPageTitle;
		} else {
			throw new WikiTitleNotFoundException("WikiTitle not found by extractLocalPartFromURI()");
		}	
	}

	# HELPER METHODS #############################################################################################

	function globalSettingForPropertiesToUseAsWikiTitleExists() {
		return isset( $rdfiogPropertiesToUseAsWikiTitle );
	}
	function setglobalSettingForPropertiesToUseAsWikiTitleToDefult() {
		global $rdfiogPropertiesToUseAsWikiTitle;
		$rdfiogPropertiesToUseAsWikiTitle = array(
			'http://semantic-mediawiki.org/swivt/1.0#page', // Suggestion for new property
			'http://www.w3.org/2000/01/rdf-schema#label',
		    'http://purl.org/dc/elements/1.1/title',
		    'http://www.w3.org/2004/02/skos/core#preferredLabel',
		    'http://xmlns.com/foaf/0.1/name'
		);
	}

	function removeInvalidChars( $title ) {
		$title = str_replace('[', '', $title);
		$title = str_replace(']', '', $title);
		// TODO: Add more here later ...
		return $title;
	}

	function abbreviateParserNSPrefixes( $uri, $nsPrefixes ) {
		foreach ( $nsPrefixes as $namespace => $prefix ) {
			$nslength = strlen( $namespace );
			$basepart = '';
			$localpart = '';
			$uriContainsNamepace = substr( $uri, 0, $nslength ) === $namespace;
			if ( $uriContainsNamepace ) {
				$localpart = substr( $uri, $nslength );
				$basepart = $prefix;
			}
		}

		// ----------------------------------------------------
		// Take care of some special cases:
		// ----------------------------------------------------
		
		if ( $basepart === '' &&  $localpart === '' ) {
			$uriParts = $this->splitURI( $uri );
			$basepart = $uriParts[0];
			$localpart = $uriParts[1];
		}

		if ( $localpart === '' ) {
			$abbreviatedUri = $basepart;
		} elseif ( $this->startsWithUnderscore( $basepart ) ) {
			// FIXME: Shouldn't the above check the local part instead??

			// Change ARC:s default "random string", to indicate more clearly that
			// it lacks title
			$abbreviatedUri = str_replace( 'arc', 'untitled', $localpart );

		} elseif ( $this->startsWithHttpOrHttps( $basepart ) ) {
			// If the abbreviation does not seem to have succeeded,
			// fall back to use only the local part
			$abbreviatedUri = $localpart;

		} elseif ( $this->endsWithColon( $basepart ) ) {
			// Don't add another colon
			$abbreviatedUri = $basepart . $localpart;

		} elseif ( $basepart == false || $basepart == '' ) {
			$abbreviatedUri = $localpart;

		} else {
			$abbreviatedUri = $basepart . ':' . $localpart;

		}

		return $abbreviatedUri;
	}


	/**
	 * Customized version of the splitURI($uri) of the ARC2 library (http://arc.semsol.org)
	 * Splits a URI into its base part and local part, and returns them as an
	 * array of two strings
	 * @param string $uri
	 * @return array
	 */
	public function splitURI( $uri ) {
		global $rdfiogBaseURIs;
		/* ADAPTED FROM ARC2 WITH SOME MODIFICATIONS
		 * the following namespaces may lead to conflated URIs,
		 * we have to set the split position manually
		 */
		if ( strpos( $uri, 'www.w3.org' ) ) {
			$specials = array(
		        'http://www.w3.org/XML/1998/namespace',
		        'http://www.w3.org/2005/Atom',
		        'http://www.w3.org/1999/xhtml',
			);
			if ( $rdfiogBaseURIs != '' ) {
				$specials = array_merge( $specials, $rdfiogBaseURIs );
			}
			foreach ( $specials as $ns ) {
				if ( strpos( $uri, $ns ) === 0 ) {
					$local_part = substr( $uri, strlen( $ns ) );
					if ( !preg_match( '/^[\/\#]/', $local_part ) ) {
						return array( $ns, $local_part );
					}
				}
			}
		}
		/* auto-splitting on / or # */
		// $re = '^(.*?)([A-Z_a-z][-A-Z_a-z0-9.]*)$';
		if ( preg_match( '/^(.*[\#])([^\#]+)$/', $uri, $matches ) ) {
			return array( $matches[1], $matches[2] );
		}
		if ( preg_match( '/^(.*[\:])([^\:\/]+)$/', $uri, $matches ) ) {
			return array( $matches[1], $matches[2] );
		}
		if ( preg_match( '/^(.*[\/])([^\/]+)$/', $uri, $matches ) ) {
			return array( $matches[1], $matches[2] );
		}        /* auto-splitting on last special char, e.g. urn:foo:bar */
		return array( $uri, '' );
	}

	function startsWithUnderscore( $str ) {
		return substr( $str, 0, 1 ) === '_';
	}

	function startsWithHttpOrHttps( $str ) {
		return ( substr( $str, 0, 7 ) === 'http://' || substr( $str, 0, 8 ) == 'https://' );
	}

	function endsWithColon( $str ) {
		return ( substr( $str, -1 ) === ':' );
	}

}

#######################################################################################
# Class: RDFIOURIToWikiTitleConverter #################################################
#######################################################################################

class RDFIOURIToWikiTitleConverter extends RDFIOURIToTitleConverter {

}

#######################################################################################
# Class: RDFIOURIToWikiTitleConverter #################################################
#######################################################################################

class RDFIOURIToPropertyTitleConverter extends RDFIOURIToTitleConverter {

	/**
	 * The main method, which need some special handling.
	 * @param string $propertyURI
	 * @return string $propertyTitle
	 */
	function convert( $propertyURI ) {
		$propertyTitle = '';
		$existingPropTitle = $this->arc2Store->getWikiTitleByEquivalentURI($propertyURI, $is_property=true);
		if ( $existingPropTitle != "" ) {
			// If the URI had an existing title, use that
			$propertyTitle = $existingPropTitle;
		} else {
			$uriToWikiTitleConverter = new RDFIOURIToWikiTitleConverter( $this->arc2Triples, $this->arc2ResourceIndex, $this->arc2NSPrefixes );
			$propertyTitle = $uriToWikiTitleConverter->convert( $propertyURI );
		}
		$propertyTitle = $this->removeInvalidChars( $propertyTitle );
		return $propertyTitle;
	}

}	

?>