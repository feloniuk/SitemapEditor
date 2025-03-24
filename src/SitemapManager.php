<?php declare(strict_types=1);

namespace SitemapManager;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class SitemapManager extends Plugin
{
    public function install(InstallContext $context): void
    {
        parent::install($context);
    }

    public function uninstall(UninstallContext $context): void
    {
        if (!$context->keepUserData()) {
            // Remove config and other data if not keeping user data
        }
        
        parent::uninstall($context);
    }
}