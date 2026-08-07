<?php

use Azuriom\Plugin\GamingHubManager\Controllers\Admin\BackupController;
use Azuriom\Plugin\GamingHubManager\Controllers\Admin\DashboardController;
use Azuriom\Plugin\GamingHubManager\Controllers\Admin\PackageActionController;
use Azuriom\Plugin\GamingHubManager\Controllers\Admin\PackageController;
use Azuriom\Plugin\GamingHubManager\Controllers\Admin\ReleaseController;
use Azuriom\Plugin\GamingHubManager\Controllers\Admin\SourceController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:gaminghub.manager.view')->group(function () {
    Route::get('/', [DashboardController::class, 'overview'])->name('overview');
    Route::get('/installed', [DashboardController::class, 'installed'])->name('installed');
    Route::get('/available', [DashboardController::class, 'available'])->name('available');
    Route::get('/packages/{extension}', [PackageController::class, 'show'])->name('packages.show');
    Route::get('/sources/{source}/releases/{packageId}', [ReleaseController::class, 'show'])->name('releases.show');
});

Route::middleware('can:gaminghub.manager.sources')->group(function () {
    Route::get('/registries', [DashboardController::class, 'registries'])->name('registries');
    Route::post('/sources', [SourceController::class, 'store'])->name('sources.store');
    Route::patch('/sources/{source}/refresh', [SourceController::class, 'refresh'])->name('sources.refresh');
    Route::patch('/sources/{source}/toggle', [SourceController::class, 'toggle'])->name('sources.toggle');
    Route::patch('/sources/{source}/trust', [SourceController::class, 'trust'])->name('sources.trust');
    Route::delete('/sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');
});

Route::middleware('can:gaminghub.manager.install')->group(function () {
    Route::post('/sources/{source}/install', [PackageActionController::class, 'install'])->name('packages.install');
});

Route::middleware('can:gaminghub.manager.update')->group(function () {
    Route::post('/packages/{extension}/update', [PackageActionController::class, 'update'])->name('packages.update');
    Route::post('/packages/{extension}/reinstall', [PackageActionController::class, 'reinstall'])->name('packages.reinstall');
    Route::post('/packages/{extension}/verify', [PackageActionController::class, 'verify'])->name('packages.verify');
});

Route::middleware('can:gaminghub.manager.lifecycle')->group(function () {
    Route::patch('/packages/{extension}/enable', [PackageActionController::class, 'enable'])->name('packages.enable');
    Route::patch('/packages/{extension}/disable', [PackageActionController::class, 'disable'])->name('packages.disable');
});

Route::middleware('can:gaminghub.manager.uninstall')->group(function () {
    Route::get('/packages/{extension}/uninstall', [PackageController::class, 'confirmUninstall'])->name('packages.uninstall.confirm');
    Route::delete('/packages/{extension}', [PackageController::class, 'destroy'])->name('packages.uninstall');
});

Route::middleware('can:gaminghub.manager.logs')->group(function () {
    Route::get('/logs', [DashboardController::class, 'logs'])->name('logs');
});

Route::middleware('can:gaminghub.manager.backups')->group(function () {
    Route::get('/backups', [DashboardController::class, 'backups'])->name('backups');
    Route::post('/packages/{extension}/backup', [PackageActionController::class, 'backup'])->name('packages.backup');
    Route::post('/backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');
    Route::delete('/backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');
});

Route::middleware('can:gaminghub.manager.settings')->group(function () {
    Route::get('/settings', [DashboardController::class, 'settings'])->name('settings');
    Route::put('/settings', [DashboardController::class, 'updateSettings'])->name('settings.update');
});
