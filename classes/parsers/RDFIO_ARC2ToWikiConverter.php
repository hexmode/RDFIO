<?php

class RDFIOARC2ToWikiConverter extends RDFIOParser {
	
	protected $mARC2ResourceIndex = null;
	protected $mWikiPages = null;
	protected $mPropPages = null;
	protected $mArc2Store = null;
	
	public function __construct() {
		$this->mArc2Store = new RDFIOARC2StoreWrapper();
	}
	
	public function parseData( $arc2Triples, $arc2ResourceIndex, $arc2NSPrefixes ) {
		
		$this->mARC2ResourceIndex = $arc2ResourceIndex;
		
		$wikiPages = array();
		$propPages = array();
		
		foreach ( $arc2Triples as $triple ) {
			
			$subjURI = $triple['s'];
			$propURI = $triple['p'];
			$objURI = $triple['o'];

			# Convert URI:s to wiki titles
			$wikiTitle = $this->getWikiTitleFromURI($subjURI);
			$propTitle = $this->getPropertyWikiTitleFromURI($triple['p']);
			$propTitleWithNS = 'Property:' . $propTitle; 
			$objTitle = $this->getWikiTitleFromURI($triple['o']);
			
			$fact = array( 'p' => $propTitle, 'o' => $objTitle );
				
			$wikiPages = $this->mergeIntoPagesArray( $wikiTitle, $subjURI, $fact, $wikiPages );
			$propPages = $this->mergeIntoPagesArray( $propTitleWithNS, $propURI, null, $propPages );
			# if o is an URI, also create object page
			if ( $triple['o_type'] == "uri" ) {
				// @TODO: Should the o_type also decide data type of the property (i.e. page, or value?)
				$wikiPages = $this->mergeIntoPagesArray( $objTitle, $objURI, null, $wikiPages );
			} 
			
		}
		# Store in class variable
		$this->mWikiPages = $wikiPages;
		$this->mPropPages = $propPages;
	}
	
	public function getWikiPages() {
		return $this->mWikiPages;
	}

	public function getPropertyPages() {
		return $this->mPropPages;
	}

	// PRIVATE FUNCTIONS
	
	private function mergeIntoPagesArray( $pageTitle, $equivURI, $fact = null, $pagesArray ) {
		if ( !array_key_exists($pageTitle, $pagesArray) ) {
			$page = array();
			$page['equivuris'] = array( $equivURI );
			if ( $fact != null ) {
				$page['facts'] = array( $fact );
			} else {
				$page['facts'] = array();
			}
			$pagesArray[$pageTitle] = $page;
		} else {
			# Just merge data into existing page
			$page = $pagesArray[$pageTitle];
			$page['equivuris'][] = $equivURI;
			if ( $fact != null ) {
				$page['facts'][] = $fact;
			}
		}
		return $pagesArray;
	}
	
	private function getWikiTitleFromURI( $uri ) {
		# @TODO: Create some "conversion index", from URI:s to wiki titles?
		
		global $rdfiogPropertiesToUseAsWikiTitle;
		
		if ( !isset( $rdfiogPropertiesToUseAsWikiTitle ) ) {
			// Some defaults
			$rdfiogPropertiesToUseAsWikiTitle = array(
				'http://semantic-mediawiki.org/swivt/1.0#page', // Suggestion for new property
            	'http://www.w3.org/2000/01/rdf-schema#label',
		        'http://purl.org/dc/elements/1.1/title',
		        'http://www.w3.org/2004/02/skos/core#preferredLabel',
		        'http://xmlns.com/foaf/0.1/name'
            );
		}
		
		/**
		 * This is how to do it:
		 *
		 * 1. [ ] Check if the uri exists as Equiv URI already (Overrides everything)
		 * 2. [ ] Apply facts suitable for naming (such as dc:title, rdfs:label, skos:prefLabel etc...)
		 * 3. [ ] Shorten the Namespace (even for entities, optionally) into an NS Prefix
		 *        according to mappings from parser (Such as chenInf:Blabla ...)
		 * 4. [ ] The same, but according to mappings from LocalSettings.php
		 * 5. [ ] The same, but according to abbreviation screen
		 *
		 *    (In all the above, keep properties and normal entities separately)
		 *
		 */
		
		$wikiTitle = "";
		$wikiTitle = preg_replace("/http.*\//", "", $uri); // @FIXME Dummy method for testing
		return $wikiTitle;
	}
	
	private function getPropertyWikiTitleFromURI( $uri ) {
		$propWikiTitle = $this->getWikiTitleFromURI($uri);
		return $propWikiTitle;
	}
	
	//
	// ---------- SOME JUNK THAT MIGHT BE USED OR NOT ----------------
	//
	
	public function tryToGetExistingWikiTitleForURI( $uri ) {
		$wikititle = $this->getArc2Store()->getWikiTitleByOriginalURI( $uri );
		return $wikititle;
	}

	public static function startsWithUnderscore( $str ) {
		return substr( $str, 0, 1 ) == '_';
	}
	public static function startsWithHttpOrHttps( $str ) {
		return ( substr( $str, 0, 7 ) == 'http://' || substr( $str, 0, 8 ) == 'https://' );
	}
	public static function endsWithColon( $str ) {
		return substr( $str, -1 ) == ':';
	}

	public function abbreviateWithNamespacePrefixesFromParser( $uri ) {
		$nsPrefixesFromParser = $this->getNamespacePrefixesFromParser();
		foreach ( $nsPrefixesFromParser as $namespace => $prefix ) {
			$nslength = strlen( $namespace );
			$basepart = '';
			$localpart = '';
			$uriContainsNamepace = substr( $uri, 0, $nslength ) === $namespace;
			if ( $uriContainsNamepace ) {
				$localpart = substr( $uri, $nslength );
				$basepart = $prefix;
			}
		}

		# ----------------------------------------------------
		# Take care of some special cases:
		# ----------------------------------------------------
		
		if ( $basepart == '' &&  $localpart == '' ) {
			$uriParts = $this->splitURIIntoBaseAndLocalPart( $uri );
			$basepart = $uriParts[0];
			$localpart = $uriParts[1];
		}

		if ( $localpart == '' ) {
			$abbreviatedUri = $basepart;
		} elseif ( RDFIOURIToWikiTitleConverter::startsWithUnderscore( $basepart ) ) {
			// FIXME: Shouldn't the above check the local part instead??

			// Change ARC:s default "random string", to indicate more clearly that
			// it lacks title
			$abbreviatedUri = str_replace( 'arc', 'untitled', $localpart );

		} elseif ( RDFIOURIToWikiTitleConverter::startsWithHttpOrHttps( $basepart ) ) {
			// If the abbreviation does not seem to have succeeded,
			// fall back to use only the local part
			$abbreviatedUri = $localpart;

		} elseif ( RDFIOURIToWikiTitleConverter::endsWithColon( $basepart ) ) {
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
     * Use a "natural language" property, such as dc:title or similar, as wiki title
     * @param string $subject
     * @return string $wikiTitle
     */
    function getWikiTitleByNaturalLanguageProperty( $subjectURI ) {
        // Looks through, in order, the uri:s in $this->m_wikititlepropertyuris
        // to see if any of them is set for $subjectURI. if so, return corresponding
        // value as title.
        // FIXME: Update to work with RDFIO2 Data structures
        $wikiTitle = '';
        $naturalLanguagePropertyURIs = $this->getNaturalLanguagePropertyURIs();
        foreach ( $naturalLanguagePropertyURIs as $naturalLanguagePropertyURI ) {
        	$importedDataAggregate = $this->getCurrentURIObject()->getOwningDataAggregate();
        	$subjectData = $importedDataAggregate->getSubjectDataFromURI( $subjectURI );
        	if ( isset( $subjectData ) )
        		$fact = $subjectData->getFactFromPropertyURI( $naturalLanguagePropertyURI );
        	if ( isset( $fact ) )
        		$wikiTitle = $fact->getObject()->getAsText();
            if ( !empty( $wikiTitle ) ) {
                // When we have found a "$naturalLanguagePropertyURI" that matches,
                // return the value immediately
                return $wikiTitle;
            }
        }
        return $wikiTitle;
    }

	/**
	 * Customized version of the splitURI($uri) of the ARC2 library (http://arc.semsol.org)
	 * Splits a URI into its base part and local part, and returns them as an
	 * array of two strings
	 * @param string $uri
	 * @return array
	 */
	public function splitURIIntoBaseAndLocalPart( $uri ) {
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

	# Convenience methods

	public function isURIResolverURI( $uri ) {
		return ( preg_match( '/Special:URIResolver/', $uri ) > 0 );
	}
	
}
