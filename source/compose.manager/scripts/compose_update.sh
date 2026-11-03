#!/bin/bash
# Sourced by compose.sh after resolving arguments, credentials and the stack lock.
# shellcheck disable=SC2154

update_compose() {
  if [ "$debug" = true ]; then
    log_msg DEBUG "${compose_base[*]} -p $name $*" >&2
  fi
  if [ "$automatic_update" = true ]; then
    timeout "${COMPOSE_COMMAND_TIMEOUT:-1800}" "${compose_base[@]}" -p "$name" "$@"
  else
    "${compose_base[@]}" -p "$name" "$@"
  fi
}

update_outcome() {
  local outcome="$1"
  if [ -n "$update_outcome_file" ]; then
    if ! printf '%s\n' "$outcome" > "$update_outcome_file"; then
      log_msg ERROR "Could not write update outcome for $name"
      return 1
    fi
  fi
  log_msg INFO "Update outcome for $name: $outcome"
}

update_failed() {
  local code="$1" phase="$2"
  save_result failed "$code" update
  update_outcome failed
  log_msg ERROR "Failed to $phase stack $name (exit code: $code)"
  echo "Failed to $phase stack $name (exit code: $code)"
  return "$code"
}

run_stack_update() {
  local ids id details current_image image_ref image_id rc rebuild=false needs_update=false
  local old_images="" new_images="" configured_images=""
  local -a pull_args=() up_args=(-d "${cmd_args[@]}") stale_images=()

  if ! ids=$(update_compose ps -q); then
    update_failed 1 "enumerate containers for"
    return 1
  fi
  if [ "$automatic_update" = true ] && [ -z "$ids" ]; then
    echo "Stack '$name' is not running; skipping update."
    save_result success 0 update
    update_outcome skipped
    return $?
  fi

  # Capture running image IDs even when their tags were already moved by an earlier pull.
  while IFS= read -r id; do
    [ -n "$id" ] || continue
    if ! details=$(docker inspect --format='{{.Image}} {{.Config.Image}}' "$id"); then
      update_failed 1 "inspect container for"
      return 1
    fi
    read -r current_image image_ref <<< "$details"
    if [ -z "$current_image" ] || [ -z "$image_ref" ]; then
      update_failed 1 "read container image metadata for"
      return 1
    fi
    old_images+="$current_image"$'\n'
  done <<< "$ids"

  # Include stopped containers in post-success cleanup without starting them here.
  if ! configured_images=$(update_compose images -q); then
    update_failed 1 "enumerate images for"
    return 1
  fi
  old_images+="$configured_images"

  if [ "$build_on_update" = true ]; then
    rebuild=true
    pull_args+=(--ignore-buildable)
  elif [ "$automatic_update" = true ]; then
    pull_args+=(--ignore-buildable)
  fi
  echo "Pulling latest images..."
  update_compose pull "${pull_args[@]}"
  rc=$?
  # Preserve manual Update's local-tag compatibility without enabling unattended builds.
  if [ "$rc" -ne 0 ] && [ "$build_on_update" != true ] && [ "$automatic_update" != true ]; then
    log_msg WARNING "Pull failed for $name; retrying without buildable services and rebuilding"
    update_compose pull --ignore-buildable
    rc=$?
    if [ "$rc" -eq 0 ]; then
      rebuild=true
    fi
  fi
  if [ "$rc" -ne 0 ]; then
    update_failed "$rc" pull
    return "$rc"
  fi

  if [ "$rebuild" = true ]; then
    echo "Rebuilding images with latest base images..."
    update_compose build --pull
    rc=$?
    if [ "$rc" -ne 0 ]; then
      log_msg WARNING "Build with --pull failed for $name; retrying with local base images"
      echo "Build with --pull failed; retrying with local base images..."
      update_compose build
      rc=$?
    fi
    if [ "$rc" -ne 0 ]; then
      update_failed "$rc" build
      return "$rc"
    fi
  fi

  # Compare against the actual running containers, not just tags before and after pull.
  while IFS= read -r id; do
    [ -n "$id" ] || continue
    if ! details=$(docker inspect --format='{{.Image}} {{.Config.Image}}' "$id"); then
      update_failed 1 "inspect container for"
      return 1
    fi
    read -r current_image image_ref <<< "$details"
    if [ -z "$current_image" ] || [ -z "$image_ref" ] ||
       ! image_id=$(docker image inspect --format='{{.Id}}' "$image_ref") || [ -z "$image_id" ]; then
      update_failed 1 "inspect image for"
      return 1
    fi
    if [ "$current_image" != "$image_id" ]; then
      needs_update=true
    fi
  done <<< "$ids"
  if [ "$automatic_update" = true ] && [ "$needs_update" != true ]; then
    echo "No updates for stack '$name'."
    save_result success 0 update
    update_outcome unchanged
    return $?
  fi

  if plugin_setting_enabled CREATE_MISSING_EXTERNAL_NETWORKS; then
    if ! create_missing_external_networks "${compose_base[@]}" -p "$name"; then
      update_failed 1 "create networks for"
      return 1
    fi
  fi
  # A completed build must not be repeated by a service's pull_policy.
  # Manual updates still allow Compose to build a missing local image when not opted in.
  up_args+=(--pull never)
  if [ "$rebuild" = true ] || [ "$automatic_update" = true ]; then
    up_args+=(--no-build)
  fi
  echo "Recreating containers..."
  update_compose up "${up_args[@]}"
  rc=$?
  if [ "$rc" -ne 0 ]; then
    update_failed "$rc" recreate
    return "$rc"
  fi

  if ! new_images=$(update_compose images -q); then
    update_failed 1 "enumerate updated images for"
    return 1
  fi
  mapfile -t stale_images < <(
    comm -23 \
      <(printf '%s\n' "$old_images" | sed 's/^sha256://' | grep -E '^[[:xdigit:]]{64}$' | sort -u) \
      <(printf '%s\n' "$new_images" | sed 's/^sha256://' | grep -E '^[[:xdigit:]]{64}$' | sort -u)
  )
  if (( ${#stale_images[@]} )); then
    # Never force removal: other stacks may still use these images.
    if ! docker rmi "${stale_images[@]}"; then
      log_msg WARNING "Some superseded images for $name remain in use or could not be removed"
    fi
  fi
  if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
    date -Iseconds > "$stack_path/started_at"
    persist_running_profiles
  fi
  save_result success 0 update
  update_outcome updated || return 1
  echo "Stack $name updated successfully"
}
