<?php

declare(strict_types=1);

final class StorageException extends RuntimeException {}

final class SecureStorage
{
    private const SCHEMA_VERSION = 1;
    private const CLASS_CREATION_WINDOW = 3600;
    private const CLASS_CREATION_LIMIT = 5;

    private string $registryPath;
    private string $classDirectory;
    private string $archiveDirectory;
    private string $masterKey;
    private string $indexKey;
    private string $registryKey;
    private PDO $registry;

    public function __construct(?array $config = null)
    {
        $config ??= [];
        $this->registryPath = $config['registry'] ?? (getenv('SCUOLA_STORAGE_REGISTRY_DB') ?: '');
        $this->classDirectory = $config['classes'] ?? (getenv('SCUOLA_CLASS_STORAGE_DIR') ?: '');
        $this->archiveDirectory = $config['archive'] ?? (getenv('SCUOLA_LEGACY_ARCHIVE_DIR') ?: '');
        $keyFile = $config['key'] ?? (getenv('SCUOLA_STORAGE_KEY_FILE') ?: '');

        if ($this->registryPath === '' || $this->classDirectory === '' || $keyFile === '') {
            throw new StorageException('Secure storage is not configured.');
        }
        $this->assertOutsideWebRoot($this->registryPath, 'registry database');
        $this->assertOutsideWebRoot($this->classDirectory, 'class storage directory');
        $this->assertOutsideWebRoot($keyFile, 'storage key');
        if ($this->archiveDirectory !== '') $this->assertOutsideWebRoot($this->archiveDirectory, 'legacy archive directory');
        if (!extension_loaded('sodium')) {
            throw new StorageException('The Sodium PHP extension is required.');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new StorageException('The PDO SQLite PHP extension is required.');
        }

        $this->masterKey = $this->loadKey($keyFile);
        $this->indexKey = $this->deriveKey('scuola:index:v1');
        $this->registryKey = $this->deriveKey('scuola:registry:v1');

        $this->ensureDirectory(dirname($this->registryPath));
        $this->ensureDirectory($this->classDirectory);
        if ($this->archiveDirectory !== '') {
            $this->ensureDirectory($this->archiveDirectory);
        }

        $this->registry = $this->openDatabase($this->registryPath);
        $this->initializeRegistry();
    }

    public static function generateKeyFile(string $path): void
    {
        if (file_exists($path)) {
            throw new StorageException('Refusing to overwrite an existing key file.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException('Unable to create the key directory.');
        }
        $encoded = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
        if (file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new StorageException('Unable to write the key file.');
        }
        chmod($path, 0600);
    }

    public function hasClasses(): bool
    {
        return (int) $this->registry->query('SELECT COUNT(*) FROM classes')->fetchColumn() > 0;
    }

    public function listClassesForUser(string $uid): array
    {
        if ($uid === '') {
            return [];
        }
        $statement = $this->registry->prepare(
            'SELECT c.class_id, c.name_nonce, c.name_cipher
             FROM memberships m JOIN classes c ON c.class_id = m.class_id
             WHERE m.user_tag = :user_tag ORDER BY c.created_at, c.class_id'
        );
        $statement->execute([':user_tag' => $this->userTag($uid)]);

        $classes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $classes[] = [
                'id' => $row['class_id'],
                'name' => $this->decryptRegistryValue(
                    $row['name_nonce'],
                    $row['name_cipher'],
                    'class-name:' . $row['class_id']
                ),
            ];
        }
        return $classes;
    }

    public function resolveClassForUser(string $uid, ?string $selector = null, ?string $legacyName = null): ?string
    {
        if ($uid === '') {
            return null;
        }
        $classes = $this->listClassesForUser($uid);
        if ($selector !== null && $selector !== '') {
            foreach ($classes as $class) {
                if (hash_equals($class['id'], $selector)) {
                    return $class['id'];
                }
            }
            return null;
        }
        if ($legacyName !== null && $legacyName !== '') {
            $legacyName = $legacyName === 'default' ? '' : $legacyName;
            $classId = $this->classIdForLegacyName($legacyName);
            if ($classId !== null) {
                foreach ($classes as $class) {
                    if (hash_equals($class['id'], $classId)) {
                        return $classId;
                    }
                }
            }
            return null;
        }
        return count($classes) === 1 ? $classes[0]['id'] : null;
    }

    public function getClass(string $classId): array
    {
        $metadata = $this->getClassMetadata($classId);
        $database = $this->openDatabase($this->classPath($metadata['db_file']));
        $row = $database->query('SELECT nonce, ciphertext FROM state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new StorageException('The class data is missing.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $row['ciphertext'],
            $this->classAssociatedData($classId),
            $row['nonce'],
            $this->classKey($classId)
        );
        if ($plaintext === false) {
            throw new StorageException('The class data failed authentication.');
        }
        $data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        return $this->normalizeClassData($data, $classId, $metadata['name']);
    }

    public function saveClass(string $classId, array $data): void
    {
        $metadata = $this->getClassMetadata($classId);
        $data = $this->normalizeClassData($data, $classId, $metadata['name']);
        $this->writeClassState($metadata['db_file'], $classId, $data);
        $this->reindexClassMemberships($classId, array_keys($data['users']));
    }

    public function mutateClass(string $classId, callable $callback): mixed
    {
        $lockPath = $this->classDirectory . DIRECTORY_SEPARATOR . '.' . $classId . '.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new StorageException('Unable to lock the class data.');
        }
        try {
            $data = $this->getClass($classId);
            $result = $callback($data);
            $this->saveClass($classId, $data);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function createClass(string $name, string $adminName, ?string $requestIdentity = null): array
    {
        if (!$this->classCreationAllowed()) {
            throw new StorageException('Class creation is currently disabled.');
        }
        $name = $this->validateDisplayText($name, 'class name');
        $adminName = $this->validateDisplayText($adminName, 'administrator name');
        $requestIdentity ??= 'unknown';
        $this->enforceCreationRateLimit($requestIdentity);

        $classId = bin2hex(random_bytes(16));
        $dbFile = $classId . '.sqlite3';
        $uid = $this->base64UrlEncode(random_bytes(24));
        $data = $this->newClassData($classId, $name, $uid, $adminName);
        [$nameNonce, $nameCipher] = $this->encryptRegistryValue($name, 'class-name:' . $classId);

        $this->initializeClassDatabase($dbFile);
        try {
            $this->writeClassState($dbFile, $classId, $data);
            $this->registry->beginTransaction();
            $insert = $this->registry->prepare(
                'INSERT INTO classes(class_id, db_file, name_nonce, name_cipher, legacy_tag, created_at)
                 VALUES(:class_id, :db_file, :name_nonce, :name_cipher, NULL, :created_at)'
            );
            $insert->bindValue(':class_id', $classId);
            $insert->bindValue(':db_file', $dbFile);
            $insert->bindValue(':name_nonce', $nameNonce, PDO::PARAM_LOB);
            $insert->bindValue(':name_cipher', $nameCipher, PDO::PARAM_LOB);
            $insert->bindValue(':created_at', time(), PDO::PARAM_INT);
            $insert->execute();
            $this->insertMembership($uid, $classId);
            $this->recordCreation($requestIdentity);
            $this->registry->commit();
        } catch (Throwable $error) {
            if ($this->registry->inTransaction()) {
                $this->registry->rollBack();
            }
            @unlink($this->classPath($dbFile));
            throw $error;
        }

        return ['classId' => $classId, 'className' => $name, 'UID' => $uid, 'data' => $data];
    }

    public function createClassForExistingAdmin(string $name, string $uid, array $userData, array $subjects = []): array
    {
        $name = $this->validateDisplayText($name, 'class name');
        if ($uid === '') {
            throw new StorageException('A login code is required.');
        }
        $classId = bin2hex(random_bytes(16));
        $dbFile = $classId . '.sqlite3';
        $userData['admin'] = true;
        $userData['priority'] = (bool) ($userData['priority'] ?? false);
        $userData['answers'] = is_array($userData['answers'] ?? null) ? $userData['answers'] : [];
        $data = $this->normalizeClassData([
            'id' => $classId,
            'name' => $name,
            'legacyName' => null,
            'users' => [$uid => $userData],
            'subjects' => $subjects,
        ], $classId, $name);
        [$nameNonce, $nameCipher] = $this->encryptRegistryValue($name, 'class-name:' . $classId);
        $this->initializeClassDatabase($dbFile);
        try {
            $this->writeClassState($dbFile, $classId, $data);
            $this->registry->beginTransaction();
            $statement = $this->registry->prepare(
                'INSERT INTO classes(class_id, db_file, name_nonce, name_cipher, legacy_tag, created_at)
                 VALUES(:class_id, :db_file, :name_nonce, :name_cipher, NULL, :created_at)'
            );
            $statement->bindValue(':class_id', $classId);
            $statement->bindValue(':db_file', $dbFile);
            $statement->bindValue(':name_nonce', $nameNonce, PDO::PARAM_LOB);
            $statement->bindValue(':name_cipher', $nameCipher, PDO::PARAM_LOB);
            $statement->bindValue(':created_at', time(), PDO::PARAM_INT);
            $statement->execute();
            $this->insertMembership($uid, $classId);
            $this->registry->commit();
        } catch (Throwable $error) {
            if ($this->registry->inTransaction()) {
                $this->registry->rollBack();
            }
            @unlink($this->classPath($dbFile));
            throw $error;
        }
        return ['classId' => $classId, 'className' => $name, 'UID' => $uid, 'data' => $data];
    }

    public function importClass(string $name, array $users, array $subjects): array
    {
        $adminUid = null;
        foreach ($users as $uid => $user) {
            if ((bool) ($user['admin'] ?? false)) {
                $adminUid = (string) $uid;
                break;
            }
        }
        if ($adminUid === null) {
            throw new StorageException('An imported class must contain at least one administrator.');
        }
        $created = $this->createClassForExistingAdmin($name, $adminUid, $users[$adminUid]);
        try {
            $data = $created['data'];
            $data['users'] = $users;
            $data['subjects'] = $subjects;
            $this->saveClass($created['classId'], $data);
        } catch (Throwable $error) {
            $this->deleteClass($created['classId']);
            throw $error;
        }
        return $created;
    }

    public function copyClass(string $sourceClassId, string $newName, string $adminUid): array
    {
        $source = $this->getClass($sourceClassId);
        if (!(bool) ($source['users'][$adminUid]['admin'] ?? false)) {
            throw new StorageException('Not authorized to copy this class.');
        }
        $newName = $this->validateDisplayText($newName, 'class name');
        $classId = bin2hex(random_bytes(16));
        $dbFile = $classId . '.sqlite3';
        $source['id'] = $classId;
        $source['name'] = $newName;
        $source['legacyName'] = null;
        $source['createdAt'] = gmdate(DATE_ATOM);
        [$nameNonce, $nameCipher] = $this->encryptRegistryValue($newName, 'class-name:' . $classId);
        $this->initializeClassDatabase($dbFile);
        $this->writeClassState($dbFile, $classId, $source);

        try {
            $this->registry->beginTransaction();
            $statement = $this->registry->prepare(
                'INSERT INTO classes(class_id, db_file, name_nonce, name_cipher, legacy_tag, created_at)
                 VALUES(:class_id, :db_file, :name_nonce, :name_cipher, NULL, :created_at)'
            );
            $statement->bindValue(':class_id', $classId);
            $statement->bindValue(':db_file', $dbFile);
            $statement->bindValue(':name_nonce', $nameNonce, PDO::PARAM_LOB);
            $statement->bindValue(':name_cipher', $nameCipher, PDO::PARAM_LOB);
            $statement->bindValue(':created_at', time(), PDO::PARAM_INT);
            $statement->execute();
            foreach (array_keys($source['users']) as $uid) {
                $this->insertMembership((string) $uid, $classId);
            }
            $this->registry->commit();
        } catch (Throwable $error) {
            if ($this->registry->inTransaction()) {
                $this->registry->rollBack();
            }
            @unlink($this->classPath($dbFile));
            throw $error;
        }
        return ['classId' => $classId, 'className' => $newName];
    }

    public function renameClass(string $classId, string $newName): void
    {
        $newName = $this->validateDisplayText($newName, 'class name');
        [$nonce, $cipher] = $this->encryptRegistryValue($newName, 'class-name:' . $classId);
        $statement = $this->registry->prepare(
            'UPDATE classes SET name_nonce = :nonce, name_cipher = :cipher WHERE class_id = :class_id'
        );
        $statement->bindValue(':nonce', $nonce, PDO::PARAM_LOB);
        $statement->bindValue(':cipher', $cipher, PDO::PARAM_LOB);
        $statement->bindValue(':class_id', $classId);
        $statement->execute();
        if ($statement->rowCount() !== 1) {
            throw new StorageException('Class not found.');
        }
        $this->mutateClass($classId, static function (array &$data) use ($newName): void {
            $data['name'] = $newName;
        });
    }

    public function deleteClass(string $classId): void
    {
        $metadata = $this->getClassMetadata($classId);
        $this->registry->beginTransaction();
        try {
            $statement = $this->registry->prepare('DELETE FROM memberships WHERE class_id = :class_id');
            $statement->execute([':class_id' => $classId]);
            $statement = $this->registry->prepare('DELETE FROM classes WHERE class_id = :class_id');
            $statement->execute([':class_id' => $classId]);
            $this->registry->commit();
        } catch (Throwable $error) {
            $this->registry->rollBack();
            throw $error;
        }
        if (!@unlink($this->classPath($metadata['db_file']))) {
            throw new StorageException('Class metadata was removed but its database could not be deleted.');
        }
    }

    public function bootstrapLegacy(string $applicationRoot): array
    {
        $directories = [];
        foreach (glob(rtrim($applicationRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'JSON*', GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);
            if ($name === 'JSON' || preg_match('/^JSON-[A-Za-z0-9_-]+$/', $name)) {
                $directories[$name] = $path;
            }
        }
        if ($directories === []) {
            return ['imported' => 0, 'archived' => 0];
        }
        if ($this->archiveDirectory === '') {
            throw new StorageException('A legacy archive directory is required before migration.');
        }
        ksort($directories, SORT_STRING);

        $lockPath = dirname($this->registryPath) . DIRECTORY_SEPARATOR . 'legacy-import.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new StorageException('Unable to acquire the legacy import lock.');
        }
        try {
            $fingerprintContext = hash_init('sha256');
            $imports = [];
            foreach ($directories as $directoryName => $path) {
                $imports[$directoryName] = $this->readLegacyDirectory($path, $fingerprintContext);
            }
            $fingerprint = hash_final($fingerprintContext);
            $rollback = [];
            try {
                foreach ($imports as $directoryName => $legacyData) {
                    $legacyName = $directoryName === 'JSON' ? '' : substr($directoryName, 5);
                    $existingId = $this->classIdForLegacyName($legacyName);
                    $rollback[] = [
                        'classId' => $existingId,
                        'data' => $existingId === null ? null : $this->getClass($existingId),
                    ];
                    $newId = $this->importLegacyClass($legacyName, $legacyData);
                    if ($existingId === null) $rollback[array_key_last($rollback)]['classId'] = $newId;
                }
                $statement = $this->registry->prepare(
                    'INSERT OR REPLACE INTO imports(fingerprint, imported_at) VALUES(:fingerprint, :imported_at)'
                );
                $statement->execute([':fingerprint' => $fingerprint, ':imported_at' => time()]);
            } catch (Throwable $error) {
                foreach (array_reverse($rollback) as $entry) {
                    try {
                        if ($entry['data'] === null && $entry['classId'] !== null) {
                            $this->deleteClass($entry['classId']);
                        } elseif ($entry['data'] !== null) {
                            $this->saveClass($entry['classId'], $entry['data']);
                        }
                    } catch (Throwable $rollbackError) {
                        error_log('Legacy import rollback failed: ' . $rollbackError->getMessage());
                    }
                }
                throw $error;
            }

            $verified = 0;
            foreach ($imports as $directoryName => $legacyData) {
                $legacyName = $directoryName === 'JSON' ? '' : substr($directoryName, 5);
                $classId = $this->classIdForLegacyName($legacyName);
                if ($classId === null) {
                    throw new StorageException('A migrated class could not be resolved.');
                }
                $class = $this->getClass($classId);
                $expected = $this->normalizeClassData([
                    'users' => $legacyData['users'],
                    'subjects' => $legacyData['subjects'],
                    'legacyName' => $legacyName,
                ], $classId, $legacyName === '' ? 'Classe predefinita' : $legacyName);
                if ($this->canonicalJson($class['users']) !== $this->canonicalJson($expected['users']) ||
                    $this->canonicalJson($class['subjects']) !== $this->canonicalJson($expected['subjects'])) {
                    throw new StorageException('Legacy migration verification failed.');
                }
                $verified++;
            }

            $archived = $this->archiveLegacyDirectories($directories, $fingerprint);
            return ['imported' => $verified, 'archived' => $archived, 'fingerprint' => $fingerprint];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function exportLegacy(string $targetDirectory): array
    {
        if (file_exists($targetDirectory)) {
            throw new StorageException('The export target already exists.');
        }
        if (!mkdir($targetDirectory, 0700, true) && !is_dir($targetDirectory)) {
            throw new StorageException('Unable to create the export target.');
        }
        $rows = $this->registry->query('SELECT class_id FROM classes ORDER BY created_at, class_id')->fetchAll(PDO::FETCH_COLUMN);
        $created = [];
        foreach ($rows as $classId) {
            $data = $this->getClass((string) $classId);
            $legacyName = $data['legacyName'];
            if ($legacyName === null) {
                $legacyName = $this->safeLegacyName($data['name']);
            }
            $directoryName = $legacyName === '' ? 'JSON' : 'JSON-' . $legacyName;
            $candidate = $directoryName;
            if (is_dir($targetDirectory . DIRECTORY_SEPARATOR . $candidate)) {
                $candidate .= '-' . substr((string) $classId, 0, 8);
            }
            $classDirectory = $targetDirectory . DIRECTORY_SEPARATOR . $candidate;
            mkdir($classDirectory, 0700, true);
            $this->writeJsonFile($classDirectory . DIRECTORY_SEPARATOR . 'users.json', $data['users']);
            foreach ($data['subjects'] as $subjectName => $subjectData) {
                $this->writeJsonFile(
                    $classDirectory . DIRECTORY_SEPARATOR . $this->safeLegacyName((string) $subjectName) . '.json',
                    $subjectData
                );
            }
            $created[] = $candidate;
        }
        return $created;
    }

    public function listAllClassIds(): array
    {
        return array_map('strval', $this->registry->query('SELECT class_id FROM classes ORDER BY created_at, class_id')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function initializeRegistry(): void
    {
        $this->registry->exec(
            'CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL);
             CREATE TABLE IF NOT EXISTS classes (
                class_id TEXT PRIMARY KEY,
                db_file TEXT NOT NULL UNIQUE,
                name_nonce BLOB NOT NULL,
                name_cipher BLOB NOT NULL,
                legacy_tag TEXT UNIQUE,
                created_at INTEGER NOT NULL
             );
             CREATE TABLE IF NOT EXISTS memberships (
                user_tag TEXT NOT NULL,
                class_id TEXT NOT NULL,
                PRIMARY KEY(user_tag, class_id),
                FOREIGN KEY(class_id) REFERENCES classes(class_id) ON DELETE CASCADE
             );
             CREATE INDEX IF NOT EXISTS memberships_user_tag ON memberships(user_tag);
             CREATE TABLE IF NOT EXISTS imports (
                fingerprint TEXT PRIMARY KEY,
                imported_at INTEGER NOT NULL
             );
             CREATE TABLE IF NOT EXISTS creation_log (
                request_tag TEXT NOT NULL,
                created_at INTEGER NOT NULL
             );
             CREATE INDEX IF NOT EXISTS creation_log_request ON creation_log(request_tag, created_at);'
        );
        $statement = $this->registry->prepare('INSERT OR IGNORE INTO metadata(key, value) VALUES(\'schema_version\', :version)');
        $statement->execute([':version' => (string) self::SCHEMA_VERSION]);
        chmod($this->registryPath, 0600);
    }

    private function initializeClassDatabase(string $dbFile): void
    {
        $path = $this->classPath($dbFile);
        if (file_exists($path)) {
            throw new StorageException('The class database already exists.');
        }
        $database = $this->openDatabase($path);
        $database->exec(
            'CREATE TABLE state (
                id INTEGER PRIMARY KEY CHECK(id = 1),
                nonce BLOB NOT NULL,
                ciphertext BLOB NOT NULL,
                updated_at INTEGER NOT NULL
             );'
        );
        chmod($path, 0600);
    }

    private function writeClassState(string $dbFile, string $classId, array $data): void
    {
        $database = $this->openDatabase($this->classPath($dbFile));
        $plaintext = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $this->classAssociatedData($classId),
            $nonce,
            $this->classKey($classId)
        );
        $database->beginTransaction();
        try {
            $statement = $database->prepare(
                'INSERT INTO state(id, nonce, ciphertext, updated_at) VALUES(1, :nonce, :ciphertext, :updated_at)
                 ON CONFLICT(id) DO UPDATE SET nonce = excluded.nonce, ciphertext = excluded.ciphertext, updated_at = excluded.updated_at'
            );
            $statement->bindValue(':nonce', $nonce, PDO::PARAM_LOB);
            $statement->bindValue(':ciphertext', $ciphertext, PDO::PARAM_LOB);
            $statement->bindValue(':updated_at', time(), PDO::PARAM_INT);
            $statement->execute();
            $database->commit();
        } catch (Throwable $error) {
            $database->rollBack();
            throw $error;
        }
    }

    private function reindexClassMemberships(string $classId, array $uids): void
    {
        $this->registry->beginTransaction();
        try {
            $statement = $this->registry->prepare('DELETE FROM memberships WHERE class_id = :class_id');
            $statement->execute([':class_id' => $classId]);
            foreach ($uids as $uid) {
                $this->insertMembership((string) $uid, $classId);
            }
            $this->registry->commit();
        } catch (Throwable $error) {
            $this->registry->rollBack();
            throw $error;
        }
    }

    private function insertMembership(string $uid, string $classId): void
    {
        $statement = $this->registry->prepare(
            'INSERT OR IGNORE INTO memberships(user_tag, class_id) VALUES(:user_tag, :class_id)'
        );
        $statement->execute([':user_tag' => $this->userTag($uid), ':class_id' => $classId]);
    }

    private function importLegacyClass(string $legacyName, array $legacyData): string
    {
        $legacyTag = $this->legacyTag($legacyName);
        $classId = $this->classIdForLegacyName($legacyName);
        $name = $legacyName === '' ? 'Classe predefinita' : $legacyName;
        $created = false;
        if ($classId === null) {
            $classId = bin2hex(random_bytes(16));
            $dbFile = $classId . '.sqlite3';
            [$nameNonce, $nameCipher] = $this->encryptRegistryValue($name, 'class-name:' . $classId);
            $this->initializeClassDatabase($dbFile);
            $statement = $this->registry->prepare(
                'INSERT INTO classes(class_id, db_file, name_nonce, name_cipher, legacy_tag, created_at)
                 VALUES(:class_id, :db_file, :name_nonce, :name_cipher, :legacy_tag, :created_at)'
            );
            $statement->bindValue(':class_id', $classId);
            $statement->bindValue(':db_file', $dbFile);
            $statement->bindValue(':name_nonce', $nameNonce, PDO::PARAM_LOB);
            $statement->bindValue(':name_cipher', $nameCipher, PDO::PARAM_LOB);
            $statement->bindValue(':legacy_tag', $legacyTag);
            $statement->bindValue(':created_at', time(), PDO::PARAM_INT);
            $statement->execute();
            $created = true;
        } else {
            $dbFile = $this->getClassMetadata($classId)['db_file'];
        }

        $data = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'id' => $classId,
            'name' => $name,
            'legacyName' => $legacyName,
            'createdAt' => gmdate(DATE_ATOM),
            'users' => $legacyData['users'],
            'subjects' => $legacyData['subjects'],
        ];
        try {
            $this->writeClassState($dbFile, $classId, $this->normalizeClassData($data, $classId, $name));
            $this->reindexClassMemberships($classId, array_keys($legacyData['users']));
        } catch (Throwable $error) {
            if ($created) {
                try { $this->deleteClass($classId); } catch (Throwable) {}
            }
            throw $error;
        }
        return $classId;
    }

    private function readLegacyDirectory(string $path, $fingerprintContext): array
    {
        $users = [];
        $subjects = [];
        $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);
        foreach ($entries as $entry) {
            $file = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_link($file) || !is_file($file) || pathinfo($entry, PATHINFO_EXTENSION) !== 'json') {
                throw new StorageException('Unexpected entry in legacy data: ' . $entry);
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new StorageException('Unable to read legacy data: ' . $entry);
            }
            if (strlen($contents) > 10 * 1024 * 1024) {
                throw new StorageException('Legacy data file is too large: ' . $entry);
            }
            hash_update($fingerprintContext, basename($path) . '/' . $entry . "\0" . hash('sha256', $contents, true));
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new StorageException('Legacy JSON must contain an object or array.');
            }
            if ($entry === 'users.json') {
                $users = $decoded;
            } else {
                $subjects[pathinfo($entry, PATHINFO_FILENAME)] = $decoded;
            }
        }
        if (!in_array('users.json', $entries, true)) {
            throw new StorageException('Legacy data is missing users.json.');
        }
        foreach ($users as &$user) {
            if (is_array($user)) {
                $user['priority'] ??= false;
            }
        }
        unset($user);
        return ['users' => $users, 'subjects' => $subjects];
    }

    private function archiveLegacyDirectories(array $directories, string $fingerprint): int
    {
        if ($this->archiveDirectory === '') {
            return 0;
        }
        $destination = $this->archiveDirectory . DIRECTORY_SEPARATOR . gmdate('Ymd-His') . '-' . substr($fingerprint, 0, 12);
        if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new StorageException('Unable to create the legacy archive directory.');
        }
        $count = 0;
        foreach ($directories as $name => $source) {
            $target = $destination . DIRECTORY_SEPARATOR . $name;
            if (!@rename($source, $target)) {
                $this->copyDirectory($source, $target);
                $this->deleteDirectory($source);
            }
            $count++;
        }
        chmod($destination, 0700);
        return $count;
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!mkdir($target, 0700, true) && !is_dir($target)) {
            throw new StorageException('Unable to create an archive target.');
        }
        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $entry) {
            if (!copy($source . DIRECTORY_SEPARATOR . $entry, $target . DIRECTORY_SEPARATOR . $entry)) {
                throw new StorageException('Unable to archive legacy data.');
            }
        }
    }

    private function deleteDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            if (!unlink($directory . DIRECTORY_SEPARATOR . $entry)) {
                throw new StorageException('Unable to remove migrated legacy data.');
            }
        }
        if (!rmdir($directory)) {
            throw new StorageException('Unable to remove the migrated legacy directory.');
        }
    }

    private function normalizeClassData(array $data, string $classId, string $className): array
    {
        $data['schemaVersion'] = self::SCHEMA_VERSION;
        $data['id'] = $classId;
        $data['name'] = $data['name'] ?? $className;
        $data['legacyName'] = $data['legacyName'] ?? null;
        $data['createdAt'] = $data['createdAt'] ?? gmdate(DATE_ATOM);
        $data['users'] = is_array($data['users'] ?? null) ? $data['users'] : [];
        $data['subjects'] = is_array($data['subjects'] ?? null) ? $data['subjects'] : [];
        foreach ($data['users'] as &$user) {
            if (!is_array($user)) {
                $user = [];
            }
            $user['name'] ??= 'Utente';
            $user['admin'] = (bool) ($user['admin'] ?? false);
            $user['priority'] = (bool) ($user['priority'] ?? false);
            $user['answers'] = is_array($user['answers'] ?? null) ? $user['answers'] : [];
        }
        unset($user);
        foreach ($data['subjects'] as &$subject) {
            if (!is_array($subject)) {
                $subject = [];
            }
            $subject['lock'] = (bool) ($subject['lock'] ?? false);
            $subject['hide'] = (bool) ($subject['hide'] ?? false);
            $subject['answerCount'] = (int) ($subject['answerCount'] ?? 0);
            $subject['answers'] = is_array($subject['answers'] ?? null) ? $subject['answers'] : [];
            $subject['days'] = is_array($subject['days'] ?? null) ? $subject['days'] : [];
            $subject['type'] ??= 'subject';
            $subject['campaign'] = is_array($subject['campaign'] ?? null) ? $subject['campaign'] : [];
        }
        unset($subject);
        return $data;
    }

    private function newClassData(string $classId, string $name, string $uid, string $adminName): array
    {
        return $this->normalizeClassData([
            'id' => $classId,
            'name' => $name,
            'legacyName' => null,
            'users' => [
                $uid => [
                    'name' => $adminName,
                    'admin' => true,
                    'priority' => false,
                    'answers' => [],
                ],
            ],
            'subjects' => [],
        ], $classId, $name);
    }

    private function getClassMetadata(string $classId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $classId)) {
            throw new StorageException('Invalid class identifier.');
        }
        $statement = $this->registry->prepare(
            'SELECT class_id, db_file, name_nonce, name_cipher, legacy_tag FROM classes WHERE class_id = :class_id'
        );
        $statement->execute([':class_id' => $classId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new StorageException('Class not found.');
        }
        $row['name'] = $this->decryptRegistryValue(
            $row['name_nonce'],
            $row['name_cipher'],
            'class-name:' . $classId
        );
        return $row;
    }

    private function classIdForLegacyName(string $legacyName): ?string
    {
        $statement = $this->registry->prepare('SELECT class_id FROM classes WHERE legacy_tag = :legacy_tag');
        $statement->execute([':legacy_tag' => $this->legacyTag($legacyName)]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function encryptRegistryValue(string $plaintext, string $context): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            'scuola-registry:v1:' . $context,
            $nonce,
            $this->registryKey
        );
        return [$nonce, $cipher];
    }

    private function decryptRegistryValue(string $nonce, string $cipher, string $context): string
    {
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipher,
            'scuola-registry:v1:' . $context,
            $nonce,
            $this->registryKey
        );
        if ($plaintext === false) {
            throw new StorageException('Registry data failed authentication.');
        }
        return $plaintext;
    }

    private function userTag(string $uid): string
    {
        return bin2hex(sodium_crypto_generichash('uid:' . $uid, $this->indexKey, 32));
    }

    private function legacyTag(string $legacyName): string
    {
        return bin2hex(sodium_crypto_generichash('legacy:' . $legacyName, $this->indexKey, 32));
    }

    private function classKey(string $classId): string
    {
        return $this->deriveKey('scuola:class:v1:' . $classId);
    }

    private function classAssociatedData(string $classId): string
    {
        return 'scuola-class:v1:' . $classId;
    }

    private function deriveKey(string $context): string
    {
        return sodium_crypto_generichash($context, $this->masterKey, 32);
    }

    private function loadKey(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new StorageException('The storage key file is missing or invalid.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new StorageException('Unable to read the storage key file.');
        }
        $decoded = base64_decode(trim($contents), true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new StorageException('The storage key file must contain one base64-encoded 32-byte key.');
        }
        return $decoded;
    }

    private function openDatabase(string $path): PDO
    {
        $database = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $database->exec('PRAGMA busy_timeout = 5000; PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA synchronous = FULL;');
        return $database;
    }

    private function ensureDirectory(string $path): void
    {
        if ($path === '' || $path === '.' || $path === DIRECTORY_SEPARATOR) {
            throw new StorageException('Refusing to use an unsafe storage directory.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new StorageException('Unable to create a secure storage directory.');
        }
        chmod($path, 0700);
    }

    private function assertOutsideWebRoot(string $path, string $label): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new StorageException('The ' . $label . ' path must be absolute.');
        }
        $webRoot = realpath(dirname(__DIR__));
        $existingPath = realpath($path);
        $candidate = $existingPath !== false ? $existingPath : ((realpath(dirname($path)) ?: dirname($path)) . DIRECTORY_SEPARATOR . basename($path));
        if ($webRoot !== false && ($candidate === $webRoot || str_starts_with($candidate, $webRoot . DIRECTORY_SEPARATOR))) {
            throw new StorageException('The ' . $label . ' must be outside the web root.');
        }
    }

    private function classPath(string $dbFile): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.sqlite3$/', $dbFile)) {
            throw new StorageException('Invalid class database filename.');
        }
        return $this->classDirectory . DIRECTORY_SEPARATOR . $dbFile;
    }

    private function validateDisplayText(string $value, string $field): string
    {
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < 1 || $length > 100 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new StorageException('Invalid ' . $field . '.');
        }
        return $value;
    }

    private function classCreationAllowed(): bool
    {
        $value = strtolower((string) (getenv('SCUOLA_ALLOW_CLASS_CREATION') ?: '1'));
        return !in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    private function enforceCreationRateLimit(string $requestIdentity): void
    {
        $cutoff = time() - self::CLASS_CREATION_WINDOW;
        $this->registry->prepare('DELETE FROM creation_log WHERE created_at < :cutoff')->execute([':cutoff' => $cutoff]);
        $requestTag = bin2hex(sodium_crypto_generichash('request:' . $requestIdentity, $this->indexKey, 32));
        $statement = $this->registry->prepare(
            'SELECT COUNT(*) FROM creation_log WHERE request_tag = :request_tag AND created_at >= :cutoff'
        );
        $statement->execute([':request_tag' => $requestTag, ':cutoff' => $cutoff]);
        if ((int) $statement->fetchColumn() >= self::CLASS_CREATION_LIMIT) {
            throw new StorageException('Too many classes were created recently. Please try again later.');
        }
    }

    private function recordCreation(string $requestIdentity): void
    {
        $requestTag = bin2hex(sodium_crypto_generichash('request:' . $requestIdentity, $this->indexKey, 32));
        $statement = $this->registry->prepare(
            'INSERT INTO creation_log(request_tag, created_at) VALUES(:request_tag, :created_at)'
        );
        $statement->execute([':request_tag' => $requestTag, ':created_at' => time()]);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function canonicalJson(mixed $value): string
    {
        $sort = function (&$item) use (&$sort): void {
            if (!is_array($item)) {
                return;
            }
            foreach ($item as &$child) {
                $sort($child);
            }
            unset($child);
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
        };
        $sort($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function safeLegacyName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($name));
        return trim((string) $safe, '-') ?: 'class';
    }

    private function writeJsonFile(string $path, mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new StorageException('Unable to write a legacy export file.');
        }
        chmod($path, 0600);
    }
}
