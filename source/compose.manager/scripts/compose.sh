#!/bin/bash
export HOME=/root

# Compose Manager - Docker Compose wrapper script
# Provides error handling, result tracking, and operation locking

# shellcheck disable=SC1091
. "$(dirname "$0")/common.sh"

# Configuration - can be overridden via environment
LOCK_TIMEOUT=${COMPOSE_LOCK_TIMEOUT:-30}
LOCK_DIR=${COMPOSE_LOCK_DIR:-/var/run/compose.manager}

SHORT=e:,c:,f:,p:,d:,o:,g:,s:,w:
LONG=env,command:,file:,project_name:,project_dir:,override:,profile:,debug,recreate,remove-orphans,stack-path:,workdir:,follow-logs,wait,wait-timeout:,build,credential-id:,git-commit:,save-local-changes
OPTS=$(getopt -a -n compose --options $SHORT --longoptions $LONG -- "$@")

eval set -- "$OPTS"

envFile=""
env_args=()
file_args=()
profile_names=()
profile_args=()
project_directory=""
cmd_args=()
stack_path=""
debug=false
follow_logs=false
wait_for_healthy=false
wait_timeout=""
build_on_update=false
lock_fd=""
operation_exit_code=0
credential_id=""
docker_config_dir=""
git_commit=""
save_local_changes=false


# Logging helper — delegates to shared composeLogger, adds console echo in debug mode
log_msg() {
    local level="$1"
    local msg="$2"
    composeLogger "$msg" "${level,,}" compose
    if [ "$debug" = true ]; then
        echo "[$level] $msg"
    fi
}

# Locking functions to prevent concurrent operations on the same stack
acquire_lock() {
    local lock_name="$1"
    
    # Create lock directory if needed
    mkdir -p "$LOCK_DIR" 2>/dev/null
    
    local lock_file="$LOCK_DIR/${lock_name}.lock"
    
    # Open lock file for writing (fd 9)
    exec 9>"$lock_file"
    
    # Try to acquire lock with timeout
    local waited=0
    while ! flock -n 9 2>/dev/null; do
        if [ "$waited" -ge "$LOCK_TIMEOUT" ]; then
            log_msg "ERROR" "Could not acquire lock for $lock_name after ${LOCK_TIMEOUT}s - another operation may be in progress"
            echo "✗ Another operation is already in progress for this stack. Please wait and try again."
            return 1
        fi
        
        if [ $waited -eq 0 ]; then
            echo "⏳ Waiting for another operation to complete..."
        fi
        
        sleep 1
        waited=$((waited + 1))
    done
    
    # Write lock info
    echo "{\"pid\":$$,\"command\":\"$command\",\"time\":\"$(date -Iseconds)\"}" >&9
    
    lock_fd=9
    return 0
}

release_lock() {
    if [ -n "$lock_fd" ]; then
        flock -u $lock_fd 2>/dev/null
        exec 9>&-
        lock_fd=""
    fi
}

# Ensure lock is released and follow state is cleared on exit.
cleanup_docker_config() {
  if [ -n "$docker_config_dir" ]; then
    php "$(dirname "$0")/credential_config.php" --remove "$docker_config_dir" >/dev/null 2>&1 || true
    docker_config_dir=""
  fi
}

trap 'release_lock; clear_follow_pid; cleanup_docker_config' EXIT

# Save operation result to stack directory
save_result() {
    local result="$1"
    local exit_code="$2"
    local operation="$3"
    
    if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        echo "{\"result\":\"$result\",\"exit_code\":$exit_code,\"operation\":\"$operation\",\"timestamp\":\"$(date -Iseconds)\"}" > "$stack_path/last_result.json"
    fi
}

save_follow_pid() {
    local pid="$1"
    if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        echo "$pid" > "$stack_path/.compose_follow.pid"
    fi
}

clear_follow_pid() {
    if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        rm -f "$stack_path/.compose_follow.pid"
    fi
}

# Persist selected profile names for later operations (e.g. update target scope).
persist_running_profiles() {
  if [ -z "$stack_path" ] || [ ! -d "$stack_path" ]; then
    return
  fi

  if [ ${#profile_names[@]} -gt 0 ]; then
    printf '%s\n' "$(IFS=,; echo "${profile_names[*]}")" > "$stack_path/running_profiles"
  else
    rm -f "$stack_path/running_profiles"
  fi
}

while :
do
  case "$1" in
    -e | --env )
      envFile="$2"
      shift 2
      
      if [ -f "$envFile" ]; then
        echo "using .env: $envFile"
      else
        echo ".env doesn't exist: $envFile"
        exit 1
      fi

      env_args=("--env-file" "$envFile")
      ;;
    -c | --command )
      command="$2"
      shift 2
      ;;
    -f | --file )
      file_args+=("-f" "$2")
      shift 2
      ;;
    -p | --project_name )
      name="$2"
      shift 2
      ;;
    -d | --project_dir )
      if [ -d "$2" ]; then
        while IFS= read -r -d '' file; do
          file_args+=("-f" "$file")
        done < <(find "$2" -maxdepth 1 -type f \( -name '*compose*.yml' -o -name '*compose*.yaml' \) -print0)
      fi
      shift 2
      ;;
    -g | --profile )
      profile_names+=("$2")
      shift 2
      ;;
    -w | --workdir )
      if [ -d "$2" ]; then
        project_directory="$2"
      else
        log_msg "ERROR" "Project directory does not exist: $2"
        exit 1
      fi
      shift 2
      ;;
    --recreate )
      cmd_args+=("--force-recreate")
      shift;
      ;;
    --remove-orphans )
      cmd_args+=("--remove-orphans")
      shift;
      ;;
    -s | --stack-path )
      stack_path="$2"
      shift 2
      ;;
    --debug )
      debug=true
      shift;
      ;;
    --follow-logs )
      follow_logs=true
      shift;
      ;;
    --wait )
      wait_for_healthy=true
      shift;
      ;;
    --wait-timeout )
      wait_timeout="$2"
      shift 2
      ;;
    --build )
      build_on_update=true
      shift;
      ;;
    --credential-id )
      credential_id="$2"
      shift 2
      ;;
    --git-commit )
      git_commit="$2"
      shift 2
      ;;
    --save-local-changes )
      save_local_changes=true
      shift;
      ;;
    --)
      shift;
      break
      ;;
    *)
      echo "Unexpected option: $1"
      shift
      ;;
  esac
done

if [ -n "$credential_id" ]; then
  if ! credential_output=$(php "$(dirname "$0")/credential_config.php" --credential-id "$credential_id"); then
    log_msg "ERROR" "Selected registry credential could not be loaded"
    exit 1
  fi
  docker_config_dir=$(printf '%s\n' "$credential_output" | sed -n '1p')
  credential_label=$(printf '%s\n' "$credential_output" | sed -n '2p')
  export DOCKER_CONFIG="$docker_config_dir"
  log_msg "DEBUG" "Using registry credential '${credential_label:-$credential_id}' for $name"
elif [[ "$command" =~ ^(up|pull|update|gitdeploy)$ ]]; then
  log_msg "DEBUG" "No registry credential configured for $name; using default Docker credentials"
fi

# Build docker compose profile flags from canonical profile names.
for profile_name in "${profile_names[@]}"; do
  profile_args+=("--profile" "$profile_name")
done

if [ -n "$project_directory" ]; then
  if ! cd "$project_directory" 2>/dev/null; then
    log_msg "ERROR" "Failed to cd into project directory: $project_directory"
    exit 1
  fi
fi

# Build the compose base command as an array (no eval needed).
# When we need Docker Compose default discovery we intentionally run from the
# project directory itself so it matches plain `cd <dir> && docker compose ...`,
# which is the behavior the project was validated against.
compose_base=(docker compose "${env_args[@]}" "${file_args[@]}" "${profile_args[@]}")

# Canonicalize project name through shared PHP sanitizer.
if ! name=$(canonicalize_project_name "$name"); then
  log_msg "ERROR" "Could not canonicalize project name"
  exit 1
fi

# Acquire lock for operations that modify state (not for read-only commands)
case $command in
  up|down|pull|update|stop|gitdeploy)
    # Lock by canonical project name so every path uses the same stack identity.
    lock_name="$name"
    if ! acquire_lock "$lock_name"; then
      exit 1
    fi
    ;;
esac

# Optionally create missing `external: true` networks before starting the stack
# (Settings > Compose > Create Missing External Networks).
case $command in
  up|update)
    if plugin_setting_enabled CREATE_MISSING_EXTERNAL_NETWORKS; then
      create_missing_external_networks "${compose_base[@]}" -p "$name"
    fi
    ;;
esac

case $command in

  up)
    if [ "$follow_logs" = true ] && [ "$wait_for_healthy" = true ]; then
      log_msg "ERROR" "Follow logs and wait-for-healthy cannot be used together for the same compose up command"
      echo "✗ Follow stack logs and wait-for-healthy cannot be enabled at the same time."
      exit 1
    fi

    if [ "$wait_for_healthy" = true ]; then
      cmd_args+=("--wait")
      if [ -n "$wait_timeout" ]; then
        cmd_args+=("--wait-timeout" "$wait_timeout")
      fi
    fi

    if [ "$follow_logs" = true ]; then
      save_follow_pid "$$"
      if [ "$debug" = true ]; then
        log_msg "DEBUG" "${compose_base[*]} -p $name up ${cmd_args[*]}"
      fi
      "${compose_base[@]}" -p "$name" up "${cmd_args[@]}"
    else
      if [ "$debug" = true ]; then
        log_msg "DEBUG" "${compose_base[*]} -p $name up ${cmd_args[*]} -d"
      fi
      "${compose_base[@]}" -p "$name" up "${cmd_args[@]}" -d
    fi
    exit_code=$?
    operation_exit_code=$exit_code
    
    if [ $exit_code -eq 0 ]; then
      # Save stack started timestamp and running profiles
      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        date -Iseconds > "$stack_path/started_at"
        persist_running_profiles
      fi
      save_result "success" $exit_code "up"
      echo ""
      echo "✓ Stack $name started successfully"
    else
      save_result "failed" $exit_code "up"
      log_msg "ERROR" "Failed to start stack $name (exit code: $exit_code)"
      echo ""
      echo "✗ Stack $name failed to start (exit code: $exit_code)"
    fi
    ;;

  down)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name down ${cmd_args[*]}"
    fi
    
    "${compose_base[@]}" -p "$name" down "${cmd_args[@]}" 2>&1
    exit_code=$?
    operation_exit_code=$exit_code
    
    if [ $exit_code -eq 0 ]; then
      # Clear running profiles on successful down
      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        rm -f "$stack_path/running_profiles"
      fi
      save_result "success" $exit_code "down"
      echo ""
      echo "✓ Stack $name stopped successfully"
    else
      save_result "failed" $exit_code "down"
      log_msg "ERROR" "Failed to stop stack $name (exit code: $exit_code)"
      echo ""
      echo "✗ Stack $name failed to stop (exit code: $exit_code)"
    fi
    ;;

  pull)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name pull --ignore-buildable"
    fi
    
    "${compose_base[@]}" -p "$name" pull --ignore-buildable
    exit_code=$?
    operation_exit_code=$exit_code
    
    if [ $exit_code -eq 0 ]; then
      save_result "success" $exit_code "pull"
      echo ""
      echo "✓ Images pulled successfully for $name"
    else
      save_result "failed" $exit_code "pull"
      log_msg "ERROR" "Failed to pull images for $name (exit code: $exit_code)"
      echo ""
      echo "✗ Failed to pull images for $name (exit code: $exit_code)"
    fi
    ;;
    
  update)
    up_args=("-d")
    pull_args=()
    if [ "$build_on_update" = true ]; then
      # --ignore-buildable only makes sense when we rebuild those services ourselves.
      pull_args+=("--ignore-buildable")
      up_args+=("--build")
    fi

    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name images -q"
      log_msg "DEBUG" "${compose_base[*]} -p $name pull ${pull_args[*]}"
      log_msg "DEBUG" "${compose_base[*]} -p $name up ${up_args[*]}"
    fi

    # Capture current images for cleanup later
    images=()
    mapfile -t images < <("${compose_base[@]}" -p "$name" images -q 2>/dev/null)

    if [ "${#images[@]}" -eq 0 ]; then
      # Fallback: extract image names from compose files directly
      local_files=()
      for (( i=0; i<${#file_args[@]}; i++ )); do
        if [ "${file_args[$i]}" = "-f" ] && [ -f "${file_args[$((i+1))]}" ]; then
          local_files+=("${file_args[$((i+1))]}")
        fi
      done
      if (( ${#local_files[@]} )); then
        mapfile -t services < <(sed -n 's/image:\(.*\)/\1/p' "${local_files[@]}")
        for image in "${services[@]}"; do
          mapfile -t temp_images < <(docker images -q --no-trunc "${image}" 2>/dev/null)
          images+=( "${temp_images[@]}" )
        done
      fi

      images=( "${images[@]##sha256:}" )
    fi
    
    # Pull latest images. Buildable services are only skipped when we rebuild them below.
    echo "Pulling latest images..."
    "${compose_base[@]}" -p "$name" pull "${pull_args[@]}"
    pull_exit=$?
    
    if [ $pull_exit -ne 0 ]; then
      operation_exit_code=$pull_exit
      save_result "failed" $pull_exit "update"
      log_msg "ERROR" "Failed to pull images for $name, aborting update"
      echo ""
      echo "✗ Failed to pull images for $name, update aborted"
      exit $operation_exit_code
    fi
    
    # Recreate containers with new images
    echo ""
    echo "Recreating containers..."
    "${compose_base[@]}" -p "$name" up "${up_args[@]}"
    up_exit=$?

    if [ $up_exit -eq 0 ]; then
      operation_exit_code=0
      # Clean up old images
      mapfile -t new_images < <("${compose_base[@]}" -p "$name" images -q 2>/dev/null)
      for target in "${new_images[@]}"; do
        for i in "${!images[@]}"; do
          if [[ ${images[i]} = "$target" ]]; then
            unset 'images[i]'
          fi
        done
      done

      if (( ${#images[@]} )); then
        if [ "$debug" = true ]; then
          log_msg "DEBUG" "docker rmi ${images[*]}"
        fi
        echo ""
        echo "Cleaning up old images..."
        docker rmi "${images[@]}" 2>/dev/null || true
      fi
      
      # Save stack started timestamp and running profiles after update
      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        date -Iseconds > "$stack_path/started_at"
        persist_running_profiles
      fi
      save_result "success" 0 "update"
      echo ""
      echo "✓ Stack $name updated successfully"
    else
      operation_exit_code=$up_exit
      save_result "failed" $up_exit "update"
      log_msg "ERROR" "Failed to update stack $name (exit code: $up_exit)"
      echo ""
      echo "✗ Stack $name failed to update (exit code: $up_exit)"
    fi
    ;;

  gitdeploy)
    # Deploy a git stack. Fetching, checking out, checking, pulling and
    # starting all happen under this one lock. Until "up" starts, any failure
    # leaves the containers untouched and the clone at its previous commit.
    git_helper="$(dirname "$0")/git_stack.php"
    # Run the git helper. PHP notices go to stderr, so stdout carries only its answer.
    git_stack() {
      php -d display_errors=stderr "$git_helper" "$@"
    }
    # After a failure before "up", check the previous commit out again and say
    # where the clone is now.
    put_back_previous_commit() {
      if git_stack restore "$stack_path" "$previous_commit"; then
        echo "The previous commit was put back and no container was changed."
      else
        log_msg "ERROR" "Could not put back commit $previous_commit for git stack $name"
        echo "No container was changed, but the previous commit could not be put back: the clone is still at the new commit."
      fi
    }
    if [ -z "$stack_path" ] || [ ! -f "$stack_path/git.json" ]; then
      log_msg "ERROR" "gitdeploy needs the stack folder of a git stack (--stack-path)"
      echo "✗ $name is not a git stack."
      exit 1
    fi

    # 1. Fetch, check out and check the new commit (git_stack.php prints the
    #    commit that was checked out before, for step 2).
    prepare_args=("$stack_path" "$name")
    if [ -n "$git_commit" ]; then
      prepare_args+=("--commit" "$git_commit")
    fi
    if [ "$save_local_changes" = true ]; then
      prepare_args+=("--save-local-changes")
    fi
    for profile_name in "${profile_names[@]}"; do
      prepare_args+=("--profile" "$profile_name")
    done
    if ! previous_commit=$(git_stack prepare "${prepare_args[@]}"); then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "Git deploy of $name stopped before any container was changed"
      echo ""
      echo "✗ Stack $name was not deployed. No container was changed."
      exit 1
    fi
    if ! [[ "$previous_commit" =~ ^[0-9a-f]{40}([0-9a-f]{24})?$ ]]; then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "git_stack.php prepare printed something other than a commit id for $name"
      echo ""
      echo "✗ Stack $name was not deployed. No container was changed, but the clone may be at the new commit."
      exit 1
    fi

    # The compose files may have changed with the new commit, so build the
    # command from the stack as it is checked out now.
    git_compose_args=()
    git_args_file=$(mktemp)
    if ! git_stack compose-args "$stack_path" > "$git_args_file"; then
      rm -f "$git_args_file"
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "Could not read the compose files of git stack $name"
      echo ""
      echo "✗ Stack $name was not deployed."
      put_back_previous_commit
      exit 1
    fi
    mapfile -d '' -t git_compose_args < "$git_args_file"
    rm -f "$git_args_file"
    git_compose=(docker compose "${git_compose_args[@]}" "${profile_args[@]}")

    # A deploy always builds services that have a build section (the new
    # commit may change their Dockerfile), as a step of its own before up.
    up_args=("-d" "--remove-orphans")
    if [ "$wait_for_healthy" = true ]; then
      up_args+=("--wait")
      if [ -n "$wait_timeout" ]; then
        up_args+=("--wait-timeout" "$wait_timeout")
      fi
    fi
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${git_compose[*]} -p $name pull --ignore-buildable"
      log_msg "DEBUG" "${git_compose[*]} -p $name build"
      log_msg "DEBUG" "${git_compose[*]} -p $name up ${up_args[*]}"
    fi

    # Remember the stack's current images, to remove the ones it no longer uses afterwards.
    old_images=()
    mapfile -t old_images < <("${git_compose[@]}" -p "$name" images -q 2>/dev/null)

    # 2. Pull and build images. If either fails, put the previous commit back:
    #    still no container has changed.
    echo "Pulling images..."
    if ! "${git_compose[@]}" -p "$name" pull --ignore-buildable; then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "Failed to pull images for git stack $name"
      echo ""
      echo "✗ Pulling images for $name failed."
      put_back_previous_commit
      exit 1
    fi
    echo ""
    echo "Building images..."
    if ! "${git_compose[@]}" -p "$name" build; then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "Failed to build images for git stack $name"
      echo ""
      echo "✗ Building images for $name failed."
      put_back_previous_commit
      exit 1
    fi

    # 3. Ask whether every container should be recreated (the stack's
    #    "recreate on any change in its folder" setting).
    if ! git_up_extra=$(git_stack up-arguments "$stack_path" "$previous_commit"); then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "Could not compare commits for git stack $name"
      echo ""
      echo "✗ Stack $name was not deployed."
      put_back_previous_commit
      exit 1
    fi
    # Only the two answers git_stack.php gives are accepted, so a stray line
    # of output can never become an argument to up.
    case "$git_up_extra" in
      "")
        ;;
      "--force-recreate")
        up_args+=("--force-recreate")
        ;;
      *)
        save_result "failed" 1 "gitdeploy"
        log_msg "ERROR" "git_stack.php up-arguments printed something unexpected for $name"
        echo ""
        echo "✗ Stack $name was not deployed."
        put_back_previous_commit
        exit 1
        ;;
    esac

    # Optionally create missing `external: true` networks, as up and update do
    # (Settings > Compose > Create Missing External Networks). This is done
    # here, after every check, pull and build has passed, so a deploy that
    # stops earlier creates nothing.
    if plugin_setting_enabled CREATE_MISSING_EXTERNAL_NETWORKS; then
      create_missing_external_networks "${git_compose[@]}" -p "$name"
    fi

    # 4. Start the stack at the new commit.
    echo ""
    echo "Starting containers..."
    "${git_compose[@]}" -p "$name" up "${up_args[@]}"
    up_exit=$?
    operation_exit_code=$up_exit

    if [ $up_exit -eq 0 ]; then
      if ! git_stack finish "$stack_path" success; then
        log_msg "ERROR" "Git stack $name started, but its deployed commit could not be recorded"
        echo "⚠ The stack started, but the deployed commit could not be recorded. Deploy it again to record it."
      fi

      new_images=()
      mapfile -t new_images < <("${git_compose[@]}" -p "$name" images -q 2>/dev/null)
      superseded_images=()
      for old_image in "${old_images[@]}"; do
        still_used=false
        for new_image in "${new_images[@]}"; do
          if [ "$old_image" = "$new_image" ]; then
            still_used=true
          fi
        done
        if [ "$still_used" = false ]; then
          superseded_images+=("$old_image")
        fi
      done
      if (( ${#superseded_images[@]} )); then
        echo ""
        echo "Cleaning up old images..."
        # Plain docker rmi leaves alone any image another container still uses.
        docker rmi "${superseded_images[@]}" 2>/dev/null || true
      fi

      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        date -Iseconds > "$stack_path/started_at"
        persist_running_profiles
      fi
      save_result "success" 0 "gitdeploy"
      echo ""
      echo "✓ Stack $name deployed successfully"
    else
      # A half-finished up cannot be undone reliably, so it is not rolled back:
      # the commit is recorded as failed, for a person to fix and deploy again.
      if ! git_stack finish "$stack_path" failed; then
        log_msg "ERROR" "The failed deploy of git stack $name could not be recorded"
      fi
      save_result "failed" $up_exit "gitdeploy"
      log_msg "ERROR" "Failed to deploy git stack $name (exit code: $up_exit)"
      echo ""
      echo "✗ Stack $name failed to start (exit code: $up_exit). The commit is recorded as failed (compose-git status shows it)."
      echo "Fix it and deploy again, or deploy a known good commit with --commit."
    fi
    ;;

  stop)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name stop"
    fi
    
    "${compose_base[@]}" -p "$name" stop 2>&1
    exit_code=$?
    operation_exit_code=$exit_code
    
    if [ $exit_code -eq 0 ]; then
      save_result "success" $exit_code "stop"
      echo ""
      echo "✓ Stack $name stopped successfully"
    else
      save_result "failed" $exit_code "stop"
      log_msg "ERROR" "Failed to stop stack $name (exit code: $exit_code)"
      echo ""
      echo "✗ Stack $name failed to stop (exit code: $exit_code)"
    fi
    ;;

  list) 
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker compose ls -a --format json"
    fi
    docker compose ls -a --format json 2>&1
    ;;

  ps)
    # Get all compose containers with their status/uptime
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker ps -a --filter label=com.docker.compose.project --format json"
    fi
    docker ps -a --filter 'label=com.docker.compose.project' --format json 2>&1
    ;;

  logs)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name logs -f"
    fi
    "${compose_base[@]}" -p "$name" logs -f 2>&1
    exit_code=$?
    operation_exit_code=$exit_code
    if [ $exit_code -ne 0 ]; then
      log_msg "ERROR" "Failed to stream logs (exit code: $exit_code)"
    fi
    ;;

  *)
    echo "Unknown command: $command"
    log_msg "ERROR" "Unknown command: $command (name: $name, files: ${file_args[*]})"
    exit 1
    ;;
esac

case $command in
  up|down|pull|update|stop|logs|gitdeploy)
    exit "${operation_exit_code:-0}"
    ;;
esac