<?php

namespace DataAccounting\API;

use DataAccounting\TransclusionManager;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Revision\RevisionStore;
use RequestContext;
use Title;
use TitleFactory;
use User;
use Wikimedia\ParamValidator\ParamValidator;

class TransclusionHashUpdater extends SimpleHandler {
	/** @var PermissionManager */
	private PermissionManager $permissionManager;
	/** @var TransclusionManager */
	private TransclusionManager $transclusionManager;
	/** @var TitleFactory */
	private TitleFactory $titleFactory;
	/** @var RevisionStore  */
	private RevisionStore $revisionStore;
	private User $user;

	/**
	 * @param TransclusionManager $transclusionManager
	 * @param TitleFactory $titleFactory
	 * @param RevisionStore $revisionStore
	 */
	public function __construct(
		TransclusionManager $transclusionManager, TitleFactory $titleFactory,
		RevisionStore $revisionStore, PermissionManager $permissionManager
	) {
		$this->transclusionManager = $transclusionManager;
		$this->titleFactory = $titleFactory;
		$this->revisionStore = $revisionStore;
		$this->permissionManager = $permissionManager;
		$this->user = RequestContext::getMain()->getUser();
	}

	/** @inheritDoc */
	public function run( $pageTitle, $resourcePage ) {
		$subject = $this->titleFactory->newFromText( $pageTitle );
		if ( !( $subject instanceof Title ) || !$subject->exists() ) {
			throw new HttpException( 'Invalid subject title' );
		}
		if ( !$this->permissionManager->userCan( 'move', $this->user, $subject ) ) {
			throw new HttpException( "Permission denied", 401 );
		}
		// Always operate on latest revision
		$latestRevision = $this->revisionStore->getRevisionByTitle( $subject );
		if ( $latestRevision === null ) {
			throw new HttpException( 'Could not retrieve revision for ' . $subject->getPrefixedDBkey() );
		}
		$res = $this->transclusionManager->updateResource(
			$latestRevision, $resourcePage, $this->user
		);

		return $this->getResponseFactory()->createJson( [
			'success' => $res
		] );
	}

	/** @inheritDoc */
	public function needsWriteAccess() {
		return true;
	}

	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'page_title' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'resource' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}
}
