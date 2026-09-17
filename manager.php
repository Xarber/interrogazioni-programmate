<?php

declare(strict_types=1);

error_reporting(E_ERROR | E_PARSE);
session_start();

require_once __DIR__ . '/lib/SecureStorage.php';
require_once __DIR__ . '/lib/CampaignService.php';

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

set_exception_handler(static function (Throwable $error): never {
    error_log('Scuola request failed: ' . get_class($error) . ': ' . $error->getMessage());
    $clientError = $error instanceof StorageException || $error instanceof InvalidArgumentException;
    jsonResponse([
        'status' => false,
        'message' => $clientError ? $error->getMessage() : 'Unexpected server error.',
    ], $clientError ? 400 : 500);
});

function requestBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function safeName(string $name): string
{
    return trim((string) preg_replace('/[^\pL\pN _-]+/u', '-', $name));
}

function requireAdmin(?array $user): void
{
    if (!(bool) ($user['admin'] ?? false)) jsonResponse(['status' => false, 'message' => 'Not Authorized!'], 403);
}

function allSubjectData(array $classData): array
{
    $result = [];
    foreach ($classData['subjects'] as $name => $data) $result[] = ['fileName' => $name, 'data' => $data];
    return $result;
}

function classesForAdmin(SecureStorage $storage, string $userId): array
{
    $classes = [];
    foreach ($storage->listClassesForUser($userId) as $membership) {
        $data = $storage->getClass($membership['id']);
        if ((bool) ($data['users'][$userId]['admin'] ?? false)) {
            $classes[] = ['id' => $membership['id'], 'name' => $membership['name'], 'admin' => true];
        }
    }
    return $classes;
}

function pushRequest(string $path, array $payload): array
{
    $base = rtrim((string) (getenv('SCUOLA_PUSH_URL') ?: 'http://127.0.0.1:5743'), '/');
    $context = stream_context_create(['http' => [
        'header' => "Content-Type: application/json\r\n", 'method' => 'POST',
        'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($base . $path, false, $context);
    if ($response === false) return ['status' => false, 'message' => 'Push service unavailable.'];
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['status' => false, 'message' => 'Invalid push service response.'];
}

function calendarEscape(string $value): string
{
    return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $value);
}

function generateICSFile(array $events): string
{
    $content = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//scuola.xcenter.it//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nX-WR-CALNAME:Calendario Interrogazioni Programmate\r\nX-WR-TIMEZONE:Europe/Rome\r\n";
    foreach ($events as $event) {
        $content .= "BEGIN:VEVENT\r\nSUMMARY:" . calendarEscape($event['title']) . "\r\n";
        $content .= 'DTSTART;VALUE=DATE:' . $event['start']->format('Ymd') . "\r\n";
        $content .= 'DTEND;VALUE=DATE:' . $event['end']->format('Ymd') . "\r\n";
        $content .= 'LOCATION:' . calendarEscape($event['location']) . "\r\n";
        $content .= 'DESCRIPTION:' . calendarEscape($event['description']) . "\r\n";
        $content .= 'UID:' . hash('sha256', $event['id']) . "@scuola.xcenter.it\r\n";
        $content .= 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\nSTATUS:CONFIRMED\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\n";
    }
    return $content . "END:VCALENDAR\r\n";
}

$body = requestBody();
$request = array_merge($_GET, $_POST, $body);
$scope = (string) ($request['scope'] ?? '');

try {
    $storage = new SecureStorage();
    $storage->bootstrapLegacy(__DIR__);
} catch (Throwable $error) {
    error_log('Secure storage bootstrap failed: ' . $error->getMessage());
    jsonResponse(['status' => false, 'message' => 'Secure storage is unavailable.'], 500);
}

if ($scope === 'createClass') {
    try {
        $created = $storage->createClass(
            (string) ($body['className'] ?? ''), (string) ($body['adminName'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        );
        $_SESSION['userID'] = $created['UID'];
        $_SESSION['classId'] = $created['classId'];
        jsonResponse(['status' => true, 'UID' => $created['UID'], 'classId' => $created['classId'], 'className' => $created['className']], 201);
    } catch (Throwable $error) {
        jsonResponse(['status' => false, 'message' => $error->getMessage()], 400);
    }
}

$userId = (string) ($request['UID'] ?? $_SESSION['userID'] ?? '');
if ($userId !== '') $_SESSION['userID'] = $userId;
$classSelector = (string) ($body['appLoadClass'] ?? $request['class'] ?? '');
$legacyProfile = (string) ($body['appLoadProfile'] ?? $request['profile'] ?? '');
$classId = null;
if ($userId !== '') {
    if ($classSelector !== '') {
        $classId = $storage->resolveClassForUser($userId, $classSelector);
        if ($classId === null && $legacyProfile !== '') {
            $classId = $storage->resolveClassForUser($userId, null, $legacyProfile);
        }
    } elseif ($legacyProfile !== '') {
        $classId = $storage->resolveClassForUser($userId, null, $legacyProfile);
    } elseif (!empty($_SESSION['classId'])) {
        $classId = $storage->resolveClassForUser($userId, (string) $_SESSION['classId']);
    }
    if ($classId === null && $classSelector === '' && $legacyProfile === '') {
        $classId = $storage->resolveClassForUser($userId);
    }
}
if ($classId !== null) $_SESSION['classId'] = $classId;
$classData = $classId !== null ? $storage->getClass($classId) : null;
$userData = $classData['users'][$userId] ?? null;
$subjectName = (string) ($request['subject'] ?? '');
$subjectData = $classData !== null && isset($classData['subjects'][$subjectName]) ? $classData['subjects'][$subjectName] : null;
$memberships = $userId !== '' ? $storage->listClassesForUser($userId) : [];

if ($scope === 'loadPageData') {
    $classList = [];
    foreach ($memberships as $membership) {
        $memberClass = $storage->getClass($membership['id']);
        $classList[] = ['id' => $membership['id'], 'name' => $membership['name'], 'admin' => (bool) ($memberClass['users'][$userId]['admin'] ?? false)];
    }
    $result = [
        'status' => true, 'user' => ['subjectData' => ['day' => false]], 'users' => [], 'classId' => $classId,
        'className' => $classData['name'] ?? null, 'classList' => $classList,
        'profileList' => $classList, 'profiles' => (bool) ($userData['admin'] ?? false) ? classesForAdmin($storage, $userId) : [],
        'profiled' => $classId, 'subject' => false, 'subjectList' => [], 'serverTime' => time(),
    ];
    if ($userData !== null && $classData !== null) {
        $result['user'] = array_merge($userData, ['subjectData' => ['day' => $subjectData['answers'][$userId]['date'] ?? false]]);
        $result['users'] = (bool) ($userData['admin'] ?? false) ? $classData['users'] : [];
        foreach ($classData['subjects'] as $name => $data) if (!(bool) ($data['hide'] ?? false)) $result['subjectList'][] = $name;
        if ($subjectData !== null && !(bool) ($subjectData['hide'] ?? false)) {
            $result['subject'] = [
                'name' => $subjectName, 'days' => $subjectData['days'], 'lock' => $subjectData['lock'],
                'hide' => $subjectData['hide'], 'type' => $subjectData['type'] ?? 'subject',
                'campaign' => CampaignService::normalize($subjectData['campaign'] ?? []),
                'voting' => CampaignService::votingDecision($subjectData, $userId, time()),
            ];
        }
    }
    if ($userId === '') $result['section'] = 'login';
    elseif ($memberships === []) $result['section'] = 'login-account-not-found';
    elseif ($classData === null || $userData === null || isset($request['changeProfile'])) $result['section'] = 'changeprofile';
    elseif ($subjectData === null || (bool) ($subjectData['hide'] ?? false)) $result['section'] = 'schedule-subject';
    elseif (isset($subjectData['answers'][$userId])) {
        $result['section'] = $subjectData['answers'][$userId]['date'] === 'Esclusi' ? 'alreadyscheduled-excluded' : 'alreadyscheduled';
    } else {
        $decision = CampaignService::votingDecision($subjectData, $userId, time());
        if (($decision['reason'] ?? null) === 'priority-window') $result['section'] = 'priority-wait';
        elseif (!$decision['allowed']) $result['section'] = 'nodays';
        elseif (isset($request['day']) && (!isset($subjectData['days'][$request['day']]) || (int) strtok((string) $subjectData['days'][$request['day']]['availability'], '/') === 0)) $result['section'] = 'dayunavailable';
        elseif (count($subjectData['days']) === 0) $result['section'] = 'nodays';
        else $result['section'] = 'schedule-day';
    }
    jsonResponse($result);
}

if ($classData === null || $userData === null || $classId === null) jsonResponse(['status' => false, 'message' => 'Not Authorized!'], 403);

if ($scope === 'getAllData') { requireAdmin($userData); jsonResponse(allSubjectData($classData)); }
if ($scope === 'getAllUsers') { requireAdmin($userData); jsonResponse($classData['users']); }

if ($scope === 'updateSettings') {
    requireAdmin($userData);
    $updates = array_is_list($body) ? $body : [$body];
    $type = (string) ($request['type'] ?? 'subject');
    $storage->mutateClass($classId, function (array &$data) use ($updates, $type, $userId): void {
        if (!(bool) ($data['users'][$userId]['admin'] ?? false)) throw new StorageException('Not Authorized!');
        foreach ($updates as $update) {
            if ($type === 'users') {
                $newUsers = $update['data'] ?? $update;
                if (!is_array($newUsers) || !isset($newUsers[$userId])) throw new StorageException('Invalid user update.');
                foreach ($newUsers as &$user) $user['priority'] = (bool) ($user['priority'] ?? false);
                unset($user);
                $data['users'] = $newUsers;
                continue;
            }
            $fileName = safeName((string) ($update['fileName'] ?? ''));
            if ($fileName === '' || strtolower($fileName) === 'users') continue;
            if (($update['data'] ?? null) === 'removed') {
                unset($data['subjects'][$fileName]);
                foreach ($data['users'] as &$user) unset($user['answers'][$fileName]);
                unset($user);
                continue;
            }
            $subject = $update['data'] ?? [];
            if (!is_array($subject)) throw new StorageException('Invalid subject update.');
            if ((bool) ($update['cleared'] ?? false)) {
                $subject['answers'] = []; $subject['answerCount'] = 0;
                foreach ($subject['days'] ?? [] as &$day) {
                    $maximum = explode('/', (string) ($day['availability'] ?? '0/0'), 2)[1] ?? '0';
                    $day['availability'] = $maximum . '/' . $maximum;
                }
                unset($day);
                foreach ($data['users'] as &$user) unset($user['answers'][$fileName]);
                unset($user);
                CampaignService::reset($subject);
            }
            $subject['campaign'] = CampaignService::normalize($subject['campaign'] ?? []);
            if ((bool) ($subject['lock'] ?? false)) CampaignService::pauseForLock($subject);
            $data['subjects'][$fileName] = $subject;
        }
    });
    $fresh = $storage->getClass($classId);
    jsonResponse(['status' => true, 'newData' => ['subjects' => allSubjectData($fresh), 'users' => $fresh['users'], 'profiles' => $storage->listClassesForUser($userId)]]);
}

if ($scope === 'profileMGMT' || $scope === 'classMGMT') {
    requireAdmin($userData);
    $action = (string) ($body['action'] ?? '');
    if ($action === 'listprofiles' || $action === 'listclasses') jsonResponse(['status' => true, 'profiles' => classesForAdmin($storage, $userId)]);
    if ($action === 'newprofile' || $action === 'newclass') {
        $name = (string) ($body['profile'] ?? $body['className'] ?? '');
        $created = ($body['method'] ?? '') === 'import'
            ? $storage->copyClass($classId, $name, $userId)
            : $storage->createClassForExistingAdmin($name, $userId, $userData);
        jsonResponse(['status' => true, ...$created]);
    }
    $targetId = (string) ($body['classId'] ?? $body['profile'] ?? '');
    $targetId = $storage->resolveClassForUser($userId, $targetId) ?? '';
    if ($targetId === '') jsonResponse(['status' => false, 'message' => 'Class not found.'], 404);
    $target = $storage->getClass($targetId);
    requireAdmin($target['users'][$userId] ?? null);
    if ($action === 'renameprofile' || $action === 'renameclass') { $storage->renameClass($targetId, (string) ($body['newName'] ?? '')); jsonResponse(['status' => true]); }
    if ($action === 'deleteprofile' || $action === 'deleteclass') {
        $storage->deleteClass($targetId);
        if (($_SESSION['classId'] ?? null) === $targetId) unset($_SESSION['classId']);
        jsonResponse(['status' => true]);
    }
    jsonResponse(['status' => false, 'message' => 'Invalid action.'], 400);
}

if ($scope === 'campaign') {
    requireAdmin($userData);
    $action = (string) ($body['action'] ?? 'status');
    if ($subjectName === '' || !isset($classData['subjects'][$subjectName])) jsonResponse(['status' => false, 'message' => 'Subject not found.'], 404);
    if ($action === 'status') jsonResponse(['status' => true, 'campaign' => CampaignService::normalize($subjectData['campaign'] ?? [])]);
    $storage->mutateClass($classId, function (array &$data) use ($action, $subjectName, $body, $userId): void {
        $subject = &$data['subjects'][$subjectName];
        if ($action === 'configure') CampaignService::configure($subject, $body['settings'] ?? [], $userId);
        elseif ($action === 'start') CampaignService::startNow($subject, $data['users'], $userId, time());
        elseif ($action === 'cancel') CampaignService::reset($subject);
        elseif ($action === 'resume') {
            if ((bool) ($subject['hide'] ?? false)) throw new StorageException('Make the subject visible before resuming.');
            $subject['campaign']['status'] = 'scheduled'; $subject['campaign']['pausedReason'] = null;
            $subject['campaign']['unlockAt'] = max(time(), (int) ($subject['campaign']['unlockAt'] ?? time()));
        } else throw new StorageException('Invalid campaign action.');
        unset($subject);
    });
    $fresh = $storage->getClass($classId);
    jsonResponse(['status' => true, 'campaign' => $fresh['subjects'][$subjectName]['campaign']]);
}

if ($scope === 'notifications') {
    $action = (string) ($body['action'] ?? '');
    if ($action === 'VAPIDkey') jsonResponse(pushRequest('/api/vapid-public-key', $body));
    if ($action === 'subscribe' || $action === 'unsubscribe') {
        $subscription = $body['subscription'] ?? null;
        if ($action === 'subscribe' && (!is_array($subscription) || empty($subscription['endpoint']) || empty($subscription['keys']))) jsonResponse(['status' => false, 'message' => 'Invalid Subscription!'], 400);
        $subscriptionUpdate = function (array &$data) use ($userId, $subscription, $action): void {
            if (!isset($data['users'][$userId])) return;
            $subscriptions = $data['users'][$userId]['pushSubscriptions'] ?? [];
            if ($action === 'subscribe' && !in_array(json_encode($subscription), array_map('json_encode', $subscriptions), true)) $subscriptions[] = $subscription;
            if ($action === 'unsubscribe') $subscriptions = array_values(array_filter($subscriptions, static fn ($item): bool => json_encode($item) !== json_encode($subscription)));
            if ($subscriptions === []) unset($data['users'][$userId]['pushSubscriptions']);
            else $data['users'][$userId]['pushSubscriptions'] = $subscriptions;
        };
        if ($action === 'unsubscribe') {
            foreach ($storage->listClassesForUser($userId) as $membership) $storage->mutateClass($membership['id'], $subscriptionUpdate);
        } else {
            $storage->mutateClass($classId, $subscriptionUpdate);
        }
        jsonResponse(['status' => true, 'message' => null]);
    }
    if ($action === 'sendNotifications') {
        requireAdmin($userData);
        $subscriptions = [];
        foreach (($body['users'] ?? []) as $uid) $subscriptions = array_merge($subscriptions, $classData['users'][$uid]['pushSubscriptions'] ?? []);
        $payload = $body; $payload['subscriptions'] = $subscriptions; $payload['classId'] = $classId;
        jsonResponse(pushRequest('/api/send-notification', $payload));
    }
    jsonResponse(['status' => false, 'message' => 'Invalid Action!'], 400);
}

if ($scope === 'schedule') {
    $day = (string) ($request['day'] ?? '');
    $result = ['status' => false, 'message' => 'Invalid Day!'];
    $storage->mutateClass($classId, function (array &$data) use ($userId, $subjectName, $day, &$result): void {
        if (!isset($data['subjects'][$subjectName], $data['users'][$userId])) return;
        $subject = &$data['subjects'][$subjectName];
        $decision = CampaignService::votingDecision($subject, $userId, time());
        if (!$decision['allowed']) { $result = ['status' => false, 'message' => $decision['reason'], 'opensAt' => $decision['opensAt'] ?? null]; unset($subject); return; }
        if (isset($subject['answers'][$userId]) || !isset($subject['days'][$day])) { unset($subject); return; }
        $availability = explode('/', (string) $subject['days'][$day]['availability'], 2);
        if ((int) ($availability[0] ?? 0) === 0) { unset($subject); return; }
        if (($availability[1] ?? '') !== '-1') $subject['days'][$day]['availability'] = ((int) $availability[0] - 1) . '/' . $availability[1];
        $subject['answerCount'] = (int) ($subject['answerCount'] ?? 0) + 1;
        $subject['answers'][$userId] = ['date' => $day, 'answerNumber' => $subject['answerCount']];
        $data['users'][$userId]['answers'][$subjectName] ??= [];
        $data['users'][$userId]['answers'][$subjectName][] = $day;
        unset($subject);
        CampaignService::processClass($data, time());
        $result = ['status' => true, 'message' => null];
    });
    jsonResponse($result, $result['status'] ? 200 : 409);
}

if ($scope === 'downloadProfile' || $scope === 'downloadClass') {
    requireAdmin($userData);
    if (!class_exists('ZipArchive')) jsonResponse(['status' => false, 'message' => 'ZIP support is unavailable.'], 500);
    $temp = tempnam(sys_get_temp_dir(), 'scuola-profile-'); $zip = new ZipArchive();
    if ($temp === false || $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) jsonResponse(['status' => false, 'message' => 'Unable to create the export.'], 500);
    $zip->addFromString('profile.json', json_encode(['name' => $classData['name'], 'classId' => $classId, 'date' => date('d.m.Y')]));
    $zip->addEmptyDir('data');
    $zip->addFromString('data/users.json', json_encode($classData['users'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    foreach ($classData['subjects'] as $name => $data) $zip->addFromString('data/' . safeName($name) . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->close();
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="' . safeName($classData['name']) . '.profile.zip"'); header('Content-Length: ' . filesize($temp));
    readfile($temp); unlink($temp); exit;
}

if ($scope === 'uploadProfile' || $scope === 'uploadClass') {
    requireAdmin($userData);
    if (!isset($_FILES['profileData']['tmp_name']) || !is_uploaded_file($_FILES['profileData']['tmp_name'])) jsonResponse(['status' => false, 'message' => 'No file uploaded.'], 400);
    $zip = new ZipArchive();
    if ($zip->open($_FILES['profileData']['tmp_name']) !== true) jsonResponse(['status' => false, 'message' => 'Invalid profile ZIP.'], 400);
    $profileRaw = $zip->getFromName('profile.json'); $usersRaw = $zip->getFromName('data/users.json');
    if ($profileRaw === false || $usersRaw === false) { $zip->close(); jsonResponse(['status' => false, 'message' => 'Invalid profile ZIP.'], 400); }
    $profile = json_decode($profileRaw, true, 512, JSON_THROW_ON_ERROR); $users = json_decode($usersRaw, true, 512, JSON_THROW_ON_ERROR); $subjects = [];
    if ($zip->numFiles > 500 || strlen($usersRaw) > 10 * 1024 * 1024) { $zip->close(); jsonResponse(['status' => false, 'message' => 'Profile ZIP is too large.'], 400); }
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entry = $zip->getNameIndex($index);
        if (!is_string($entry) || $entry === 'data/users.json' || !preg_match('#^data/([^/]+)\.json$#u', $entry, $matches)) continue;
        $subjectRaw = $zip->getFromIndex($index);
        if (!is_string($subjectRaw) || strlen($subjectRaw) > 10 * 1024 * 1024) { $zip->close(); jsonResponse(['status' => false, 'message' => 'Profile ZIP is too large.'], 400); }
        $subjects[$matches[1]] = json_decode($subjectRaw, true, 512, JSON_THROW_ON_ERROR);
    }
    $zip->close();
    if (!isset($users[$userId])) $users[$userId] = $userData;
    $users[$userId]['admin'] = true;
    $created = $storage->importClass((string) ($profile['name'] ?? 'Classe importata'), $users, $subjects);
    jsonResponse(['status' => true, 'profileName' => $created['className'], 'classId' => $created['classId']]);
}

if ($scope === 'syncICal') {
    $events = [];
    foreach (($userData['answers'] ?? []) as $answerSubject => $answers) {
        if (($classData['subjects'][$answerSubject]['type'] ?? 'subject') !== 'subject') continue;
        foreach ($answers as $answer) {
            $date = DateTimeImmutable::createFromFormat('!d-m-Y', (string) $answer);
            if (!$date) continue;
            $events[] = ['title' => 'Interrogazione: ' . $answerSubject, 'start' => $date, 'end' => $date->modify('+1 day'), 'location' => 'Scuola', 'description' => 'Interrogazione programmata per ' . $answerSubject . ' il ' . $answer, 'id' => $classId . ':' . $userId . ':' . $answerSubject . ':' . $answer];
        }
    }
    header('Content-Type: text/calendar; charset=utf-8'); echo generateICSFile($events); exit;
}

if ($scope === 'redirectToCalendar') {
    $calendar = rawurlencode('webcal://' . $_SERVER['HTTP_HOST'] . '/manager.php?' . http_build_query(['scope' => 'syncICal', 'UID' => $userId, 'class' => $classId]));
    header('Location: https://calendar.google.com/calendar/r?cid=' . $calendar, true, 302); exit;
}

jsonResponse(['status' => false, 'message' => 'Invalid scope.'], 400);
