<?php

// Local-only, forward-only deployment of this one additive migration. Running
// without --apply is read-only. Never prints rows, credentials or APP_KEY.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = config('database.default');
$host = config('database.connections.' . $connection . '.host');
if (!app()->environment('local') || $connection !== 'mysql' ||
    !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, 'Refused: this script only supports local Laragon MySQL.' . PHP_EOL);
    exit(1);
}
$columns = [];
foreach (['users', 'customers', 'services', 'transactions', 'transaction_details',
    'transaction_payments', 'laundry_settings'] as $table) {
    $columns[$table] = Illuminate\Support\Facades\Schema::getColumnListing($table);
}
$snapshot = static function () use ($columns): array {
    $result = [];
    foreach ($columns as $table => $fields) {
        $hash = hash_init('sha256'); $count = 0;
        foreach (Illuminate\Support\Facades\DB::table($table)->select($fields)->orderBy('id')->cursor() as $row) {
            hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $count++;
        }
        $result[$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
    }
    return $result;
};
$before = $snapshot();
$applied = false;
if (in_array('--apply', $argv, true) &&
    !Illuminate\Support\Facades\Schema::hasTable('payment_destinations')) {
    $exitCode = Illuminate\Support\Facades\Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_10_11_000007_add_owner_payment_destinations.php',
        '--force' => true,
    ]);
    if ($exitCode !== 0) { fwrite(STDERR, 'Migration failed; inspect server logs.' . PHP_EOL); exit(1); }
    $applied = true;
}
$after = $snapshot();
$ready = Illuminate\Support\Facades\Schema::hasTable('payment_destinations') &&
    Illuminate\Support\Facades\Schema::hasColumn('transaction_payments', 'payment_destination_id') &&
    Illuminate\Support\Facades\Schema::hasColumn('transaction_payments', 'payment_destination_label');
echo json_encode(['migration_applied' => $applied, 'schema_ready' => $ready,
    'existing_rows_and_values_unchanged' => $before === $after,
    'tables_verified' => array_keys($columns)], JSON_THROW_ON_ERROR) . PHP_EOL;
if ($before !== $after) { fwrite(STDERR, 'Unexpected change; no rollback/reset was executed.' . PHP_EOL); exit(1); }
