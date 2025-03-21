<?php declare(strict_types=1);

namespace SitemapEditor\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Shopware\Core\Content\Sitemap\ScheduledTask\SitemapGenerateTaskHandler;
use Shopware\Core\Content\Sitemap\ScheduledTask\SitemapGenerateTask;

class SystemConfigChangedSubscriber implements EventSubscriberInterface
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onSystemConfigChanged',
        ];
    }

    public function onSystemConfigChanged(SystemConfigChangedEvent $event): void
    {
        // Check if the changed config belongs to our plugin
        if (strpos($event->getKey(), 'SitemapEditor.config.') === 0) {
            // Schedule a sitemap regeneration
            $this->scheduleSitemapGeneration($event->getSalesChannelId());
        }
    }

    private function scheduleSitemapGeneration(?string $salesChannelId): void
    {
        // Create a new SitemapGenerateTask
        $task = new SitemapGenerateTask();
        
        // Dispatch the task to be handled by the message bus
        // This will trigger the sitemap regeneration in the background
        $this->messageBus->dispatch($task);
    }
}