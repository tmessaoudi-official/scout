#!/usr/bin/env bash
# Proves the fixture scrubber verifies what it CLAIMS to verify: that the subscriber's address is
# not RECOVERABLE from the output — not merely that it is not present as text.
#
# The distinction is not academic; it is the defect this file was written for. Every Bien'ici alert
# link carries `signedRecipient=eyJhbGciOi…`, a JWT whose payload base64url-decodes to
# `{"email":"<the subscriber>","iat":…}`. Measured 2026-08-25 on a real capture: the literal address
# is absent from the decoded body, and one `base64 -d` recovers it in full. The scrubber's own
# docblock promised it would refuse to write such a file. It wrote it, and said `scrubbed`.
#
# Three halves, because a guard needs all three to mean anything:
#   MUST STRIP    a JWT token is replaced, shape kept, and the address is gone from the decode
#   MUST REFUSE   an address encoded in a shape the scrubber cannot strip stops the write
#   MUST BE QUIET an ordinary capture with no tokens still scrubs cleanly
#
# The refusal half is the one that matters. A scrubber that strips what it knows about and stays
# silent about what it does not is worse than no scrubber, because its output looks scrubbed.
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

pass=0
fail=0

check() {
  local label="$1"
  shift
  if "$@"; then
    printf '  \033[32mok\033[0m   %s\n' "$label"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %s\n' "$label"
    fail=$((fail + 1))
  fi
}

# `check ! grep …` cannot work — `!` is shell syntax, not a command, so `"$@"` runs it as one and
# every negated assertion errors out with `!: command not found`. That failure LOOKS like the
# assertion failing, which is the shape of a test that proves nothing.
# AN ERROR IS NOT A REFUTATION (round-5 panel, 2026-08-31). This used to treat EVERY non-zero exit
# as the negative it was asserting — including 2 (a usage error) and 127 (command not found). Round 4
# found an instance: a section-header comment fused onto the end of a `refute` line with no newline
# handed `test` eight arguments, which exited 2 and reported `ok` whatever the scrubber had done.
# That was fixed as an instance; the MECHANISM that made it invisible was not, so the next fused
# line, typo'd flag or renamed helper would do it again — in the one test file whose subject is a
# privacy guard, where a vacuous `ok` is exactly the failure being guarded against.
#
# So: exit 1 is the genuine falsity this asserts, and anything above it is the command itself
# breaking, which is a FAILURE of the test rather than a pass. `bash -n` and `shellcheck -S warning`
# are both silent on the shape, so this is the only place it can be caught.
refute() {
  local label="$1"
  local status=0
  shift
  "$@" || status=$?

  if [ "$status" -eq 0 ]; then
    printf '  \033[31mFAIL\033[0m %s\n' "$label"
    fail=$((fail + 1))
  elif [ "$status" -gt 1 ]; then
    printf '  \033[31mFAIL\033[0m %s (la commande a ÉCHOUÉ avec le code %d — ce n'"'"'est pas une réfutation)\n' "$label" "$status"
    fail=$((fail + 1))
  else
    printf '  \033[32mok\033[0m   %s\n' "$label"
    pass=$((pass + 1))
  fi
}

address='subscriber.person@example.test'

# base64url, unpadded — the encoding a JWT actually uses.
b64url() {
  printf '%s' "$1" | base64 | tr -d '=\n' | tr '+/' '-_'
}

jwt="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.$(b64url "{\"email\":\"${address}\",\"iat\":1787680538}").YqW6nmGpd-YcbYStBwx5EPHOge5FVBXeaSumGzmfTcs"

message_with_jwt() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 2 nouvelles annonces
Content-Type: text/plain; charset=utf-8

Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=${jwt}
1 170 EUR par mois charges comprises
EOF
}

# The same address, base64'd into a parameter that is NOT jwt-shaped. Nothing can strip this
# without knowing the portal's scheme, so the only correct answer is to refuse.
opaque="$(b64url "${address}")"

message_with_opaque() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8

Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?u=${opaque}
1 170 EUR par mois charges comprises
EOF
}

message_plain() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8

Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123
1 170 EUR par mois charges comprises
EOF
}

# A capture in the shape leboncoin actually sends: HTML-only, quoted-printable, greeting the
# subscriber by USERNAME rather than by address, and carrying an account-scoped saved-search UUID
# plus opaque analytics hexes. None of those is an email address, so the address checks all pass on
# it -- which is exactly how a file carrying personal data got reported as `scrubbed`.
message_with_personal_ids() {
  cat <<'EOF'
From: no.reply@portal.test
To: subscriber@example.test
Subject: 3 nouveaux biens a louer
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

<p>Bonjour tmessaoudi,</p>
<a href=3D"https://www.portal.test/my-searches/e5ce7f30-114f-4d67-96be-28d6c8=
9cad0b/">Ma recherche</a>
<a href=3D"https://www.portal.test/vi/3256902167.htm?t=3D1d09633ac8dfb2e54bc9=
ffa92ba58ef3e7dffb26">Appartement 48 m2</a>
EOF
}

scrub() {
  php "$repo/tools/scrub-eml.php" "$1" "$2" "$address"
}

# Every long base64url run in a file, decoded and concatenated. This is the attacker's move, and
# the test performs it rather than trusting the scrubber's report.
decoded_runs() {
  python3 - "$1" <<'PY'
import base64, re, sys
raw = open(sys.argv[1], 'rb').read().decode('utf-8', 'replace')
out = []
for run in re.findall(r'[A-Za-z0-9_\-]{16,}', raw):
    padded = run + '=' * (-len(run) % 4)
    try:
        out.append(base64.urlsafe_b64decode(padded).decode('utf-8', 'replace'))
    except Exception:
        pass
print('\n'.join(out))
PY
}

# Quoted-printable decoded. Without this a `grep -F` for a FOLDED identifier finds nothing and the
# assertion passes on a file that still carries it -- QP breaks lines at 76 columns, straight through
# the middle of a UUID. Two assertions below were green for exactly that reason before this existed.
decoded_qp() {
  python3 -c 'import quopri,sys;sys.stdout.write(quopri.decodestring(open(sys.argv[1],"rb").read()).decode("utf-8","replace"))' "$1"
}

printf '\n== test-scrub-eml: is the address RECOVERABLE from a scrubbed fixture? ==\n\n'

# ── MUST STRIP ────────────────────────────────────────────────────────────────────────────────────
message_with_jwt >"$work/jwt.eml"
jwt_status=0
scrub "$work/jwt.eml" "$work/jwt.out.eml" >"$work/jwt.log" 2>&1 || jwt_status=$?

check "a capture whose links carry a JWT is scrubbed rather than refused" \
  test "$jwt_status" -eq 0
check "the scrubbed output exists" test -f "$work/jwt.out.eml"

if [[ -f "$work/jwt.out.eml" ]]; then
  decoded_runs "$work/jwt.out.eml" >"$work/jwt.decoded"

  refute "the address is not recoverable by decoding the output's tokens" \
    grep -qF "$address" "$work/jwt.decoded"
  refute "the local part alone is not recoverable either" \
    grep -qF "${address%%@*}" "$work/jwt.decoded"
  refute "the address is not present as literal text" \
    grep -qF "$address" "$work/jwt.out.eml"
  # The VALUE goes and the SHAPE stays, exactly as the qs= rule already says: a fixture whose links
  # have no token at all would not exercise the link handling it was captured to exercise.
  check "a three-segment token shape survives the scrub" \
    grep -qE '[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+' "$work/jwt.out.eml"
  check "the surviving token announces itself as a placeholder" \
    grep -qi 'placeholder' "$work/jwt.out.eml"
  # The point of scrubbing rather than deleting: the parser must still see a listing.
  check "the listing URL survives" grep -q '/annonce/abc-123' "$work/jwt.out.eml"
fi

# ── MUST REFUSE A BASE64 BODY ──────────────────────────────────────────────────────────────────────
# The encoding after quoted-printable. A `Content-Transfer-Encoding: base64` body carries the same
# JWT as opaque 76-column lines: no run in the raw or QP-unfolded text decodes to the address, so the
# old check reported `scrubbed` on a file the address was one `base64 -d` away from (review panel,
# 2026-08-30). The tool cannot rewrite inside a base64 body without re-encoding it, so the honest
# answer is a REFUSAL, exit non-zero, no output file.
message_b64() {
  body="Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=${jwt}
1 170 EUR par mois charges comprises"
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: base64

$(printf '%s' "$body" | base64 | fold -w 76)
EOF
}

message_b64 >"$work/b64.eml"
b64_status=0
scrub "$work/b64.eml" "$work/b64.out.eml" >"$work/b64.log" 2>&1 || b64_status=$?

check "a base64-encoded body from which the address is recoverable is REFUSED" test "$b64_status" -ne 0
refute "and nothing is written" test -f "$work/b64.out.eml"
check "the refusal says the address is recoverable" grep -qi 'recoverable' "$work/b64.log"
# The tail (round-3 panel): every line short. A 36-column fold was written outright.
message_b64_short() {
  body="Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=${jwt}
1 170 EUR par mois charges comprises"
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: base64

$(printf '%s' "$body" | base64 -w 0 | fold -w 36)
EOF
}

message_b64_short >"$work/b64s.eml"
b64s_status=0
scrub "$work/b64s.eml" "$work/b64s.out.eml" >"$work/b64s.log" 2>&1 || b64s_status=$?

check "a base64 body folded at 36 columns is REFUSED as well" test "$b64s_status" -ne 0
refute "and nothing is written for it" test -f "$work/b64s.out.eml"

# ROUND 4: a per-line WIDTH floor is the same defect with a smaller number. Round 3 lowered it from
# 40 to 20 for the 36-column case above; at 19 the pattern matched nothing, the decode never ran,
# and the tool WROTE the file and reported it clean with the address one `base64 -d` away. The floor
# is gone — total decoded length was always the real constraint. Folded narrower than any real mailer
# would, on purpose: the guarantee is "any width", not "the widths seen so far".
message_b64_narrow() {
  body="Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=${jwt}
1 170 EUR par mois charges comprises — envoye a ${address}"
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: base64

$(printf '%s' "$body" | base64 -w 0 | fold -w 19)
EOF
}

message_b64_narrow >"$work/b64n.eml"
b64n_status=0
scrub "$work/b64n.eml" "$work/b64n.out.eml" >"$work/b64n.log" 2>&1 || b64n_status=$?

check "a base64 body folded at 19 columns is REFUSED too (no per-line width floor)" test "$b64n_status" -ne 0
refute "and nothing is written for the narrow fold" test -f "$work/b64n.out.eml"

# And UTF-16 (round-3 panel): every ASCII byte followed by a NUL, so a byte search never matches.
message_b64_utf16() {
  body="https://www.portal.test/annonce/abc-123?signedRecipient=${jwt} — envoye a ${address}"
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-16le
Content-Transfer-Encoding: base64

$(printf '%s' "$body" | iconv -f UTF-8 -t UTF-16LE | base64 -w 0 | fold -w 76)
EOF
}

message_b64_utf16 >"$work/b64u.eml"
b64u_status=0
scrub "$work/b64u.eml" "$work/b64u.out.eml" >"$work/b64u.log" 2>&1 || b64u_status=$?

check "a UTF-16 base64 body from which the address is recoverable is REFUSED" test "$b64u_status" -ne 0
refute "and nothing is written for it either" test -f "$work/b64u.out.eml"

# ── MUST STRIP THROUGH QUOTED-PRINTABLE ───────────────────────────────────────────────────────────
# The case the first version of this file did not have, and the one that mattered. Most alert mail
# is QP-encoded: `=` becomes `=3D`, and every line folds at 76 columns with a trailing `=`. So a
# real capture reads `signedRecipient=3DeyJhbGciOi…` with soft breaks through the middle of the
# token — and a `\b`-anchored pattern refuses to start, because the character before `eyJ` is the
# `D` of `=3D`. Measured 2026-08-25: the unencoded case above passed while the tool stripped
# NOTHING from all three real Bien'ici captures.
qp_head="${jwt:0:60}"
qp_tail="${jwt:60}"

message_qp() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Appartement 3 pi=C3=A8ces 65 m2
https://www.portal.test/annonce/abc-123?signedRecipient=3D${qp_head}=
${qp_tail}
1 170 EUR par mois charges comprises
EOF
}

message_qp >"$work/qp.eml"
qp_status=0
scrub "$work/qp.eml" "$work/qp.out.eml" >"$work/qp.log" 2>&1 || qp_status=$?

check "a quoted-printable capture is scrubbed rather than refused" test "$qp_status" -eq 0

if [[ -f "$work/qp.out.eml" ]]; then
  # The attacker unfolds first. So does the check.
  python3 - "$work/qp.out.eml" >"$work/qp.decoded" <<'PY'
import base64, quopri, re, sys
raw = open(sys.argv[1], 'rb').read()
forms = [raw.decode('utf-8', 'replace'), quopri.decodestring(raw).decode('utf-8', 'replace')]
out = []
for text in forms:
    for run in re.findall(r'[A-Za-z0-9_\-]{16,}', text):
        padded = run + '=' * (-len(run) % 4)
        try:
            out.append(base64.urlsafe_b64decode(padded).decode('utf-8', 'replace'))
        except Exception:
            pass
print('\n'.join(out))
PY

  refute "the address is not recoverable by unfolding and decoding" \
    grep -qF "$address" "$work/qp.decoded"
  refute "nor is the local part" \
    grep -qF "${address%%@*}" "$work/qp.decoded"
  check "the report says a signed token was replaced" \
    grep -qE '[1-9][0-9]* signed tokens' "$work/qp.log"
  # The replacement is re-folded when the original was folded, so the scrub cannot LENGTHEN a line.
  # Emitting one long token where a folded one stood would leave a non-conformant line where a
  # conformant one had been, and the capture's STRUCTURE is what this whole tool exists to preserve.
  #
  # Stated as "no longer than the input" rather than "at most 76 octets" because 76 is a claim about
  # the input, and it is false of real mail: Bien'ici's own captures carry 258-column lines. An
  # assertion that fails on a conformant scrub of a non-conformant capture measures the fixture.
  longest() { awk '{ if (length($0) > m) m = length($0) } END { print m + 0 }' "$1"; }
  check "the scrub did not lengthen any line" \
    test "$(longest "$work/qp.out.eml")" -le "$(longest "$work/qp.eml")"
fi

# ── A NEEDLE THAT OVERLAPS THE ADDRESS MUST NOT DESTROY IT FIRST ─────────────────────────────────
# Round-6 panel, 2026-08-31. The needle loop ran BEFORE the address replacement, and a needle is
# typically the subscriber's NAME — which is usually IN the address. So `Takieddine` + `MESSAOUDI`
# rewrote `takieddine.messaoudi.official@gmail.com` into `abonne.abonne.official@gmail.com` before
# anything looked for the address; `str_replace($address)` then matched nothing, the local-part
# fallback matched nothing, and the final verification matched nothing either — so the tool wrote
# the file, printed `scrubbed` and exited 0 while the remainder reconstructed the address beside the
# commit author. Two fixtures shipped exactly that, hours after ALERT-CAPTURE.md started telling
# operators to pass the name. This is the procedure the docs prescribe, so it is the procedure the
# suite must exercise.
message_needle_overlaps_address() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: <jeanne.dubois.official@example.test>
Subject: 1 nouvelle annonce

Bonjour Jeanne DUBOIS,
https://www.portal.test/annonce/abc?email=jeanne.dubois.official%40example.test&md5=aa
EOF
}

message_needle_overlaps_address >"$work/overlap.eml"
overlap_status=0
php "$repo/tools/scrub-eml.php" "$work/overlap.eml" "$work/overlap.out.eml" \
  'jeanne.dubois.official@example.test' Jeanne DUBOIS >"$work/overlap.log" 2>&1 || overlap_status=$?

check "a capture whose NEEDLE overlaps the address is scrubbed rather than refused" test "$overlap_status" -eq 0
refute "and no fragment of the local part survives beside an untouched remainder" \
  grep -qiE 'abonne[.-]?abonne' "$work/overlap.out.eml"
refute "and the greeting name is gone" grep -qiF 'DUBOIS' "$work/overlap.out.eml"
check "and the listing survives, because it is the payload" \
  grep -qF 'annonce/abc' "$work/overlap.out.eml"

# ── A NEEDLE SPLIT BY A QUOTED-PRINTABLE SOFT BREAK MUST STILL BE STRIPPED ────────────────────────
# Measured 2026-09-13 on real LinkedIn job alerts. The footer names the subscriber in both parts. In
# the QP-encoded HTML part, one occurrence is folded by a soft break through the middle of the name.
# The raw file carries the name 8 times and the QP-decoded file 9 times, on four captures of twenty.
# The literal needle replace cannot see `Jea=\nnne`. The recoverability check can, so the tool
# refused every such capture. That is the correct refusal of a scrub that cannot finish, but it made
# the capture uncommittable. The repair is to strip THROUGH the fold, never to relax the check.
# The fold sits in a GREETING here, not in LinkedIn's footer sentence, on purpose: the footer rule
# below now removes that whole sentence first, so a footer fixture would pass with the needle path
# deleted, and this case would test nothing.
message_needle_split_by_soft_break() {
  cat <<'EOF'
From: Portal <no_reply@portal.test>
To: <subscriber.person@example.test>
Subject: 1 nouvelle offre
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: quoted-printable

<p>Bonjour Jea=
nne DUBOIS,</p>
<p>Senior Software Engineer</p>
<a href=3D"https://www.portal.test/comm/jobs/view/4461976159/">Voir</a>
EOF
}

message_needle_split_by_soft_break >"$work/softbreak.eml"
softbreak_status=0
php "$repo/tools/scrub-eml.php" "$work/softbreak.eml" "$work/softbreak.out.eml" "$address" Jeanne DUBOIS \
  >"$work/softbreak.log" 2>&1 || softbreak_status=$?

check "a needle folded by a QP soft break is scrubbed rather than refused" test "$softbreak_status" -eq 0
if [[ -f "$work/softbreak.out.eml" ]]; then
  decoded_qp "$work/softbreak.out.eml" >"$work/softbreak.decoded"
  refute "and the folded first name is gone once decoded" grep -qiF 'Jeanne' "$work/softbreak.decoded"
  refute "and the surname is gone" grep -qiF 'DUBOIS' "$work/softbreak.decoded"
  check "and the job link survives, because it is the payload" \
    grep -qF 'jobs/view/4461976159' "$work/softbreak.decoded"
  check "and the soft-break STRUCTURE survives (the fold is a shape the parser must keep meeting)" \
    grep -qE '=$' "$work/softbreak.out.eml"
else
  check "and the folded first name is gone once decoded" false
  check "and the surname is gone" false
  check "and the job link survives, because it is the payload" false
  check "and the soft-break STRUCTURE survives (the fold is a shape the parser must keep meeting)" false
fi

# ── LINKEDIN'S PER-RECIPIENT LINK PARAMETERS: the VALUE goes, the NAME stays ──────────────────────
# Measured 2026-09-13 on a real LinkedIn job alert: every link carries `lipi`, `midToken`, `midSig`
# and `eid` (49 each), `trk`, `trkEmail` and `otpToken` (46), `trackingId` and `refId` (24), plus
# `savedSearchId`. None of these decodes to the address, so the recoverability check passes on them.
# The name had already gone, and the scrub reported success while 41 `otpToken=` values tied every
# link in the file to one member's account. A token that carries no address is still an identifier.
# Values below are synthetic, and the capture's QP shape is kept: `=3D` escapes, a folded token.
message_linkedin_tokens() {
  cat <<'EOF'
From: LinkedIn Job Alerts <jobalerts-noreply@linkedin.com>
To: <subscriber.person@example.test>
Subject: Aneo recrute au poste de Senior Software Engineer
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: quoted-printable

<a href=3D"https://www.linkedin.com/comm/jobs/view/4461976159/?trackingId=3DtRkIdSyNtHeTiC0001%3D%3D&amp;refId=3DrEfIdSyNtHeTiC0002%3D%3D&amp;lipi=3Durn%3Ali%3Apage%3Aemail_email_job_alert_digest_01%3BlIpIsYnThEtIc0003&amp;midToken=3DAQHmIdToKeNsYnThEtIc0004&amp;midSig=3D0mIdSiGsYnThEtIc0005&amp;trk=3Deml-email_job_alert_digest_01-job_card-0-jobcard_body_text&amp;trkEmail=3Deml-email_job_alert_digest_01-job_card-0-jobcard_body_text-null-tRkEmAiLsYnThEtIc0006&amp;eid=3DeIdSyNtHeTiC0007&amp;otpToken=3DMTAwOTE3ZTQxMzJiY2RiNGI0MjQ0NGU0NDMxOGUzYjA0ODE5ZDNmMzk3YW=
E4ZTYxNzZjZDAwN2YwNjlmNjUxMmM1MjY3YWU4ZDQ1YTBjOTM5ZmYxN2VjOTQ5N2VkMTE2OGEw=
MDg4YTYxMThjMzBmYjg4N2MzOGRlYSwxLDE%3D">Senior Software Engineer</a>
<a href=3D"https://www.linkedin.com/comm/jobs/view/4461976160/?midSig=
=3D0fOlDbEfOrEsEpSyNtH0008&amp;otpTo=
ken=3DfOlDiNnAmEsYnThEtIc0009">Autre offre</a>
<a href=3D"https://www.linkedin.com/comm/jobs/search?savedSearchId=3D1805245866&amp;origin=3DJOB_ALERT_EMAIL">Voir toutes les offres</a>
EOF
}
# The second link carries the two fold shapes measured on the real capture, where the pattern
# matched nothing and 20 values survived: a soft break between a NAME and its `=3D`, and a soft
# break through the middle of a NAME. QP encoders never split an `=3D` escape, but they fold
# anywhere else.

message_linkedin_tokens >"$work/linkedin.eml"
linkedin_status=0
scrub "$work/linkedin.eml" "$work/linkedin.out.eml" >"$work/linkedin.log" 2>&1 || linkedin_status=$?

check "a LinkedIn alert carrying per-recipient link parameters is scrubbed" test "$linkedin_status" -eq 0
if [[ -f "$work/linkedin.out.eml" ]]; then
  decoded_qp "$work/linkedin.out.eml" >"$work/linkedin.decoded"
  for token in tRkIdSyNtHeTiC0001 rEfIdSyNtHeTiC0002 lIpIsYnThEtIc0003 AQHmIdToKeNsYnThEtIc0004 \
    0mIdSiGsYnThEtIc0005 tRkEmAiLsYnThEtIc0006 eIdSyNtHeTiC0007 MTAwOTE3ZTQxMzJiY2RiNGI0MjQ0NGU0 1805245866 \
    0fOlDbEfOrEsEpSyNtH0008 fOlDiNnAmEsYnThEtIc0009; do
    refute "and the per-recipient value $token is gone (checked DECODED)" grep -qF "$token" "$work/linkedin.decoded"
  done
  for name in trackingId refId lipi midToken midSig trkEmail eid otpToken savedSearchId; do
    check "but the parameter NAME $name stays, because the link shape is what the fixture exercises" \
      grep -qF "${name}=" "$work/linkedin.decoded"
  done
  check "and the job id survives, because it is the payload" grep -qF 'jobs/view/4461976159' "$work/linkedin.decoded"
  check "and the parser still reads the job link out of the scrubbed HTML" \
    php -r 'require $argv[2]; $m = Scout\Adapters\Mail\EmailMessage::parse(file_get_contents($argv[1]));
            exit(str_contains($m->htmlText, "/jobs/view/4461976159/") ? 0 : 1);' "$work/linkedin.out.eml" "$repo/vendor/autoload.php"
else
  check "and the per-recipient values are gone" false
fi

# ── THE FOOTER HEADLINE: the whole parenthetical after "destiné à <name>", fold- and QP-aware ──────
# Measured 2026-09-13: LinkedIn's footer reads `Cet e-mail est destiné à <name> (<profile headline>)`.
# Fragment needles cannot remove the headline. `·` is `=C2=B7` in quoted-printable, so a byte-literal
# needle never matches it. Its generic half (`Lead Developer | Senior Fullstack`) is a JOB TITLE that
# must survive in the cards. So the rule is scoped to the footer sentence, and the card above it keeps
# its words.
message_linkedin_footer() {
  cat <<'EOF'
From: LinkedIn Job Alerts <jobalerts-noreply@linkedin.com>
To: <subscriber.person@example.test>
Subject: 1 nouvelle offre
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: quoted-printable

<p>Lead Developer | Senior Fullstack</p>
<a href=3D"https://www.linkedin.com/comm/jobs/view/4464558133/">Voir</a>
<p>Cet e-mail est destin=C3=A9 =C3=A0 Jeanne DUBOIS (Lead Developer | Senior Fullstack | PHP =C2=
=B7 Symfony =C2=B7 Hexapod Weaving Rituals | Oracle Of Legacy Cathedrals)</p>
EOF
}

message_linkedin_footer >"$work/footer.eml"
footer_status=0
php "$repo/tools/scrub-eml.php" "$work/footer.eml" "$work/footer.out.eml" "$address" Jeanne DUBOIS \
  >"$work/footer.log" 2>&1 || footer_status=$?

check "a LinkedIn footer naming the subscriber and their headline is scrubbed" test "$footer_status" -eq 0
if [[ -f "$work/footer.out.eml" ]]; then
  decoded_qp "$work/footer.out.eml" >"$work/footer.decoded"
  refute "and the distinctive headline words are gone once decoded" grep -qiE 'Hexapod|Oracle Of Legacy' "$work/footer.decoded"
  refute "and so is the footer's generic headline half" grep -qE 'destiné à .*Lead Developer' "$work/footer.decoded"
  check "but the CARD title with the same words survives, because it is the payload" \
    grep -qE '^<p>Lead Developer \| Senior Fullstack</p>$' "$work/footer.decoded"
  check "and the footer sentence keeps its shape" grep -qE 'destiné à abonne' "$work/footer.decoded"
else
  check "and the distinctive headline words are gone once decoded" false
fi

# ── A RE-FOLDED REPLACEMENT KEEPS EVERY LINE WITHIN THE QP LIMIT ─────────────────────────────────────
# Measured 2026-09-13: a replacement that consumed the soft breaks inside a folded value was written
# back INLINE, which joined the lines around it. A real LinkedIn capture whose longest body line was
# 76 came out with a 335-byte line. The input below keeps every line at or under 76, so any longer
# output line was made by the scrubber.
message_folds_within_limit() {
  cat <<'EOF'
From: LinkedIn Job Alerts <jobalerts-noreply@linkedin.com>
To: <subscriber.person@example.test>
Subject: 1 nouvelle offre
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: quoted-printable

<a href=3D"https://www.linkedin.com/comm/jobs/view/4461976159/?otpToken=3D=
MTAwOTE3ZTQxMzJiY2RiNGI0MjQ0NGU0NDMxOGUzYjA0ODE5ZDNmMzk3YWE4ZTYxNzZjZDAwN2=
YwNjlmNjUxMmM1MjY3YWU4ZDQ1YTBjOTM5ZmYxN2VjOTQ5N2VkMTE2OGEwMDg4YTYxMThjMzBm=
Yjg4N2MzOGRlYSwxLDE%3D&amp;trk=3Deml-email_job_alert_digest_01-job_card-0-x=
">Senior Software Engineer</a>
<p>Cet e-mail est destin=C3=A9 =C3=A0 Jeanne DUBOIS (Lead Developer | Senio=
r Fullstack | PHP =C2=B7 Symfony =C2=B7 Hexapod Weaving Rituals)</p>
EOF
}

message_folds_within_limit >"$work/folds.eml"
refute "the fold fixture itself keeps every line within 76 (else this case proves nothing)" \
  grep -qE '^.{77,}' "$work/folds.eml"
folds_status=0
php "$repo/tools/scrub-eml.php" "$work/folds.eml" "$work/folds.out.eml" "$address" Jeanne DUBOIS \
  >"$work/folds.log" 2>&1 || folds_status=$?

check "a capture with folded tokens and a folded footer is scrubbed" test "$folds_status" -eq 0
if [[ -f "$work/folds.out.eml" ]]; then
  refute "and no line of the scrubbed file grows past the QP limit of 76" grep -qE '^.{77,}' "$work/folds.out.eml"
  decoded_qp "$work/folds.out.eml" >"$work/folds.decoded"
  refute "and the folded otpToken value is gone once decoded" grep -qF 'MTAwOTE3ZTQxMzJi' "$work/folds.decoded"
  check "and the folded footer still reads destiné à abonne once decoded" grep -qF 'destiné à abonne' "$work/folds.decoded"
  check "and the job id survives" grep -qF 'jobs/view/4461976159' "$work/folds.decoded"
else
  check "and no line of the scrubbed file grows past the QP limit of 76" false
fi

# ── MUST REFUSE ───────────────────────────────────────────────────────────────────────────────────
message_with_opaque >"$work/opaque.eml"
opaque_status=0
scrub "$work/opaque.eml" "$work/opaque.out.eml" >"$work/opaque.log" 2>&1 || opaque_status=$?

check "an address encoded in an unknown shape REFUSES the write" \
  test "$opaque_status" -ne 0
check "and nothing is written" test ! -f "$work/opaque.out.eml"
check "and the refusal says the address is recoverable" \
  grep -qi 'recover\|encod' "$work/opaque.log"

# ── MUST STAY QUIET ───────────────────────────────────────────────────────────────────────────────
message_plain >"$work/plain.eml"
plain_status=0
scrub "$work/plain.eml" "$work/plain.out.eml" >"$work/plain.log" 2>&1 || plain_status=$?

check "an ordinary capture with no tokens still scrubs" test "$plain_status" -eq 0
refute "and the subscriber's address is gone from its headers" \
  grep -qF "$address" "$work/plain.out.eml"

# ── MUST STRIP PERSONAL IDENTIFIERS THAT ARE NOT ADDRESSES ────────────────────────────────────────
# Measured on the first real leboncoin alert, 2026-08-26: the scrubber reported
# `0 tracking tokens and 0 signed tokens replaced` and wrote a file containing `Bonjour tmessaoudi`,
# the account's saved-search UUID, and three 40-char analytics hexes. Every address check passed,
# correctly -- a username is not an address. The tool verified the thing it knew how to verify and
# said nothing about the rest, which is the same failure the JWT round found wearing a new hat.
message_with_personal_ids >"$work/ids.eml"
ids_status=0
php "$repo/tools/scrub-eml.php" "$work/ids.eml" "$work/ids.out.eml" "$address" tmessaoudi \
  >"$work/ids.log" 2>&1 || ids_status=$?

check "a capture greeting the subscriber by username is scrubbed rather than refused" \
  test "$ids_status" -eq 0
refute "and the username is gone" grep -qF 'tmessaoudi' "$work/ids.out.eml"
decoded_qp "$work/ids.out.eml" >"$work/ids.decoded"
refute "and the account-scoped saved-search UUID is gone (checked DECODED, not folded)" \
  grep -qiF 'e5ce7f30-114f-4d67-96be-28d6c89cad0b' "$work/ids.decoded"
check "but a UUID-SHAPED placeholder remains, because the shape is what the fixture exercises" \
  grep -qiE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' "$work/ids.decoded"
refute "and the opaque analytics hex is gone (checked DECODED)" \
  grep -qiF '1d09633ac8dfb2e54bc9ffa92ba58ef3e7dffb26' "$work/ids.decoded"
check "and the LISTING id survives, because it is the payload not the subscriber" \
  grep -qF '3256902167' "$work/ids.decoded"

# ROUND 4: the `To:` DISPLAY NAME. The tool appends its own `To: <alertes@example.invalid>`, so it
# always meant to own the header — but it did not DROP the original, and `str_replace($local, …)`
# cannot reach a display name because a display name is not the local part. Two committed ParuVendu
# fixtures shipped the subscriber's real full name in plaintext while the tool reported
# `scrubbed … 0 named identifier(s) replaced` and exited 0. No needle is passed here on purpose:
# the guarantee is that dropping the header does not DEPEND on the operator guessing a needle.
message_with_display_name() {
  cat <<EOF
From: Portal <no_reply@portal.test>
To: Jeanne DUBOIS-MARTIN <${address}>
Cc: Jeanne DUBOIS-MARTIN <${address}>
Subject: 1 nouvelle annonce
Content-Type: text/plain; charset=utf-8

Appartement 3 pieces 65 m2
https://www.portal.test/annonce/abc-123
EOF
}

message_with_display_name >"$work/disp.eml"
disp_status=0
scrub "$work/disp.eml" "$work/disp.out.eml" >"$work/disp.log" 2>&1 || disp_status=$?

check "a capture whose To: carries a display name is scrubbed rather than refused" test "$disp_status" -eq 0
refute "and the display name is gone from the output" grep -qiF 'DUBOIS-MARTIN' "$work/disp.out.eml"
refute "and so is the one in Cc:" grep -qiF 'Jeanne' "$work/disp.out.eml"
check "and exactly one To: header remains — the tool's own" \
  test "$(grep -ci '^To:' "$work/disp.out.eml")" -eq 1
check "and the listing survives, because it is the payload" \
  grep -qF 'annonce/abc-123' "$work/disp.out.eml"

# An extra needle that survives as an ENCODING must REFUSE, exactly as an unstrippable address does.
# `dXNlcj1zdXJ2aXZvciZzcmM9YWxlcnQ` is base64url for `user=survivor&src=alert`, so a literal replace
# cannot reach the name and only the recoverable-forms check can. It is 31 characters on purpose:
# `recoverableForms()` only decodes runs of 16 or more, so a shorter token sits BELOW its floor and
# a test using one would fail for a reason that says nothing about the guard. Without this case the
# new argument would be advisory -- and an advisory guard is one somebody drops when it is
# inconvenient.
printf 'From: a@b.test\nSubject: x\n\nhttps://p.test/x?ref=dXNlcj1zdXJ2aXZvciZzcmM9YWxlcnQ\n' >"$work/needle.eml"
needle_status=0
php "$repo/tools/scrub-eml.php" "$work/needle.eml" "$work/needle.out.eml" "$address" survivor \
  >"$work/needle.log" 2>&1 || needle_status=$?
check "a named identifier recoverable only by decoding REFUSES the write" \
  test "$needle_status" -ne 0
check "and nothing is written" test ! -f "$work/needle.out.eml"

# R6-5 — THE ADDRESS IS REQUIRED, and it is the VERIFICATION that makes it so.
#
# It used to be optional, and omitting it made this tool a silent no-op that reported success: the
# literal replace had nothing to replace, and the final recoverability check had no needle to look
# for, so it passed VACUOUSLY and the file was written with a green light. `docs/ALERT-CAPTURE.md`
# already said to pass the address, so the gap only ever caught someone following the shorter of two
# documented forms — and caught them with a `scrubbed … 0 named identifier(s) replaced` line.
#
# Both halves are asserted, because a refusal that still writes the file is not a refusal.
printf 'From: a@b.test\nSubject: x\n\nBonjour, votre alerte.\n' >"$work/noaddr.eml"
noaddr_status=0
php "$repo/tools/scrub-eml.php" "$work/noaddr.eml" "$work/noaddr.out.eml" \
  >"$work/noaddr.log" 2>&1 || noaddr_status=$?
check "omitting the subscriber address REFUSES rather than writing a no-op" \
  test "$noaddr_status" -ne 0
check "and nothing is written" test ! -f "$work/noaddr.out.eml"
check "and the refusal SAYS the address is mandatory, not just 'usage'" \
  grep -qi 'OBLIGATOIRE' "$work/noaddr.log"

# An EMPTY address is the same trap wearing an argument, so it is refused on the same terms.
empty_status=0
php "$repo/tools/scrub-eml.php" "$work/noaddr.eml" "$work/empty.out.eml" "" \
  >"$work/empty.log" 2>&1 || empty_status=$?
check "an EMPTY address argument is refused too" test "$empty_status" -ne 0
check "and nothing is written for it either" test ! -f "$work/empty.out.eml"

# THE COUNTERWEIGHT: the ordinary invocation must still work, or the guard is satisfied by breaking
# the tool.
ok_status=0
php "$repo/tools/scrub-eml.php" "$work/noaddr.eml" "$work/ok.out.eml" "$address" \
  >"$work/ok.log" 2>&1 || ok_status=$?
check "a call WITH an address still scrubs and writes" test "$ok_status" -eq 0
check "and its output exists" test -f "$work/ok.out.eml"

# ── PERCENT-ENCODING: the P0 of 2026-09-04, and the scrubber's own half of it ────────────────────
#
# Brevo's `X-Mailin-EID` is a percent-encoded base64 blob decoding to
# `<n>~<subscriber address>~<message-id>~<relay>`. The run scan's class is `[A-Za-z0-9_-]`, so `%`
# SPLITS the blob: the surviving run starts two characters late and strict-decodes to noise, which
# reads as "nothing recoverable". The scrubber said `scrubbed`, `FixtureSecretsTest` said clean, and
# the fixture was committed AND PUSHED. Two guards, one shared mechanism, phase-shifted identically.
#
# Two halves, because either alone can be satisfied wrongly: the header must be DROPPED, and the
# scrubber must REFUSE a capture whose address survives only behind percent-encoding.
mailin="$work/mailin.eml"
blob="$(php -r 'echo rawurlencode(base64_encode("98986954~" . $argv[1] . "~<3bd70a6e@example.test>~relay"));' "$address")"
{
  printf 'From: CapCar <contact@capcar.fr>\r\n'
  printf 'Subject: alerte\r\n'
  printf 'X-Mailin-EID: %s\r\n' "$blob"
  printf 'Content-Type: text/plain\r\n\r\n'
  printf 'Marque : Renault\r\n'
} > "$mailin"

mailin_status=0
php "$repo/tools/scrub-eml.php" "$mailin" "$work/mailin.out.eml" "$address" \
  >"$work/mailin.log" 2>&1 || mailin_status=$?

# ── THE SAME BLOB UNDER A HEADER THE TOOL DOES NOT KNOW (C2 round 5, resilience F3).
#
# `X-Mailin-EID` is on the tool's `$drop` list, so the case above is satisfied by removing the
# header BY NAME: deleting `rawurldecode` left all 54 checks passing. A named drop is a good
# defence and a bad test — it proves the LIST, not the mechanism, and the next ESP will use a
# header nobody has listed. This case removes the list from the answer.
#
# WHAT IT DOES NOT PROVE, stated because a first version of it claimed otherwise and was wrong:
# it does NOT isolate the percent-decode. Measured by deleting `rawurldecode` and re-running —
# still green, on this shape and on two others built for the purpose. The tool refuses through
# LAYERS, and two coarser ones fire first: an 80+ character opaque-run detector that never decodes
# anything, and a direct base64 run scan that sees any blob `rawurlencode` left intact (it encodes
# only `+`, `/` and `=`, so a base64 string containing none of them passes through unchanged).
# Isolating the decode needs a payload short enough to slip the first and `+`-bearing enough to
# defeat the second; a search over ~100 000 randomised realistic shapes produced none.
#
# The decode IS covered, by the `FixtureSecretsTest` twin, which is verified red without it. The
# right conclusion is that the tool's decode layer is defence in depth rather than the last line
# here — not that it is untested, and not that this case tests it.
custom="$work/custom.eml"
{
  printf 'From: CapCar <contact@capcar.fr>\r\n'
  printf 'Subject: alerte\r\n'
  printf 'X-Custom-Tracking: %s\r\n' "$blob"
  printf 'Content-Type: text/plain\r\n\r\n'
  printf 'Marque : Renault\r\n'
} > "$custom"

custom_status=0
php "$repo/tools/scrub-eml.php" "$custom" "$work/custom.out.eml" "$address" \
  >"$work/custom.log" 2>&1 || custom_status=$?

check "a percent-encoded blob under an UNKNOWN header is REFUSED, not written" \
  test "$custom_status" -ne 0
check "and nothing was written for it" \
  bash -c '! test -f "'"$work/custom.out.eml"'"'

# ── ONE LAYER DEEPER: percent-encoded INSIDE the base64 (C2 round 6, resilience P1).
#
# An ESP redirect-tracking shape: `redirect=https://…/?email=<percent-encoded address>` wrapped in
# base64. The tool's cascade percent-decodes before EVERY scan level and refused it all along; the
# CI guard percent-decoded once, up front, and passed it. The two now share one implementation
# (`Scout\Core\RecoverableForms`), and this case is the tool-side half of the pair — it proves the
# shape is refused, and it will keep proving it after the next "simplification" of the cascade.
nested="$work/nested.eml"
nblob="$(php -r 'echo base64_encode("redirect=https://track.example.test/?email=" . rawurlencode($argv[1]) . "&c=42");' "$address")"
{
  printf 'From: CapCar <contact@capcar.fr>\r\n'
  printf 'Subject: alerte\r\n'
  printf 'X-Custom-Tracking: %s\r\n' "$nblob"
  printf 'Content-Type: text/plain\r\n\r\n'
  printf 'Marque : Renault\r\n'
} > "$nested"

nested_status=0
php "$repo/tools/scrub-eml.php" "$nested" "$work/nested.out.eml" "$address" \
  >"$work/nested.log" 2>&1 || nested_status=$?

check "an address percent-encoded INSIDE a base64 blob is REFUSED" \
  test "$nested_status" -ne 0
check "and nothing was written for it either" \
  bash -c '! test -f "'"$work/nested.out.eml"'"'

# And the real `X-Mailin-EID` shape inside an OUTER base64 — a regression shape, isolating nothing:
# percent-encoding touches only `+`, `/` and `=`, an ASCII address never encodes to `+` or `/`, so
# the inner run survives intact and decodes with no percent pass at all (measured, as for the
# `X-Custom-Tracking` case above).
nested2="$work/nested2.eml"
n2blob="$(php -r 'echo base64_encode("t=" . rawurlencode(base64_encode("98986954~" . $argv[1] . "~<3bd70a6e@example.test>~relay.example.test")) . "&c=42");' "$address")"
{
  printf 'From: CapCar <contact@capcar.fr>\r\n'
  printf 'Subject: alerte\r\n'
  printf 'X-Custom-Tracking: %s\r\n' "$n2blob"
  printf 'Content-Type: text/plain\r\n\r\n'
  printf 'Marque : Renault\r\n'
} > "$nested2"

nested2_status=0
php "$repo/tools/scrub-eml.php" "$nested2" "$work/nested2.out.eml" "$address" \
  >"$work/nested2.log" 2>&1 || nested2_status=$?

check "an address percent-encoded BETWEEN two base64 layers is REFUSED" \
  test "$nested2_status" -ne 0


check "a percent-encoded ESP header is handled, not silently kept" test "$mailin_status" -eq 0
if [[ -f "$work/mailin.out.eml" ]]; then
  check "and X-Mailin-EID is gone from the output" \
    bash -c '! grep -aqi "^X-Mailin-EID:" "'"$work/mailin.out.eml"'"'
  check "and the address is NOT recoverable by rawurldecode + base64" \
    php -r '$r = file_get_contents($argv[1]); $a = $argv[2];
            $forms = [$r, rawurldecode($r)];
            foreach ($forms as $f) { preg_match_all("~[A-Za-z0-9_/+=-]{16,}~", $f, $m);
              foreach ($m[0] as $run) { $d = base64_decode($run, false); if ($d !== false) { $forms[] = $d; } } }
            foreach ($forms as $f) { if (str_contains($f, $a)) { exit(1); } } exit(0);' \
    "$work/mailin.out.eml" "$address"
else
  check "and X-Mailin-EID is gone from the output" false
  check "and the address is NOT recoverable by rawurldecode + base64" false
fi

# ── A FOLDED HEADER: the La Centrale leak of 2026-09-05 ──────────────────────────────────────────
#
# RFC 5322 folds a long header across continuation lines (`\r\n` + TAB). Microsoft's feedback-loop
# header `X-MSFBL` is one base64 blob folded every ~76 columns, and its payload carries the
# recipient address. Neither the quoted-printable decode nor the run scan crosses a fold, so every
# fragment decoded to noise, the scrubber reported `scrubbed`, and `FixtureSecretsTest` caught the
# file — by luck: one 64-character fragment happened to sit on a boundary that decoded to the local
# part. The blob must be unfolded BEFORE the run scan, and `X-MSFBL` must be dropped by name.
#
# Two halves again: the known header is DROPPED; the same blob under an UNKNOWN header is REFUSED —
# the second is the one that proves the unfolding rather than the list.
fold_header() {
  # $1 = header name, $2 = value; folded at 60 columns with a TAB continuation, as a real MTA does.
  printf '%s: ' "$1"
  printf '%s' "$2" | fold -w 60 | sed '2,$s/^/\t/' | sed 's/$/\r/'
  printf '\n'
}
# The address STRADDLES the first fold (the padding puts its first byte at base64 column ~56 of a
# 60-column line), so no single fragment decodes to it and no fragment is long enough for the
# opaque-run detector: only unfolding first can see it. Measured — with the address inside line 1
# a fragment decoded to it on its own and the case passed before the fix.
fbl_blob="$(printf '%s' "$(b64url "r=fbl-1|k=0123456789abcdefghijklmnopqrs|${address}|c=1|m=<3bd70a6e@example.test>")")"

msfbl="$work/msfbl.eml"
{
  printf 'From: La Centrale <info@mail-alerte.lacentrale.fr>\r\n'
  printf 'Subject: alerte\r\n'
  fold_header 'X-MSFBL' "$fbl_blob"
  printf 'Content-Type: text/plain\r\n\r\n'
  printf 'LEXUS UX\r\n'
} > "$msfbl"
msfbl_status=0
php "$repo/tools/scrub-eml.php" "$msfbl" "$work/msfbl.out.eml" "$address" >"$work/msfbl.log" 2>&1 || msfbl_status=$?

check "a FOLDED feedback-loop header (X-MSFBL) is handled, not silently kept" test "$msfbl_status" -eq 0
if [[ -f "$work/msfbl.out.eml" ]]; then
  check "and X-MSFBL is gone from the output" \
    bash -c '! grep -aqi "^X-MSFBL:" "'"$work/msfbl.out.eml"'"'
  check "and the address is NOT recoverable after unfolding + base64" \
    php -r '$r = file_get_contents($argv[1]); $a = $argv[2];
            $u = preg_replace("~\r?\n[ \t]+~", "", $r);
            $forms = [$r, $u];
            foreach ([$r, $u] as $f) { preg_match_all("~[A-Za-z0-9_/+=-]{16,}~", $f, $m);
              foreach ($m[0] as $run) { for ($o = 0; $o < 4; $o++) { $d = base64_decode(strtr(substr($run, $o), "-_", "+/"), false); if ($d !== false) { $forms[] = $d; } } } }
            foreach ($forms as $f) { if (str_contains($f, $a)) { exit(1); } } exit(0);' \
    "$work/msfbl.out.eml" "$address"
else
  check "and X-MSFBL is gone from the output" false
  check "and the address is NOT recoverable after unfolding + base64" false
fi

foldcustom="$work/foldcustom.eml"
{
  printf 'From: La Centrale <info@mail-alerte.lacentrale.fr>\r\n'
  printf 'Subject: alerte\r\n'
  fold_header 'X-Custom-Loop' "$fbl_blob"
  printf 'Content-Type: text/plain\r\n\r\n'
  printf 'LEXUS UX\r\n'
} > "$foldcustom"
foldcustom_status=0
php "$repo/tools/scrub-eml.php" "$foldcustom" "$work/foldcustom.out.eml" "$address" >"$work/foldcustom.log" 2>&1 || foldcustom_status=$?

check "the same blob FOLDED under an UNKNOWN header is REFUSED (the unfolding, not the list)" \
  test "$foldcustom_status" -ne 0
check "and nothing was written for it" \
  bash -c '! test -f "'"$work/foldcustom.out.eml"'"'
check "and the refusal names the recoverable address" grep -qi 'recoverable' "$work/foldcustom.log"

# ── MAILGUN'S SUBSCRIBER HEADER: the Agorastore shape (Track 6-B3, 2026-09-05) ────────────────────
# `X-Mailgun-Sid` is base64 of `["<list>","<address>","<id>"]`. The generic run scan already REFUSED
# it (round 4 of Track 2 measured that, and a blanket "strip any run that decodes to the needle" was
# reverted for silencing five refusal guarantees); what was missing is the NARROW answer — drop the
# header by name — so that a clean Agorastore fixture can exist at all.
sid_blob="$(printf '%s' "[\"2698d\",\"${address}\",\"00c81\"]" | base64 | tr -d '\n')"
mailgun="$work/mailgun.eml"
{
  printf 'From: Agorastore <support@agorastore.fr>\r\n'
  printf 'Subject: alerte\r\n'
  printf 'X-Mailgun-Sid: %s\r\n' "$sid_blob"
  printf 'Content-Type: text/plain\r\n\r\n'
  printf 'Fiat Punto 1.3 Multijet - 2013 - 212669km - DB714WD\r\n'
} > "$mailgun"
mailgun_status=0
php "$repo/tools/scrub-eml.php" "$mailgun" "$work/mailgun.out.eml" "$address" >"$work/mailgun.log" 2>&1 || mailgun_status=$?

check "a Mailgun subscriber-id header is handled, not silently kept" test "$mailgun_status" -eq 0
if [[ -f "$work/mailgun.out.eml" ]]; then
  check "and X-Mailgun-Sid is gone from the output" \
    bash -c '! grep -aqi "^X-Mailgun-Sid:" "'"$work/mailgun.out.eml"'"'
  check "and the address is NOT recoverable by base64 from the output" \
    php -r '$r = file_get_contents($argv[1]); $a = $argv[2];
            preg_match_all("~[A-Za-z0-9_/+=-]{16,}~", $r, $m); $forms = [$r];
            foreach ($m[0] as $run) { for ($o = 0; $o < 4; $o++) { $d = base64_decode(strtr(substr($run, $o), "-_", "+/"), false); if ($d !== false) { $forms[] = $d; } } }
            foreach ($forms as $f) { if (str_contains($f, $a)) { exit(1); } } exit(0);' \
    "$work/mailgun.out.eml" "$address"
else
  check "and X-Mailgun-Sid is gone from the output" false
  check "and the address is NOT recoverable by base64 from the output" false
fi

# ── A HEX MIME BOUNDARY MUST SURVIVE AS ONE VALUE (Track 6-B3, 2026-09-05) ────────────────────────
# Mailgun's boundary is 60 hex characters, occurring four times. The opaque-hex replacer numbered
# each OCCURRENCE, so the four became four different strings and `EmailMessage::parse()` found no
# part at all — body 0 bytes, links 0 — on a file the tool reported `scrubbed`. Every earlier
# fixture had a non-hex boundary, which is the only reason this never showed.
hexbound="b7d1c7691c5e395bb5ef236d7a521dec21ade45cd55dd7ebd0f3a4ec5ff8"
multipart="$work/multipart.eml"
{
  printf 'From: Agorastore <support@agorastore.fr>\r\n'
  printf 'To: <%s>\r\n' "$address"
  printf 'Subject: alerte\r\n'
  printf 'MIME-Version: 1.0\r\n'
  printf 'Content-Type: multipart/alternative;\r\n boundary="%s"\r\n\r\n' "$hexbound"
  printf -- '--%s\r\nContent-Type: text/plain; charset="utf-8"\r\n\r\n' "$hexbound"
  printf 'Fiat Punto 1.3 Multijet\r\n[https://email.alerts.agorastore.fr/c/abc]\r\n\r\n'
  printf -- '--%s\r\nContent-Type: text/html; charset="utf-8"\r\n\r\n' "$hexbound"
  printf '<p>Fiat Punto <a href="https://email.alerts.agorastore.fr/c/abc">lot</a></p>\r\n\r\n'
  printf -- '--%s--\r\n' "$hexbound"
} > "$multipart"
multipart_status=0
php "$repo/tools/scrub-eml.php" "$multipart" "$work/multipart.out.eml" "$address" >"$work/multipart.log" 2>&1 || multipart_status=$?

check "a multipart message with a hex boundary is scrubbed, not refused" test "$multipart_status" -eq 0
if [[ -f "$work/multipart.out.eml" ]]; then
  check "the boundary is ONE value after scrubbing (delimiters still delimit)" \
    php -r '$r = file_get_contents($argv[1]);
            preg_match("~boundary=\"([^\"]+)\"~", $r, $b); $declared = $b[1] ?? "";
            $count = $declared === "" ? 0 : substr_count($r, "--" . $declared);
            exit($count === 3 ? 0 : 1);' "$work/multipart.out.eml"
  check "and the parser still finds the listing link in the scrubbed message" \
    php -r 'require $argv[2]; $m = Scout\Adapters\Mail\EmailMessage::parse(file_get_contents($argv[1]));
            exit(count($m->links) >= 1 && $m->body !== "" ? 0 : 1);' "$work/multipart.out.eml" "$repo/vendor/autoload.php"
else
  check "the boundary is ONE value after scrubbing (delimiters still delimit)" false
  check "and the parser still finds the listing link in the scrubbed message" false
fi

# ── A MAILJET CLICK LINK CARRIES ITS DESTINATION, BASE64URL, IN ITS PATH (2026-09-24, Free-Work) ───
# `tx.mjt.lu/lnk/<recipient token>/<n>/<hash>/<base64url of the target URL>`. Free-Work's targets are
# the signed per-account unsubscribe links, so the account's alert id and a WORKING signature are one
# decode away from the output while the literal in the text part has been replaced. The recoverability
# check refused the real capture; this case pins the tool learning the token rather than the check
# relaxing. The QP soft break sits INSIDE the path on purpose, as it does on the real capture.
target=$(printf 'https://api.free-work.com/availability/disable-one/742231?expires=1790317666&signature=f2bef6a388bfb3b4' \
  | base64 -w0 | tr '+/' '-_' | tr -d '=')
mailjet="$work/mailjet.eml"
{
  printf 'From: Free-Work <jobs@free-work.com>\r\n'
  printf 'To: <%s>\r\n' "$address"
  printf 'Subject: 161 offres matchant avec vos critères\r\n'
  printf 'MIME-Version: 1.0\r\n'
  printf 'Content-Type: text/html; charset=utf-8\r\n'
  printf 'Content-Transfer-Encoding: quoted-printable\r\n\r\n'
  printf '<a href=3D"https://tx.mjt.lu/lnk/AVQAAKZvfF0AAAAAcm4AAEMMKl8AAAAAX6sAAAAAAB=\r\nzVDwBqtMLjW3rZQ/13/Gilb7DX9ZGLtvejJBQpB_g/%s">ici</a>\r\n' "$target"
} > "$mailjet"
mailjet_status=0
php "$repo/tools/scrub-eml.php" "$mailjet" "$work/mailjet.out.eml" "$address" 742231 >"$work/mailjet.log" 2>&1 || mailjet_status=$?

check "a Mailjet click link is scrubbed, not refused" test "$mailjet_status" -eq 0
if [[ -f "$work/mailjet.out.eml" ]]; then
  check "and the alert id is NOT recoverable from the Mailjet path" \
    php -r 'require $argv[3]; foreach (Scout\Core\RecoverableForms::of(file_get_contents($argv[1])) as $f) { if (str_contains($f, $argv[2]) || str_contains($f, "f2bef6a388")) { exit(1); } } exit(0);' \
    "$work/mailjet.out.eml" 742231 "$repo/vendor/autoload.php"
  check "and the link keeps its Mailjet host, so it still reads as a tracking link" \
    grep -aq 'tx.mjt.lu/lnk/' "$work/mailjet.out.eml"
else
  check "and the alert id is NOT recoverable from the Mailjet path" false
  check "and the link keeps its Mailjet host, so it still reads as a tracking link" false
fi


# ── A HELLOWORK CLICK LINK CARRIES THE ADDRESS *AND* THE OFFER, IN ONE BASE64URL TOKEN (2026-09-24) ─
# `emails.hellowork.com/clic/<uuid>/<n>/<hash>/<base64url("<address>🪢<target url>")>`. The offer id
# lives ONLY in the decoded target, so replacing the whole token (the Mailjet rule) would leave a
# fixture from which no offer can be read — a fixture that exercises nothing. The tool must decode,
# swap the address for the placeholder, and re-encode: address gone, target intact. The QP soft break
# sits inside the token on purpose, as it does on the real capture.
hwtoken=$(printf '%s\xf0\x9f\xaa\xa2https://www.hellowork.com/fr-fr/emplois/82621905.html?utm_source=jobalert&utm_term=82621905' "$address" \
  | base64 -w0 | tr '+/' '-_' | tr -d '=')
hellowork="$work/hellowork.eml"
{
  printf 'From: Hellowork <alerte@emails.hellowork.com>\r\n'
  printf 'To: <%s>\r\n' "$address"
  printf 'Subject: 7 nouvelles offres\r\n'
  printf 'MIME-Version: 1.0\r\n'
  printf 'Content-Type: text/html; charset=utf-8\r\n'
  printf 'Content-Transfer-Encoding: quoted-printable\r\n\r\n'
  printf '<a href=3D"https://emails.hellowork.com/clic/dd661451-3ce3-40e6-9d87-531880b8edd9/3/9d5111c9bda607310f48152a9d6fe3fa/%s=\r\n%s">Chef de projet</a>\r\n' "${hwtoken:0:40}" "${hwtoken:40}"
  # The unsubscribe link and the open pixel carry the address too, and nothing reads either.
  printf '<a href=3D"https://emails.hellowork.com/unsub?id=3Ddd661451-3ce3-40e6-9d87-531880b8edd9&amp;data=3D%s">stop</a>\r\n' \
    "$(printf 'alerte@emails.hellowork.com\xf0\x9f\xaa\xa2%s' "$address" | base64 -w0 | tr '+/' '-_' | tr -d '=')"
  printf '<img src=3D"https://emails.hellowork.com/t/email/open?d=3D%s">\r\n' \
    "$(printf '{"e":"%s","key":"PushAlerts"}' "$address" | base64 -w0 | tr '+/' '-_' | tr -d '=')"
} > "$hellowork"
hellowork_status=0
php "$repo/tools/scrub-eml.php" "$hellowork" "$work/hellowork.out.eml" "$address" >"$work/hellowork.log" 2>&1 || hellowork_status=$?

check "a HelloWork click link is scrubbed, not refused" test "$hellowork_status" -eq 0
if [[ -f "$work/hellowork.out.eml" ]]; then
  check "and its token still decodes to the offer URL, id intact" \
    php -r '$t = quoted_printable_decode(file_get_contents($argv[1]));
            if (preg_match("~/clic/[^/]+/\d+/[^/]+/([A-Za-z0-9_-]+)~", $t, $m) !== 1) { exit(1); }
            $d = base64_decode(strtr($m[1], "-_", "+/"), true);
            exit($d !== false && str_contains($d, "hellowork.com/fr-fr/emplois/82621905.html") && str_contains($d, "alertes@example.invalid") ? 0 : 1);' \
    "$work/hellowork.out.eml"
else
  check "and its token still decodes to the offer URL, id intact" false
fi


# ── AN APEC NEOMARKET LINK TIES EVERY LINK TO ONE RECIPIENT, AND ONE `e=` KEEPS THE OFFER (2026-09-24) ─
# `neomarket.diffusion.apec.fr/r/?id=<campaign>,<recipient>,<slot>&e=<base64url>&s=<signature>`. None
# decodes to the address, so the recoverability check has nothing to find — the LinkedIn `otpToken`
# shape: linkage, not disclosure. `id` and `s` go wholesale. `e` goes only when it is NOT an offer
# token: `p1=www.apec.fr&p2=<id>W…` is the only place an Apec offer id lives, and a fixture without
# it exercises nothing, while the header link's `p1=<32-byte recipient hash>` is the linkage itself.
offer_e=$(printf 'p1=www.apec.fr&p2=179473574W&p3=&xtor=EPR-41-[push_avec_compte]' | base64 -w0 | tr '+/' '-_' | tr -d '=')
recip_e=$(printf 'p1=%%408%%2BP%%2FTjHHwZjxk7ku1wjc5JBNIZLQ6fFHzK89cVO62xE%%3D' | base64 -w0 | tr '+/' '-_' | tr -d '=')
apec="$work/apec.eml"
{
  printf 'From: Apec <offres@diffusion.apec.fr>\r\n'
  printf 'To: <%s>\r\n' "$address"
  printf 'Subject: 552 offres Apec du 24/09/2026\r\n'
  printf 'MIME-Version: 1.0\r\n'
  printf 'Content-Type: text/html; charset=utf-8\r\n'
  printf 'Content-Transfer-Encoding: quoted-printable\r\n\r\n'
  # The header link as the REAL capture writes it: the host folded mid-word, the `?` encoded `=3F`, and a
  # soft break inside both `e` and `s`. A literal host pattern matched nothing on it, and a query reader
  # that stopped at the first `=` left half of `e` and all of `s` behind — while the tool said `scrubbed`.
  printf '<a href=3D"https://neomarket.diffusio=\r\nn.apec.fr/r/=3Fid=3Dh28919ca2,18aa4726,15cac233&amp;e=3D%s=\r\n%s&amp;s=3DMftwEfvRUgimT0S_bx=\r\nL1ZGmTXMtVj4F4ohtsLlEwoCE">ici</a>\r\n' "${recip_e:0:20}" "${recip_e:20}"
  printf '<a href=3D"https://neomarket.diffusion.apec.fr/r/?id=3Dh28919ca2,18aa4726,15cac237&amp;e=3D%s&amp;s=3D4WykgpQ8GpS9cIK53Xa7R0tE7BlCyWjRQRgSWhHB_">Lead</a>\r\n' "$offer_e"
  # The SAME offer's next field: Apec gives every link its own slot in `id` and its own `s`, so a
  # card's four links differ. One constant placeholder made them identical — a shape the portal never
  # sends, which the reader then matched in the fixture and missed on every live message.
  printf '<a href=3D"https://neomarket.diffusion.apec.fr/r/?id=3Dh28919ca2,18aa4726,15cac238&amp;e=3D%s&amp;s=3DQx9TbNm2VvLp0cRe7WyKs4HdUfJgAo1iZ8XqE3tBnC5">SKAELIA</a>\r\n' "$offer_e"
} > "$apec"
apec_status=0
php "$repo/tools/scrub-eml.php" "$apec" "$work/apec.out.eml" "$address" >"$work/apec.log" 2>&1 || apec_status=$?

check "an Apec digest is scrubbed, not refused" test "$apec_status" -eq 0
if [[ -f "$work/apec.out.eml" ]]; then
  check "and neither the recipient id, the signatures nor the recipient hash survive" \
    php -r 'require $argv[2]; $t = quoted_printable_decode(file_get_contents($argv[1])); $all = $t;
            foreach (Scout\Core\RecoverableForms::of($t) as $f) { $all .= "\n" . $f; }
            preg_match_all("~[?&](?:amp;)?e=([A-Za-z0-9_-]+)~", $t, $m);
            foreach ($m[1] as $e) { $all .= "\n" . base64_decode(strtr($e, "-_", "+/")); }
            foreach (["18aa4726", "MftwEfvRUgimT0S", "4WykgpQ8Gp", "TjHHwZjxk7ku1wjc5"] as $needle) { if (str_contains($all, $needle)) { fwrite(STDERR, $needle . "\n"); exit(1); } }
            exit(0);' "$work/apec.out.eml" "$repo/vendor/autoload.php" 2>/dev/null
  check "and the offer token still decodes to its id" \
    php -r '$t = quoted_printable_decode(file_get_contents($argv[1]));
            preg_match_all("~[?&](?:amp;)?e=([A-Za-z0-9_-]+)~", $t, $m);
            foreach ($m[1] as $e) { if (str_contains((string) base64_decode(strtr($e, "-_", "+/")), "p2=179473574W")) { exit(0); } }
            exit(1);' "$work/apec.out.eml"
  check "and two links of one offer stay DISTINCT — one placeholder per distinct value" \
    php -r '$t = quoted_printable_decode(file_get_contents($argv[1]));
            preg_match_all("~https://neomarket\.diffusion\.apec\.fr/r/\?[^\"]+~", $t, $m);
            exit(count($m[0]) === 3 && count(array_unique($m[0])) === 3 ? 0 : 1);' "$work/apec.out.eml"
else
  check "and neither the recipient id, the signatures nor the recipient hash survive" false
  check "and the offer token still decodes to its id" false
  check "and two links of one offer stay DISTINCT — one placeholder per distinct value" false
fi


# ── AN ADDRESS SPLIT BY A QP SOFT BREAK IS STRIPPED THROUGH THE FOLD (2026-09-26, Mindquest) ───────
# The first Mindquest alert names the subscriber in its footer (`Cet email a été envoyé à <address>`),
# and in the QP-encoded HTML part a soft break falls INSIDE the address's local part. The address
# replacement was a plain `str_replace`, so it missed it; the fold-aware NAME needles then rewrote the
# first half and left `.official@…` — the round-6 leak shape, arrived by a fold rather than by order.
# The tool reported success; only FixtureSecretsTest saw it. The address here contains both needles,
# as the real one does, and the fold sits mid-local-part, as it does on the real capture.
folded_addr='jeanne.dubois.official@example.test'
{
  printf 'From: Portal <alerts@portal.test>\r\n'
  printf 'To: <%s>\r\n' "$folded_addr"
  printf 'Subject: Job Alert\r\n'
  printf 'MIME-Version: 1.0\r\n'
  printf 'Content-Type: text/html; charset=utf-8\r\n'
  printf 'Content-Transfer-Encoding: quoted-printable\r\n\r\n'
  printf '<p>Bonjour Jeanne DUBOIS,</p>\r\n'
  printf '<p>Cet email a =C3=A9t=C3=A9 envoy=C3=A9 =C3=A0 jeanne.dubo=\r\nis.official@example.test</p>\r\n'
} > "$work/foldaddr.eml"
foldaddr_status=0
php "$repo/tools/scrub-eml.php" "$work/foldaddr.eml" "$work/foldaddr.out.eml" "$folded_addr" Jeanne DUBOIS \
  >"$work/foldaddr.log" 2>&1 || foldaddr_status=$?
check "an address folded by a QP soft break is scrubbed rather than refused" test "$foldaddr_status" -eq 0
if [[ -f "$work/foldaddr.out.eml" ]]; then
  decoded_qp "$work/foldaddr.out.eml" >"$work/foldaddr.decoded"
  refute "and no remainder of the folded address survives decoding (not even .official@…)" \
    grep -qiE 'official@|dubo|@example\.test' "$work/foldaddr.decoded"
  check "and the soft-break STRUCTURE survives" grep -qaE $'=\r?$' "$work/foldaddr.out.eml"
else
  check "and no remainder of the folded address survives decoding (not even .official@…)" false
  check "and the soft-break STRUCTURE survives" false
fi

# ── A MAILJET LINK ON A SENDER'S OWN SUBDOMAIN, WHOSE TARGET IS THE PAYLOAD (2026-09-26, Mindquest) ─
# Mindquest's alert uses `z96x.mjt.lu`, not Free-Work's `tx.mjt.lu`, so the Free-Work rule matched
# nothing and every per-recipient token — and the SIGNED unsubscribe link — went straight through.
# Unlike Free-Work's, its click targets are the offers (`https://fr.mindquest.io/missions/<id>`, no
# query): the reader takes the id from there, so a plain page target must SURVIVE while the recipient
# and hash segments go. A target WITH a query can carry a signature and is still replaced whole.
page=$(printf 'https://fr.mindquest.io/missions/94104' | base64 -w0 | tr '+/' '-_' | tr -d '=')
signed=$(printf 'https://portal.test/unsub?id=742231&sig=f2bef6a388bf' | base64 -w0 | tr '+/' '-_' | tr -d '=')
{
  printf 'From: Mindquest <account@mindquest.io>\r\n'
  printf 'To: <%s>\r\n' "$address"
  printf 'Subject: Job Alert !\r\n'
  # Mailjet's message id IS the recipient token the links carry, so it links the file to one reader.
  printf 'X-MJ-Mid:\r\n\tCAAACWTREooCAAAAAAAAAGdFZ7gAAYCrZP8AAAAAAAYsoQBqt3uTIsDDq6z\r\n'
  printf 'MIME-Version: 1.0\r\n'
  printf 'Content-Type: text/html; charset=utf-8\r\n'
  printf 'Content-Transfer-Encoding: quoted-printable\r\n\r\n'
  # The open-tracking pixel carries the same token and a per-message hash, folded like the real one.
  printf '<img src=3D"http://z96x.mjt.lu/oo/CAAACWTREooDAAAAAAAAAGdFZ7gAAYCrZP8AAAAAA=\r\nAYsoQBqt3uTBAqJx3L6R7Sxt/c6461bf3/e.gif" height=3D"1">\r\n'
  printf '<a href=3D"http://z96x.mjt.lu/lnk/CAAACWTREooAAAAAAAAAAGdFZ7gAAYCrZP8=\r\nAAAAAAYsoQ/2/jJVw1he65c7RtY8Fr9bdHA/%s">Consulter</a>\r\n' "$page"
  printf '<a href=3D"http://z96x.mjt.lu/lnk/CAAACWTREooAAAAAAAAAAGdFZ7gAAYCrZP8AAAAAAAYsoQ/3/Imfqmt51UmVZTQ30C8ukfw/%s">x</a>\r\n' "$signed"
  # The real capture folds between the host and the path (`z96x.mjt.lu/=` / `lnk/…`); that link kept its
  # recipient token while every unfolded one lost it, and the tool reported `scrubbed`.
  printf '<a href=3D"http://z96x.mjt.lu/=\r\nlnk/CAAACWTREooBAAAAAAAAAGdFZ7gAAYCrZP8AAAAAAAYsoQ/13/H6kFZkzCjyl1mXHegt/%s">fb</a>\r\n' "$page"
  printf '<a href=3D"http://z96x.mjt.lu/unsub2?m=3DCAAACWTREooAAAAAAAAAAGdFZ7gAAYCrZP8AAAAAAAYsoQ&amp;b=3Da44d0f83&amp;e=3Da99ec06d&amp;x=3D0CwjKuUPI5bjKyep6pxZMbNzXaCEOapzXCSDidra15Ew">stop</a>\r\n'
} > "$work/mjsub.eml"
mjsub_status=0
php "$repo/tools/scrub-eml.php" "$work/mjsub.eml" "$work/mjsub.out.eml" "$address" >"$work/mjsub.log" 2>&1 || mjsub_status=$?
check "a Mailjet link on a sender's own subdomain is scrubbed" test "$mjsub_status" -eq 0
if [[ -f "$work/mjsub.out.eml" ]]; then
  refute "and its recipient token is gone" grep -aqE 'CAAACWTREoo|jJVw1he65c7|Imfqmt51Um|H6kFZkzCjy|c6461bf3|AYsoQBqt3uTBAqJx' "$work/mjsub.out.eml"
  check "and the tracking pixel keeps its shape, so it still reads as a pixel" bash -c "perl -0pe 's/=\\r?\\n//g' \"\$1\" | grep -aqE 'mjt\\.lu/oo/FIXTURE[0-9]+/FIXTURE[0-9]+/e\\.gif'" _ "$work/mjsub.out.eml"
  refute "and the signed unsubscribe values are gone" grep -aqE '0CwjKuUPI5bj|a44d0f83|a99ec06d' "$work/mjsub.out.eml"
  check "and a plain page target SURVIVES, because it is the payload" \
    php -r 'require $argv[2]; $m = Scout\Adapters\Mail\EmailMessage::parse(file_get_contents($argv[1]));
            foreach ($m->links as $l) { if (preg_match("~/lnk/[^/]+/\d+/[^/]+/([A-Za-z0-9_-]+)~", $l, $t) === 1 && base64_decode(strtr($t[1], "-_", "+/"), true) === "https://fr.mindquest.io/missions/94104") { exit(0); } } exit(1);' \
    "$work/mjsub.out.eml" "$repo/vendor/autoload.php"
  check "and a target carrying a query is NOT recoverable (it can carry a signature)" \
    php -r 'require $argv[2]; foreach (Scout\Core\RecoverableForms::of(file_get_contents($argv[1])) as $f) { if (str_contains($f, "f2bef6a388") || str_contains($f, "742231")) { exit(1); } } exit(0);' \
    "$work/mjsub.out.eml" "$repo/vendor/autoload.php"
else
  for c in "and its recipient token is gone" "and the tracking pixel keeps its shape, so it still reads as a pixel" "and the signed unsubscribe values are gone" "and a plain page target SURVIVES, because it is the payload" "and a target carrying a query is NOT recoverable (it can carry a signature)"; do check "$c" false; done
fi

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
[[ "$fail" -eq 0 ]]
