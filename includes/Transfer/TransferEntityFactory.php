<?php

namespace DataAccounting\Transfer;

use DataAccounting\ServerInfo;
use DataAccounting\Verification\Entity\GenericDatabaseEntity;
use DataAccounting\Verification\VerificationEngine;
use DataAccounting\Verification\Entity\VerificationEntity;
use DataAccounting\Verification\WitnessingEngine;
use Language;
use MediaWiki\MediaWikiServices;
use NamespaceInfo;
use Title;
use TitleFactory;

class TransferEntityFactory {
	/** @var VerificationEngine */
	private $verificationEngine;
	/** @var WitnessingEngine */
	private $witnessingEngine;
	/** @var TitleFactory */
	private $titleFactory;
	/** @var Language */
	private $language;
	/** @var NamespaceInfo */
	private $nsInfo;
	/** @var array|null */
	private $siteInfo = null;

	/**
	 * @param VerificationEngine $engine
	 * @param WitnessingEngine $witnessingEngine
	 * @param TitleFactory $titleFactory
	 * @param Language $contentLang
	 * @param NamespaceInfo $nsInfo
	 */
	public function __construct(
		VerificationEngine $engine,
		WitnessingEngine $witnessingEngine,
		TitleFactory $titleFactory,
		Language $contentLang,
		NamespaceInfo $nsInfo
	) {
		$this->verificationEngine = $engine;
		$this->titleFactory = $titleFactory;
		$this->witnessingEngine = $witnessingEngine;
		$this->language = $contentLang;
		$this->nsInfo = $nsInfo;
	}

	/**
	 * @param array $data
	 * @return TransferContext|null
	 */
	public function newTransferContextFromData( array $data ): ?TransferContext {
		if (
			isset( $data['site_info'] ) && is_array( $data['site_info'] ) &&
			isset( $data['title'] ) && isset( $data['namespace'] )
		) {
			$title = $this->titleFactory->makeTitle( $data['namespace'], $data['title'] );
			if ( !( $title instanceof \Title ) ) {
				return null;
			}
			return new TransferContext(
				$data[VerificationEntity::GENESIS_HASH],
				$data[VerificationEntity::DOMAIN_ID],
				$data['latest_verification_hash'] ?? '',
				$data['site_info'],
				$title,
				$data['chain_height'] ?? 0
			);
		}

		return null;
	}

	public function newTransferContextFromTitle( \Title $title ): ?TransferContext {
		$entity = $this->verificationEngine->getLookup()->verificationEntityFromTitle( $title );
		if ( !$entity ) {
			return null;
		}

		return $this->newTransferContextFromData( [
			VerificationEntity::GENESIS_HASH => $entity->getHash( VerificationEntity::GENESIS_HASH ),
			VerificationEntity::DOMAIN_ID => $entity->getDomainId(),
			'latest_verification_hash' => $entity->getHash( VerificationEntity::VERIFICATION_HASH ),
			'site_info' => $this->getSiteInfo(),
			'title' => $entity->getTitle()->getDBkey(),
			'namespace' => $entity->getTitle()->getNamespace(),
			'chain_height' => $this->verificationEngine->getPageChainHeight(
				$title
			),
		] );
	}

	/**
	 * @return array
	 */
	public function getSiteInfo(): array {
		if ( $this->siteInfo === null ) {
			$config = MediaWikiServices::getInstance()->getMainConfig();

			$nsList = [];
			foreach ( $this->language->getFormattedNamespaces() as $ns => $title ) {
				$nsList[$ns] = [
					'case' => $this->nsInfo->isCapitalized( $ns ),
					'title' => $title
				];
			}
			$this->siteInfo = [
				'sitename' => $config->get( 'Sitename' ),
				'dbname' => $config->get( 'DBname' ),
				'base' => Title::newMainPage()->getCanonicalURL(),
				'generator' => 'MediaWiki ' . MW_VERSION,
				'case' => $config->get( 'CapitalLinks' ) ? 'first-letter' : 'case-sensitive',
				'namespaces' => $nsList,
				'version' => ServerInfo::DA_API_VERSION
			];
		}

		return $this->siteInfo;
	}

	/**
	 * @param array $data
	 * @return TransferRevisionEntity|null
	 */
	public function newRevisionEntityFromApiData( array $data ): ?TransferRevisionEntity {
		if (
			isset( $data['verification_context'] ) && is_array( $data['verification_context'] ) &&
			isset( $data['content'] ) && is_array( $data['content'] ) &&
			isset( $data['metadata'] ) && is_array( $data['metadata'] ) &&
			isset( $data['signature'] ) && is_array( $data['signature'] )
		) {
			return new TransferRevisionEntity(
				$data['verification_context'],
				$data['content'],
				$data['metadata'],
				$data['signature'],
				$data['witness'] ?? null
			);
		}

		return null;
	}

	/**
	 * @param VerificationEntity $entity
	 * @return TransferRevisionEntity
	 */
	public function newRevisionEntityFromVerificationEntity(
		VerificationEntity $entity
	): TransferRevisionEntity {
		$contentOutput = [
			'rev_id' => $entity->getRevision()->getId(),
			'content' => $this->prepareContent( $entity ),
			'content_hash' => $entity->getHash( VerificationEntity::CONTENT_HASH ),
		];

		if ( $entity->getRevision()->getPage()->getNamespace() === NS_FILE ) {
			$file = $this->verificationEngine->getFileForVerificationEntity( $entity );
			if ( $file instanceof \File ) {
				$content = file_get_contents( $file->getLocalRefPath() );
				if ( is_string( $content ) ) {
					$contentOutput['file'] = [
						'data' => base64_encode( $content ),
						'filename' => $file->getName(),
						'size' => $file->getSize(),
						'comment' => $entity->getRevision()->getComment()->text,
					];
				}
			}
		}

		$metadataOutput = [
			'domain_id' => $entity->getDomainId(),
			'time_stamp' => $entity->getTime()->format( 'YmdHis' ),
			'previous_verification_hash' => $entity->getHash( VerificationEntity::PREVIOUS_VERIFICATION_HASH ),
			'metadata_hash' => $entity->getHash( VerificationEntity::METADATA_HASH ),
			'verification_hash' => $entity->getHash( VerificationEntity::VERIFICATION_HASH )
		];

		$signatureOutput = [
			'signature' => $entity->getSignature(),
			'public_key' => $entity->getPublicKey(),
			'wallet_address' => $entity->getWalletAddress(),
			'signature_hash' => $entity->getHash( VerificationEntity::SIGNATURE_HASH ),
		];

		$witnessOutput = null;
		if ( $entity->getWitnessEventId() ) {
			$witnessEntity = $this->witnessingEngine->getLookup()->witnessEventFromVerificationEntity( $entity );
			if ( $witnessEntity instanceof GenericDatabaseEntity ) {
				$witnessOutput = $witnessEntity->jsonSerialize();
				$witnessOutput['structured_merkle_proof'] =
					$this->witnessingEngine->getLookup()->requestMerkleProof(
						$entity->getWitnessEventId(),
						$entity->getHash( VerificationEntity::VERIFICATION_HASH )
					);
			}
		}

		return new TransferRevisionEntity(
			$entity->getVerificationContext(),
			$contentOutput,
			$metadataOutput,
			$signatureOutput,
			$witnessOutput
		);
	}

	/**
	 * Collect and compile content of all slots
	 *
	 * @param VerificationEntity $entity
	 * @return array
	 */
	private function prepareContent( VerificationEntity $entity ) {
		// Important! We sort the slot array alphabetically [1], to make it
		// consistent with canonical JSON (see
		// https://datatracker.ietf.org/doc/html/rfc8785).
		// [1] Actually, it is
		// > MUST order the members of all objects lexicographically by the UCS
		// (Unicode Character Set) code points of their names.
		$slots = $entity->getRevision()->getSlotRoles();
		sort($slots);
		$merged = [];
		foreach ( $slots as $role ) {
			$slot = $entity->getRevision()->getSlot( $role );
			if ( !$slot->getContent() ) {
				continue;
			}
			$merged[$role] = $slot->getContent()->serialize();
		}

		return $merged;
	}
}
