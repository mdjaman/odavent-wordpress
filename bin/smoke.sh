#!/usr/bin/env bash
# End-to-end smoke test of the plugin on a real WordPress, against the stub Odavent API.
# Needs the stack from bin/dev-up. Prints `PASS name` / `FAIL name` per check and exits non-zero on any FAIL.
#
# Usage: bin/smoke.sh            # every check
#        ONLY="1 5" bin/smoke.sh # some checks (numbers below); 13 also runs 12
# Host tools: docker, curl, jq, openssl. HTML is inspected by docker/smoke/html.php inside the stack.
#
#  1 all-blocks page            6 settings page / REST security    11 update checker
#  2 cancelled event            7 shortcodes == blocks             12 uninstall (on the packaged copy)
#  3 draft privacy + no-cache   8 hostile payload                  13 the release zip on a fresh WordPress
#  4 resilience + breaker       9 settings UX + autoload
#  5 signed live refresh       10 stale view never offers Register  14 event page block + shortcode
#                                                                  15 widget + page cache + refresh
#                                                                  16 Appearance settings (hostile input)
set -uo pipefail
# shellcheck source=lib/dev.sh
. "$(dirname "$0")/lib/dev.sh"

FAILS=0
PASSES=0
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
ONLY="${ONLY:-}"
# A full run prints exactly this many PASS lines. Fewer means a section was skipped (a failed setup step, an
# early `if` taken): the run fails. Update it when checks are added or removed.
EXPECTED_PASSES=189

pass() { PASSES=$((PASSES + 1)); printf 'PASS %s\n' "$1"; }
fail() { FAILS=$((FAILS + 1)); printf 'FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '     %s\n' "$2"; return 0; }
check() { # check <name> <command...>: PASS when the command succeeds
	local name="$1"; shift
	if "$@" >/dev/null; then pass "$name"; else fail "$name"; fi
}
want() { [ -z "$ONLY" ] && return 0; case " $ONLY " in *" $1 "*) return 0 ;; esac; return 1; }

# ---- prerequisites ------------------------------------------------------------------------------------

for tool in docker curl jq openssl; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		fail "prerequisites" "$tool is not installed"
		exit 1
	fi
done
if ! dc ps --status running --services 2>/dev/null | grep -qx wordpress \
	|| [ "$(curl -s -o /dev/null -w '%{http_code}' "$ODV_SITE/" 2>/dev/null)" != "200" ]; then
	fail "prerequisites" "the dev stack is not running on $ODV_SITE: run bin/dev-up first"
	exit 1
fi

# Signatures are timed (300 s tolerance) and so are the resilience checks: a Docker VM clock that drifted
# (it happens after the host sleeps) makes them fail for reasons that have nothing to do with the plugin.
drift=$(( $(date -u +%s) - $(dc exec -T wordpress date -u +%s | tr -d '\r') ))
if [ "${drift#-}" -gt 30 ]; then
	fail "prerequisites" "the Docker clock is ${drift}s off the host clock: restart Docker (see docs/development.md)"
	exit 1
fi

# ---- helpers ------------------------------------------------------------------------------------------

html() { dc exec -T wpcli php "$ODV_PLUGIN_DIR/docker/smoke/html.php" "$@"; }

# get <file> <url> [curl args...]: body into $TMP/<file>, headers into $TMP/<file>.h; echoes "code seconds".
get() {
	local file="$1" url="$2"; shift 2
	curl -s -o "$TMP/$file" -D "$TMP/$file.h" -w '%{http_code} %{time_total}' "$@" "$url"
}

contains() { grep -qF -- "$2" "$TMP/$1"; }
lacks() { ! grep -qF -- "$2" "$TMP/$1"; }

# Register links: <a> elements the plugin renders as Register links (class odv-register__button or
# odv-tickets__register), or any link to an Odavent /register URL.
register_links() {
	html links <"$TMP/$1" | jq '[.[] | select((.class | test("(^|\\s)(odv-register__button|odv-tickets__register)(\\s|$)")) or (.href | test("^https://odavent\\.com/events/[^/?#]+/register([?#]|$)")))] | length'
}

set_settings() { # set_settings [key]
	local key="${1-$ODV_STUB_KEY}"
	wp_cli option delete odavent_events_settings >/dev/null 2>&1 || true
	wp_cli option add odavent_events_settings \
		"$(printf '{"base_url":"http://127.0.0.1:8081","site_key":"%s","cache_ttl":60,"default_event_id":0,"load_css":true}' "$key")" \
		--format=json --autoload=no >/dev/null
}

reset_all() {
	stub_control '{"reset": true}'
	set_settings
	wp_cli eval '\Odavent\Events\Plugin::instance()->repository()->purge_all();' >/dev/null
}

stub_requests() { stub_get /__log | grep -c . || true; }

# PHP warnings/notices/fatals in a debug.log since a byte offset. Prints them; succeeds when there are none.
LOG_PATH="/var/www/html/wp-content/debug.log"
log_size() { wp_exec sh -c "if [ -f '$1' ]; then wc -c < '$1'; else echo 0; fi" | tr -d ' \r'; }
log_clean_since() { # log_clean_since <path> <offset>; a missing log FAILS (logging must have been proven by log_canary)
	local lines
	if ! wp_exec test -f "$1"; then
		printf '     log: %s does not exist: is WP_DEBUG_LOG on?\n' "$1" >&2
		return 1
	fi
	lines=$(wp_exec sh -c "[ -f '$1' ] && tail -c +$(( $2 + 1 )) '$1' || true" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal|Parse|Recoverable)|Odavent Events:' || true)
	if [ -n "$lines" ]; then
		printf '%s\n' "$lines" | head -20 | sed 's/^/     log: /' >&2
		return 1
	fi
	return 0
}

# Proves the log works: a unique line written through PHP's error_log() in WordPress must reach the file.
# Without this, every "no PHP warnings" check would pass on a site whose logging is silently broken.
log_canary() { # log_canary <wp-cli function: wp_cli|wp_pkg> <path>
	local tag="odv-canary-$$-$RANDOM$RANDOM"
	"$1" eval "error_log( '$tag' );" >/dev/null 2>&1
	wp_exec grep -q "$tag" "$2"
}

COOKIES="$TMP/cookies"
login() { # login <site url> <cookie jar>
	local site="$1" jar="$2"
	curl -s -o /dev/null -c "$jar" -b "wordpress_test_cookie=WP%20Cookie%20check" \
		--data-urlencode log=admin --data-urlencode pwd=admin --data-urlencode wp-submit="Log In" \
		--data-urlencode redirect_to="$site/wp-admin/" --data-urlencode testcookie=1 "$site/wp-login.php"
	grep -q wordpress_logged_in "$jar"
}

PING_BODY='{"event_id":1,"event_slug":"sommet-tech-abidjan-2026","sent_at":"2026-10-23T10:00:00+00:00"}'
ping() { # ping <site url> <out file> [signature header value] [body]: echoes the HTTP status
	local site="$1" out="$2" sig="${3:-}" body="${4:-$PING_BODY}"
	local args=(-s -o "$TMP/$out" -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data "$body")
	[ -n "$sig" ] && args+=(-H "X-Odavent-Signature: $sig")
	curl "${args[@]}" "$site/wp-json/odavent/v1/refresh"
}

started=$(date +%s)
log "Smoke test on $ODV_SITE ($(wp_cli core version), PHP $(wp_exec php -r 'echo PHP_VERSION;'))"

check "0 setup: PHP errors reach debug.log (canary written through error_log() is found)" log_canary wp_cli "$LOG_PATH"
check "0 setup: debug.log has no PHP warnings after install and activation" log_clean_since "$LOG_PATH" 0
LOG_START=$(log_size "$LOG_PATH")
reset_all
login "$ODV_SITE" "$COOKIES" || fail "login as admin" "wp-login.php did not set a logged-in cookie"

# ---- 1. all-blocks page -----------------------------------------------------------------------------

assert_all_blocks() { # assert_all_blocks <label> <site url>
	local label="$1" site="$2" res code ok=1
	res=$(get blocks.html "$site/all-blocks-1/")
	code=${res%% *}
	[ "$code" = "200" ] || { fail "$label page answers 200" "got $code"; return; }
	for cls in odv-header odv-speakers odv-agenda odv-sponsors odv-tickets; do
		if ! contains blocks.html "class=\"$cls\""; then ok=0; fail "$label renders $cls"; fi
	done
	[ "$ok" = 1 ] && pass "$label renders odv-header, odv-speakers, odv-agenda, odv-sponsors, odv-tickets"

	local n
	n=$(html links <"$TMP/blocks.html" | jq '[.[] | select(.class == "odv-register__button") | select(.href | (contains("utm_source=wordpress") and contains("utm_medium=plugin") and contains("return=")))] | length')
	check "$label has a Register button with utm_source=wordpress, utm_medium=plugin and return=" [ "$n" -ge 1 ]
	check "$label shows 'Registration and payment are handled securely by Odavent.'" \
		contains blocks.html "Registration and payment are handled securely by Odavent."

	local ld
	ld=$(html jsonld <"$TMP/blocks.html")
	check "$label has JSON-LD that parses as JSON (jq)" \
		[ "$(printf '%s' "$ld" | jq '[.[] | select(.valid)] | length')" -ge 1 -a "$(printf '%s' "$ld" | jq '[.[] | select(.valid | not)] | length')" = 0 ]
	if ! printf '%s' "$ld" | jq -e '.[0].data["@type"] == "Event" and (.[0].data.name | type) == "string"' >/dev/null; then
		fail "$label JSON-LD is a schema.org Event"
	else
		pass "$label JSON-LD is a schema.org Event"
	fi
	local errors
	errors=$(html parse <"$TMP/blocks.html")
	if [ "$(printf '%s' "$errors" | jq length)" = 0 ]; then
		pass "$label HTML parses without errors (libxml/DOMDocument)"
	else
		fail "$label HTML parses without errors (libxml/DOMDocument)" "$(printf '%s' "$errors" | jq -c '.[0:3]')"
	fi
}

if want 1; then
	assert_all_blocks "1" "$ODV_SITE"
fi

# ---- 2. cancelled event --------------------------------------------------------------------------------

if want 2; then
	res=$(get cancelled.html "$ODV_SITE/cancelled-2/")
	check "2 cancelled page answers 200" [ "${res%% *}" = "200" ]
	check "2 cancelled page shows the cancelled notice" contains cancelled.html "This event has been cancelled."
	n=$(register_links cancelled.html)
	check "2 cancelled page has no Register link anywhere (found $n)" [ "$n" = 0 ]
fi

# ---- 3. draft privacy + early no-cache headers ----------------------------------------------------------

if want 3; then
	res=$(get draft-anon.html "$ODV_SITE/draft-3/")
	check "3 draft page answers 200 to a visitor" [ "${res%% *}" = "200" ]
	if lacks draft-anon.html "Secret Draft Workshop" && lacks draft-anon.html "Unannounced draft description" \
		&& lacks draft-anon.html "secret-draft-workshop-3" && lacks draft-anon.html 'class="odv-'; then
		pass "3 visitor sees nothing of the draft (name, description, slug, any odv- markup)"
	else
		fail "3 visitor sees nothing of the draft (name, description, slug, any odv- markup)"
	fi

	res=$(get draft-admin.html "$ODV_SITE/draft-3/" -b "$COOKIES")
	check "3 admin sees the draft content" contains draft-admin.html "Secret Draft Workshop 3"
	check "3 admin sees the 'Draft preview' notice" contains draft-admin.html "Draft preview: this event is not published on Odavent yet."
	# Secondary: WordPress 6.9+ sends no-cache headers to every logged-in user itself, so this line cannot tell the
	# plugin's layer apart there. The DONOTCACHEPAGE probe below (and its two negative controls) is the proof.
	if grep -iE '^cache-control:.*(no-cache|no-store)' "$TMP/draft-admin.html.h" >/dev/null; then
		pass "3 admin draft preview response has Cache-Control no-cache/no-store"
	else
		fail "3 admin draft preview response has Cache-Control no-cache/no-store" "$(grep -i '^cache-control' "$TMP/draft-admin.html.h" | tr -d '\r')"
	fi
	check "3 DONOTCACHEPAGE is defined before the theme renders (template_redirect, probe header)" \
		grep -qi '^x-odavent-probe-donotcachepage: 1' "$TMP/draft-admin.html.h"
	# Control: an admin on a page without Odavent content is left to page caches.
	get sample.html "$ODV_SITE/?page_id=2" -b "$COOKIES" >/dev/null
	check "3 control: a page without Odavent content does not define DONOTCACHEPAGE" \
		bash -c "! grep -qi '^x-odavent-probe-donotcachepage' '$TMP/sample.html.h'"
	check "3 visitor's draft page does not define DONOTCACHEPAGE (stays cacheable)" \
		bash -c "! grep -qi '^x-odavent-probe-donotcachepage' '$TMP/draft-anon.html.h'"
fi

# ---- 4. resilience and circuit breaker ----------------------------------------------------------------

resilience_mode() { # resilience_mode <mode> <max seconds for the first request>
	local mode="$1" max_first="$2" res code secs before after
	reset_all
	get warm.html "$ODV_SITE/all-blocks-1/" >/dev/null # caches event 1 and its last good copy
	stub_control "{\"mode\": \"$mode\"}"
	wp_cli transient delete odavent_events_ev_1_en >/dev/null 2>&1 || true # a fresh, uncached request

	before=$(stub_requests)
	res=$(get res1.html "$ODV_SITE/all-blocks-1/")
	code=${res%% *}; secs=${res##* }
	check "4 [$mode] first uncached request answers 200 (${secs}s)" [ "$code" = "200" ]
	check "4 [$mode] first request takes at most ${max_first}s (${secs}s)" awk "BEGIN { exit !($secs <= $max_first) }"
	check "4 [$mode] still shows the last good copy of event 1" contains res1.html "Sommet Tech d&#039;Abidjan 2026"
	check "4 [$mode] stale copy shows the 'could not be refreshed' notice" contains res1.html "Registration details could not be refreshed."
	check "4 [$mode] stale copy offers no Register link" [ "$(register_links res1.html)" = 0 ]

	wp_cli transient delete odavent_events_ev_1_en >/dev/null 2>&1 || true
	before=$(stub_requests)
	res=$(get res2.html "$ODV_SITE/all-blocks-1/")
	secs=${res##* }
	after=$(stub_requests)
	check "4 [$mode] second request is fast thanks to the circuit breaker (${secs}s < 2s)" awk "BEGIN { exit !($secs < 2) }"
	check "4 [$mode] second request makes no call to Odavent" [ "$before" = "$after" ]
	check "4 [$mode] second request still shows the last good copy" contains res2.html "Sommet Tech d&#039;Abidjan 2026"
}

if want 4; then
	off=$(log_size "$LOG_PATH")
	resilience_mode 500 3
	resilience_mode timeout 10
	resilience_mode garbage 3
	check "4 no PHP warnings or notices in debug.log during the failures" log_clean_since "$LOG_PATH" "$off"
	reset_all
fi

# ---- 5. signed live refresh -----------------------------------------------------------------------------

assert_refresh() { # assert_refresh <label> <site url>
	local label="$1" site="$2" s0 s1 s2 s3 s4 s5 s6 sig body5 body6
	reset_all
	get before.html "$site/all-blocks-1/" >/dev/null
	stub_control '{"rename": {"1": "Renamed Summit 2027"}}'

	s0=$(ping "$site" p-unsigned.json)
	s1=$(ping "$site" p-wrong.json "$("$ODV_ROOT/bin/sign-ping" odv_wp_wrongkey_000000000000000 "$PING_BODY")")
	s2=$(ping "$site" p-stale.json "$("$ODV_ROOT/bin/sign-ping" "$ODV_STUB_KEY" "$PING_BODY" $(( $(date +%s) - 400 )))")
	check "$label unsigned, wrong-key and stale (t-400s) pings are all 401 ($s0 $s1 $s2)" [ "$s0$s1$s2" = "401401401" ]
	check "$label the three 401 bodies are identical" \
		bash -c "cmp -s '$TMP/p-unsigned.json' '$TMP/p-wrong.json' && cmp -s '$TMP/p-unsigned.json' '$TMP/p-stale.json'"
	get after-bad.html "$site/all-blocks-1/" >/dev/null
	check "$label rejected pings leave the old name on the page" \
		bash -c "grep -qF 'Sommet Tech d&#039;Abidjan 2026' '$TMP/after-bad.html' && ! grep -qF 'Renamed Summit 2027' '$TMP/after-bad.html'"

	sig=$("$ODV_ROOT/bin/sign-ping" "$ODV_STUB_KEY" "$PING_BODY")
	s3=$(ping "$site" p-good.json "$sig")
	check "$label a correctly signed ping answers 200 ($s3)" [ "$s3" = "200" ]
	get after-good.html "$site/all-blocks-1/" >/dev/null
	check "$label the next page request shows the new name immediately" contains after-good.html "Renamed Summit 2027"

	s4=$(ping "$site" p-replay.json "$sig")
	check "$label the same signed ping replayed is 401 ($s4) with the same body" \
		bash -c "[ '$s4' = 401 ] && cmp -s '$TMP/p-unsigned.json' '$TMP/p-replay.json'"

	# A ping drops the cached copy but keeps the last good one: if the very next fetch fails, the page still
	# shows the event (stale, no Register) instead of nothing a page cache would then keep for hours.
	# Each ping gets its own body: two pings signed in the same second with the same body are one signature,
	# and the second would rightly be refused as a replay.
	body5='{"event_id":1,"event_slug":"sommet-tech-abidjan-2026","sent_at":"2026-10-23T10:00:05+00:00"}'
	body6='{"event_id":1,"event_slug":"sommet-tech-abidjan-2026","sent_at":"2026-10-23T10:00:06+00:00"}'
	s5=$(ping "$site" p-good2.json "$("$ODV_ROOT/bin/sign-ping" "$ODV_STUB_KEY" "$body5")" "$body5")
	stub_control '{"mode": "500"}'
	get after-ping-500.html "$site/all-blocks-1/" >/dev/null
	check "$label after a signed ping ($s5), Odavent failing (500) still shows the last good copy" \
		contains after-ping-500.html "Renamed Summit 2027"
	check "$label that copy is stale: the 'could not be refreshed' notice and no Register link" \
		bash -c "grep -qF 'Registration details could not be refreshed.' '$TMP/after-ping-500.html' && [ \"\$1\" = 0 ]" _ "$(register_links after-ping-500.html)"
	stub_control '{"mode": "ok"}'
	s6=$(ping "$site" p-good3.json "$("$ODV_ROOT/bin/sign-ping" "$ODV_STUB_KEY" "$body6")" "$body6")
	get after-recover.html "$site/all-blocks-1/" >/dev/null
	check "$label once Odavent answers again, the next signed ping ($s6) lifts the breaker and Register is back" \
		bash -c "[ '$s6' = 200 ] && ! grep -qF 'Registration details could not be refreshed.' '$TMP/after-recover.html' && [ \"\$1\" -ge 1 ]" _ "$(register_links after-recover.html)"
	stub_control '{"rename": {"1": null}}'
}

if want 5; then
	assert_refresh "5" "$ODV_SITE"
fi

# ---- 6. settings page and REST security ---------------------------------------------------------------

if want 6; then
	reset_all
	res=$(get settings.html "$ODV_SITE/wp-admin/options-general.php?page=odavent-events" -b "$COOKIES")
	check "6 settings page answers 200 to the admin" [ "${res%% *}" = "200" ]
	check "6 settings page shows the masked key" contains settings.html "odv_wp_••••6789"
	check "6 settings page HTML never contains the site key" lacks settings.html "$ODV_STUB_KEY"

	res=$(get rest-anon.json "$ODV_SITE/wp-json/odavent/v1/events")
	check "6 REST event list is 401 for a visitor (${res%% *})" [ "${res%% *}" = "401" ]
	res=$(get rest-nononce.json "$ODV_SITE/wp-json/odavent/v1/events" -b "$COOKIES")
	check "6 REST event list is 401 for a cookie without a REST nonce (${res%% *})" [ "${res%% *}" = "401" ]
	nonce=$(curl -s -b "$COOKIES" "$ODV_SITE/wp-admin/admin-ajax.php?action=rest-nonce")
	res=$(get rest-admin.json "$ODV_SITE/wp-json/odavent/v1/events" -b "$COOKIES" -H "X-WP-Nonce: $nonce")
	check "6 REST event list is 200 for the admin with a nonce (${res%% *})" [ "${res%% *}" = "200" ]
	check "6 REST event list returns the stub events 1, 2, 3, 5, 6, 7" \
		bash -c "jq -e '[.[].id] | sort == [1,2,3,5,6,7]' '$TMP/rest-admin.json' >/dev/null"
	check "6 REST event list carries names and statuses" \
		bash -c "jq -e 'map(select(.id == 2))[0] | .name == \"Cancelled Conference 2026\" and .status == \"cancelled\"' '$TMP/rest-admin.json' >/dev/null"
	check "6 REST event list never contains the site key" lacks rest-admin.json "$ODV_STUB_KEY"
	check "6 REST event list sanitises a hostile event name (no '<script')" lacks rest-admin.json "<script"
fi

# ---- 7. shortcodes render the same as blocks ----------------------------------------------------------

if want 7; then
	reset_all
	get blocks7.html "$ODV_SITE/all-blocks-1/" >/dev/null
	get short7.html "$ODV_SITE/shortcodes-1/" >/dev/null
	html fragments <"$TMP/blocks7.html" >"$TMP/frag-blocks.json"
	html fragments <"$TMP/short7.html" >"$TMP/frag-short.json"
	check "7 both pages render ten odv- blocks" \
		bash -c "[ \"\$(jq length '$TMP/frag-blocks.json')\" = 10 ] && [ \"\$(jq length '$TMP/frag-short.json')\" = 10 ]"
	for i in $(seq 0 9); do
		b=$(jq -r ".[$i].block // \"missing\"" "$TMP/frag-blocks.json")
		if [ "$(jq -c ".[$i]" "$TMP/frag-blocks.json")" = "$(jq -c ".[$i]" "$TMP/frag-short.json")" ]; then
			pass "7 shortcode renders the same $b as the block"
		else
			fail "7 shortcode renders the same $b as the block" \
				"$(diff <(jq -r ".[$i].html" "$TMP/frag-blocks.json" | fold -w 160) <(jq -r ".[$i].html" "$TMP/frag-short.json" | fold -w 160) | head -6 | tr '\n' ' ')"
		fi
	done
fi

# ---- 8. hostile payload through the real wp_kses / esc_url / wp_date ------------------------------------

if want 8; then
	reset_all
	off=$(log_size "$LOG_PATH")
	res=$(get hostile.html "$ODV_SITE/hostile-5/")
	check "8 hostile page answers 200 (${res%% *}, $(wc -c <"$TMP/hostile.html" | tr -d ' ') bytes)" [ "${res%% *}" = "200" ]
	html fragments <"$TMP/hostile.html" >"$TMP/frag-hostile.json"
	check "8 hostile event renders (header, speakers, agenda, sponsors, tickets: $(jq -r '[.[].block] | join(",")' "$TMP/frag-hostile.json"))" \
		jq -e '[.[].block] as $b | ["odv-header","odv-speakers","odv-agenda","odv-sponsors","odv-tickets"] | all(. as $x | $b | index($x) != null)' "$TMP/frag-hostile.json"
	check "8 hostile page is complete (the 50 KB description does not cut it short)" \
		bash -c "grep -q 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' '$TMP/hostile.html' && grep -q '</html>' '$TMP/hostile.html'"
	scripts=$(html scripts <"$TMP/hostile.html")
	check "8 the only script inside Odavent markup is the JSON-LD" \
		[ "$(printf '%s' "$scripts" | jq '[.[] | select(.odv and .type != "application/ld+json")] | length')" = 0 ]
	ld=$(html jsonld <"$TMP/hostile.html")
	check "8 the hostile JSON-LD parses as JSON" \
		[ "$(printf '%s' "$ld" | jq 'length > 0 and all(.valid)')" = "true" ]
	n=$(grep -o '<script[^>]*>[^<]*alert(' "$TMP/hostile.html" | grep -vc 'ld+json' || true)
	check "8 no executable script contains alert( (found $n)" [ "$n" = 0 ]
	dangerous=$(html dangerous <"$TMP/hostile.html")
	if [ "$(printf '%s' "$dangerous" | jq length)" = 0 ]; then
		pass "8 no on* attributes and no javascript:/data:/vbscript: URL in any attribute (DOM walk)"
	else
		fail "8 no on* attributes and no javascript:/data:/vbscript: URL in any attribute (DOM walk)" "$(printf '%s' "$dangerous" | jq -c '.[0:5]')"
	fi
	errors=$(html parse <"$TMP/hostile.html")
	check "8 hostile page HTML parses without errors" [ "$(printf '%s' "$errors" | jq length)" = 0 ]
	check "8 no PHP warnings or notices while rendering the hostile event" log_clean_since "$LOG_PATH" "$off"
fi

# ---- 9. settings UX ------------------------------------------------------------------------------------

settings_post() { # settings_post <base_url> <site key or ''> <out file> [more curl args]: submits the real settings form
	local base="$1" key="$2" out="$3" nonce referer
	shift 3
	curl -s -b "$COOKIES" "$ODV_SITE/wp-admin/options-general.php?page=odavent-events" >"$TMP/form.html"
	nonce=$(grep -o 'name="_wpnonce" value="[^"]*"' "$TMP/form.html" | head -1 | sed 's/.*value="//; s/"$//')
	referer="/wp-admin/options-general.php?page=odavent-events"
	curl -s -L -b "$COOKIES" -o "$TMP/$out" -w '%{http_code}' \
		--data-urlencode option_page=odavent_events --data-urlencode action=update \
		--data-urlencode _wpnonce="$nonce" --data-urlencode _wp_http_referer="$referer" \
		--data-urlencode "odavent_events_settings[base_url]=$base" \
		--data-urlencode "odavent_events_settings[site_key]=$key" \
		--data-urlencode "odavent_events_settings[default_event_id]=0" \
		--data-urlencode "odavent_events_settings[cache_ttl]=60" \
		--data-urlencode "odavent_events_settings[load_css]=1" \
		"$@" "$ODV_SITE/wp-admin/options.php"
}

autoload_value() {
	wp_cli eval 'global $wpdb; echo $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = '"'"'odavent_events_settings'"'"'" );'
}

if want 9; then
	reset_all
	wp_cli option delete odavent_events_settings >/dev/null
	code=$(settings_post "http://127.0.0.1:8081" "$ODV_STUB_KEY" saved.html)
	check "9 first save through the settings form answers 200 ($code)" [ "$code" = "200" ]
	al=$(autoload_value)
	check "9 after the first save the option is not autoloaded (autoload=$al)" bash -c "[ '$al' = no ] || [ '$al' = off ]"
	check "9 first save stored the key" bash -c "[ \"\$(cd '$ODV_ROOT' && bin/wp option pluck odavent_events_settings site_key)\" = '$ODV_STUB_KEY' ]"

	code=$(settings_post "http://example.com" "" invalid.html)
	n=$(grep -o 'must start with https://' "$TMP/invalid.html" | wc -l | tr -d ' ')
	check "9 an invalid URL gives exactly one error notice (found $n)" [ "$n" = 1 ]
	check "9 the invalid URL was not saved" bash -c "[ \"\$(cd '$ODV_ROOT' && bin/wp option pluck odavent_events_settings base_url)\" = 'http://127.0.0.1:8081' ]"
	al=$(autoload_value)
	check "9 after a second save the option is still not autoloaded (autoload=$al)" bash -c "[ '$al' = no ] || [ '$al' = off ]"

	for mode in 500 timeout; do
		reset_all
		stub_control "{\"mode\": \"$mode\"}"
		res=$(get settings-$mode.html "$ODV_SITE/wp-admin/options-general.php?page=odavent-events" -b "$COOKIES")
		# The settings page gives the events lookup a 3 s budget (and skips it while the circuit breaker is set),
		# so it renders in about 3 s even when Odavent hangs, not after the client's 8 s timeout.
		check "9 [$mode] settings page answers 200 (${res%% *}) within 5s (${res##* }s)" \
			bash -c "[ '${res%% *}' = 200 ] && awk 'BEGIN { exit !(${res##* } <= 5) }'"
		check "9 [$mode] settings page falls back to a numeric default-event input" \
			contains "settings-$mode.html" 'type="number" min="0" class="small-text" id="odavent_events_default_event"'
	done
	reset_all
fi

# ---- 10. a stale view never offers Register ----------------------------------------------------------

if want 10; then
	reset_all
	get warm10.html "$ODV_SITE/slug-only/" >/dev/null
	get warm10b.html "$ODV_SITE/all-blocks-1/" >/dev/null
	check "10 setup: event 1 is live with a Register link" [ "$(register_links warm10.html)" -ge 1 ]
	set_settings ""                                                   # no key: the public route is used
	stub_control '{"status": {"1": "cancelled"}}'                     # public route now answers 404
	wp_cli transient delete odavent_events_ev_1_en >/dev/null 2>&1 || true          # the cache lifetime has passed
	wp_cli transient delete odavent_events_backoff >/dev/null 2>&1 || true
	for page in slug-only all-blocks-1; do
		get "stale-$page.html" "$ODV_SITE/$page/" >/dev/null
		check "10 [$page] still shows the event from the last good copy" contains "stale-$page.html" "Sommet Tech d&#039;Abidjan 2026"
		check "10 [$page] shows the stale notice" contains "stale-$page.html" "Registration details could not be refreshed."
		check "10 [$page] offers no Register link (found $(register_links "stale-$page.html"))" [ "$(register_links "stale-$page.html")" = 0 ]
	done
	reset_all
fi

# ---- 11. update checker ---------------------------------------------------------------------------

update_entry() { # the update_plugins response entry for the plugin after a fresh check, as JSON (or null)
	wp_cli transient delete odavent_events_update --network >/dev/null 2>&1 || true
	wp_cli eval 'delete_site_transient( "update_plugins" ); wp_update_plugins(); $t = get_site_transient( "update_plugins" ); echo wp_json_encode( isset( $t->response["odavent-events/odavent-events.php"] ) ? $t->response["odavent-events/odavent-events.php"] : null );'
}

if want 11; then
	reset_all
	off=$(log_size "$LOG_PATH")
	entry=$(update_entry)
	check "11 an update to 9.9.9 is offered" bash -c "printf '%s' '$entry' | jq -e '.new_version == \"9.9.9\"' >/dev/null"
	check "11 the package is the pinned GitHub release URL" \
		bash -c "printf '%s' '$entry' | jq -e '.package == \"https://github.com/mdjaman/odavent-wordpress/releases/download/v9.9.9/odavent-events-9.9.9.zip\"' >/dev/null"
	list=$(wp_cli plugin list --update=available --fields=name,version,update_version,update_package --format=json)
	check "11 wp plugin list --update=available lists odavent-events 9.9.9" \
		bash -c "printf '%s' '$list' | jq -e 'map(select(.name == \"odavent-events\" and .update_version == \"9.9.9\")) | length == 1' >/dev/null"
	uri=$(wp_cli eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; $d = get_plugin_data( WP_PLUGIN_DIR . "/odavent-events/odavent-events.php", false, false ); echo $d["UpdateURI"];')
	check "11 the plugin header carries Update URI ($uri)" [ "$uri" = "https://github.com/mdjaman/odavent-wordpress" ]
	stub_control '{"update": {"download_url": "https://downloads.example.com/odavent-events-9.9.9.zip"}}'
	entry=$(update_entry)
	check "11 a non-GitHub download_url is never offered (entry: $entry)" [ "$entry" = "null" ]
	stub_control '{"update": {"download_url": "https://github.com/someone-else/odavent-wordpress/releases/download/v9.9.9/odavent-events-9.9.9.zip"}}'
	entry=$(update_entry)
	check "11 another GitHub repository's package is never offered" [ "$entry" = "null" ]
	check "11 no PHP warnings during update checks" log_clean_since "$LOG_PATH" "$off"
	reset_all
	wp_cli transient delete odavent_events_update --network >/dev/null 2>&1 || true
	wp_cli eval 'delete_site_transient( "update_plugins" );' >/dev/null
fi

# ---- 14. event page block and shortcode --------------------------------------------------------------

EVENT_PAGE_SECTIONS='["header","countdown","register","tickets","find-registration","speakers","agenda","sponsors","venue","calendar"]'
EVENT_PAGE_HEADINGS='["Tickets","Speakers","Agenda","Sponsors and partners","Venue"]'

if want 14; then
	reset_all
	off=$(log_size "$LOG_PATH")

	# Event 6: published, the full fixture plus a partner of type "both"; its cover and that logo 404 on the stub.
	res=$(get ep6.html "$ODV_SITE/event-page-6/")
	check "14 event page (event 6) answers 200 (${res%% *})" [ "${res%% *}" = 200 ]
	html sections <"$TMP/ep6.html" >"$TMP/ep6-sections.json"
	check "14 one odv-event-page root for event 6" [ "$(html count 'class="odv-event-page"' <"$TMP/ep6.html")" = 1 ]
	check "14 the ten default sections render, in order ($(jq -r '[.[].slug] | join(",")' "$TMP/ep6-sections.json"))" \
		jq -e --argjson want "$EVENT_PAGE_SECTIONS" '[.[].slug] == $want' "$TMP/ep6-sections.json"
	check "14 headings Tickets, Speakers, Agenda, Sponsors and partners, Venue, in order, on their sections" \
		jq -e --argjson want "$EVENT_PAGE_HEADINGS" '[.[] | select(.heading != null) | .heading] == $want and ([.[] | select(.heading != null) | .slug] == ["tickets","speakers","agenda","sponsors","venue"])' "$TMP/ep6-sections.json"
	check "14 every section has its own anchor (odv-<section>-6, unique)" \
		jq -e '[.[].id] as $ids | ($ids | unique | length) == ($ids | length) and all($ids[]; test("^odv-[a-z-]+-6$"))' "$TMP/ep6-sections.json"
	check "14 the partner of type 'both' is headed 'Sponsors and exhibitors', never the raw 'Both'" \
		bash -c "grep -qF '<h3 class=\"odv-sponsors__group-title\">Sponsors and exhibitors</h3>' '$TMP/ep6.html' && ! grep -qiE 'group-title\">both<' '$TMP/ep6.html'"
	check "14 the live event page has a Register link ($(register_links ep6.html))" [ "$(register_links ep6.html)" -ge 1 ]
	check "14 the live event page shows no lifecycle notice" [ "$(html notices <"$TMP/ep6.html" | jq length)" = 0 ]
	errors=$(html parse <"$TMP/ep6.html")
	check "14 event page HTML parses without errors" [ "$(printf '%s' "$errors" | jq length)" = 0 ]

	html images <"$TMP/ep6.html" >"$TMP/ep6-images.json"
	check "14 data-odv-img on every image: cover, header logo, 3 speaker photos, 4 sponsor logos ($(jq -r '[.[].kind] | join(",")' "$TMP/ep6-images.json"))" \
		jq -e '([.[] | select(.kind == "cover" and .class == "odv-header__cover")] | length) == 1
			and ([.[] | select(.kind == "logo" and .class == "odv-header__logo")] | length) == 1
			and ([.[] | select(.kind == "photo" and .class == "odv-speakers__photo")] | length) == 3
			and ([.[] | select(.kind == "logo" and .class == "odv-sponsors__logo")] | length) == 4' "$TMP/ep6-images.json"
	n=$(grep -o '<img [^>]*class="odv-[^>]*>' "$TMP/ep6.html" | grep -vc 'data-odv-img' || true)
	check "14 no plugin <img> without data-odv-img (found $n)" [ "$n" = 0 ]
	check "14 the speaker photos carry their initials for the fallback" \
		bash -c "[ \"\$(grep -o 'data-odv-img=\"photo\" data-odv-initials=\"[A-Z]\{1,2\}\"' '$TMP/ep6.html' | wc -l | tr -d ' ')\" = 3 ]"
	code=$(dc exec -T wordpress curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8081/storage/missing/partner-406.png)
	check "14 the broken cover and the 'both' logo point at stub URLs that answer 404 ($code)" \
		bash -c "[ '$code' = 404 ] && jq -e 'map(.src) | index(\"http://127.0.0.1:8081/storage/missing/cover-6.webp\") != null and index(\"http://127.0.0.1:8081/storage/missing/partner-406.png\") != null' '$TMP/ep6-images.json' >/dev/null"
	scripts=$(html scripts <"$TMP/ep6.html")
	check "14 the image fallback script is loaded" \
		bash -c "jq -e 'map(.src) | any(test(\"/assets/js/image-fallback[.]js\"))' >/dev/null <<<\"\$1\"" _ "$scripts"

	# Event 7: the cancelled twin of 6.
	res=$(get ep7.html "$ODV_SITE/event-page-7/")
	check "14 cancelled twin (event 7) answers 200 (${res%% *})" [ "${res%% *}" = 200 ]
	notices=$(html notices <"$TMP/ep7.html")
	check "14 cancelled twin: exactly one lifecycle notice, the cancelled one ($(printf '%s' "$notices" | jq -c '[.[].text]'))" \
		bash -c "jq -e 'length == 1 and .[0].text == \"This event has been cancelled.\"' >/dev/null <<<\"\$1\"" _ "$notices"
	check "14 cancelled twin: 'This event has been cancelled.' appears once in the whole page" \
		[ "$(html count 'This event has been cancelled.' <"$TMP/ep7.html")" = 1 ]
	check "14 cancelled twin: no Register link anywhere (found $(register_links ep7.html))" [ "$(register_links ep7.html)" = 0 ]
	check "14 cancelled twin: no stale-copy notice" lacks ep7.html "Registration details could not be refreshed."
	html sections <"$TMP/ep7.html" >"$TMP/ep7-sections.json"
	check "14 cancelled twin keeps its content and drops countdown, register and calendar ($(jq -r '[.[].slug] | join(",")' "$TMP/ep7-sections.json"))" \
		jq -e '[.[].slug] as $s | (["header","tickets","speakers","agenda","sponsors","venue"] | all(. as $x | $s | index($x) != null)) and (["countdown","register","calendar"] | all(. as $x | $s | index($x) == null))' "$TMP/ep7-sections.json"

	# The shortcode renders the same markup as the block, live and cancelled.
	for id in 6 7; do
		get "eps$id.html" "$ODV_SITE/event-page-shortcode-$id/" >/dev/null
		html fragments <"$TMP/ep$id.html" >"$TMP/frag-ep$id.json"
		html fragments <"$TMP/eps$id.html" >"$TMP/frag-eps$id.json"
		if [ "$(jq length "$TMP/frag-ep$id.json")" -ge 1 ] && [ "$(jq -c . "$TMP/frag-ep$id.json")" = "$(jq -c . "$TMP/frag-eps$id.json")" ]; then
			pass "14 [odavent_event_page event_id=\"$id\"] renders the same as the block ($(jq -r '[.[].block] | join(",")' "$TMP/frag-eps$id.json"))"
		else
			fail "14 [odavent_event_page event_id=\"$id\"] renders the same as the block" \
				"$(diff <(jq -r '.[].html' "$TMP/frag-ep$id.json" | fold -w 160) <(jq -r '.[].html' "$TMP/frag-eps$id.json" | fold -w 160) | head -6 | tr '\n' ' ')"
		fi
	done
	check "14 the shortcode's cancelled twin has no Register link either" [ "$(register_links eps7.html)" = 0 ]
	check "14 no PHP warnings or notices while rendering the event pages" log_clean_since "$LOG_PATH" "$off"
fi

# ---- 15. a widget, a page cache and a refresh ping -----------------------------------------------------
# The dev stack has no page-cache plugin, so docker/mu-plugins/odavent-dev-page-cache.php is one (off unless the
# option odv_dev_page_cache is 1): it stores anonymous pages, answers X-Odv-Dev-Cache: HIT/MISS, and empties
# itself on the plugin's whole-site purge (odavent_events_purge_all), counting purges in purge-all.log. The
# widget sits in the dev widget area (docker/mu-plugins/odavent-dev-widget-area.php) on a page without Odavent
# content, which only the whole-site purge can refresh.

CACHE_DIR="/var/www/html/wp-content/odv-dev-cache"
cache_state() { grep -i '^x-odv-dev-cache:' "$TMP/$1.h" | tr -d '\r' | awk '{ print toupper($2) }'; }
purges() { wp_exec sh -c "if [ -f '$CACHE_DIR/$1' ]; then wc -l < '$CACHE_DIR/$1'; else echo 0; fi" | tr -d ' \r'; }
wait_purges() { # wait_purges <log> <more than>: the purge runs on shutdown, after the ping's answer
	local i
	for i in $(seq 1 20); do
		[ "$(purges "$1")" -gt "$2" ] && return 0
		sleep 0.25
	done
	return 1
}
ping_event() { # ping_event <event id> <unique second>: a signed refresh ping, echoes the HTTP status
	local body
	body=$(printf '{"event_id":%d,"event_slug":"smoke","sent_at":"2026-10-24T10:00:%02d+00:00"}' "$1" "$2")
	ping "$ODV_SITE" "p-ev$1-$2.json" "$("$ODV_ROOT/bin/sign-ping" "$ODV_STUB_KEY" "$body")" "$body"
}

if want 15; then
	reset_all
	off=$(log_size "$LOG_PATH")
	wp_exec sh -c "rm -rf '$CACHE_DIR'"
	wp_cli option update odv_dev_page_cache 1 >/dev/null
	wp_cli widget add odavent_events_register_button dev-widget-area 1 --title="Get your ticket" --eventId=6 >/dev/null
	wid=$(wp_cli widget list dev-widget-area --fields=id --format=ids | tr -d '\r')
	check "15 setup: wp widget add placed the Register button widget in a sidebar ($wid)" \
		bash -c "case '$wid' in odavent_events_register_button-[0-9]*) exit 0;; *) exit 1;; esac"

	get w1.html "$ODV_SITE/widget-host/" >/dev/null
	check "15 the widget renders for the live event, with its title and a Register link" \
		bash -c "grep -qF '<h2 class=\"widget-title\">Get your ticket</h2>' '$TMP/w1.html' && grep -qF 'class=\"widget odavent_events_widget\"' '$TMP/w1.html' && [ \"\$1\" -ge 1 ]" _ "$(register_links w1.html)"
	check "15 the widget's render loads the plugin stylesheet on a page without Odavent content" \
		grep -qE "id=['\"]odavent-events-css['\"]" "$TMP/w1.html"
	get w2.html "$ODV_SITE/widget-host/" >/dev/null
	check "15 the dev page cache stored the first visitor request ($(cache_state w1.html), then $(cache_state w2.html))" \
		bash -c "[ '$(cache_state w1.html)' = MISS ] && [ '$(cache_state w2.html)' = HIT ]"
	get ep6-c1.html "$ODV_SITE/event-page-6/" >/dev/null
	get ep6-c2.html "$ODV_SITE/event-page-6/" >/dev/null
	check "15 the event page is cached too ($(cache_state ep6-c2.html)) with its Register link" \
		bash -c "[ '$(cache_state ep6-c2.html)' = HIT ] && [ \"\$1\" -ge 1 ]" _ "$(register_links ep6-c2.html)"

	stub_control '{"status": {"6": "cancelled"}}'
	get w3.html "$ODV_SITE/widget-host/" >/dev/null
	check "15 control: cancelled on Odavent but not pinged yet, the cached page still offers Register ($(cache_state w3.html))" \
		bash -c "[ '$(cache_state w3.html)' = HIT ] && [ \"\$1\" -ge 1 ]" _ "$(register_links w3.html)"

	before=$(purges purge-all.log)
	before_post=$(purges purge-post.log)
	s=$(ping_event 6 1)
	check "15 a signed refresh ping for event 6 answers 200 ($s)" [ "$s" = 200 ]
	check "15 the ping fired the whole-site purge (odavent_events_purge_all) because a widget is active" wait_purges purge-all.log "$before"
	check "15 ... and the per-post purge of the pages using Odavent blocks (odavent_events_purge_post)" wait_purges purge-post.log "$before_post"
	get w4.html "$ODV_SITE/widget-host/" >/dev/null
	check "15 after the ping the widget page is rendered again ($(cache_state w4.html)) with no Register link (found $(register_links w4.html))" \
		bash -c "[ '$(cache_state w4.html)' = MISS ] && [ \"\$1\" = 0 ]" _ "$(register_links w4.html)"
	check "15 ... and the widget shows the cancelled notice instead" contains w4.html "This event has been cancelled."
	get ep6-c3.html "$ODV_SITE/event-page-6/" >/dev/null
	check "15 the event page was purged too ($(cache_state ep6-c3.html)), no Register link (found $(register_links ep6-c3.html))" \
		bash -c "[ '$(cache_state ep6-c3.html)' = MISS ] && [ \"\$1\" = 0 ]" _ "$(register_links ep6-c3.html)"

	get w-admin.html "$ODV_SITE/widget-host/" -b "$COOKIES" >/dev/null
	check "15 an editor on a page whose only Odavent content is a widget is not cached (DONOTCACHEPAGE probe)" \
		grep -qi '^x-odavent-probe-donotcachepage: 1' "$TMP/w-admin.html.h"

	wp_cli widget delete "$wid" >/dev/null
	wp_cli option delete odv_dev_page_cache >/dev/null
	wp_exec sh -c "rm -rf '$CACHE_DIR'"
	get w5.html "$ODV_SITE/widget-host/" >/dev/null
	check "15 cleanup: the widget is gone and the page is no longer cached" \
		bash -c "! grep -qF 'odavent_events_widget' '$TMP/w5.html' && ! grep -qi '^x-odv-dev-cache' '$TMP/w5.html.h'"
	check "15 no PHP warnings or notices with the widget, the cache and the ping" log_clean_since "$LOG_PATH" "$off"
	reset_all
fi

# ---- 16. Appearance settings through options.php ---------------------------------------------------------

appearance_json() { (cd "$ODV_ROOT" && bin/wp option pluck odavent_events_settings appearance --format=json) | tr -d '\r'; }
APPEARANCE_FIELD="odavent_events_settings[appearance]"
# The Appearance CSS printed after the plugin stylesheet, trimmed (WordPress 6.9+ appends a sourceURL comment).
inline_css() { html style odavent-events-inline-css <"$TMP/$1" | jq -r '(. // "") | gsub("/\\*# sourceURL=[^*]*\\*/"; "") | gsub("^\\s+|\\s+$"; "")'; }

if want 16; then
	reset_all
	off=$(log_size "$LOG_PATH")
	code=$(settings_post "http://127.0.0.1:8081" "$ODV_STUB_KEY" app-hostile.html \
		--data-urlencode "${APPEARANCE_FIELD}[accent]=red;}body{display:none}" \
		--data-urlencode "${APPEARANCE_FIELD}[contrast_auto]=1" \
		--data-urlencode "${APPEARANCE_FIELD}[contrast]=</style><script>alert(1)</script>" \
		--data-urlencode "${APPEARANCE_FIELD}[text]=#336699" \
		--data-urlencode "${APPEARANCE_FIELD}[border]=url(javascript:alert(1))" \
		--data-urlencode "${APPEARANCE_FIELD}[radius]=999" \
		--data-urlencode "${APPEARANCE_FIELD}[spacing]=huge;color:red" \
		--data-urlencode "${APPEARANCE_FIELD}[button_style]=<script>")
	check "16 saving hostile Appearance values through options.php answers 200 ($code)" [ "$code" = 200 ]
	stored=$(appearance_json)
	check "16 only valid values are stored: the colour kept, the radius clamped, the rest default ($stored)" \
		bash -c "jq -e '. == {\"accent\":\"\",\"contrast\":\"\",\"text\":\"#336699\",\"border\":\"\",\"radius\":24,\"spacing\":\"\",\"button_style\":\"filled\"}' >/dev/null <<<\"\$1\"" _ "$stored"
	check "16 the connection settings survive an Appearance save" \
		bash -c "[ \"\$(cd '$ODV_ROOT' && bin/wp option pluck odavent_events_settings base_url)\" = 'http://127.0.0.1:8081' ]"
	get app-front1.html "$ODV_SITE/all-blocks-1/" >/dev/null
	css=$(inline_css app-front1.html)
	check "16 the front end gets the valid values as the plugin stylesheet's inline CSS ($css)" \
		bash -c "case \"\$1\" in *'--odv-text:#336699;'*'--odv-radius:24px;'*) exit 0;; *) exit 1;; esac" _ "$css"
	check "16 no --odv-accent anywhere on the page for the invalid accent" lacks app-front1.html "--odv-accent"
	check "16 nothing of the hostile input reaches the CSS (display, script, javascript, url(, huge, 999)" \
		bash -c "! printf '%s' \"\$1\" | grep -qiE 'display|script|javascript|url\(|huge|999|red'" _ "$css"

	code=$(settings_post "http://127.0.0.1:8081" "$ODV_STUB_KEY" app-valid.html \
		--data-urlencode "${APPEARANCE_FIELD}[accent]=#0055AA" \
		--data-urlencode "${APPEARANCE_FIELD}[contrast_auto]=1" \
		--data-urlencode "${APPEARANCE_FIELD}[radius]=6" \
		--data-urlencode "${APPEARANCE_FIELD}[spacing]=roomy" \
		--data-urlencode "${APPEARANCE_FIELD}[button_style]=outline")
	stored=$(appearance_json)
	check "16 a valid accent is stored normalised ($stored)" \
		bash -c "jq -e '.accent == \"#0055aa\" and .contrast == \"\" and .radius == 6 and .spacing == \"roomy\" and .button_style == \"outline\"' >/dev/null <<<\"\$1\"" _ "$stored"
	check "16 no contrast warning for a readable pair" lacks app-valid.html "contrast ratio of"
	get app-front2.html "$ODV_SITE/all-blocks-1/" >/dev/null
	css=$(inline_css app-front2.html)
	check "16 the front end now has --odv-accent for the valid colour, with automatic white text (${css%%\}*}})" \
		bash -c "case \"\$1\" in ':root{--odv-accent:#0055aa;--odv-accent-contrast:#ffffff;--odv-radius:6px;--odv-gap:1.5rem;}'*) exit 0;; *) exit 1;; esac" _ "$css"
	check "16 the outline button style adds its rule" bash -c "case \"\$1\" in *'background:transparent'*) exit 0;; *) exit 1;; esac" _ "$css"

	code=$(settings_post "http://127.0.0.1:8081" "$ODV_STUB_KEY" app-low.html \
		--data-urlencode "${APPEARANCE_FIELD}[accent]=#ffff00" \
		--data-urlencode "${APPEARANCE_FIELD}[contrast]=#ffffff")
	check "16 a low-contrast pair is saved with a warning that gives the ratio (1.07:1)" \
		bash -c "grep -qF 'contrast ratio of 1.07:1' '$TMP/app-low.html' && [ \"\$(cd '$ODV_ROOT' && bin/wp option pluck odavent_events_settings appearance contrast)\" = '#ffffff' ]"
	check "16 no PHP warnings or notices while saving and applying Appearance" log_clean_since "$LOG_PATH" "$off"
	reset_all
fi

# ---- 13 (+12). the release zip on a fresh WordPress ---------------------------------------------------

PKG_PATH="/var/www/html/pkg"
PKG_SITE="$ODV_SITE/pkg"
wp_pkg() { dc exec -T wpcli wp --path="$PKG_PATH" "$@"; }

if want 13 || want 12; then
	off=$(log_size "$LOG_PATH")
	VERSION=$(sed -n 's/^ \* Version: *\([0-9][0-9A-Za-z.-]*\) *$/\1/p' "$ODV_ROOT/odavent-events.php")
	if [ -z "$VERSION" ] || ! "$ODV_ROOT/bin/build-zip" "$VERSION" >"$TMP/build.txt" 2>&1; then
		fail "13 the release zip builds" "$(tail -3 "$TMP/build.txt")"
	else
		pass "13 the release zip $VERSION builds ($(tail -1 "$TMP/build.txt"))"
		# A second, empty WordPress in /pkg (same database, table prefix pkg_), installed from the image's copy.
		dc exec -T wordpress sh -c "rm -rf $PKG_PATH && cp -a /usr/src/wordpress $PKG_PATH && rm -f $PKG_PATH/wp-config-docker.php && chown -R 33:33 $PKG_PATH"
		wp_cli eval-file "$ODV_PLUGIN_DIR/docker/smoke/drop-pkg-tables.php" >/dev/null
		printf '%s\n' "define( 'WP_ENVIRONMENT_TYPE', 'local' );" "define( 'WP_DEBUG', true );" "define( 'WP_DEBUG_LOG', true );" "define( 'WP_DEBUG_DISPLAY', false );" "define( 'FS_METHOD', 'direct' );" "define( 'AUTOMATIC_UPDATER_DISABLED', true );" \
			| wp_pkg config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=db --dbprefix=pkg_ --skip-check --extra-php >/dev/null
		wp_pkg core install --url="$PKG_SITE" --title="Odavent package test" --admin_user=admin --admin_password=admin \
			--admin_email=admin@example.com --skip-email >/dev/null
		wp_pkg rewrite structure '/%postname%/' --hard >/dev/null
		inst=$(wp_pkg plugin install "$ODV_PLUGIN_DIR/dist/odavent-events-$VERSION.zip" --force --activate 2>&1)
		check "13 wp plugin install <zip> --force --activate succeeds" wp_pkg plugin is-active odavent-events
		if listing=$(dc exec -T wordpress ls -A "$PKG_PATH/wp-content/plugins/odavent-events"); then
			listing=$(printf '%s\n' "$listing" | tr -d '\r')
			dev=$(printf '%s\n' "$listing" | grep -xE 'tests|docker|bin|vendor|mu-plugins|composer\.json|composer\.lock|patchwork\.json|phpunit\.xml\.dist|phpcs\.xml\.dist|node_modules|\..*' | tr '\n' ' ')
			check "13 the installed copy is the zip, not the dev tree (no dev files; found: '${dev}')" [ -z "$dev" ]
			check "13 the installed copy has the runtime files (src, blocks, languages)" \
				bash -c 'for f in src blocks languages odavent-events.php; do printf "%s\n" "$1" | grep -qx "$f" || exit 1; done' _ "$listing"
		else
			fail "13 the installed copy is the zip, not the dev tree" "could not list the installed plugin"
		fi
		check "13 the zip ships compiled translations (fr_FR .mo)" \
			dc exec -T wordpress test -f "$PKG_PATH/wp-content/plugins/odavent-events/languages/odavent-events-fr_FR.mo"
		wp_pkg option update odavent_events_settings \
			"$(printf '{"base_url":"http://127.0.0.1:8081","site_key":"%s","cache_ttl":60,"default_event_id":0,"load_css":true}' "$ODV_STUB_KEY")" \
			--format=json --autoload=no >/dev/null
		blocks=""
		for b in event-header register-button tickets find-registration speakers agenda sponsors countdown venue add-to-calendar; do
			blocks="${blocks}<!-- wp:odavent-events/${b} {\"eventId\":1} /-->"$'\n\n'
		done
		printf '%s' "$blocks" | wp_pkg post create - --post_type=page --post_status=publish --post_name=all-blocks-1 --post_title="All blocks, event 1" >/dev/null

		stub_control '{"reset": true}'
		assert_all_blocks "13 [zip] 1" "$PKG_SITE"
		assert_refresh "13 [zip] 5" "$PKG_SITE"

		wp_pkg language core install fr_FR >/dev/null 2>&1
		wp_pkg site switch-language fr_FR >/dev/null 2>&1
		wp_pkg eval '\Odavent\Events\Plugin::instance()->repository()->purge_all();' >/dev/null
		get fr.html "$PKG_SITE/all-blocks-1/" >/dev/null
		label=$(html links <"$TMP/fr.html" | jq -r '[.[] | select(.class == "odv-register__button")][0].text // ""')
		check "13 [zip, fr_FR] the Register button reads S'inscrire (got '$label')" \
			bash -c "case \"\$1\" in \"S'inscrire\"|\"S’inscrire\") exit 0;; *) exit 1;; esac" _ "$label"
		check "13 [zip, fr_FR] the secure-payment notice is in French (from the shipped .mo)" \
			contains fr.html "L’inscription et le paiement sont gérés en toute sécurité par Odavent."
		check "13 [zip, fr_FR] event content is requested in French (?locale=fr)" contains fr.html "(fr)"
		check "13 PHP errors reach the packaged site's debug.log (canary)" log_canary wp_pkg "$PKG_PATH/wp-content/debug.log"
		check "13 no PHP warnings in the packaged site's debug.log" log_clean_since "$PKG_PATH/wp-content/debug.log" 0

		# ---- 12. uninstall, the way the Plugins screen does it (deactivate, then delete_plugins()) -------
		wp_pkg transient set odavent_events_probe 1 3600 >/dev/null
		wp_pkg eval 'set_site_transient( "odavent_events_update", array( "failed" => true ), 3600 );' >/dev/null
		before=$(wp_pkg option list --search='*odavent_events*' --format=count) || before=error
		check "12 precondition: the plugin's options and transients exist before uninstalling ($before)" \
			bash -c "[ '$before' != error ] && [ '$before' -gt 0 ]"
		wp_pkg plugin deactivate odavent-events >/dev/null
		wp_pkg eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; require_once ABSPATH . "wp-admin/includes/file.php"; $r = delete_plugins( array( "odavent-events/odavent-events.php" ) ); echo true === $r ? "deleted" : "failed";' >"$TMP/delete.txt"
		check "12 delete_plugins() removed the plugin ($(cat "$TMP/delete.txt"))" grep -qx deleted "$TMP/delete.txt"
		if left=$(wp_pkg option list --search='*odavent_events*' --fields=option_name --format=csv); then
			left=$(printf '%s\n' "$left" | tail -n +2 | tr '\n' ' ' | sed 's/ *$//')
			check "12 uninstall leaves no odavent_events options or transients (had $before, left: '${left}')" [ -z "$left" ]
		else
			fail "12 uninstall leaves no odavent_events options or transients" "wp option list failed"
		fi
		check "12 the dev tree is untouched" test -f "$ODV_ROOT/odavent-events.php"
	fi
	check "13 no PHP warnings in the dev site's debug.log meanwhile" log_clean_since "$LOG_PATH" "$off"
	stub_control '{"reset": true}'
fi

check "end: no PHP warnings or notices in debug.log during the whole run" log_clean_since "$LOG_PATH" "$LOG_START"

if [ -z "$ONLY" ] && [ "$PASSES" -lt "$EXPECTED_PASSES" ]; then
	fail "every section ran" "only $PASSES checks passed, $EXPECTED_PASSES expected: a section was skipped"
fi

log "Smoke test: $PASSES passed, $FAILS failed in $(( $(date +%s) - started ))s"
[ "$FAILS" = 0 ]
