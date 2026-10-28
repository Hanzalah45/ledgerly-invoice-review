<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\SampleController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
    Route::post('/demo-login', [AuthController::class, 'demoLogin'])->middleware('throttle:20,1')->name('demo.login');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/upload', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:30,1')->name('documents.store');
    Route::post('/documents/bulk', [DocumentController::class, 'bulk'])->middleware('throttle:30,1')->name('documents.bulk');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::put('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::get('/documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
    Route::post('/documents/{document}/approve', [DocumentController::class, 'approve'])->name('documents.approve');
    Route::post('/documents/{document}/reject', [DocumentController::class, 'reject'])->name('documents.reject');
    Route::post('/documents/{document}/reopen', [DocumentController::class, 'reopen'])->name('documents.reopen');
    Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])->middleware('throttle:20,1')->name('documents.reprocess');
    Route::post('/documents/{document}/assign', [DocumentController::class, 'assign'])->name('documents.assign');
    Route::post('/documents/{document}/comments', [DocumentController::class, 'comment'])->middleware('throttle:60,1')->name('documents.comment');
    Route::get('/documents/{document}/summary', [DocumentController::class, 'summary'])->name('documents.summary');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::post('/purchase-orders/import', [PurchaseOrderController::class, 'import'])->name('purchase-orders.import');
    Route::post('/purchase-orders/{purchaseOrder}/toggle', [PurchaseOrderController::class, 'toggle'])->name('purchase-orders.toggle');
    Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy');

    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');

    Route::get('/exports', [ExportController::class, 'index'])->name('exports.index');
    Route::get('/exports/documents.csv', [ExportController::class, 'csv'])->name('exports.csv');
    Route::get('/exports/documents.json', [ExportController::class, 'json'])->name('exports.json');

    Route::get('/activity', ActivityController::class)->name('activity');
    Route::get('/samples/{file}', SampleController::class)->name('samples.download');

    Route::middleware('can:manage-settings')->group(function () {
        Route::get('/settings', SettingsController::class)->name('settings');
        Route::post('/settings/workspace', [SettingsController::class, 'updateWorkspace'])->name('settings.workspace');
        Route::post('/settings/logo', [SettingsController::class, 'uploadLogo'])->name('settings.logo');
        Route::delete('/settings/logo', [SettingsController::class, 'deleteLogo'])->name('settings.logo.delete');
        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    });
});

Route::get('/brand/logo', [BrandController::class, 'logo'])->name('brand.logo');
