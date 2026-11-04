#!/bin/bash
printf '%s\n' "$*" >> "$CALLS"
if [ "$1" = ps ]; then
  [ "${PS_EXIT:-0}" = 0 ] || exit "$PS_EXIT"
  if [ -n "${RUNTIME_ROWS:-}" ]; then
    printf '%s\n' "$RUNTIME_ROWS" | cut -d'|' -f1
  else
    echo container-id
  fi
elif [ "$1" = inspect ]; then
  [ "${INSPECT_EXIT:-0}" = 0 ] || exit "$INSPECT_EXIT"
  if [[ "$*" == *State.Status* ]]; then
    if [ -n "${RUNTIME_ROWS:-}" ]; then
      while IFS='|' read -r id service state oneoff; do
        if [ "$id" = "${!#}" ]; then
          echo "$service|$state|${oneoff:-False}"
          exit 0
        fi
      done <<< "$RUNTIME_ROWS"
      exit 1
    fi
    if [ "${STOPPED:-false}" = true ]; then echo 'app|exited|False'; else echo 'app|running|False'; fi
  else
    echo "$OLD_ID stack-app"
  fi
elif [ "$1" = image ]; then
  [ "${IMAGE_INSPECT_EXIT:-0}" = 0 ] || exit "$IMAGE_INSPECT_EXIT"
  if [ -f "$UPDATE_STATE" ]; then echo "$NEW_ID"; else echo "$OLD_ID"; fi
elif [ "$1" = rmi ]; then
  exit "${RMI_EXIT:-0}"
else
  while [ "$#" -gt 0 ]; do
    case "$1" in
      config)
        [ "${CONFIG_EXIT:-0}" = 0 ] || exit "$CONFIG_EXIT"
        if [ "${2:-}" = --profiles ]; then
          printf '%s\n' "${CONFIG_PROFILES:-}"
        else
          printf '%s\n' "${CONFIG_SERVICES:-app}"
        fi
        exit 0 ;;
      ps)
        [ "${PS_EXIT:-0}" = 0 ] || exit "$PS_EXIT"
        [ "${STOPPED:-false}" = true ] || echo container-id
        exit 0 ;;
      images)
        if [ -f "$UPDATE_UP" ] && [ -f "$UPDATE_STATE" ]; then echo "$NEW_ID"; else echo "$OLD_ID"; fi
        exit 0 ;;
      pull)
        if [ "${PULL_EXIT:-0}" != 0 ]; then
          if [ "${FALLBACK_PULL:-false}" != true ] || [ "${2:-}" != --ignore-buildable ]; then
            exit "$PULL_EXIT"
          fi
        fi
        [ "${CHANGE:-true}" != true ] || touch "$UPDATE_STATE"
        exit 0 ;;
      build)
        if [ "${2:-}" = --pull ] && [ "${BUILD_PULL_EXIT:-0}" != 0 ]; then exit "$BUILD_PULL_EXIT"; fi
        [ "${BUILD_EXIT:-0}" = 0 ] || exit "$BUILD_EXIT"
        [ "${BUILD_CHANGE:-false}" != true ] || touch "$UPDATE_STATE"
        exit 0 ;;
      up)
        [ "${UP_EXIT:-0}" = 0 ] || exit "$UP_EXIT"
        touch "$UPDATE_UP"
        exit 0 ;;
    esac
    shift
  done
fi
