<?php

namespace DataAccounting\Override\Storage;

use JobQueueGroup;
use Language;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Content\Transform\ContentTransformer;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\RevisionRenderer;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRoleRegistry;
use MediaWiki\Storage\DerivedPageDataUpdater;
use MediaWiki\Storage\EditResultCache;
use MediaWiki\Storage\PageEditStash;
use MediaWiki\Storage\PageUpdater;
use MediaWiki\Storage\PageUpdaterFactory;
use MediaWiki\User\TalkPageNotificationManager;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;
use MessageCache;
use ParserCache;
use Psr\Log\LoggerInterface;
use TitleFormatter;
use WANObjectCache;
use Wikimedia\Rdbms\ILBFactory;
use WikiPage;

/**
 * Due to everything being private is parent class,
 * we need to pretty much copy-paste all of the code
 */
class DAPageUpdaterFactory extends PageUpdaterFactory {

	/** @var RevisionStore */
	private $revisionStore;

	/** @var RevisionRenderer */
	private $revisionRenderer;

	/** @var SlotRoleRegistry */
	private $slotRoleRegistry;

	/** @var ParserCache */
	private $parserCache;

	/** @var JobQueueGroup */
	private $jobQueueGroup;

	/** @var MessageCache */
	private $messageCache;

	/** @var Language */
	private $contLang;

	/** @var ILBFactory */
	private $loadbalancerFactory;

	/** @var IContentHandlerFactory */
	private $contentHandlerFactory;

	/** @var HookContainer */
	private $hookContainer;

	/** @var EditResultCache */
	private $editResultCache;

	/** @var UserNameUtils */
	private $userNameUtils;

	/** @var LoggerInterface */
	private $logger;

	/** @var ServiceOptions */
	private $options;

	/** @var UserEditTracker */
	private $userEditTracker;

	/** @var UserGroupManager */
	private $userGroupManager;

	/** @var TitleFormatter */
	private $titleFormatter;

	/** @var ContentTransformer */
	private $contentTransformer;

	/** @var PageEditStash */
	private $pageEditStash;

	/** @var TalkPageNotificationManager */
	private $talkPageNotificationManager;

	/** @var WANObjectCache */
	private $mainWANObjectCache;

	/** @var PermissionManager */
	private $permissionManager;

	/** @var string[] */
	private $softwareTags;

	/**
	 * @param RevisionStore $revisionStore
	 * @param RevisionRenderer $revisionRenderer
	 * @param SlotRoleRegistry $slotRoleRegistry
	 * @param ParserCache $parserCache
	 * @param JobQueueGroup $jobQueueGroup
	 * @param MessageCache $messageCache
	 * @param Language $contLang
	 * @param ILBFactory $loadbalancerFactory
	 * @param IContentHandlerFactory $contentHandlerFactory
	 * @param HookContainer $hookContainer
	 * @param EditResultCache $editResultCache
	 * @param UserNameUtils $userNameUtils
	 * @param LoggerInterface $logger
	 * @param ServiceOptions $options
	 * @param UserEditTracker $userEditTracker
	 * @param UserGroupManager $userGroupManager
	 * @param TitleFormatter $titleFormatter
	 * @param ContentTransformer $contentTransformer
	 * @param PageEditStash $pageEditStash
	 * @param TalkPageNotificationManager $talkPageNotificationManager
	 * @param WANObjectCache $mainWANObjectCache
	 * @param PermissionManager $permissionManager
	 * @param string[] $softwareTags
	 */
	public function __construct(
		RevisionStore $revisionStore,
		RevisionRenderer $revisionRenderer,
		SlotRoleRegistry $slotRoleRegistry,
		ParserCache $parserCache,
		JobQueueGroup $jobQueueGroup,
		MessageCache $messageCache,
		Language $contLang,
		ILBFactory $loadbalancerFactory,
		IContentHandlerFactory $contentHandlerFactory,
		HookContainer $hookContainer,
		EditResultCache $editResultCache,
		UserNameUtils $userNameUtils,
		LoggerInterface $logger,
		ServiceOptions $options,
		UserEditTracker $userEditTracker,
		UserGroupManager $userGroupManager,
		TitleFormatter $titleFormatter,
		ContentTransformer $contentTransformer,
		PageEditStash $pageEditStash,
		TalkPageNotificationManager $talkPageNotificationManager,
		WANObjectCache $mainWANObjectCache,
		PermissionManager $permissionManager,
		array $softwareTags
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->revisionStore = $revisionStore;
		$this->revisionRenderer = $revisionRenderer;
		$this->slotRoleRegistry = $slotRoleRegistry;
		$this->parserCache = $parserCache;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->messageCache = $messageCache;
		$this->contLang = $contLang;
		$this->loadbalancerFactory = $loadbalancerFactory;
		$this->contentHandlerFactory = $contentHandlerFactory;
		$this->hookContainer = $hookContainer;
		$this->editResultCache = $editResultCache;
		$this->userNameUtils = $userNameUtils;
		$this->logger = $logger;
		$this->options = $options;
		$this->userEditTracker = $userEditTracker;
		$this->userGroupManager = $userGroupManager;
		$this->titleFormatter = $titleFormatter;
		$this->contentTransformer = $contentTransformer;
		$this->pageEditStash = $pageEditStash;
		$this->talkPageNotificationManager = $talkPageNotificationManager;
		$this->mainWANObjectCache = $mainWANObjectCache;
		$this->permissionManager = $permissionManager;
		$this->softwareTags = $softwareTags;
	}

	/**
	 * Return a PageUpdater for building an update to a page.
	 *
	 * @internal For now, most code should keep using WikiPage::newPageUpdater() instead.
	 * @note We can only start using this method everywhere when WikiPage::prepareContentForEdit()
	 * and WikiPage::getCurrentUpdate() have been removed. For now, the WikiPage instance is
	 * used to make the state of an ongoing edit available to hook handlers.
	 *
	 * @param WikiPage $page
	 * @param UserIdentity $user
	 *
	 * @return PageUpdater
	 * @since 1.37
	 */
	public function newPageUpdater(
		WikiPage $page,
		UserIdentity $user
	): PageUpdater {
		return $this->newPageUpdaterForDerivedPageDataUpdater(
			$page,
			$user,
			$this->newDerivedPageDataUpdater( $page )
		);
	}

	/**
	 * Return a PageUpdater for building an update to a page, reusing the state of
	 * an existing DerivedPageDataUpdater.
	 *
	 * @param WikiPage $page
	 * @param UserIdentity $user
	 * @param DerivedPageDataUpdater $derivedPageDataUpdater
	 *
	 * @return PageUpdater
	 * @internal needed by WikiPage to back the WikiPage::newPageUpdater method.
	 *
	 * @since 1.37
	 */
	public function newPageUpdaterForDerivedPageDataUpdater(
		WikiPage $page,
		UserIdentity $user,
		DerivedPageDataUpdater $derivedPageDataUpdater
	): PageUpdater {
		$pageUpdater = new DAPageUpdater(
			$user,
			$page, // NOTE: eventually, PageUpdater should not know about WikiPage
			$derivedPageDataUpdater,
			$this->loadbalancerFactory->getMainLB(),
			$this->revisionStore,
			$this->slotRoleRegistry,
			$this->contentHandlerFactory,
			$this->hookContainer,
			$this->userEditTracker,
			$this->userGroupManager,
			$this->titleFormatter,
			new ServiceOptions(
				PageUpdater::CONSTRUCTOR_OPTIONS,
				$this->options
			),
			$this->softwareTags
		);

		$pageUpdater->setUsePageCreationLog( $this->options->get( 'PageCreationLog' ) );
		$pageUpdater->setUseAutomaticEditSummaries(
			$this->options->get( 'UseAutomaticEditSummaries' )
		);

		return $pageUpdater;
	}

	/**
	 * @param WikiPage $page
	 *
	 * @return DerivedPageDataUpdater
	 * @internal Needed by WikiPage to back the deprecated prepareContentForEdit() method.
	 * @note Avoid direct usage of DerivedPageDataUpdater.
	 * @see docs/pageupdater.md for more information.
	 */
	public function newDerivedPageDataUpdater( WikiPage $page ): DerivedPageDataUpdater {
		$derivedDataUpdater = new DerivedPageDataUpdater(
			$page, // NOTE: eventually, PageUpdater should not know about WikiPage
			$this->revisionStore,
			$this->revisionRenderer,
			$this->slotRoleRegistry,
			$this->parserCache,
			$this->jobQueueGroup,
			$this->messageCache,
			$this->contLang,
			$this->loadbalancerFactory,
			$this->contentHandlerFactory,
			$this->hookContainer,
			$this->editResultCache,
			$this->userNameUtils,
			$this->contentTransformer,
			$this->pageEditStash,
			$this->talkPageNotificationManager,
			$this->mainWANObjectCache,
			$this->permissionManager
		);

		$derivedDataUpdater->setLogger( $this->logger );
		$derivedDataUpdater->setArticleCountMethod( $this->options->get( 'ArticleCountMethod' ) );
		$derivedDataUpdater->setRcWatchCategoryMembership(
			$this->options->get( 'RCWatchCategoryMembership' )
		);

		return $derivedDataUpdater;
	}
}
