<?php

/**
 * Behavior description of Special Page Data Accounting Config
 * All configuration settings for all modules are set here. Following settings can be adujusted:
 * Module 1: Page Verification
 *          No Settings
 * Module 2: Verify Page History 
 *          No Settings - Is now the external verifier. Settings for the external verifier have been moved into the chrome extension.
 * Module 3: Export / Import of Verified Page History
 *          No Settings
 * Module 4: Witnessing
 *          Set Witness Smart Contract (For SpecialPage:WitnessPublisher)
 *          Set Witness Network (For SpecialPage:WitnessPublisher)
 */
namespace MediaWiki\Extension\Example;

use HTMLForm;

require_once('Util.php');

class SpecialDataAccountingConfig extends \SpecialPage {

	public function __construct() {
		parent::__construct( 'DataAccountingConfig' );
	}

	/**
	 * Show the page
	 * @param string|null $par
	 */
	public function execute( $par = null ) {
		$this->setHeaders();
		$out = "HERE CHANGE ME<br>";

		$out .= "Domain ID: " . getDomainId();

		$this->getOutput()->addWikiTextAsInterface( $out );
		$this->getOutput()->setPageTitle( 'Data Accounting Configuration' );

		$formDescriptor = [
			'smartcontractaddress' => [
				'label' => 'Smart Contract Address:', // Label of the field
				'class' => 'HTMLTextField', // Input type
				'default' => '0x45f59310ADD88E6d23ca58A0Fa7A55BEE6d2a611',
			],
			'witnessnetwork' => [
				'label' => 'Witness Network:',
				'class' => 'HTMLTextField', // Input type
				'default' => 'Goerli Test Network',
			],
		];

        $htmlForm = new HTMLForm( $formDescriptor, $this->getContext(), 'saveWitnessConfig' );
        $htmlForm->setSubmitText( 'Save' );
		$htmlForm->setSubmitCallback( [ $this, 'saveWitnessConfig' ] );
		$htmlForm->show();
	}

	public static function saveWitnessConfig( $formData ) {
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbw = $lb->getConnectionRef( DB_MASTER );

        $witness_event_id = $dbw->selectRow(
            'witness_page',
            [ 'max(witness_event_id) as witness_event_id' ],
            [ 'source' => 'default' ],
            __METHOD__,
        )->witness_event_id;


		$res = $dbr->select(
			'page_verification',
			[ 'MAX(rev_id) as rev_id', 'page_title', 'hash_verification' ],
			'',
			__METHOD__,
			[ 'GROUP BY' => 'page_title']
		);
	}
}
