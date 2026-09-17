#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/test_helpers.php';
require_once dirname(__DIR__) . '/lib/SecureStorage.php';

$root = sys_get_temp_dir() . '/scuola-storage-test-' . bin2hex(random_bytes(6));
$app = $root . '/app';
$secure = $root . '/secure';
$config = [
    'registry' => $secure . '/registry.sqlite3',
    'classes' => $secure . '/classes',
    'archive' => $secure . '/archive',
    'key' => $secure . '/master.key',
];

try {
    testThrows(static fn () => new SecureStorage([
        'registry' => dirname(__DIR__) . '/unsafe.sqlite3',
        'classes' => dirname(__DIR__) . '/unsafe-classes',
        'archive' => dirname(__DIR__) . '/unsafe-archive',
        'key' => dirname(__DIR__) . '/unsafe.key',
    ]), 'Secure runtime paths inside the web root must be rejected.');

    mkdir($app . '/JSON', 0700, true);
    mkdir($app . '/JSON-seconda', 0700, true);
    SecureStorage::generateKeyFile($config['key']);

    $users = [
        'secret-admin-code' => ['name' => 'Ada Riservata', 'admin' => true, 'answers' => [], 'pushSubscriptions' => [['endpoint' => 'https://push.invalid/secret-endpoint', 'keys' => ['p256dh' => 'a', 'auth' => 'b']]]],
        'shared-code' => ['name' => 'Utente Comune', 'admin' => false, 'answers' => []],
    ];
    $subject = [
        'lock' => false, 'hide' => false, 'answerCount' => 1, 'type' => 'subject', 'usesDays' => true,
        'days' => ['01-10-2026' => ['dayName' => 'Giovedì', 'availability' => '1/2']],
        'answers' => ['secret-admin-code' => ['date' => '01-10-2026', 'answerNumber' => 1]],
    ];
    file_put_contents($app . '/JSON/users.json', json_encode($users, JSON_THROW_ON_ERROR));
    file_put_contents($app . '/JSON/MatematicaSegreta.json', json_encode($subject, JSON_THROW_ON_ERROR));
    file_put_contents($app . '/JSON-seconda/users.json', json_encode([
        'second-admin' => ['name' => 'Secondo Admin', 'admin' => true, 'answers' => []],
        'shared-code' => ['name' => 'Utente Comune', 'admin' => false, 'answers' => []],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($app . '/JSON-seconda/Italiano.json', json_encode($subject, JSON_THROW_ON_ERROR));

    $storage = new SecureStorage($config);
    $migration = $storage->bootstrapLegacy($app);
    testSame(2, $migration['imported'], 'Both legacy classes must be imported.');
    testSame(2, $migration['archived'], 'Both legacy folders must be archived.');
    testAssert(!is_dir($app . '/JSON') && !is_dir($app . '/JSON-seconda'), 'Legacy folders must leave the web root after verification.');

    $sharedClasses = $storage->listClassesForUser('shared-code');
    testSame(2, count($sharedClasses), 'A shared code must resolve to both isolated classes.');
    testSame(null, $storage->resolveClassForUser('shared-code'), 'An ambiguous code must require class selection.');
    $defaultId = $storage->resolveClassForUser('secret-admin-code', null, 'default');
    testAssert(is_string($defaultId), 'The old default profile selector must still resolve.');
    testSame(null, $storage->resolveClassForUser('unknown-code'), 'An unknown code must not resolve to any class.');
    testSame($defaultId, $storage->resolveClassForUser('secret-admin-code'), 'A code in one class must log in directly.');
    $default = $storage->getClass($defaultId);
    testSame(false, $default['users']['shared-code']['priority'], 'Migrated users must default to non-priority.');
    testSame('01-10-2026', $default['subjects']['MatematicaSegreta']['answers']['secret-admin-code']['date'], 'Answers must round-trip through encryption.');

    $diskBytes = file_get_contents($config['registry']);
    foreach (glob($config['classes'] . '/*.sqlite3*') ?: [] as $file) $diskBytes .= file_get_contents($file);
    foreach (['secret-admin-code', 'Ada Riservata', 'MatematicaSegreta', 'secret-endpoint'] as $secretValue) {
        testAssert(!str_contains($diskBytes, $secretValue), 'Encrypted storage leaked plaintext: ' . $secretValue);
    }

    $created = $storage->createClass('Classe Nuova', 'Nuovo Admin', 'test-address');
    testAssert((bool) preg_match('/^[A-Za-z0-9_-]{32}$/', $created['UID']), 'New admin codes must be cryptographically generated URL-safe values.');
    testSame(true, $storage->getClass($created['classId'])['users'][$created['UID']]['admin'], 'Class creator must become an admin.');
    testSame(1, count($storage->listClassesForUser($created['UID'])), 'A newly created class must not grant access to existing classes.');
    $copy = $storage->copyClass($created['classId'], 'Classe Copiata', $created['UID']);
    testSame(2, count($storage->listClassesForUser($created['UID'])), 'Copied classes must update the keyed membership index.');
    $storage->renameClass($copy['classId'], 'Classe Rinominata');
    testSame('Classe Rinominata', $storage->getClass($copy['classId'])['name'], 'Class rename must update encrypted metadata and payload.');
    $storage->deleteClass($copy['classId']);
    testSame(1, count($storage->listClassesForUser($created['UID'])), 'Deleting a class must remove only its memberships.');
    for ($index = 1; $index < 5; $index++) $storage->createClass('Rate ' . $index, 'Admin', 'test-address');
    testThrows(static fn () => $storage->createClass('Rate limit', 'Admin', 'test-address'), 'Anonymous class creation must be rate limited.');

    $wrongConfig = $config;
    $wrongConfig['key'] = $secure . '/wrong.key';
    SecureStorage::generateKeyFile($wrongConfig['key']);
    $wrongStorage = new SecureStorage($wrongConfig);
    testThrows(static fn () => $wrongStorage->getClass($defaultId), 'A wrong key must not decrypt registry metadata or class data.');
    unset($wrongStorage);

    $archived = glob($config['archive'] . '/*/JSON', GLOB_ONLYDIR);
    testAssert(is_array($archived) && count($archived) === 1, 'The default legacy archive must exist.');
    mkdir($app . '/JSON', 0700, true);
    $updatedUsers = $users;
    $updatedUsers['shared-code']['name'] = 'Modifica da rollback';
    file_put_contents($app . '/JSON/users.json', json_encode($updatedUsers, JSON_THROW_ON_ERROR));
    file_put_contents($app . '/JSON/MatematicaSegreta.json', json_encode($subject, JSON_THROW_ON_ERROR));
    $storage->bootstrapLegacy($app);
    testSame('Modifica da rollback', $storage->getClass($defaultId)['users']['shared-code']['name'], 'A restored legacy folder must be authoritative on re-import.');

    $classCount = count($storage->listAllClassIds());
    mkdir($app . '/JSON-broken', 0700, true);
    file_put_contents($app . '/JSON-broken/users.json', '{broken');
    testThrows(static fn () => $storage->bootstrapLegacy($app), 'Invalid legacy JSON must abort migration.');
    testSame($classCount, count($storage->listAllClassIds()), 'A failed migration must not create partial classes.');

    $export = $root . '/export';
    $exported = $storage->exportLegacy($export);
    testAssert(count($exported) >= 3 && is_file($export . '/' . $exported[0] . '/users.json'), 'Legacy export must recreate readable JSON folders.');

    $roundTripConfig = [
        'registry' => $root . '/roundtrip/registry.sqlite3',
        'classes' => $root . '/roundtrip/classes',
        'archive' => $root . '/roundtrip/archive',
        'key' => $root . '/roundtrip/master.key',
    ];
    SecureStorage::generateKeyFile($roundTripConfig['key']);
    $roundTrip = new SecureStorage($roundTripConfig);
    $roundTripResult = $roundTrip->bootstrapLegacy($export);
    testSame(count($exported), $roundTripResult['imported'], 'A legacy export must be importable into a fresh secure store.');

    $tamperId = $created['classId'];
    $tamperDatabase = new PDO('sqlite:' . $config['classes'] . '/' . $tamperId . '.sqlite3');
    $ciphertext = $tamperDatabase->query('SELECT ciphertext FROM state WHERE id = 1')->fetchColumn();
    testAssert(is_string($ciphertext) && $ciphertext !== '', 'The encrypted class payload must exist.');
    $ciphertext[0] = chr(ord($ciphertext[0]) ^ 1);
    $statement = $tamperDatabase->prepare('UPDATE state SET ciphertext = :ciphertext WHERE id = 1');
    $statement->bindValue(':ciphertext', $ciphertext, PDO::PARAM_LOB);
    $statement->execute();
    unset($tamperDatabase);
    testThrows(static fn () => $storage->getClass($tamperId), 'Modified ciphertext must fail authentication.');

    fwrite(STDOUT, "storage_test: OK\n");
} finally {
    removeTestDirectory($root);
}
