#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/test_helpers.php';
require_once dirname(__DIR__) . '/lib/CampaignService.php';

$users = [
    'priority' => ['name' => 'Priorità', 'priority' => true],
    'regular' => ['name' => 'Regolare', 'priority' => false],
    'watcher' => ['name' => 'Spettatore', 'priority' => true, 'watcherAcc' => true],
    'excluded' => ['name' => 'Escluso', 'priority' => true],
    'admin' => ['name' => 'Admin', 'priority' => false],
];
$subject = [
    'lock' => false,
    'hide' => false,
    'days' => ['01-10-2026' => ['dayName' => 'Giovedì', 'availability' => '5/5']],
    'answers' => ['excluded' => ['date' => 'Esclusi', 'answerNumber' => 1]],
];

CampaignService::configure($subject, [
    'unlockAt' => 10000,
    'preNoticeMinutes' => 60,
    'priorityWindowMinutes' => 180,
    'priorityReminderMinutes' => 30,
    'regularReminderMinutes' => 120,
    'notifyCoordinator' => true,
], 'admin');
testSame(true, $subject['lock'], 'Scheduling a campaign must lock the subject.');

$class = ['users' => $users, 'subjects' => ['Matematica' => $subject]];
CampaignService::processClass($class, 6400);
$campaign = $class['subjects']['Matematica']['campaign'];
testAssert(isset($campaign['outbox']['preunlock']), 'The pre-unlock event must be queued at the configured lead time.');

CampaignService::processClass($class, 10000);
$subject = $class['subjects']['Matematica'];
testSame(false, $subject['lock'], 'The worker must unlock the subject on time.');
testSame('priority', $subject['campaign']['status'], 'A priority cohort must start in priority-only mode.');
testSame(true, CampaignService::votingDecision($subject, 'priority', 10000)['allowed'], 'Priority users must vote immediately.');
$regularDecision = CampaignService::votingDecision($subject, 'regular', 10000);
testSame(false, $regularDecision['allowed'], 'Regular users must be blocked during priority access.');
testSame('priority-window', $regularDecision['reason'], 'Regular users must receive the priority-window reason.');
testAssert(!in_array('watcher', $subject['campaign']['cohort']['eligible'], true), 'Watchers must not block a campaign.');
testAssert(!in_array('excluded', $subject['campaign']['cohort']['eligible'], true), 'Excluded users must not block a campaign.');
$unlockOutboxCount = count($subject['campaign']['outbox']);
CampaignService::processClass($class, 10000);
testSame($unlockOutboxCount, count($class['subjects']['Matematica']['campaign']['outbox']), 'Milestone events must not be queued twice.');
testSame('Matematica', $class['subjects']['Matematica']['campaign']['outbox']['unlock-priority']['subject'], 'Notification events must retain the selected subject.');

$retryClass = $class;
CampaignService::markOutboxResult($retryClass, 'Matematica', 'unlock-priority', false, 10000);
$retryEvent = $retryClass['subjects']['Matematica']['campaign']['outbox']['unlock-priority'];
testSame('pending', $retryEvent['status'], 'Failed notifications must remain pending.');
testAssert($retryEvent['nextAttemptAt'] > 10000, 'Failed notifications must use a retry delay.');
CampaignService::markOutboxResult($retryClass, 'Matematica', 'unlock-priority', true, $retryEvent['nextAttemptAt']);
testSame('sent', $retryClass['subjects']['Matematica']['campaign']['outbox']['unlock-priority']['status'], 'Successful notification retries must be marked sent.');

CampaignService::processClass($class, 11800);
$priorityReminderFound = false;
foreach (array_keys($class['subjects']['Matematica']['campaign']['outbox']) as $eventId) {
    if (str_starts_with($eventId, 'reminder-priority-priority-')) $priorityReminderFound = true;
}
testAssert($priorityReminderFound, 'Priority reminders must be queued at the priority interval.');

$class['subjects']['Matematica']['answers']['priority'] = ['date' => '01-10-2026', 'answerNumber' => 2];
CampaignService::processClass($class, 11801);
$campaign = $class['subjects']['Matematica']['campaign'];
testSame('general', $campaign['status'], 'General access must open as soon as all priority users finish.');
testAssert(isset($campaign['outbox']['general-open']), 'General opening must be queued once.');
testAssert(isset($campaign['outbox']['priority-complete-admin']), 'The optional coordinator milestone must be queued.');
testSame(true, CampaignService::votingDecision($class['subjects']['Matematica'], 'regular', 11801)['allowed'], 'Regular users must vote in general access.');

CampaignService::processClass($class, 17200);
testAssert(!isset($class['subjects']['Matematica']['campaign']['outbox']['reminder-regular-regular-2']), 'Regular reminders must not use the shorter priority interval.');
CampaignService::processClass($class, 19001);
$regularReminderFound = false;
foreach (array_keys($class['subjects']['Matematica']['campaign']['outbox']) as $eventId) {
    if (str_starts_with($eventId, 'reminder-regular-regular-')) $regularReminderFound = true;
}
testAssert($regularReminderFound, 'Regular reminders must be queued at their own interval.');

$class['subjects']['Matematica']['answers']['regular'] = ['date' => '01-10-2026', 'answerNumber' => 3];
$class['subjects']['Matematica']['answers']['admin'] = ['date' => '01-10-2026', 'answerNumber' => 4];
CampaignService::processClass($class, 19002);
testSame('complete', $class['subjects']['Matematica']['campaign']['status'], 'The campaign must complete after every eligible user answers.');
testAssert(isset($class['subjects']['Matematica']['campaign']['outbox']['all-complete-admin']), 'The all-complete coordinator milestone must be queued.');

$locked = $class['subjects']['Matematica'];
$locked['lock'] = true;
testSame('locked', CampaignService::votingDecision($locked, 'priority', 20000)['reason'], 'Locked subjects must reject direct votes.');
$hidden = $class['subjects']['Matematica'];
$hidden['hide'] = true;
$hidden['lock'] = false;
testSame('hidden', CampaignService::votingDecision($hidden, 'priority', 20000)['reason'], 'Hidden subjects must reject direct votes.');

$timeoutSubject = [
    'lock' => false, 'hide' => false, 'days' => [], 'answers' => [],
    'campaign' => ['enabled' => true, 'status' => 'scheduled', 'unlockAt' => 30000, 'priorityWindowMinutes' => 5],
];
$timeoutClass = ['users' => ['p' => ['priority' => true], 'r' => ['priority' => false]], 'subjects' => ['Fisica' => $timeoutSubject]];
CampaignService::processClass($timeoutClass, 30000);
CampaignService::processClass($timeoutClass, 30300);
testSame('general', $timeoutClass['subjects']['Fisica']['campaign']['status'], 'General access must open when the priority window expires.');

CampaignService::reset($timeoutClass['subjects']['Fisica']);
testSame('idle', $timeoutClass['subjects']['Fisica']['campaign']['status'], 'Clearing/resetting must remove prior campaign state.');

fwrite(STDOUT, "campaign_test: OK\n");
