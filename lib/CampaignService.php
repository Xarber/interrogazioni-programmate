<?php

declare(strict_types=1);

final class CampaignService
{
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'status' => 'idle',
            'unlockAt' => null,
            'preNoticeMinutes' => 60,
            'priorityWindowMinutes' => 180,
            'priorityReminderMinutes' => 60,
            'regularReminderMinutes' => 180,
            'notifyCoordinator' => false,
            'coordinatorUid' => null,
            'timezone' => 'Europe/Rome',
            'startedAt' => null,
            'generalOpenedAt' => null,
            'completedAt' => null,
            'pausedReason' => null,
            'cohort' => ['eligible' => [], 'priority' => [], 'regular' => []],
            'events' => [],
            'lastReminders' => [],
            'outbox' => [],
        ];
    }

    public static function normalize(array $campaign): array
    {
        $campaign = array_replace(self::defaults(), $campaign);
        $campaign['cohort'] = array_replace(self::defaults()['cohort'], is_array($campaign['cohort']) ? $campaign['cohort'] : []);
        $campaign['enabled'] = (bool) $campaign['enabled'];
        $campaign['notifyCoordinator'] = (bool) $campaign['notifyCoordinator'];
        foreach (['preNoticeMinutes', 'priorityWindowMinutes', 'priorityReminderMinutes', 'regularReminderMinutes'] as $field) {
            $campaign[$field] = max(1, min(10080, (int) $campaign[$field]));
        }
        if ($campaign['unlockAt'] !== null) {
            $campaign['unlockAt'] = (int) $campaign['unlockAt'];
        }
        foreach (['eligible', 'priority', 'regular'] as $group) {
            $campaign['cohort'][$group] = array_values(array_unique(array_map('strval', $campaign['cohort'][$group] ?? [])));
        }
        $campaign['events'] = is_array($campaign['events']) ? $campaign['events'] : [];
        $campaign['lastReminders'] = is_array($campaign['lastReminders']) ? $campaign['lastReminders'] : [];
        $campaign['outbox'] = is_array($campaign['outbox']) ? $campaign['outbox'] : [];
        return $campaign;
    }

    public static function configure(array &$subject, array $settings, string $coordinatorUid): array
    {
        $campaign = self::normalize($subject['campaign'] ?? []);
        $unlockAt = self::parseTimestamp($settings['unlockAt'] ?? null, (string) ($settings['timezone'] ?? $campaign['timezone']));
        if ($unlockAt === null) {
            throw new InvalidArgumentException('A valid unlock date and time is required.');
        }
        $campaign = self::normalize([
            ...$campaign,
            'enabled' => true,
            'status' => 'scheduled',
            'unlockAt' => $unlockAt,
            'preNoticeMinutes' => $settings['preNoticeMinutes'] ?? $campaign['preNoticeMinutes'],
            'priorityWindowMinutes' => $settings['priorityWindowMinutes'] ?? $campaign['priorityWindowMinutes'],
            'priorityReminderMinutes' => $settings['priorityReminderMinutes'] ?? $campaign['priorityReminderMinutes'],
            'regularReminderMinutes' => $settings['regularReminderMinutes'] ?? $campaign['regularReminderMinutes'],
            'notifyCoordinator' => $settings['notifyCoordinator'] ?? $campaign['notifyCoordinator'],
            'coordinatorUid' => $coordinatorUid,
            'timezone' => $settings['timezone'] ?? $campaign['timezone'],
            'startedAt' => null,
            'generalOpenedAt' => null,
            'completedAt' => null,
            'pausedReason' => null,
            'cohort' => ['eligible' => [], 'priority' => [], 'regular' => []],
            'events' => [],
            'lastReminders' => [],
            'outbox' => [],
        ]);
        $subject['lock'] = true;
        $subject['campaign'] = $campaign;
        return $campaign;
    }

    public static function startNow(array &$subject, array $users, string $coordinatorUid, int $now): array
    {
        $campaign = self::normalize($subject['campaign'] ?? []);
        $campaign['enabled'] = true;
        $campaign['status'] = 'scheduled';
        $campaign['unlockAt'] = $now;
        $campaign['coordinatorUid'] = $coordinatorUid;
        $campaign['events'] = [];
        $campaign['lastReminders'] = [];
        $campaign['outbox'] = [];
        $subject['campaign'] = $campaign;
        self::processSubject($subject, $users, $now, (string) ($subject['_name'] ?? 'Materia'));
        return $subject['campaign'];
    }

    public static function reset(array &$subject): void
    {
        $subject['campaign'] = self::defaults();
    }

    public static function pauseForLock(array &$subject): void
    {
        $campaign = self::normalize($subject['campaign'] ?? []);
        if ($campaign['enabled'] && in_array($campaign['status'], ['priority', 'general'], true)) {
            $campaign['status'] = 'paused';
            $campaign['pausedReason'] = 'locked';
        }
        $subject['campaign'] = $campaign;
    }

    public static function votingDecision(array $subject, string $uid, int $now): array
    {
        if ((bool) ($subject['hide'] ?? false)) {
            return ['allowed' => false, 'reason' => 'hidden'];
        }
        if ((bool) ($subject['lock'] ?? false)) {
            $campaign = self::normalize($subject['campaign'] ?? []);
            if ($campaign['enabled'] && $campaign['status'] === 'scheduled') {
                return ['allowed' => false, 'reason' => 'scheduled', 'opensAt' => $campaign['unlockAt']];
            }
            return ['allowed' => false, 'reason' => 'locked'];
        }
        $campaign = self::normalize($subject['campaign'] ?? []);
        if (!$campaign['enabled'] || in_array($campaign['status'], ['idle', 'complete'], true)) {
            return ['allowed' => true, 'reason' => null];
        }
        if ($campaign['status'] === 'general') {
            return ['allowed' => in_array($uid, $campaign['cohort']['eligible'], true), 'reason' => 'not-eligible'];
        }
        if ($campaign['status'] === 'priority') {
            if (in_array($uid, $campaign['cohort']['priority'], true)) {
                return ['allowed' => true, 'reason' => null];
            }
            $opensAt = ((int) $campaign['startedAt']) + ($campaign['priorityWindowMinutes'] * 60);
            return ['allowed' => false, 'reason' => 'priority-window', 'opensAt' => $opensAt];
        }
        return ['allowed' => false, 'reason' => $campaign['status'] === 'scheduled' ? 'scheduled' : 'paused'];
    }

    public static function processClass(array &$classData, int $now): array
    {
        $changed = false;
        foreach ($classData['subjects'] as $name => &$subject) {
            $subject['_name'] = $name;
            $before = json_encode($subject['campaign'] ?? []);
            self::processSubject($subject, $classData['users'], $now, (string) $name);
            unset($subject['_name']);
            if ($before !== json_encode($subject['campaign'] ?? [])) {
                $changed = true;
            }
        }
        unset($subject);
        return ['changed' => $changed];
    }

    public static function pendingOutbox(array $classData, int $now): array
    {
        $pending = [];
        foreach ($classData['subjects'] as $subjectName => $subject) {
            $campaign = self::normalize($subject['campaign'] ?? []);
            foreach ($campaign['outbox'] as $eventId => $event) {
                if (($event['status'] ?? 'pending') === 'pending' && (int) ($event['nextAttemptAt'] ?? 0) <= $now) {
                    $event['eventId'] = $eventId;
                    $event['subject'] = $subjectName;
                    $pending[] = $event;
                }
            }
        }
        return $pending;
    }

    public static function markOutboxResult(array &$classData, string $subjectName, string $eventId, bool $success, int $now): void
    {
        if (!isset($classData['subjects'][$subjectName])) {
            return;
        }
        $campaign = self::normalize($classData['subjects'][$subjectName]['campaign'] ?? []);
        if (!isset($campaign['outbox'][$eventId])) {
            return;
        }
        $event = &$campaign['outbox'][$eventId];
        $event['attempts'] = (int) ($event['attempts'] ?? 0) + 1;
        if ($success) {
            $event['status'] = 'sent';
            $event['sentAt'] = $now;
        } else {
            $event['status'] = 'pending';
            $event['nextAttemptAt'] = $now + min(3600, 60 * (2 ** min(5, $event['attempts'])));
        }
        unset($event);
        $classData['subjects'][$subjectName]['campaign'] = $campaign;
    }

    private static function processSubject(array &$subject, array $users, int $now, string $subjectName): void
    {
        $campaign = self::normalize($subject['campaign'] ?? []);
        if (!$campaign['enabled']) {
            $subject['campaign'] = $campaign;
            return;
        }
        if ((bool) ($subject['hide'] ?? false)) {
            if ($campaign['status'] !== 'complete') {
                $campaign['status'] = 'paused';
                $campaign['pausedReason'] = 'hidden';
            }
            $subject['campaign'] = $campaign;
            return;
        }

        if ($campaign['status'] === 'scheduled') {
            $eligibleNow = self::eligibleUsers($users, $subject);
            $preAt = ((int) $campaign['unlockAt']) - ($campaign['preNoticeMinutes'] * 60);
            if ($now >= $preAt && $now < (int) $campaign['unlockAt'] && !isset($campaign['events']['preunlock'])) {
                self::queue($campaign, 'preunlock', $eligibleNow, 'Apertura programmata',
                    $subjectName . ' aprirà alle ' . self::formatTime((int) $campaign['unlockAt'], $campaign['timezone']) . '.', $subjectName, $now);
                $campaign['events']['preunlock'] = $now;
            }
            if ($now >= (int) $campaign['unlockAt']) {
                $cohort = self::buildCohort($users, $subject);
                $campaign['cohort'] = $cohort;
                $campaign['startedAt'] = $now;
                $campaign['pausedReason'] = null;
                $subject['lock'] = false;
                $campaign['status'] = $cohort['priority'] === [] ? 'general' : 'priority';
                if ($campaign['status'] === 'general') {
                    $campaign['generalOpenedAt'] = $now;
                }
                if (!isset($campaign['events']['unlock'])) {
                    self::queue($campaign, 'unlock-priority', $cohort['priority'], 'Prenotazioni aperte',
                        'Hai accesso prioritario a ' . $subjectName . '. Scegli ora la tua opzione.', $subjectName, $now);
                    self::queue($campaign, 'unlock-regular', $cohort['regular'], 'Apertura di ' . $subjectName,
                        $campaign['status'] === 'general'
                            ? 'Le prenotazioni sono aperte. Scegli ora la tua opzione.'
                            : 'La fase prioritaria è iniziata. Ti avviseremo appena potrai scegliere.',
                        $subjectName, $now);
                    $campaign['events']['unlock'] = $now;
                }
            }
        }

        if ($campaign['status'] === 'paused' && $campaign['pausedReason'] === 'locked') {
            $subject['campaign'] = $campaign;
            return;
        }

        if ($campaign['status'] === 'priority') {
            $missingPriority = self::missingUsers($campaign['cohort']['priority'], $subject);
            $expired = $now >= ((int) $campaign['startedAt'] + ($campaign['priorityWindowMinutes'] * 60));
            if ($missingPriority === [] || $expired) {
                $campaign['status'] = 'general';
                $campaign['generalOpenedAt'] = $now;
                $missingRegular = self::missingUsers($campaign['cohort']['regular'], $subject);
                self::queue($campaign, 'general-open', $missingRegular, 'Prenotazioni aperte',
                    'Ora puoi scegliere la tua opzione per ' . $subjectName . '.', $subjectName, $now);
                if ($campaign['notifyCoordinator']) {
                    self::queue($campaign, 'priority-complete-admin', [(string) $campaign['coordinatorUid']],
                        'Fase prioritaria completata', 'L’accesso generale per ' . $subjectName . ' è ora aperto.', $subjectName, $now);
                }
                $campaign['events']['general-open'] = $now;
            } else {
                self::queueDueReminders($campaign, $missingPriority, 'priority', $campaign['priorityReminderMinutes'],
                    'Promemoria prioritario', 'La tua risposta prioritaria per ' . $subjectName . ' è ancora in attesa.', $subjectName, $now);
            }
        }

        if ($campaign['status'] === 'general') {
            $missingPriority = self::missingUsers($campaign['cohort']['priority'], $subject);
            $missingRegular = self::missingUsers($campaign['cohort']['regular'], $subject);
            if ($missingPriority === [] && $missingRegular === []) {
                $campaign['status'] = 'complete';
                $campaign['completedAt'] = $now;
                if ($campaign['notifyCoordinator']) {
                    self::queue($campaign, 'all-complete-admin', [(string) $campaign['coordinatorUid']],
                        'Tutte le risposte sono pronte', 'La lista di ' . $subjectName . ' è completa e pronta da copiare.', $subjectName, $now);
                }
                $campaign['events']['complete'] = $now;
            } else {
                self::queueDueReminders($campaign, $missingPriority, 'priority', $campaign['priorityReminderMinutes'],
                    'Promemoria prioritario', 'La tua risposta prioritaria per ' . $subjectName . ' è ancora in attesa.', $subjectName, $now);
                self::queueDueReminders($campaign, $missingRegular, 'regular', $campaign['regularReminderMinutes'],
                    'Promemoria', 'Non hai ancora risposto per ' . $subjectName . '.', $subjectName, $now);
            }
        }

        $subject['campaign'] = $campaign;
    }

    private static function eligibleUsers(array $users, array $subject): array
    {
        $eligible = [];
        foreach ($users as $uid => $user) {
            if ((bool) ($user['watcherAcc'] ?? false)) {
                continue;
            }
            if (($subject['answers'][$uid]['date'] ?? null) === 'Esclusi') {
                continue;
            }
            $eligible[] = (string) $uid;
        }
        return $eligible;
    }

    private static function buildCohort(array $users, array $subject): array
    {
        $eligible = self::eligibleUsers($users, $subject);
        $priority = [];
        $regular = [];
        foreach ($eligible as $uid) {
            if ((bool) ($users[$uid]['priority'] ?? false)) {
                $priority[] = $uid;
            } else {
                $regular[] = $uid;
            }
        }
        return ['eligible' => $eligible, 'priority' => $priority, 'regular' => $regular];
    }

    private static function missingUsers(array $uids, array $subject): array
    {
        return array_values(array_filter($uids, static fn (string $uid): bool => !isset($subject['answers'][$uid])));
    }

    private static function queueDueReminders(
        array &$campaign,
        array $users,
        string $group,
        int $intervalMinutes,
        string $title,
        string $body,
        string $subject,
        int $now
    ): void {
        foreach ($users as $uid) {
            $baseTime = $group === 'regular' ? $campaign['generalOpenedAt'] : $campaign['startedAt'];
            $last = (int) ($campaign['lastReminders'][$uid] ?? $baseTime ?? $now);
            if ($now - $last < $intervalMinutes * 60) {
                continue;
            }
            $bucket = intdiv($now, $intervalMinutes * 60);
            self::queue($campaign, 'reminder-' . $group . '-' . $uid . '-' . $bucket, [$uid], $title, $body, $subject, $now);
            $campaign['lastReminders'][$uid] = $now;
        }
    }

    private static function queue(
        array &$campaign,
        string $eventId,
        array $users,
        string $title,
        string $body,
        string $subject,
        int $now
    ): void {
        $users = array_values(array_unique(array_filter(array_map('strval', $users))));
        if ($users === [] || isset($campaign['outbox'][$eventId])) {
            return;
        }
        $campaign['outbox'][$eventId] = [
            'users' => $users,
            'title' => $title,
            'body' => $body,
            'tag' => 'scuola-' . hash('sha256', $eventId),
            'status' => 'pending',
            'attempts' => 0,
            'nextAttemptAt' => $now,
            'createdAt' => $now,
            'subject' => $subject,
        ];
    }

    private static function parseTimestamp(mixed $value, string $timezone): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone($timezone)))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private static function formatTime(int $timestamp, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($timezone))->format('d/m/Y H:i');
    }
}
