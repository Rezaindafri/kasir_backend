<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SystemSettingsController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::patch('/profile', [AuthController::class, 'updateProfile']);
    Route::patch('/profile/password', [AuthController::class, 'updatePassword']);
    Route::middleware('role:owner,kasir')->group(function () {
        Route::get('/settings', [SettingsController::class, 'show']);
    });

    // Kasir membutuhkan pelanggan dan katalog layanan untuk membuat transaksi,
    // tetapi tidak boleh mengubah data master atau harga layanan.
    Route::apiResource('customers', CustomerController::class)
        ->only(['index', 'show', 'store'])
        ->middleware('role:owner,kasir');
    Route::apiResource('services', ServiceController::class)
        ->only(['index', 'show'])
        ->middleware('role:owner,kasir');
    Route::middleware('role:owner,super_owner')->group(function () {
        Route::apiResource('customers', CustomerController::class)
            ->only(['update', 'destroy']);
        Route::apiResource('services', ServiceController::class)
            ->only(['store', 'update', 'destroy']);
    });
    // Harus dideklarasikan sebelum resource /transactions/{id}, supaya
    // kata "today" dan "ready-to-collect" tidak dianggap sebagai ID transaksi.
    Route::middleware('role:owner,kasir')->group(function () {
        Route::get('/transactions/today', [TransactionController::class, 'today']);
        Route::get('/transactions/ready-to-collect', [TransactionController::class, 'readyToCollect']);
    });
    Route::apiResource('transactions', TransactionController::class)
        ->except(['update', 'destroy'])
        ->middleware('role:owner,kasir');
    Route::apiResource('users', UserController::class)->except(['edit']);
    Route::post('/users/{id}/credentials', [UserController::class, 'updateCredentials']);
    Route::patch('/users/{id}/status', [UserController::class, 'toggleStatus']);

    Route::middleware('role:owner,kasir')->group(function () {
        Route::get('/dashboard/kasir', [DashboardController::class, 'kasir']);
    });
    Route::middleware('role:owner')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::match(['put', 'patch'], '/transactions/{id}', [TransactionController::class, 'update']);
        Route::delete('/transactions/{id}', [TransactionController::class, 'destroy']);
        Route::patch('/settings', [SettingsController::class, 'update']);
        Route::get('/dashboard/owner', [DashboardController::class, 'owner']);
        Route::get('/dashboard/owner/financial', [DashboardController::class, 'ownerFinancial']);
        Route::get('/reports/revenue', [DashboardController::class, 'revenue']);
        Route::get('/reports/statistics', [DashboardController::class, 'statistics']);
        Route::get('/reports/overview', [DashboardController::class, 'overview']);
        Route::get('/reports/export', [DashboardController::class, 'export']);
        Route::apiResource('expenses', ExpenseController::class)
            ->only(['index', 'store', 'update', 'destroy']);
    });

    Route::middleware('role:owner,super_owner')->group(function () {
        Route::get('/account-policy', [SystemSettingsController::class, 'accountPolicy']);
    });

    Route::middleware('role:super_owner')->group(function () {
        Route::get('/dashboard/super-owner', [DashboardController::class, 'superOwner']);
        Route::get('/system-settings', [SystemSettingsController::class, 'show']);
        Route::patch('/system-settings', [SystemSettingsController::class, 'update']);
        Route::get('/settings/backup', [SettingsController::class, 'backup']);
        Route::post('/settings/restore', [SettingsController::class, 'restore']);
    });

    Route::middleware('role:owner,kasir')->group(function () {
        Route::post('/transactions/{id}/collection', [TransactionController::class, 'collect']);
        Route::patch('/transactions/{id}/status', [TransactionController::class, 'updateStatus']);
        Route::post('/transactions/{id}/payment', [TransactionController::class, 'markPayment']);
        Route::post('/transactions/{id}/proof', [TransactionController::class, 'uploadProof']);
    });
});
