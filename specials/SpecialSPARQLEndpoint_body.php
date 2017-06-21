<?php

class SPARQLEndpoint extends SpecialPage {

	protected $sparqlendpointconfig;
	protected $sparqlendpoint;
	protected $sparqlparser;
	protected $storewrapper;

	protected $user;

	// TODO: Really keep the below ones as class variables?    

	function __construct() {
		parent::__construct( 'SPARQLEndpoint' );
		# Set up some stuff
		$this->sparqlendpointconfig = $this->getSPARQLEndpointConfig();
		$this->sparqlendpoint = ARC2::getStoreEndpoint( $this->sparqlendpointconfig );
		$this->sparqlparser = ARC2::getSPARQLPlusParser();
		$this->storewrapper = new RDFIOARC2StoreWrapper();
		$this->user = new RDFIOUser();
		$this->requestdata = null;
	}

	/**
	 * The main function
	 */
	function execute( $par ) {
		global $wgOut;

		$this->setHeaders();
		try {
			$this->requestdata = $this->handleRequestData();
		} catch ( Exception $e ) {
			$this->failMsg( $e->getMessage() );
		}

		if ( $this->hasSparqlQuery() ) {

			$this->ensureSparqlEndpointInstalled();

			if ( $this->requestdata->querybyequivuri ) {
				$this->urisToEquivURIsInQuery();
			}


			if ( $this->requestdata->querytype == '' ) {
				$wgOut->addHTML( "<b>ERROR: Could not determine query type!</b><br>It seems you have a problem with your query!" );
			} else {
				switch ( $this->requestdata->querytype ) {
					case 'insert':
						try {
							$this->importTriplesInQuery();
						} catch ( MWException $e ) {
							$this->failMsg( "ERROR: Could not perform import!" );
							$this->failMsg( "Error message:" );
							$this->failMsg( $e->getMessage() );
						}

						$this->printHTMLForm();
						break;
					case 'delete':
						if ( $this->checkAllowDelete() ) {
							$this->deleteTriplesInQuery();
						}
						// TODO Add a "successfully inserted/deleted" message here
						$this->printHTMLForm();
						break;
					default:
						switch ( $this->requestdata->outputtype ) {
							case 'htmltab':
								$this->printHTMLForm();
								$this->executeNonEditSparqlQuery();
								break;
							case 'rdfxml':
								if ( $this->requestdata->querytype != 'construct' ) {
									$wgOut->addHTML( RDFIOUtils::formatErrorHTML( "Invalid choice", "RDF/XML can only be used with CONSTRUCT, if constructing triples" ) );
									$this->printHTMLForm();
								} else {
									$this->prepareCreatingDownloadableFile();
									$this->executeNonEditSparqlQuery();
								}
								break;
							case 'xml':
								$this->prepareCreatingDownloadableFile();
								$this->executeNonEditSparqlQuery();
								break;
						}
				}
			}

		} else { // SPARQL query is empty
			$this->printHTMLForm();
		}
	}

	/**
	 * Execute method for SPARQL queries that only queries and returns results, but
	 * does not modify, add or delete triples.
	 */
	function executeNonEditSparqlQuery() {
		global $wgOut;

		$output = $this->passSparqlToARC2AndGetAsPhpSerialization();
		$outputtype = $this->determineOutputType();

		if ( $outputtype == 'rdfxml' ) {
			# Here the results should be RDF/XML triples,
			# not just plain XML SPARQL result set
			$outputStructure = unserialize( $output );
			$tripleindex = $outputStructure['result'];
			$triples = ARC2::getTriplesFromIndex( $tripleindex );

			if ( $this->requestdata->outputequivuris ) {

				// FIXME: Why is this uncommented???
				# $triples = $this->storewrapper->complementTriplesWithEquivURIsForProperties( $triples );
				$triples = $this->storewrapper->complementTriplesWithEquivURIs( $triples );
			}
			$output = $this->triplesToRDFXML( $triples );
			# Using echo instead of $wgOut->addHTML() here, since output format is not HTML
			echo $output;
		} else {
			// TODO: Add some kind of check that the output is really an object
			if ( count( $output ) > 0 ) {
				$outputStructure = unserialize( $output );
				if ( $this->requestdata->outputequivuris ) {
					$outputStructure = $this->urisToEquivURIsInSparqlResults( $outputStructure );
				}

				if ( $outputtype == 'htmltab' ) {
					$output = $this->sparqlResultToHTML( $outputStructure );
					$wgOut->addHTML( $output );
				} else {
					# Using echo instead of $wgOut->addHTML() here, since output format is not HTML
					$output = $this->sparqlendpoint->getSPARQLXMLSelectResultDoc( $outputStructure );
					echo $output;
				}
			} else {
				$wgOut->setHTML( "ERROR: No results from SPARQL query!" );
			}
		}
	}

	function shouldShowQuery() {
		global $wgRequest;
		return $wgRequest->getBool( 'showquery', false );
	}

	function passSparqlToARC2AndGetAsPhpSerialization() {
		# Make sure ARC2 returns a PHP serialization, so that we
		# can do stuff with it programmatically
		$this->setOutputTypeInPost( 'php_ser' );
		$this->sparqlendpoint->handleRequest();
		$this->handleSPARQLErrors();
		$output = $this->sparqlendpoint->getResult();
		return $output;
	}

	/**
	 * Determine the output type of the SPARQL query
	 */
	function determineOutputType() {
		$outputtype = $this->requestdata->outputtype;
		if ( $outputtype == '' && $this->requestdata->querytype == 'construct' ) {
			$outputtype = 'rdfxml';
		}
		return $outputtype;
	}

	function hasSparqlQuery() {
		return ( $this->requestdata->query != '' );
	}

	/**
	 * Take care of data from the request object and store
	 * in class variables
	 */
	function handleRequestData() {
		global $wgRequest,
			   $rogQueryByEquivURI,
			   $rogOutputEquivURIs;

		$requestData = new RDFIOSPARQLRequestData();

		$requestData->query = $wgRequest->getText( 'query' );

		if ( $rogQueryByEquivURI != '' ) {
			$requestData->querybyequivuri = $rogQueryByEquivURI;
		} else {
			$requestData->querybyequivuri = $wgRequest->getBool( 'equivuri_q' );
		}

		if ( $rogOutputEquivURIs != '' ) {
			$requestData->outputequivuris = $rogOutputEquivURIs;
		} else {
			$requestData->outputequivuris = $wgRequest->getBool( 'equivuri_o' );
		}

		$requestData->outputtype = $wgRequest->getText( 'output' );
		if ( $requestData->query !== '' ) {
			// Convert Sparql Update syntax to ARC2's SPARQL+ syntax:
			$reqDataSparqlPlus = str_replace( "INSERT DATA", "INSERT INTO <>", $requestData->query );
			// Parse the SPARQL query string into array structure
			$this->sparqlparser->parse( $reqDataSparqlPlus, '' );

			// Handle errors
			$errors = $this->sparqlparser->getErrors();
			foreach ( $errors as $error ) {
				throw new MWException( "Error parsing SPARQL query: " . $error );
			}

			$requestData->query_parsed = $this->sparqlparser->getQueryInfos();
			if ( array_key_exists( 'query', $requestData->query_parsed ) ) {
				$requestData->querytype = $requestData->query_parsed['query']['type'];
			}
		}
		return $requestData;
	}

	function ensureSparqlEndpointInstalled() {
		if ( !$this->sparqlendpoint->isSetUp() ) {
			$this->sparqlendpoint->setUp(); /* create MySQL tables */
		}
	}

	/**
	 * Modify the SPARQL pattern to allow querying using the original URI
	 */
	function urisToEquivURIsInQuery() {
		$queryStructure = $this->requestdata->query_parsed;
		$triple = $queryStructure['query']['pattern']['patterns'][0]['patterns'][0];
		$subj = $triple['s'];
		$prop = $triple['p'];
		$obj = $triple['o'];
		$subjType = $triple['s_type'];
		$propType = $triple['p_type'];
		$objType = $triple['o_type'];
		if ( $subjType === 'uri' ) {
			$triple['s'] = 's';
			$triple['s_type'] = 'var';
			$newtriple = $this->createEquivURITriple( $subj, 's' );
			// TODO: Shouldn't the new triple replace the old one, not just be added?
			$queryStructure['query']['pattern']['patterns'][0]['patterns'][] = $newtriple;
		}
		if ( $propType === 'uri' ) {
			$triple['p'] = 'p';
			$triple['p_type'] = 'var';
			$newtriple = $this->createEquivURITriple( $prop, 'p', true );
			$queryStructure['query']['pattern']['patterns'][0]['patterns'][] = $newtriple;
		}
		if ( $objType === 'uri' ) {
			$triple['o'] = 'o';
			$triple['o_type'] = 'var';
			$newtriple = $this->createEquivURITriple( $obj, 'o' );
			$queryStructure['query']['pattern']['patterns'][0]['patterns'][] = $newtriple;
		}
		// restore the first triple into its original location
		$queryStructure['query']['pattern']['patterns'][0]['patterns'][0] = $triple;
		require_once( __DIR__ . "/../bundle/ARC2_SPARQLSerializerPlugin.php" );
		$sparqlserializer = new ARC2_SPARQLSerializerPlugin( "<>", $this );
		$query = $sparqlserializer->toString( $queryStructure );

		$this->setQueryInPost( $query );
	}

	/**
	 * Create an RDF triple that links a wiki page to its corresponding
	 * equivalent URI
	 * @param string $uri
	 * @param string $varname
	 * @param boolean $isproperty
	 * @return array $equivuritriple
	 */
	function createEquivURITriple( $uri, $varname, $isproperty = false ) {
		if ( $isproperty ) {
			$equivuriuri = $this->storewrapper->getEquivPropertyURIURI();
		} else {
			$equivuriuri = $this->storewrapper->getEquivURIURI();
		}
		$equivuritriple = array(
			'type' => 'triple',
			's' => $varname,
			'p' => $equivuriuri,
			'o' => $uri,
			's_type' => 'var',
			'p_type' => 'uri',
			'o_type' => 'uri',
			'o_datatype' => '',
			'o_lang' => ''
		);
		return $equivuritriple;
	}

	/**
	 * Check if writing to wiki is allowed, and handle a number
	 * of exceptions to that, by showing error messages etc
	 */
	function checkAllowInsert() {
		global $wgOut;

		if ( $this->wrongEditTokenDetected() ) {
			$wgOut->addHTML( RDFIOUtils::formatErrorHTML( "Error", "Cross-site request forgery detected!" ) );
			return false;
		} else {
			if ( $this->user->hasWriteAccess() ) {
				return true;
			} else {
				$wgOut->addHTML( RDFIOUtils::formatErrorHTML( "Permission error", "The current user lacks access either to edit or create pages (or both) in this wiki." ) );
				return false;
			}
		}
	}

	/**
	 * Detect whether the edit token is not correct, even though remote editing is not permitted
	 * (in which case this check will not be done).
	 */
	function wrongEditTokenDetected() {
		global $rogAllowRemoteEdit, $wgRequest;
		if ( $rogAllowRemoteEdit == '' ) {
			$rogAllowRemoteEdit = false;
		}
		return ( !$rogAllowRemoteEdit &&
			!$this->user->editTokenIsCorrect( $wgRequest->getText( 'token' ) ) );
	}

	/**
	 * Check if deleting from wiki is allowed, and handle a number
	 * of exceptions to that, by showing error messages etc
	 */
	function checkAllowDelete() {
		global $wgRequest, $wgUser, $wgOut, $rogAllowRemoteEdit;
		if ( !$wgUser->matchEditToken( $wgRequest->getText( 'token' ) ) &&
			!$rogAllowRemoteEdit
		) {
			die( 'Cross-site request forgery detected!' );
		} else {
			if ( $this->user->hasDeleteAccess() || $rogAllowRemoteEdit ) {
				return true;
			} else {
				$errortitle = "Permission error";
				$errormessage = "The current user lacks access either to edit or delete pages (or both) in this wiki.";
				$wgOut->addHTML( RDFIOUtils::formatErrorHTML( $errortitle, $errormessage ) );
				return false;
			}
		}
	}

	/**
	 * Do preparations for getting outputted data as a downloadable file
	 * rather than written to the current page
	 */
	function prepareCreatingDownloadableFile() {
		global $wgOut;
		// Disable MediaWikis theming
		$wgOut->disable();
		// Enables downloading as a stream, which is important for large dumps
		wfResetOutputBuffers();
		// Send headers telling that this is a special content type
		// and potentially is to be downloaded as a file
		$this->sendHeadersForOutputType( $this->requestdata->outputtype );
	}

	/**
	 * Print out the HTML Form
	 */
	function printHTMLForm() {
		global $wgOut;
		$wgOut->addScript( $this->getHTMLFormScript() );
		$wgOut->addHTML( $this->getHTMLForm( $this->requestdata->query ) );
	}

	/**
	 * Extract the main content from ARC:s SPARQL result HTML
	 * and do some enhancing (wikify tables)
	 * @param string $output
	 * @return string $html
	 */
	function sparqlResultToHTML( $resultStructure ) {
		$html = "";
		$html = "<h3>Result:</h3><div style='font-size: 11px;'>" . $html . "</div>";
		$html .= "<table class=\"wikitable sortable\">";
		$result = $resultStructure['result'];
		$variables = $result['variables'];

		$html .= "<tr>";
		foreach ( $variables as $variable ) {
			$html .= "<th width='34%''>$variable</th>";
		}
		$html .= "</tr>";

		$rows = $result['rows'];
		foreach ( $rows as $row ) {
			$html .= "<tr>";
			foreach ( $variables as $variable ) {
				$value = $row[$variable];
				//$valueType = $row[$variable . ' type'];
				$html .= "<td style=\"font-size:9px!important;white-space:nowrap!important;\">" . $value . "</td>";
			}
			$html .= "</tr>";
		}

		$html .= "</table>";
		return $html;
	}

	/**
	 * After a query is parsed, import the parsed data to the wiki
	 */
	function importTriplesInQuery() {
		if ( $this->checkAllowInsert() ) {
			$triples = $this->requestdata->query_parsed['query']['construct_triples'];

			$rdfImporter = new RDFIORDFImporter();
			$rdfImporter->importTriples( $triples );
			$this->successMsg( "Successfully imported the triples!" );
		}
	}

	/**
	 * After a query is parsed, delete the parsed data from the wiki
	 */
	function deleteTriplesInQuery() {
		$triples = $this->requestdata->query_parsed['query']['construct_triples'];
		$rdfImporter = new RDFIOSMWBatchWriter( $triples, 'triples_array' );
		$rdfImporter->executeDelete();
	}

	/**
	 * Die and display current errors
	 */
	function handleSPARQLErrors() {
		global $wgOut;
		$sparqlEndpointErrors = $this->sparqlendpoint->getErrors();
		if ( count( $sparqlEndpointErrors ) > 0 ) {
			$errormessage = '';
			if ( is_array( $sparqlEndpointErrors ) ) {
				foreach ( $sparqlEndpointErrors as $sparqlEndpointError ) {
					$errormessage .= "<p>$sparqlEndpointError</p>";
				}
			} else {
				$errormessage = "<p>$sparqlEndpointErrors</p>";
			}
			$wgOut->addHTML( "SPARQL Error: " . $errormessage );
		}
	}

	/**
	 * Replace URI:s with an accompanying "Equivalent URI" with that one. If
	 * there are móre than one Equivalent URI for a given URI, the others than
	 * the first one will be ignored.
	 * @param array $sparqlResult
	 * @return array $sparqlResult
	 */
	function urisToEquivURIsInSparqlResults( $sparqlResult ) {
		$rows = $sparqlResult['result']['rows'];
		$sparqlVars = $sparqlResult['result']['variables'];
		foreach ( $rows as $rowid => $row ) {
			foreach ( $sparqlVars as $sparqlVar ) {
				$typeKey = "$sparqlVar type";
				$type = $row[$typeKey];
				$uri = $row[$sparqlVar];
				if ( $type === 'uri' ) {
					$equivURIs = $this->storewrapper->getEquivURIsForURI( $uri );
					if ( !RDFIOUtils::arrayEmpty( $equivURIs ) ) {
						$equivURI = $equivURIs[0];
						# Replace the URI with the Equivalent URI
						$rows[$rowid][$sparqlVar] = $equivURI;
					}
				}
			}
		}
		# Put back the modified rows into the results structure
		$sparqlResult['result']['rows'] = $rows;
		return $sparqlResult;
	}

	/**
	 * Convert an ARC triple index array structure into RDF/XML
	 * @param array $tripleindex
	 * @return string $rdfxml
	 */
	function tripleIndexToRDFXML( $tripleindex ) {
		$ser = ARC2::getRDFXMLSerializer(); // TODO: Choose format depending on user choice
		// Serialize into RDF/XML, since it will contain
		// all URIs in un-abbreviated form, so that they
		// can easily be replaced by search-and-replace
		$rdfxml = $ser->getSerializedIndex( $tripleindex );
		$errors = $ser->getErrors();
		foreach ( $errors as $error ) {
			throw new MWException( "ARC Serializer Error: " . $error );
		}
		return $rdfxml;
	}

	/**
	 * Convert an ARC triples array into RDF/XML
	 * @param array $triples
	 * @return string $rdfxml
	 */
	function triplesToRDFXML( $triples ) {
		$ser = ARC2::getRDFXMLSerializer(); // TODO: Choose format depending on user choice
		// Serialize into RDF/XML, since it will contain
		// all URIs in un-abbreviated form, so that they
		// can easily be replaced by search-and-replace
		$rdfxml = $ser->getSerializedTriples( $triples );
		if ( $ser->getErrors() ) {
			die( "ARC Serializer Error: " . $ser->getErrors() );
		}
		return $rdfxml;
	}

	function getPredicateVariableName() {
		$predVar = $this->requestdata->query_parsed['vars'][1];
		return $predVar;
	}

	/**
	 * Get a configuration array for initializing the ARCs
	 * SPARQL endpoint
	 */
	private function getSPARQLEndpointConfig() {
		global $smwgARC2StoreConfig;
		$epconfig = $smwgARC2StoreConfig;
		$epconfig['endpoint_features'] =
			array(
				'select',
				'construct',
				'ask',
				'describe',
				# 'load',
				# 'insert', // This is not needed, since it is done via SMWWriter instead
				# 'delete', // This is not needed, since it is done via SMWWriter instead
				# 'dump' /* dump is a special command for streaming SPOG export */
			);
		$epconfig['endpoint_timeout'] = 60; /* not implemented in ARC2 preview */
		# 'endpoint_read_key' => '', /* optional */
		# 'endpoint_write_key' => 'somekey', /* optional */
		# 'endpoint_max_limit' => 250, /* optional */

		return $epconfig;
	}

	/**
	 * Set headers appropriate to the filetype specified in $outputtype
	 * @param string $outputtype
	 */
	private function sendHeadersForOutputType( $outputtype ) {
		global $wgRequest;
		// Provide a sane filename suggestion
		$basefilename = 'SPARQLOutput_';
		switch ( $outputtype ) {
			case 'xml':
				$wgRequest->response()->header( "Content-type: application/xml; charset=utf-8" );
				$filename = urlencode( $basefilename . wfTimestampNow() . '.xml' );
				$wgRequest->response()->header( "Content-disposition: attachment;filename={$filename}" );
				break;
			case 'rdfxml':
				$wgRequest->response()->header( "Content-type: application/xml; charset=utf-8" );
				$filename = urlencode( $basefilename . wfTimestampNow() . '.rdf.xml' );
				$wgRequest->response()->header( "Content-disposition: attachment;filename={$filename}" );
				break;
			case 'json':
				$wgRequest->response()->header( "Content-type: text/html; charset=utf-8" );
				$filename = urlencode( $basefilename . wfTimestampNow() . '.json.txt' );
				$wgRequest->response()->header( "Content-disposition: attachment;filename={$filename}" );
				break;
			case 'turtle':
				$wgRequest->response()->header( "Content-type: text/html; charset=utf-8" );
				$filename = urlencode( $basefilename . wfTimestampNow() . '.turtle.txt' );
				$wgRequest->response()->header( "Content-disposition: attachment;filename={$filename}" );
				break;
			case 'htmltab':
				// For HTML table we are taking care of the output earlier
				# $wgRequest->response()->header( "Content-type: text/html; charset=utf-8" );
				# $filename = urlencode( $basefilename . wfTimestampNow() . '.html' );
				break;
			case 'tsv':
				$wgRequest->response()->header( "Content-type: text/html; charset=utf-8" );
				$filename = urlencode( $basefilename . wfTimestampNow() . '.tsv.txt' );
				$wgRequest->response()->header( "Content-disposition: attachment;filename={$filename}" );
				break;
			default:
				$wgRequest->response()->header( "Content-type: application/xml; charset=utf-8" );
				$filename = urlencode( $basefilename . wfTimestampNow() . '.xml' );
				$wgRequest->response()->header( "Content-disposition: attachment;filename={$filename}" );
		}

	}

	/**
	 * Get the HTML for the main SPARQL querying form. If $query is set, use it to prefill the main textarea
	 * @param string $query
	 * @return string $htmlForm
	 */
	private function getHTMLForm( $query = '' ) {
		global $wgArticlePath, $wgUser, $wgRequest;

		$uriResolverURI = SpecialPage::getTitleFor( 'URIResolver' )->getFullURL() . '/';

		$defaultQuery = "@PREFIX w : <$uriResolverURI> .\n\nSELECT *\nWHERE { ?s ?p ?o }\nLIMIT 25";

		if ( $query == '' ) {
			$query = $defaultQuery;
		}

		$checkedEquivUriQ = $wgRequest->getBool( 'equivuri_q', false ) == 1 ? ' checked="true" ' : '';
		$checkedEquivUriO = $wgRequest->getBool( 'equivuri_o', false ) == 1 ? ' checked="true" ' : '';
		$checkedFilterVocab = $wgRequest->getBool( 'filtervocab', false ) == 1 ? ' checked="true" ' : '';

		$selectedOutputHTML = $wgRequest->getText( 'output', '' ) == 'htmltab' ? ' selected="selected" ' : '';
		$selectedOutputRDFXML = $wgRequest->getText( 'output', '' ) == 'rdfxml' ? ' selected="selected" ' : '';

		// Make the HTML format selected by default
		if ( $selectedOutputRDFXML == '' ) {
			$selectedOutputHTML = ' selected="selected" ';
		}

		$htmlForm = '<form method="post" action="' . str_replace( '/$1', '', $wgArticlePath ) . '/Special:SPARQLEndpoint"
	        name="createEditQuery">
	        <div style="font-size: 10px">

	        <table border="0"><tbody>
	        <tr><td colspan="3">Enter SPARQL query:</td><tr>
	        <tr><td colspan="3"><textarea cols="80" rows="9" name="query">' . $query . '</textarea></td></tr>
	        <tr>
	        <td style="vertical-align: top; border-right: 1px solid #ccc;">

	        <table border="0" style="background: transparent; font-size: 11px;">
	        <tr><td style="text-align: right">Query by Equivalent URIs:</td>
	        <td>
			<input type="checkbox" name="equivuri_q" value="1" ' . $checkedEquivUriQ . '/>
	        </td></tr>
	        </table>

	        </td>
	        <td width="170" style="vertical-align: top; border-right: 1px solid #ccc;">

	        <table border="0" style="font-size: 11px; background: transparent;">
	        <tr><td style="text-align: right">Output Equivalent URIs:</td>
	        <td>
			<input type="checkbox" name="equivuri_o" id="outputequivuri" value="1" ' . $checkedEquivUriO /* . ' onChange="toggleDisplay(\'byontology\');" */ . '/>
	        </td></tr>
	        </table>

	        </td>
	        <td width="260" style="vertical-align: top;">
	        <table border="0" style="font-size: 11px; background: transparent;" >
	        <tr><td style="text-align: right" width="180">Output format:</td>
	        <td style="vertical-align: top">
	        <select id="output" name="output" onChange="toggleDisplay(\'byontology\');" >
	          <!-- <option value="" >default</option> -->
	          <!-- <option value="json" >JSON</option> -->
	          <!-- <option value="plain" >Plain</option> -->
	          <!-- <option value="php_ser" >Serialized PHP</option> -->
	          <!-- <option value="turtle" >Turtle</option> -->
	          <option value="htmltab" ' . $selectedOutputHTML . '>HTML</option>
	          <option value="xml" >XML Resultset</option>
	          <option value="rdfxml" ' . $selectedOutputRDFXML . '>RDF/XML</option>
	          <!-- <option value="infos" >Query Structure</option> -->
	          <!-- <option value="tsv" >TSV</option> -->
	        </select>
	        </td></tr>
	        <tr>
	        <td colspan="2">
	        <span style="font-family: arial, helvetica, sans-serif; font-size: 10px; color: #777;">(RDF/XML requires creating triples using <a href="http://www.w3.org/TR/rdf-sparql-query/#construct">CONSTRUCT</a>)</span>
	        </td>
	        </table>

	        </td>
	        </tr>
	        <tr>
	        <td colspan="3">

	        <div id="byontology" style="display: none; background: #ffd; border: 1px solid #ee7;">
	        <table border="0" style="font-size: 11px; background: transparent;" >
	        <tr><td style="text-align: right;">Filter by vocabulary:</td>
	        <td>
			<input type="checkbox" name="filtervocab" value="1" ' . $checkedFilterVocab . '/>
	        </td>
	        <td style="text-align: right">Vocabulary URL:</td>
	        <td>
			<input type="text" name="filtervocaburl" size="48" />
	        </td></tr>
	        <tr>
	        <td>&#160;</td>
	        <td>&#160;</td>
	        <td>&#160;</td>
	        <td>
	        <span style="font-family: arial, helvetica, sans-serif; font-size: 10px; color: #777">Example: http://xmlns.com/foaf/spec/index.rdf</span>
	        </td></tr>
			</table>
			</div>

	        </td>
	        </table>
			</div>

	        <input type="submit" value="Submit">' . Html::Hidden( 'token', $wgUser->getEditToken() ) . '
	        </form>';
		return $htmlForm;
	}

	/**
	 * Get the javascript used for some functionality in the main SPARQL
	 * querying HTML form
	 * @return string $htmlFormScript
	 */
	private function getHTMLFormScript() {
		$htmlFormScript = "<script type=\"text/javascript\">
	    function toggleDisplay(id1) {
	    	var bostyle = document.getElementById(id1).style.display;
	    	var fmtsel = document.getElementById('output');
	    	var fmt = fmtsel.options[fmtsel.selectedIndex].value;
	    	var outsel = document.getElementById('outputequivuri');
	    	if ( outsel.checked && fmt.match('rdfxml') ) {
				document.getElementById(id1).style.display = 'block';
			} else {
				document.getElementById(id1).style.display = 'none';
			}
		}
	 	</script>";
		return $htmlFormScript;
	}

	/**
	 * Get the query parameter from the request object
	 * @return string $query
	 */
	function getQuery() {
		global $wgRequest;
		$query = $wgRequest->getText( 'query' );
		return $query;
	}

	/**
	 * Update the query variable in the $_POST object.
	 * Useful for passing on parsing to ARC, since $_POST is what ARC reads
	 * @param string $query
	 */
	function setQueryInPost( $query ) {
		// Set the query in $_POST, so that ARC will grab the modified query
		$_POST['query'] = $query;
	}

	/**
	 * Update the output (type) variable in the $_POST object.
	 * Useful for passing on parsing to ARC, since $_POST is what ARC reads
	 * @param string $type
	 */
	function setOutputTypeInPost( $type ) {
		$_POST['output'] = $type;
	}

	function successMsg( $message ) {
		global $wgOut;
		$successHtml = RDFIOUtils::formatSuccessMessageHTML( "Success!", $message );
		$wgOut->addHTML( $successHtml );
	}

	function failMsg( $message ) {
		global $wgOut;
		$errorHtml = RDFIOUtils::formatErrorHTML( "Error!", $message );
		$wgOut->addHTML( $errorHtml );
	}

}

class RDFIOSPARQLRequestData {
	function __construct() {
		$this->query = '';
		$this->querytype = '';
		$this->querybyequivuri = false;
		$this->outputequivuris = false;
		$this->outputtype = '';
		$this->query_parsed = array();
	}
}
