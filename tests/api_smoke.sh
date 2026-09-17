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

deleted=$(curl --silent --show-error "$base_url/manager.php?scope=profileMGMT&UID=$uid&class=$class_id" \
    -H 'Content-Type: application/json' --data "$(jq -nc --arg class "$new_class_id" '{action:"deleteprofile",classId:$class}')")
jq -e '.status == true' <<<"$deleted" >/dev/null

notification_proxy=$(curl --silent --show-error "$base_url/manager.php?scope=notifications&UID=$uid&class=$class_id" \
    -H 'Content-Type: application/json' --data '{"action":"VAPIDkey"}')
jq -e '.status == false' <<<"$notification_proxy" >/dev/null

echo 'api_smoke: OK'
