<?php

namespace Azuriom\Plugin\GamingHubManager\Providers;

use Azuriom\Extensions\Plugin\BasePluginServiceProvider;
use Azuriom\Models\Permission;
use Azuriom\Plugin\GamingHubManager\Services\AzuriomPluginLifecycle;
use Azuriom\Plugin\GamingHubManager\Services\BackupManager;
use Azuriom\Plugin\GamingHubManager\Services\DirectoryHasher;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionArchiveInspector;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionChecksumVerifier;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionCompatibility;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionDependencyGuard;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionInstaller;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionManifestValidator;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionPathGuard;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionRegistryValidator;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionSafeMessage;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionSourceManager;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionUninstaller;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionUrlGuard;
use Azuriom\Plugin\GamingHubManager\Services\ExtensionVersionPolicy;
use Azuriom\Plugin\GamingHubManager\Services\GitHubReleaseClient;
use Azuriom\Plugin\GamingHubManager\Services\InstalledExtensionResolver;
use Azuriom\Plugin\GamingHubManager\Services\LegacyMetadataImporter;
use Azuriom\Plugin\GamingHubManager\Services\ManagerRuntime;
use Azuriom\Plugin\GamingHubManager\Services\ManagerSchema;
use Azuriom\Plugin\GamingHubManager\Services\ManagerSettings;
use Azuriom\Plugin\GamingHubManager\Services\PackageCatalog;
use Azuriom\Plugin\GamingHubManager\Services\PackageReleaseResolver;
use Azuriom\Plugin\GamingHubManager\Services\SafeExtensionHttpClient;
use Azuriom\Plugin\GamingHubManager\Support\ManagerAlertNormalizer;

final class GamingHubManagerServiceProvider extends BasePluginServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            plugin_path($this->plugin->id.'/config/manager.php'),
            'gaming-hub-manager.manager',
        );

        foreach ([
            ExtensionUrlGuard::class,
            SafeExtensionHttpClient::class,
            ExtensionRegistryValidator::class,
            ExtensionManifestValidator::class,
            ExtensionVersionPolicy::class,
            ExtensionCompatibility::class,
            GitHubReleaseClient::class,
            ExtensionChecksumVerifier::class,
            ExtensionArchiveInspector::class,
            ExtensionPathGuard::class,
            DirectoryHasher::class,
            AzuriomPluginLifecycle::class,
            ExtensionSafeMessage::class,
            ExtensionSourceManager::class,
            InstalledExtensionResolver::class,
            ExtensionDependencyGuard::class,
            BackupManager::class,
            ExtensionInstaller::class,
            ExtensionUninstaller::class,
            ManagerSchema::class,
            ManagerSettings::class,
            LegacyMetadataImporter::class,
            ManagerRuntime::class,
            PackageCatalog::class,
            PackageReleaseResolver::class,
            ManagerAlertNormalizer::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->loadViews();
        $this->loadTranslations();
        $this->loadMigrations();
        $this->registerAdminNavigation();

        Permission::registerPermissions([
            'gaminghub.manager.view' => 'gaming-hub-manager::admin.permissions.view',
            'gaminghub.manager.sources' => 'gaming-hub-manager::admin.permissions.sources',
            'gaminghub.manager.install' => 'gaming-hub-manager::admin.permissions.install',
            'gaminghub.manager.update' => 'gaming-hub-manager::admin.permissions.update',
            'gaminghub.manager.uninstall' => 'gaming-hub-manager::admin.permissions.uninstall',
            'gaminghub.manager.lifecycle' => 'gaming-hub-manager::admin.permissions.lifecycle',
            'gaminghub.manager.backups' => 'gaming-hub-manager::admin.permissions.backups',
            'gaminghub.manager.logs' => 'gaming-hub-manager::admin.permissions.logs',
            'gaminghub.manager.settings' => 'gaming-hub-manager::admin.permissions.settings',
        ]);
    }

    /**
     * Azuriom's supported plugin navigation contract registers plugin-owned
     * top-level entries. It does not expose a safe insertion point inside the
     * native Extensions group, so Manager uses the supported standalone entry.
     */
    protected function adminNavigation(): array
    {
        return [
            'gaming-hub-manager' => [
                'name' => 'Gaming Hub Manager',
                'type' => 'dropdown',
                'icon' => 'bi bi-box-seam',
                'route' => 'gaming-hub-manager.admin.*',
                'items' => [
                    'gaming-hub-manager.admin.overview' => [
                        'name' => 'Overview',
                        'permission' => 'gaminghub.manager.view',
                    ],
                    'gaming-hub-manager.admin.installed' => [
                        'name' => 'Installed Packages',
                        'permission' => 'gaminghub.manager.view',
                    ],
                    'gaming-hub-manager.admin.available' => [
                        'name' => 'Available Packages',
                        'permission' => 'gaminghub.manager.view',
                    ],
                    'gaming-hub-manager.admin.registries' => [
                        'name' => 'Registries',
                        'permission' => 'gaminghub.manager.sources',
                    ],
                    'gaming-hub-manager.admin.logs' => [
                        'name' => 'Install Logs',
                        'permission' => 'gaminghub.manager.logs',
                    ],
                    'gaming-hub-manager.admin.backups' => [
                        'name' => 'Backups',
                        'permission' => 'gaminghub.manager.backups',
                    ],
                    'gaming-hub-manager.admin.settings' => [
                        'name' => 'Settings',
                        'permission' => 'gaminghub.manager.settings',
                    ],
                ],
            ],
        ];
    }
}
