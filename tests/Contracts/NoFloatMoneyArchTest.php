<?php

declare(strict_types=1);

it('no migration declares REAL or FLOAT on a money column', function (): void {
    $migrationDirs = glob(base_path('Modules/*/Database/Migrations')) ?: [];
    $offenders = [];

    foreach ($migrationDirs as $dir) {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $contents = file_get_contents($file);
            if (preg_match('/->(float|double|real)\(["\']\w*(amount|minor)\w*["\']/i', $contents) === 1) {
                $offenders[] = $file;
            }
        }
    }
    expect($offenders)->toBeEmpty();

    $allMigrationContents = '';
    foreach ($migrationDirs as $dir) {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $allMigrationContents .= file_get_contents($file)."\n";
        }
    }
    expect($allMigrationContents)->toContain("bigInteger('amount_minor')");
});
