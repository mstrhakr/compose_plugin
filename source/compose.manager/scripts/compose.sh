#!/bin/bash
export HOME=/root

# Compose Manager - Docker Compose wrapper script
# Provides error handling, result tracking, and operation locking

# shellcheck disable=SC1091
. "$(dirname "$0")/common.sh"

# Configuration - can be overridden via environment
LOCK_TIMEOUT=${COMPOSE_LOCK_TIMEOUT:-30}
LOCK_DIR="/var/run/compose.manager"

SHORT=e:,c:,f:,p:,d:,o:,g:,s:,w:
LONG=env,command:,file:,project_name:,project_dir:,override:,profile:,debug,recreate,remove-orphans,stack-path:,workdir:
OPTS=$(getopt -a -n compose --options $SHORT --longoptions $LONG -- "$@")

eval set -- "$OPTS"

envFile=""
env_args=()
file_args=()
profile_names=()
profile_args=()
project_dir_args=()
cmd_args=()
stack_path=""
debug=false
lock_fd=""
operation_exit_code=0


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

# Ensure lock is released on exit
trap release_lock EXIT

# Save operation result to stack directory
save_result() {
    local result="$1"
    local exit_code="$2"
    local operation="$3"
    
    if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        echo "{\"result\":\"$result\",\"exit_code\":$exit_code,\"operation\":\"$operation\",\"timestamp\":\"$(date -Iseconds)\"}" > "$stack_path/last_result.json"
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
        exit
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
        project_dir_args=("--project-directory" "$2")
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

# Build docker compose profile flags from canonical profile names.
for profile_name in "${profile_names[@]}"; do
  profile_args+=("--profile" "$profile_name")
done

# Build the compose base command as an array (no eval needed)
compose_base=(docker compose "${project_dir_args[@]}" "${env_args[@]}" "${file_args[@]}" "${profile_args[@]}")

# Canonicalize project name through shared PHP sanitizer.
if ! name=$(canonicalize_project_name "$name"); then
  log_msg "ERROR" "Could not canonicalize project name"
  exit 1
fi

# Acquire lock for operations that modify state (not for read-only commands)
case $command in
  up|down|pull|update|stop)
    # Lock by canonical project name so every path uses the same stack identity.
    lock_name="$name"
    if ! acquire_lock "$lock_name"; then
      exit 1
    fi
    ;;
esac

case $command in

  up)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name up ${cmd_args[*]} -d"
    fi
    
    "${compose_base[@]}" -p "$name" up "${cmd_args[@]}" -d
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
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name images -q"
      log_msg "DEBUG" "${compose_base[*]} -p $name pull --ignore-buildable"
      log_msg "DEBUG" "${compose_base[*]} -p $name up -d --build"
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
    
    # Pull latest images (--ignore-buildable: skip services with build sections, they are rebuilt by up --build)
    echo "Pulling latest images..."
    "${compose_base[@]}" -p "$name" pull --ignore-buildable
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
    "${compose_base[@]}" -p "$name" up -d --build
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
  up|down|pull|update|stop|logs)
    exit "${operation_exit_code:-0}"
    ;;
esac