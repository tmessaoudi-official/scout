#!/usr/bin/env bash
#
# Did the redeploy actually land? — the mechanical half of F25.
#
# `docker compose up -d` is not a deployment. Both watchers set `stop_grace_period: 5m` and
# `WatchLoop` stops only after the pass in flight finishes, so a recreate can sit for minutes;
# compose renames the old container while it waits, and twice on 2026-08-31 that wedged — once
# failing outright (`Conflict. The container name "/scout-car-scout-1" is already in use`) with
# rent-scout left in `Created`, once sitting ~13 minutes beside a hex-prefixed leftover.
#
# NOTHING ANNOUNCED EITHER TIME, and that is the actual defect. `docker compose ps` without `-a`
# simply OMITS a non-running service, so the failure renders as a shorter list — the silent-absence
# shape hard rule 2 is about, one layer down in the deployment. A watcher that is DOWN is worse than
# one that is stale: a stale watcher still pushes, wrongly; a stopped one pushes nothing, and
# *nothing arriving* is exactly what a quiet market looks like.
#
# So this asserts three things a human reading `up -d`'s output cannot see:
#
#   1. every service compose declares has a container, and it is running;
#   2. that container runs the CURRENT image, not the one it was created from three deploys ago —
#      `src/` is baked in, so a green tree says nothing about what the watcher is executing;
#   3. no hex-prefixed leftover (`d9272b63ebf1_scout-car-scout-1`) is still lying around, because
#      that is what makes the NEXT recreate fail rather than this one — and that such a name is
#      classified before a remedy is printed beside it: the same shape is a DEAD leftover to remove
#      and a RENAMED container that IS the running service, which must not be removed.
#
# Read-only: it inspects, it never starts, stops or removes anything. Exit 0 = the deployment is
# what you think it is.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 2

IMAGE="${SCOUT_IMAGE:-scout:local}"
problems=0

say()  { printf '  %s\n' "$1"; }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$1"; problems=$((problems + 1)); }
good() { printf '  \033[32m✓\033[0m %s\n' "$1"; }

if ! command -v docker > /dev/null 2>&1; then
  printf 'verify-deploy: docker introuvable — rien à vérifier.\n' >&2
  exit 2
fi

# AN UNREACHABLE DAEMON IS NOT A MISSING IMAGE, and reporting it as one told the operator to build
# while the thing that would do the building was down. Self-correcting within a step — the build
# fails too — but this is a deploy-verification tool whose entire value is a diagnosis that can be
# believed, and the F25 defect it exists for is precisely an absence rendered as the wrong cause.
if ! docker info > /dev/null 2>&1; then
  printf 'verify-deploy: le démon Docker est injoignable — rien de vérifiable (ce n%s est PAS une image manquante).\n' "'" >&2
  exit 2
fi

current="$(docker image inspect "$IMAGE" --format '{{.Id}}' 2>/dev/null)"
if [[ -z "$current" ]]; then
  printf "verify-deploy: l'image %s n'existe pas — construisez-la avant de déployer.\n" "$IMAGE" >&2
  exit 2
fi

say "image courante : $IMAGE $current"

# `-a` IS THE POINT OF THIS LINE. Without it a service that is down does not appear at all, which
# is precisely the state we are looking for.
mapfile -t rows < <(docker compose ps -a --format '{{.Service}}\t{{.Name}}\t{{.State}}' 2>/dev/null)

mapfile -t services < <(docker compose config --services 2>/dev/null)

if [[ ${#services[@]} -eq 0 ]]; then
  printf 'verify-deploy: aucun service dans compose.yaml — configuration illisible ?\n' >&2
  exit 2
fi

for service in "${services[@]}"; do
  line=""
  for row in "${rows[@]}"; do
    [[ "${row%%$'\t'*}" == "$service" ]] && line="$row" && break
  done

  if [[ -z "$line" ]]; then
    # The silent one: no container at all. `ps` without -a would simply not have mentioned it.
    bad "$service : AUCUN conteneur — le service est absent, pas seulement arrêté"
    continue
  fi

  name="$(printf '%s' "$line" | cut -f2)"
  state="$(printf '%s' "$line" | cut -f3)"

  if [[ "$state" != "running" ]]; then
    bad "$service ($name) : état « $state » — le watcher ne tourne pas"
    continue
  fi

  running_image="$(docker inspect --format '{{.Image}}' "$name" 2>/dev/null)"
  if [[ "$running_image" != "$current" ]]; then
    # A stale watcher is the failure this repo has paid for three times: green, pushed, and running
    # yesterday's code, with nothing in `git status` or a passing suite to say so.
    bad "$service ($name) : tourne sur une AUTRE image que $IMAGE — redéploiement non pris en compte"
    continue
  fi

  good "$service ($name) : running, image courante"
done

# ── IS THE IMAGE ITSELF NEWER THAN THE CODE? ────────────────────────────────────────────────────
#
# The three checks above answer "are the containers running the image I built". They do NOT answer
# "is that image built from the code I committed", and those are different questions with the same
# comforting output. `src/` is baked into the image and `config/` is bind-mounted, so a `git pull`
# changes the criteria immediately and changes NO CODE until a rebuild — every container `running,
# image courante` throughout.
#
# This is the failure that cost this project a day and a half on 2026-09-04: a §1 fix — a flat the
# store holds as PLS being vetoed when a portal re-advertises it under a new ad id — was committed,
# pushed, CI-green and UNARMED in production, on three link-keyed portals where a re-advertisement
# mints a new id. Nothing in `git status`, `git log` or a passing suite disagreed, and the earlier
# instance of the same shape ran seventeen hours. Green, pushed and deployed are three different
# things, and only this line is about the third one.
if git -C "$(pwd)" rev-parse --is-inside-work-tree > /dev/null 2>&1; then
  # EVERY INPUT THE IMAGE IS BUILT FROM, derived from the RECIPE rather than from a line range.
  #
  # Round 2 widened this from `src` alone to `src bin composer.json`, reading `Dockerfile:66-69`.
  # Round 3 showed that citation was the bug: reading four lines of a recipe is not reading the
  # recipe. `COPY tests/fixtures/rent/fixture_demo/` is baked too, `composer.lock` drives the vendor
  # stage, and a change to the `Dockerfile` or `.dockerignore` changes the image while touching none
  # of the paths it copies. Re-derive this list with `grep -nE '^(COPY|ADD)' Dockerfile` whenever
  # that file changes; a line-number citation is what drifted.
  #
  # `config/` is deliberately EXCLUDED even though it is copied: `compose.yaml` bind-mounts it, so
  # the mount wins at runtime and a config change takes effect without a rebuild. Including it would
  # report a stale image that is not stale — the false-alarm direction, which trains an operator to
  # ignore the check.
  newest_src_epoch="$(git log -1 --format=%ct -- src bin composer.json composer.lock Dockerfile .dockerignore tests/fixtures/rent/fixture_demo 2>/dev/null)"
  image_iso="$(docker image inspect "$IMAGE" --format '{{.Created}}' 2>/dev/null)"
  image_epoch="$(date -d "$image_iso" +%s 2>/dev/null)"

  if [[ -z "$newest_src_epoch" || -z "$image_epoch" ]]; then
    # Not a failure: a shallow clone has no history to compare against. Say so rather than passing
    # quietly, because a check that cannot run and does not say so is the vacuous-green shape.
    say "image vs code : indéterminable (historique git ou date d'image absente)"
  elif (( image_epoch < newest_src_epoch )); then
    bad "l'image est ANTÉRIEURE au dernier commit d'une entrée du Dockerfile — le watcher tourne du code périmé"
    printf '      image  %s\n      commit %s (%s)\n' \
      "$image_iso" "$(git log -1 --format=%cI -- src bin composer.json composer.lock Dockerfile .dockerignore tests/fixtures/rent/fixture_demo)" "$(git log -1 --format=%h -- src bin composer.json composer.lock Dockerfile .dockerignore tests/fixtures/rent/fixture_demo)"
    printf '      docker compose build && docker compose up -d --remove-orphans\n'
  else
    good "l'image est postérieure à toutes ses entrées de build"
  fi
fi

# ── A HEX-PREFIXED NAME IS TWO DIFFERENT STATES, AND THEY WANT OPPOSITE COMMANDS ────────────────
#
# The leftover is not this deploy's failure; it is the NEXT one's. Compose renames the old container
# out of the way and, when the recreate does not complete, leaves it behind holding the name.
#
# BUT AN INTERRUPTED RECREATE DOES NOT ALWAYS LEAVE A CORPSE. When compose is killed inside its
# minutes-long stop grace period — a foreground `timeout` SIGTERMing the whole process group — the
# renamed container is left RUNNING, and compose still resolves the service to it. This scan used to
# grep `docker ps -a` and exclude nothing, so it could not tell that apart from a dead leftover: on
# 2026-09-07 one real run printed `✓ car-scout (0250190bdb78_scout-car-scout-1) : running, image
# courante` and, four lines below, offered `docker rm -f` for that same container. The service check
# was right; the remedy would have removed the watcher it had just certified.
#
# So the set is partitioned against the association compose itself reports, and the two halves get
# opposite instructions. Neither half is silent: both are still `bad`.
#
# THE MAP IS PER ROW, NEVER PER SERVICE, and that distinction is the whole point. The state this
# tool was written for — its own header — is `rent-scout` sitting in `Created` BESIDE a hex-prefixed
# leftover, and both of those carry the service's compose labels, so `docker compose ps -a` lists
# TWO rows under one service. A map keyed on the service (one entry, first row wins) drops the
# second, the running rename misses the association, and `docker rm -f` is printed for it again —
# the defect rebuilt inside its own fix. The service loop above keeps its own `break` because it is
# answering a different question (does this service have a container at all).
declare -A service_of=() state_of=()
for row in "${rows[@]}"; do
  row_service="${row%%$'\t'*}"
  for service in "${services[@]}"; do
    if [[ "$row_service" == "$service" ]]; then
      service_of["$(printf '%s' "$row" | cut -f2)"]="$service"
      state_of["$(printf '%s' "$row" | cut -f2)"]="$(printf '%s' "$row" | cut -f3)"
      break
    fi
  done
done

# `docker ps -a` IS MACHINE-WIDE, and this host runs other compose projects. Somebody else's
# interrupted recreate cannot make OUR next one fail, and `docker rm -f` beside it is the same
# destructive remedy with a wider blast radius than the one this block exists for. A compose
# container is `<project>-<service>-<replica>`, so the leftover is ours only when what follows the
# hex prefix names one of the services we just read out of compose.
ours() {
  local rest="${1#*_}" base svc
  base="${rest%-*}"
  [[ "$base" != "$rest" && "${rest##*-}" =~ ^[0-9]+$ ]] || return 1
  for svc in "${services[@]}"; do
    [[ "$base" == "$svc" || "$base" == *"-$svc" ]] && return 0
  done
  return 1
}

recreate_hint() {
  printf '      un recreate interrompu pendant le stop grace period laisse le conteneur renommé EN\n'
  printf '      MARCHE comme service. La reprise lui rend son nom propre :\n'
  printf '        setsid docker compose up -d --force-recreate --remove-orphans %s\n' "$1"
  printf '      lancez compose DÉTACHÉ (setsid) — sous un timeout au premier plan, l%sétat revient.\n' "'"
}

live=() dead=() foreign=0
while IFS= read -r leftover; do
  [[ -n "$leftover" ]] || continue
  if [[ -n "${service_of[$leftover]:-}" ]]; then
    live+=("$leftover")
  elif ours "$leftover"; then
    dead+=("$leftover")
  else
    foreign=$((foreign + 1))
  fi
done < <(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -E '^[0-9a-f]{12}_' || true)

for leftover in "${live[@]}"; do
  svc="${service_of[$leftover]}"
  if [[ "${state_of[$leftover]}" == "running" ]]; then
    bad "$leftover : conteneur RENOMMÉ qui EST le service « $svc » — NE LE SUPPRIMEZ PAS"
  else
    # The service loop above already counted this one; a second `bad` would report one container as
    # two problems. Say it anyway, because the remedy is not the one that block prints.
    say "$leftover : le service « $svc » y est résolu (état « ${state_of[$leftover]} ») — déjà signalé ci-dessus"
  fi
  recreate_hint "$svc"
done

if [[ ${#dead[@]} -gt 0 ]]; then
  bad "conteneurs orphelins laissés par un recreate interrompu — ils feront échouer le prochain :"
  printf '      %s\n' "${dead[@]}"
  printf '      docker rm -f %s\n' "${dead[@]}"
fi

if [[ ${#live[@]} -eq 0 && ${#dead[@]} -eq 0 ]]; then
  good "aucun conteneur laissé par un recreate interrompu"
fi

if (( foreign > 0 )); then
  say "($foreign conteneur(s) préfixé(s) d'un AUTRE projet ignoré(s) — docker ps -a est global à la machine)"
fi

if (( problems > 0 )); then
  printf '\n  \033[31m%d problème(s)\033[0m — le déploiement n'"'"'est pas celui que vous croyez.\n\n' "$problems"
  exit 1
fi

printf '\n  déploiement vérifié : chaque service tourne, sur l'"'"'image courante.\n\n'
exit 0
