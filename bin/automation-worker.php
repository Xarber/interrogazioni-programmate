#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/SecureStorage.php';
require_once dirname(__DIR__) . '/lib/CampaignService.php';

$lockPath = getenv('SCUOLA_AUTOMATION_LOCK') ?: sys_get_temp_dir() . '/scuola-automation.lock';
$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another automation worker is already running.\n");
    exit(0);
}

function sendAutomatedPush(array $payload): bool
{
    $base = rtrim((string) (getenv('SCUOLA_PUSH_URL') ?: 'http://127.0.0.1:5743'), '/');
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($base . '/api/send-notification', false, $context);
    if ($response === false) return false;
    $decoded = json_decode($response, true);
    return is_array($decoded) && ($decoded['status'] ?? false) === true;
}

try {
    $storage = new SecureStorage();
    $storage->bootstrapLegacy(dirname(__DIR__));
    $now = time();
    $processed = 0;
    $sent = 0;
    foreach ($storage->listAllClassIds() as $classId) {
        $storage->mutateClass($classId, static function (array &$data) use ($now): void {
            CampaignService::processClass($data, $now);
        });
        $classData = $storage->getClass($classId);
        foreach (CampaignService::pendingOutbox($classData, $now) as $event) {
            $subscriptions = [];
            foreach ($event['users'] as $uid) {
                $subscriptions = array_merge($subscriptions, $classData['users'][$uid]['pushSubscriptions'] ?? []);
            }
            $success = true;
            if ($subscriptions !== []) {
                $success = sendAutomatedPush([
                    'title' => $event['title'],
                    'body' => $event['body'],
                    'tag' => $event['tag'],
                    'renotify' => false,
                    'requireInteraction' => false,
                    'silent' => false,
                    'urgency' => 'normal',
                    'subject' => $event['subject'],
                    'classId' => $classId,
                    'subscriptions' => $subscriptions,
                ]);
            }
            $storage->mutateClass($classId, static function (array &$data) use ($event, $success, $now): void {
                CampaignService::markOutboxResult($data, $event['subject'], $event['eventId'], $success, $now);
            });
            if ($success) $sent++;
        }
        $processed++;
    }
    fwrite(STDOUT, sprintf("Processed %d classes; completed %d notification events.\n", $processed, $sent));
} catch (Throwable $error) {
    fwrite(STDERR, 'Automation worker failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
