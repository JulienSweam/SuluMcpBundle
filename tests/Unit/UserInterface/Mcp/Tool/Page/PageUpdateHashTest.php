<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Page;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Component\Hash\AuditableHasher;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Content\Application\ContentHash\ContentHashChecker;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Application\ContentMerger\ContentMerger;
use Sulu\Content\Application\ContentMerger\Merger\AuditableMerger;
use Sulu\Content\Domain\Model\DimensionContentCollection;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Metadata\ExtensionFormKeys;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageUpdateTool;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class PageUpdateHashTest extends TestCase
{
    public function testUpdateUsesPersistedDraftHashInsteadOfMergedHash(): void
    {
        [$result, $data, $expectedHash] = $this->updatePage(true);

        self::assertTrue($result['success']);
        self::assertSame($expectedHash, $data['_hash']);
        self::assertSame('2025', $data['year']);
        self::assertFalse($data['seoNoIndex']);
    }

    public function testNewTranslationDoesNotSendAHash(): void
    {
        [$result, $data] = $this->updatePage(false);

        self::assertTrue($result['success']);
        self::assertTrue($result['created_locale']);
        self::assertArrayNotHasKey('_hash', $data);
    }

    public function testAChangedDraftStillFailsTheHashCheck(): void
    {
        [$result] = $this->updatePage(true, true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('does not match the current hash', $result['error']);
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>|null, string|null}
     */
    private function updatePage(bool $hasTranslation, bool $changeBeforeCheck = false): array
    {
        $page = new Page();
        $page->setWebspaceKey('website');
        $global = $page->createDimensionContent();
        $global->setChanged(new \DateTimeImmutable('@1000'));
        $user = $this->createStub(UserInterface::class);
        $user->method('getId')->willReturn(42);
        $global->setChanger($user);
        $page->addDimensionContent($global);

        $draft = null;
        if ($hasTranslation) {
            $draft = $page->createDimensionContent();
            $draft->setLocale('fr');
            $draft->setChanged(new \DateTimeImmutable('@2000'));
            $page->addDimensionContent($draft);
        }

        $attributes = ['locale' => 'fr', 'stage' => 'draft'];
        $collection = new DimensionContentCollection($page->getDimensionContents(), $attributes, $global::class);
        $merged = (new ContentMerger([new AuditableMerger()], PropertyAccess::createPropertyAccessor()))->merge($collection);
        $hasher = new AuditableHasher();
        $expectedHash = $draft ? $hasher->hash($draft) : null;
        if ($draft) {
            self::assertNotSame($expectedHash, $hasher->hash($merged));
        }

        $contentManager = $this->createStub(ContentManagerInterface::class);
        $contentManager->method('resolve')->willReturn($merged);
        $contentManager->method('normalize')->willReturn([
            '_hash' => $hasher->hash($merged),
            'template' => 'oeuvre',
            'title' => 'Test',
        ]);
        $repository = $this->createStub(PageRepositoryInterface::class);
        $repository->method('getOneBy')->willReturn($page);
        $metadataProvider = $this->createStub(MetadataProviderInterface::class);
        $metadataProvider->method('getMetadata')->willThrowException(new \RuntimeException('No test metadata'));
        $localeResolver = new MetadataLocaleResolver($this->createStub(TokenStorageInterface::class), 'fr');
        $capturedData = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function(Envelope $envelope) use (&$capturedData, $page, $draft, $changeBeforeCheck, $hasher, $attributes): Envelope {
                $message = $envelope->getMessage();
                self::assertInstanceOf(ModifyPageMessage::class, $message);
                $capturedData = $message->getData();
                if ($changeBeforeCheck && $draft) {
                    $draft->setChanged(new \DateTimeImmutable('@2001'));
                }
                (new ContentHashChecker($hasher))->checkHash($capturedData, $page, $attributes, $page->getId());

                return $envelope->with(new HandledStamp($page, 'test'));
            },
        );

        $tool = new PageUpdateTool(
            $bus,
            $contentManager,
            $repository,
            new BlockDataValidator($metadataProvider, $localeResolver),
            $this->createStub(BlockIdGeneratorInterface::class),
            new ContentMetadataMapper($metadataProvider, new ExtensionFormKeys([], [])),
            $this->createStub(AdminLinkGeneratorInterface::class),
            $this->createStub(ToolPermissionCheckerInterface::class),
            $this->createStub(WebspaceManagerInterface::class),
            $hasher,
        );
        $result = $tool->updatePage(
            uuid: $page->getUuid(),
            locale: 'fr',
            title: 'Test',
            url: '/test',
            template: 'oeuvre',
            content: ['year' => '2025', '_hash' => 'untrusted'],
            seo: ['seoNoIndex' => false],
        );

        return [$result, $capturedData, $expectedHash];
    }
}
