#!/bin/bash
# Sourced by compose.sh after resolving arguments, credentials and the stack lock.
# shellcheck disable=SC2154

update_compose() {
  if [ "$debug" = true ]; then
    log_msg DEBUG "${compose_base[*]} ${update_profile_args[*]} -p $name $*" >&2
  fi
  if [ "$automatic_update" = true ]; then
    timeout "${COMPOSE_COMMAND_TIMEOUT:-1800}" "${compose_base[@]}" "${update_profile_args[@]}" -p "$name" "$@"
  else
    "${compose_base[@]}" "${update_profile_args[@]}" -p "$name" "$@"
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

update_target_images() {
  # shellcheck disable=SC2016  # These variables belong to PHP.
  "${COMPOSE_MANAGER_PHP:-php}" -r '
$config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($config["services"] ?? null) || empty($config["name"])) {
    throw new RuntimeException("Missing resolved Compose services or project name.");
}
$separator = filter_var(getenv("COMPOSE_COMPATIBILITY"), FILTER_VALIDATE_BOOLEAN) ? "_" : "-";
foreach ($config["services"] as $service => $definition) {
    // Compose names build-only images from the resolved project and service.
    $image = $definition["image"] ?? ($config["name"] . $separator . $service);
    if (!is_string($image) || $image === "" || strpbrk($image, "|\r\n") !== false) {
        throw new RuntimeException("Invalid target image for " . $service);
    }
    echo $service, "|", $image, PHP_EOL;
}
'
}

run_stack_update() {
  local ids id details current_image image_ref image_id rc rebuild=false needs_update=false
  local service state oneoff configured_services profiles profile arg targets config_json had_skips=false
  local old_images="" new_images=""
  local -a pull_args=() up_args=(-d "${cmd_args[@]}") stale_images=() services=() candidates=()
  local -a update_profile_args=(--profile '*')
  local -A counts=() running=() stopped=() unsafe=() container_services=()
  local -A configured=() selected=() container_images=() target_images=() service_images=()
  local -a container_ids=()

  for arg in "${cmd_args[@]}"; do
    if [ "$arg" = --remove-orphans ]; then
      echo "Scoped updates cannot remove unrelated orphan containers; use a separate lifecycle action."
      update_failed 1 "apply remove-orphans during"
      return 1
    fi
  done

  # Enumerate the runtime project, not the saved/default profile selection.
  if ! ids=$(docker ps --no-trunc -aq --filter "label=com.docker.compose.project=$name"); then
    update_failed 1 "enumerate containers for"
    return 1
  fi
  # Older Compose accepts wildcard config but not every wildcard up/dependency path.
  if ! profiles=$(update_compose config --profiles); then
    update_failed 1 "resolve profiles for"
    return 1
  fi
  update_profile_args=()
  while IFS= read -r profile; do
    [ -n "$profile" ] || continue
    if [[ ! "$profile" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]]; then
      update_failed 1 "read profile metadata for"
      return 1
    fi
    update_profile_args+=(--profile "$profile")
  done <<< "$profiles"
  if ! configured_services=$(update_compose config --services); then
    update_failed 1 "resolve services for"
    return 1
  fi
  while IFS= read -r service; do
    [ -z "$service" ] || configured[$service]=true
  done <<< "$configured_services"
  while IFS= read -r id; do
    [ -n "$id" ] || continue
    container_ids+=("$id")
  done <<< "$ids"
  if (( ${#container_ids[@]} )); then
    if ! details=$(docker inspect --format='{{.Id}}|{{ index .Config.Labels "com.docker.compose.service" }}|{{.State.Status}}|{{ index .Config.Labels "com.docker.compose.oneoff" }}|{{.Image}}' "${container_ids[@]}"); then
      update_failed 1 "inspect containers for"
      return 1
    fi
  else
    details=""
  fi
  while IFS='|' read -r id service state oneoff current_image; do
    [ -n "$id" ] || continue
    case "${oneoff,,}" in true) continue ;; esac
    if [[ ! "$service" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]] || [ -z "$state" ] || [ -z "$current_image" ]; then
      update_failed 1 "read container service metadata for"
      return 1
    fi
    if [ -z "${counts[$service]:-}" ]; then
      candidates+=("$service")
    fi
    counts[$service]=$(( ${counts[$service]:-0} + 1 ))
    container_services[$id]="$service"
    container_images[$id]="$current_image"
    case "$state" in
      running) running[$service]=true ;;
      exited|created) stopped[$service]=true ;;
      *) unsafe[$service]="$state" ;;
    esac
  done <<< "$details"

  for service in "${candidates[@]}"; do
    if [ -z "${configured[$service]:-}" ]; then
      echo "Skipping service '$service': no longer defined in Compose."
      log_msg WARNING "Skipping update of $name/$service: service no longer defined"
      had_skips=true
    elif [ -n "${unsafe[$service]:-}" ]; then
      echo "Skipping service '$service': container state ${unsafe[$service]} requires attention."
      log_msg WARNING "Skipping update of $name/$service: container state ${unsafe[$service]}"
      had_skips=true
    elif [ -n "${stopped[$service]:-}" ] && { [ "$include_stopped" != true ] || [ "$automatic_update" = true ]; }; then
      echo "Leaving service '$service' unchanged: stopped containers are not included."
      if [ -n "${running[$service]:-}" ]; then
        log_msg WARNING "Skipping update of $name/$service: mixed running and stopped replicas"
        had_skips=true
      fi
    else
      services+=("$service")
      selected[$service]=true
      up_args+=(--scale "$service=${counts[$service]}")
    fi
  done
  if (( ${#services[@]} == 0 )); then
    echo "No eligible services for stack '$name'; no images or containers changed."
    save_result success 0 update
    if [ "$had_skips" = true ]; then update_outcome partial; else update_outcome skipped; fi
    return $?
  fi

  # Capture only selected containers, including tags moved by an earlier pull.
  while IFS= read -r id; do
    [ -n "$id" ] || continue
    service="${container_services[$id]:-}"
    if [ -n "$service" ] && [ -n "${selected[$service]:-}" ]; then
      old_images+="${container_images[$id]}"$'\n'
    fi
  done <<< "$ids"

  if [ "$build_on_update" = true ]; then
    rebuild=true
    pull_args+=(--ignore-buildable)
  elif [ "$automatic_update" = true ]; then
    pull_args+=(--ignore-buildable)
  fi
  echo "Pulling latest images..."
  update_compose pull "${pull_args[@]}" "${services[@]}"
  rc=$?
  # Preserve manual Update's local-tag compatibility without enabling unattended builds.
  if [ "$rc" -ne 0 ] && [ "$build_on_update" != true ] && [ "$automatic_update" != true ]; then
    log_msg WARNING "Pull failed for $name; retrying without buildable services and rebuilding"
    update_compose pull --ignore-buildable "${services[@]}"
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
    update_compose build --pull "${services[@]}"
    rc=$?
    if [ "$rc" -ne 0 ]; then
      log_msg WARNING "Build with --pull failed for $name; retrying with local base images"
      echo "Build with --pull failed; retrying with local base images..."
      update_compose build "${services[@]}"
      rc=$?
    fi
    if [ "$rc" -ne 0 ]; then
      update_failed "$rc" build
      return "$rc"
    fi
  fi

  if [ "$automatic_update" = true ]; then
    # Config service selection includes dependencies; read a keyed map, not an image list.
    if ! config_json=$(update_compose config --format json "${services[@]}") ||
       ! targets=$(update_target_images <<< "$config_json"); then
      update_failed 1 "resolve target images for"
      return 1
    fi
    while IFS='|' read -r service image_ref; do
      [ -z "$service" ] || service_images[$service]="$image_ref"
    done <<< "$targets"
    for service in "${services[@]}"; do
      image_ref="${service_images[$service]:-}"
      if [ -z "$image_ref" ]; then
        update_failed 1 "resolve target image for"
        return 1
      fi
      if [ -z "${target_images[$image_ref]:-}" ]; then
        if ! image_id=$(docker image inspect --format='{{.Id}}' "$image_ref") || [ -z "$image_id" ]; then
          update_failed 1 "inspect image for"
          return 1
        fi
        target_images[$image_ref]="$image_id"
      fi
      selected[$service]="${target_images[$image_ref]}"
    done
    while IFS= read -r id; do
      [ -n "$id" ] || continue
      service="${container_services[$id]:-}"
      if [ -n "$service" ] && [ -n "${selected[$service]:-}" ] &&
         [ "${container_images[$id]}" != "${selected[$service]}" ]; then
        needs_update=true
      fi
    done <<< "$ids"
  fi
  if [ "$automatic_update" = true ] && [ "$needs_update" != true ]; then
    echo "No updates for stack '$name'."
    save_result success 0 update
    if [ "$had_skips" = true ]; then update_outcome partial; else update_outcome unchanged; fi
    return $?
  fi

  if plugin_setting_enabled CREATE_MISSING_EXTERNAL_NETWORKS; then
    if ! create_missing_external_networks "${compose_base[@]}" "${update_profile_args[@]}" -p "$name"; then
      update_failed 1 "create networks for"
      return 1
    fi
  fi
  # A completed build must not be repeated by a service's pull_policy.
  # Manual updates still allow Compose to build a missing local image when not opted in.
  up_args+=(--pull never --no-deps)
  if [ "$rebuild" = true ] || [ "$automatic_update" = true ]; then
    up_args+=(--no-build)
  fi
  echo "Recreating containers..."
  update_compose up "${up_args[@]}" "${services[@]}"
  rc=$?
  if [ "$rc" -ne 0 ]; then
    update_failed "$rc" recreate
    return "$rc"
  fi

  if ! new_images=$(update_compose images -q "${services[@]}"); then
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
  fi
  save_result success 0 update
  if [ "$had_skips" = true ]; then
    update_outcome partial || return 1
    echo "Eligible services updated; some services in stack '$name' were skipped."
  else
    update_outcome updated || return 1
    echo "Selected services in stack '$name' updated successfully."
  fi
}
