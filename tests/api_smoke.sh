#!/usr/bin/env bash

set -euo pipefail

source_root=$(cd "$(dirname "$0")/.." && pwd)
test_root=$(mktemp -d "${TMPDIR:-/tmp}/scuola-api-test.XXXXXX")
server_pid=''
cleanup() {
    if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; fi
    rm -rf -- "$test_root"
}
trap cleanup EXIT

# Exercise an isolated copy so manager.php can never discover or migrate live
# JSON/JSON-* directories when this suite is launched from a deployment checkout.
app_root="$test_root/app"
mkdir -p "$app_root"
tar -C "$source_root" --exclude='.git' --exclude='JSON' --exclude='JSON-*' -cf - . | tar -C "$app_root" -xf -

php_bin=${PHP_BIN:-php}
php_options=()
if [[ -n "${SCUOLA_TEST_SQLITE_DIR:-}" ]]; then
    php_options+=(
        -d "extension=${SCUOLA_TEST_SQLITE_DIR}/sqlite3.so"
        -d "extension=${SCUOLA_TEST_SQLITE_DIR}/pdo_sqlite.so"
    )
fi

export SCUOLA_STORAGE_REGISTRY_DB="$test_root/secure/registry.sqlite3"
export SCUOLA_CLASS_STORAGE_DIR="$test_root/secure/classes"
export SCUOLA_STORAGE_KEY_FILE="$test_root/secure/master.key"
export SCUOLA_LEGACY_ARCHIVE_DIR="$test_root/secure/archive"
export SCUOLA_ALLOW_CLASS_CREATION=1
export SCUOLA_PUSH_URL="http://127.0.0.1:9"

"$php_bin" "${php_options[@]}" "$app_root/bin/storage.php" generate-key "$SCUOLA_STORAGE_KEY_FILE"

port=${SCUOLA_TEST_PORT:-18756}
"$php_bin" "${php_options[@]}" -S "127.0.0.1:$port" -t "$app_root" >"$test_root/php-server.log" 2>&1 &
server_pid=$!
base_url="http://127.0.0.1:$port"
for _ in {1..50}; do
    if curl --silent --fail "$base_url/interrogazioni.php" >/dev/null; then break; fi
    sleep 0.1
done
curl --silent --fail "$base_url/interrogazioni.php" >/dev/null

created=$(curl --silent --show-error "$base_url/manager.php?scope=createClass" \
    -H 'Content-Type: application/json' \
    --data '{"className":"Classe API","adminName":"Admin API"}')
jq -e '.status == true and (.UID | length == 32) and (.classId | length == 32)' <<<"$created" >/dev/null
uid=$(jq -r '.UID' <<<"$created")
class_id=$(jq -r '.classId' <<<"$created")

loaded=$(curl --silent --show-error "$base_url/manager.php?scope=loadPageData" \
    -H 'Content-Type: application/json' \
    --data "$(jq -nc --arg uid "$uid" --arg class "$class_id" '{UID:$uid,appLoadClass:$class}')")
jq -e '.status == true and .section == "schedule-subject" and .user.admin == true' <<<"$loaded" >/dev/null

users=$(curl --silent --show-error "$base_url/manager.php?scope=getAllUsers&UID=$uid&class=$class_id")
users=$(jq --arg admin "$uid" '.[$admin].priority = true | .["regular-code"] = {name:"Regolare",admin:false,priority:false,answers:{}}' <<<"$users")
updated_users=$(curl --silent --show-error "$base_url/manager.php?scope=updateSettings&type=users&UID=$uid&class=$class_id" \
    -H 'Content-Type: application/json' --data "$(jq -nc --argjson users "$users" '[$users]')")
jq -e '.status == true and .newData.users["regular-code"].priority == false' <<<"$updated_users" >/dev/null

subject='{"fileName":"Matematica","data":{"lock":false,"hide":false,"answerCount":0,"answers":{},"days":{"01-10-2026":{"dayName":"Giovedì","availability":"2/2"}},"type":"subject","usesDays":true}}'
subject_update=$(curl --silent --show-error "$base_url/manager.php?scope=updateSettings&type=subject&UID=$uid&class=$class_id" \
    -H 'Content-Type: application/json' --data "[$subject]")
jq -e '.status == true and (.newData.subjects | length) == 1' <<<"$subject_update" >/dev/null

campaign=$(curl --silent --show-error "$base_url/manager.php?scope=campaign&subject=Matematica&UID=$uid&class=$class_id" \
    -H 'Content-Type: application/json' --data '{"action":"start"}')
jq -e '.status == true and .campaign.status == "priority"' <<<"$campaign" >/dev/null

regular_blocked=$(curl --silent --show-error "$base_url/manager.php?scope=schedule&subject=Matematica&day=01-10-2026&UID=regular-code&class=$class_id")
jq -e '.status == false and .message == "priority-window"' <<<"$regular_blocked" >/dev/null

priority_vote=$(curl --silent --show-error "$base_url/manager.php?scope=schedule&subject=Matematica&day=01-10-2026&UID=$uid&class=$class_id")
jq -e '.status == true' <<<"$priority_vote" >/dev/null
regular_vote=$(curl --silent --show-error "$base_url/manager.php?scope=schedule&subject=Matematica&day=01-10-2026&UID=regular-code&class=$class_id")
jq -e '.status == true' <<<"$regular_vote" >/dev/null

# Exercise the complete scheduled automation lifecycle in a second, disposable
# class. The worker uses this suite's isolated encrypted storage, and none of
# these users has a push subscription, so no external notification is sent.
automation_created=$(curl --silent --show-error "$base_url/manager.php?scope=createClass" \
    -H 'Content-Type: application/json' \
    --data '{"className":"Classe Automazione","adminName":"Admin Automazione"}')
jq -e '.status == true and (.UID | length == 32) and (.classId | length == 32)' <<<"$automation_created" >/dev/null
automation_uid=$(jq -r '.UID' <<<"$automation_created")
automation_class_id=$(jq -r '.classId' <<<"$automation_created")

automation_users=$(curl --silent --show-error "$base_url/manager.php?scope=getAllUsers&UID=$automation_uid&class=$automation_class_id")
automation_users=$(jq --arg admin "$automation_uid" '
    .[$admin].watcherAcc = true |
    .[$admin].priority = false |
    .["priority-test"] = {name:"Priorità Test",admin:false,priority:true,answers:{}} |
    .["regular-test"] = {name:"Regolare Test",admin:false,priority:false,answers:{}} |
    .["watcher-test"] = {name:"Spettatore Test",admin:false,priority:true,watcherAcc:true,answers:{}}
' <<<"$automation_users")
automation_users_update=$(curl --silent --show-error "$base_url/manager.php?scope=updateSettings&type=users&UID=$automation_uid&class=$automation_class_id" \
    -H 'Content-Type: application/json' --data "$(jq -nc --argjson users "$automation_users" '[$users]')")
jq -e '.status == true and .newData.users["priority-test"].priority == true and .newData.users["watcher-test"].watcherAcc == true' <<<"$automation_users_update" >/dev/null

automation_subject='{"fileName":"Automazione","data":{"lock":false,"hide":false,"answerCount":0,"answers":{},"days":{"01-11-2026":{"dayName":"Domenica","availability":"2/2"}},"type":"subject","usesDays":true}}'
automation_subject_update=$(curl --silent --show-error "$base_url/manager.php?scope=updateSettings&type=subject&UID=$automation_uid&class=$automation_class_id" \
    -H 'Content-Type: application/json' --data "[$automation_subject]")
jq -e '.status == true and (.newData.subjects | length) == 1' <<<"$automation_subject_update" >/dev/null

automation_unlock=$(( $(date +%s) + 30 ))
automation_campaign=$(curl --silent --show-error "$base_url/manager.php?scope=campaign&subject=Automazione&UID=$automation_uid&class=$automation_class_id" \
    -H 'Content-Type: application/json' \
    --data "$(jq -nc --argjson unlock "$automation_unlock" '{action:"configure",settings:{unlockAt:$unlock,preNoticeMinutes:1,priorityWindowMinutes:1,priorityReminderMinutes:1,regularReminderMinutes:1,notifyCoordinator:true,timezone:"Europe/Rome"}}')")
jq -e '.status == true and .campaign.status == "scheduled"' <<<"$automation_campaign" >/dev/null

run_automation_worker() {
    SCUOLA_AUTOMATION_LOCK="$test_root/automation-worker.lock" \
        "$php_bin" "${php_options[@]}" "$app_root/bin/automation-worker.php" >>"$test_root/automation-worker.log"
}

get_automation_subject() {
    curl --silent --show-error "$base_url/manager.php?scope=getAllData&UID=$automation_uid&class=$automation_class_id" |
        jq -c 'map(select(.fileName == "Automazione"))[0]'
}

save_automation_subject() {
    curl --silent --show-error "$base_url/manager.php?scope=updateSettings&type=subject&UID=$automation_uid&class=$automation_class_id" \
        -H 'Content-Type: application/json' --data "[$1]"
}

run_automation_worker
automation_state=$(get_automation_subject)
jq -e '
    .data.lock == true and
    .data.campaign.status == "scheduled" and
    .data.campaign.outbox.preunlock.status == "sent" and
    (.data.campaign.outbox.preunlock.users | sort) == ["priority-test", "regular-test"]
' <<<"$automation_state" >/dev/null

automation_state=$(jq --argjson unlock "$(( $(date +%s) - 1 ))" '.data.campaign.unlockAt = $unlock' <<<"$automation_state")
automation_save=$(save_automation_subject "$automation_state")
jq -e '.status == true' <<<"$automation_save" >/dev/null
run_automation_worker
automation_state=$(get_automation_subject)
jq -e '
    .data.lock == false and
    .data.campaign.status == "priority" and
    .data.campaign.cohort.priority == ["priority-test"] and
    .data.campaign.cohort.regular == ["regular-test"] and
    (.data.campaign.cohort.eligible | index("watcher-test") | not) and
    .data.campaign.outbox["unlock-priority"].status == "sent" and
    .data.campaign.outbox["unlock-regular"].status == "sent"
' <<<"$automation_state" >/dev/null

automation_regular_blocked=$(curl --silent --show-error "$base_url/manager.php?scope=schedule&subject=Automazione&day=01-11-2026&UID=regular-test&class=$automation_class_id")
jq -e '.status == false and .message == "priority-window"' <<<"$automation_regular_blocked" >/dev/null

automation_state=$(jq --argjson started "$(( $(date +%s) - 120 ))" '.data.campaign.startedAt = $started | .data.campaign.lastReminders = {}' <<<"$automation_state")
automation_save=$(save_automation_subject "$automation_state")
jq -e '.status == true' <<<"$automation_save" >/dev/null
run_automation_worker
automation_state=$(get_automation_subject)
jq -e '[.data.campaign.outbox | to_entries[] | select(.key | startswith("reminder-priority-priority-test-")) | select(.value.status == "sent")] | length == 1' <<<"$automation_state" >/dev/null

automation_priority_vote=$(curl --silent --show-error "$base_url/manager.php?scope=schedule&subject=Automazione&day=01-11-2026&UID=priority-test&class=$automation_class_id")
jq -e '.status == true' <<<"$automation_priority_vote" >/dev/null
automation_state=$(get_automation_subject)
jq -e '.data.campaign.status == "general" and (.data.campaign.outbox["priority-complete-admin"] != null)' <<<"$automation_state" >/dev/null

automation_state=$(jq --argjson opened "$(( $(date +%s) - 120 ))" '.data.campaign.generalOpenedAt = $opened | .data.campaign.lastReminders = {}' <<<"$automation_state")
automation_save=$(save_automation_subject "$automation_state")
jq -e '.status == true' <<<"$automation_save" >/dev/null
run_automation_worker
automation_state=$(get_automation_subject)
jq -e '
    .data.campaign.outbox["priority-complete-admin"].status == "sent" and
    ([.data.campaign.outbox | to_entries[] | select(.key | startswith("reminder-regular-regular-test-")) | select(.value.status == "sent")] | length == 1)
' <<<"$automation_state" >/dev/null

automation_regular_vote=$(curl --silent --show-error "$base_url/manager.php?scope=schedule&subject=Automazione&day=01-11-2026&UID=regular-test&class=$automation_class_id")
jq -e '.status == true' <<<"$automation_regular_vote" >/dev/null
run_automation_worker
automation_state=$(get_automation_subject)
jq -e '
    .data.campaign.status == "complete" and
    .data.campaign.outbox["all-complete-admin"].status == "sent" and
    ([.data.campaign.outbox[] | select(.status != "sent")] | length == 0) and
    (.data.answers["watcher-test"] == null)
' <<<"$automation_state" >/dev/null

ical=$(curl --silent --show-error "$base_url/manager.php?scope=syncICal&UID=$uid&class=$class_id")
grep -q 'BEGIN:VCALENDAR' <<<"$ical"
grep -q 'SUMMARY:Interrogazione: Matematica' <<<"$ical"

new_class=$(curl --silent --show-error "$base_url/manager.php?scope=profileMGMT&UID=$uid&class=$class_id" \
    -H 'Content-Type: application/json' --data '{"action":"newprofile","method":"new","profile":"Seconda API"}')
jq -e '.status == true and (.classId | length == 32)' <<<"$new_class" >/dev/null
new_class_id=$(jq -r '.classId' <<<"$new_class")

ambiguous=$(curl --silent --show-error "$base_url/manager.php?scope=loadPageData" \
    -H 'Content-Type: application/json' --data "$(jq -nc --arg uid "$uid" '{UID:$uid}')")
jq -e '.section == "changeprofile" and (.profileList | length) == 2' <<<"$ambiguous" >/dev/null

archive="$test_root/class.profile.zip"
curl --silent --show-error --fail "$base_url/manager.php?scope=downloadProfile&UID=$uid&class=$class_id" --output "$archive"
unzip -t "$archive" >/dev/null
uploaded=$(curl --silent --show-error "$base_url/manager.php?scope=uploadProfile&UID=$uid&class=$class_id" -F "profileData=@$archive")
jq -e '.status == true and (.classId | length == 32)' <<<"$uploaded" >/dev/null

fake_zip="$test_root/not-a-profile.zip"
printf 'this is not a zip archive' >"$fake_zip"
invalid_upload=$(curl --silent --show-error "$base_url/manager.php?scope=uploadProfile&UID=$uid&class=$class_id" -F "profileData=@$fake_zip;type=application/zip")
jq -e '.status == false and (.message | test("ZIP"))' <<<"$invalid_upload" >/dev/null

invalid_json_zip="$test_root/invalid-json.profile.zip"
cp "$archive" "$invalid_json_zip"
"$php_bin" "${php_options[@]}" -r '$zip = new ZipArchive(); $zip->open($argv[1]); $zip->addFromString("profile.json", "{invalid json"); $zip->close();' "$invalid_json_zip"
invalid_json_upload=$(curl --silent --show-error "$base_url/manager.php?scope=uploadProfile&UID=$uid&class=$class_id" -F "profileData=@$invalid_json_zip;type=application/zip")
jq -e '.status == false and (.message | test("invalid JSON"))' <<<"$invalid_json_upload" >/dev/null

oversized_zip="$test_root/oversized.profile.zip"
dd if=/dev/zero of="$oversized_zip" bs=1048577 count=1 status=none
oversized_upload=$(curl --silent --show-error "$base_url/manager.php?scope=uploadProfile&UID=$uid&class=$class_id" -F "profileData=@$oversized_zip;type=application/zip")
jq -e '.status == false and (.message | test("1 MB"))' <<<"$oversized_upload" >/dev/null

deleted=$(curl --silent --show-error "$base_url/manager.php?scope=profileMGMT&UID=$uid&class=$class_id" \
    -H 'Content-Type: application/json' --data "$(jq -nc --arg class "$new_class_id" '{action:"deleteprofile",classId:$class}')")
jq -e '.status == true' <<<"$deleted" >/dev/null

notification_proxy=$(curl --silent --show-error "$base_url/manager.php?scope=notifications&UID=$uid&class=$class_id" \
    -H 'Content-Type: application/json' --data '{"action":"VAPIDkey"}')
jq -e '.status == false' <<<"$notification_proxy" >/dev/null

echo 'api_smoke: OK'
