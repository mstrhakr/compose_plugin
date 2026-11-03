#!/bin/bash
printf '%s\n' "$*" >> "$CALLS"
if [ "$1" = inspect ]; then
  [ "${INSPECT_EXIT:-0}" = 0 ] || exit "$INSPECT_EXIT"
  echo "$OLD_ID stack-app"
elif [ "$1" = image ]; then
  [ "${IMAGE_INSPECT_EXIT:-0}" = 0 ] || exit "$IMAGE_INSPECT_EXIT"
  if [ -f "$UPDATE_STATE" ]; then echo "$NEW_ID"; else echo "$OLD_ID"; fi
elif [ "$1" = rmi ]; then
  exit "${RMI_EXIT:-0}"
else
  while [ "$#" -gt 0 ]; do
    case "$1" in
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
