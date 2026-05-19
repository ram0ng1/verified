<?php
/**
 * Validações cruzadas no DB local (mesma instância do alegatest).
 * Executado pelo orquestrador na fase 3.
 */

$dsn = 'mysql:host=127.0.0.1;dbname=flarum;charset=utf8mb4';
try {
    $pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    echo "DB connection failed: ".$e->getMessage()."\n";
    exit(1);
}

$results = [];

$cases = [
    [
        'name' => 'F1: users_verified_columns_removed',
        'sql'  => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='flarum' AND TABLE_NAME='users' AND COLUMN_NAME IN ('is_verified','verified_at','verified_by','verified_tier')",
        'pass' => fn ($v) => (int)$v === 0,
    ],
    [
        'name' => 'F1: companion_table_exists',
        'sql'  => "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='flarum' AND TABLE_NAME='user_verification'",
        'pass' => fn ($v) => (int)$v === 1,
    ],
    [
        'name' => 'R3-1: verified_tier_varchar_40',
        'sql'  => "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='flarum' AND TABLE_NAME='user_verification' AND COLUMN_NAME='verified_tier'",
        'pass' => fn ($v) => $v === 'varchar(40)',
    ],
    [
        'name' => 'R3-1: compound_index_user_id_status',
        'sql'  => "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='flarum' AND TABLE_NAME='verification_requests' AND INDEX_NAME='verification_requests_user_id_status_index'",
        'pass' => fn ($v) => (int)$v >= 2,
    ],
    [
        'name' => 'F1: user_verification_compound_index',
        'sql'  => "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='flarum' AND TABLE_NAME='user_verification' AND INDEX_NAME='user_verification_is_verified_tier_index'",
        'pass' => fn ($v) => (int)$v >= 2,
    ],
    [
        'name' => 'F1: companion_FK_user_cascade',
        'sql'  => "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='flarum' AND TABLE_NAME='user_verification' AND CONSTRAINT_NAME='user_verification_user_id_foreign'",
        'pass' => fn ($v) => $v === 'CASCADE',
    ],
    [
        'name' => 'F1: companion_FK_verifiedby_setnull',
        'sql'  => "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='flarum' AND TABLE_NAME='user_verification' AND CONSTRAINT_NAME='user_verification_verified_by_foreign'",
        'pass' => fn ($v) => $v === 'SET NULL',
    ],
    [
        'name' => 'verification_requests_handled_by_FK_setnull',
        'sql'  => "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='flarum' AND TABLE_NAME='verification_requests' AND CONSTRAINT_NAME='verification_requests_handled_by_foreign'",
        'pass' => fn ($v) => $v === 'SET NULL',
    ],
    [
        'name' => 'R3-2: tiers_no_script_or_onload',
        'sql'  => "SELECT value FROM settings WHERE `key`='ramon-verified.tiers'",
        'pass' => function ($v) {
            if (!$v) return true;
            $svgs = json_decode($v, true) ?: [];
            foreach ($svgs as $tier) {
                $svg = $tier['badgeSvg'] ?? '';
                if (stripos($svg, '<script') !== false) return false;
                if (stripos($svg, 'onload=') !== false) return false;
                if (stripos($svg, 'onclick=') !== false) return false;
            }
            return true;
        },
    ],
    [
        'name' => 'verified_users_have_companion_row',
        'sql'  => "SELECT COUNT(*) FROM user_verification WHERE is_verified = 1",
        'pass' => fn ($v) => (int)$v >= 0,  // informational
    ],
];

$pass = 0;
$fail = 0;
foreach ($cases as $c) {
    $val = $pdo->query($c['sql'])->fetchColumn();
    $ok = ($c['pass'])($val);
    $tag = $ok ? 'PASS' : 'FAIL';
    $valStr = is_string($val) ? substr($val, 0, 80) : (string)$val;
    printf("  [%s] %-50s value=%s\n", $tag, $c['name'], $valStr);
    if ($ok) $pass++; else $fail++;
}
printf("\nDB checks: %d passed, %d failed\n", $pass, $fail);
