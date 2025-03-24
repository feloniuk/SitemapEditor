<?php declare(strict_types=1);

namespace SitemapManager\Subscriber;

use League\Flysystem\FilesystemOperator;
use Shopware\Core\Content\Sitemap\Event\SitemapGeneratedEvent;
use Shopware\Core\Content\Sitemap\Event\SitemapFilterOpenTagEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class SitemapGenerateSubscriber implements EventSubscriberInterface
{
    private LoggerInterface $logger;
    private FilesystemOperator $filesystem;
    private SystemConfigService $systemConfigService;

    public function __construct(
        LoggerInterface $logger,
        FilesystemOperator $publicFilesystem,
        SystemConfigService $systemConfigService
    ) {
        $this->logger = $logger;
        $this->filesystem = $publicFilesystem;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * Register events to listen to
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SitemapFilterOpenTagEvent::class => 'onSitemapFilterOpenTag',
            SitemapGeneratedEvent::class => 'onSitemapGenerated',
        ];
    }

    /**
     * Add custom XML namespaces to sitemap open tag if needed
     */
    public function onSitemapFilterOpenTag(SitemapFilterOpenTagEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        
        $this->logger->info(
            'Sitemap open tag event triggered',
            [
                'salesChannelId' => $salesChannelId
            ]
        );
        
        // You could add additional namespaces if needed
        // Example: $event->addUrlsetNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');
    }

    /**
     * Perform actions after sitemap has been generated and saved
     * This is where we can create backup files
     */
    public function onSitemapGenerated(SitemapGeneratedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $languageId = $event->getSalesChannelContext()->getLanguageId();
        
        $this->logger->info(
            'Sitemap generation completed',
            [
                'salesChannelId' => $salesChannelId,
                'languageId' => $languageId
            ]
        );
        
        // If backup is enabled, create backup of generated files
        if ($this->getConfig('enableBackup', $salesChannelId)) {
            $this->createSitemapBackups($salesChannelId, $languageId);
        }
    }
    
    /**
     * Create backups of the sitemap files
     */
    private function createSitemapBackups(string $salesChannelId, string $languageId): void
    {
        try {
            $sitemapPath = 'sitemap/salesChannel-' . $salesChannelId . '-' . $languageId;
            $backupDir = 'sitemap/backup/' . $salesChannelId . '-' . $languageId . '/' . date('Y-m-d_H-i-s');
            
            // Create backup directory if it doesn't exist
            if (!$this->filesystem->directoryExists($backupDir)) {
                $this->filesystem->createDirectory($backupDir);
            }
            
            // Get all sitemap files for this sales channel and language
            if ($this->filesystem->directoryExists($sitemapPath)) {
                $files = array_filter(
                    $this->filesystem->listContents($sitemapPath)->toArray(),
                    function ($file) {
                        return $file['type'] === 'file' && pathinfo($file['path'], PATHINFO_EXTENSION) === 'xml.gz';
                    }
                );
                
                foreach ($files as $file) {
                    $fileName = basename($file['path']);
                    
                    if ($this->filesystem->fileExists($file['path'])) {
                        $content = $this->filesystem->read($file['path']);
                        $this->filesystem->write($backupDir . '/' . $fileName, $content);
                        
                        $this->logger->info(
                            'Created sitemap backup',
                            [
                                'originalFile' => $file['path'],
                                'backupFile' => $backupDir . '/' . $fileName
                            ]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Error creating sitemap backups',
                [
                    'error' => $e->getMessage(),
                    'salesChannelId' => $salesChannelId
                ]
            );
        }
    }
    
    /**
     * Get plugin configuration
     */
    private function getConfig(string $key, string $salesChannelId): mixed
    {
        return $this->systemConfigService->get(
            'SitemapManager.config.' . $key,
            $salesChannelId
        );
    }
}
