#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'HELP'
Usage: ./bin/run-scale.sh [--engine=all|mariadb|mysql] [--timeout=SECONDS]

Run the unchanged full scale benchmarks locally using Docker Engine.
Defaults: both engines sequentially; 3600 seconds for the complete task.
Requires Docker and standard Ubuntu shell tools, without host PHP or Composer.
Reports and logs: build/scale/<unique-run>/<engine>/scale-report.json
Only task-owned containers, anonymous database volumes and network are removed.
The reusable PHP image and reports are retained.
HELP
}

engine=all
task_timeout=3600
for argument in "$@"; do
    case "$argument" in
        --engine=*) engine=${argument#*=} ;;
        --timeout=*) task_timeout=${argument#*=} ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown argument: %s\n' "$argument" >&2; usage >&2; exit 2 ;;
    esac
done
case "$engine" in all|mariadb|mysql) ;; *) usage >&2; exit 2 ;; esac
if [[ ! "$task_timeout" =~ ^[1-9][0-9]{0,5}$ ]]; then
    printf 'Timeout must be a positive number of seconds (at most six digits).\n' >&2
    exit 2
fi
for required in docker timeout mktemp sha256sum; do
    command -v "$required" >/dev/null || { printf 'Missing command: %s\n' "$required" >&2; exit 2; }
done

export DOCKER_HOST=unix:///var/run/docker.sock
repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
mkdir -p "$repo_root/build/scale"
run_dir=$(mktemp -d "$repo_root/build/scale/$(date -u +%Y%m%d-%H%M%S)-XXXXXX")
run_id=${run_dir##*/}
prefix="sympress-nginx-scale-$run_id"
network="$prefix-network"
php_container="$prefix-php"
label=io.sympress.nginx-cache.scale-run
containers=("$php_container" "$prefix-mariadb" "$prefix-mysql")
active_engine=
task_pid=
deadline=$((SECONDS + task_timeout))

save_report() {
    [[ -n "$active_engine" ]] || return 0
    timeout 10s docker cp "$php_container:/work/build/scale-report.json" \
        "$run_dir/$active_engine/scale-report.json" 2>/dev/null
}

# Invoked through the EXIT trap.
# shellcheck disable=SC2329
cleanup() {
    local status=$? owned name remaining cleanup_failed=0
    trap - EXIT INT TERM
    set +e
    [[ -z "$task_pid" ]] || kill -TERM "$task_pid" 2>/dev/null
    save_report
    owned=$(timeout 5s docker container ls --all --filter "label=$label=$run_id" --format '{{.Names}}' 2>>"$run_dir/cleanup.log") || cleanup_failed=1
    for name in "${containers[@]}"; do
        case $'\n'"$owned"$'\n' in
            *$'\n'"$name"$'\n'*)
                timeout 10s docker logs "$name" >"$run_dir/$name.log" 2>&1
                timeout 20s docker rm --force --volumes "$name" >>"$run_dir/cleanup.log" 2>&1 || cleanup_failed=1
                ;;
        esac
    done
    owned=$(timeout 5s docker network ls --filter "label=$label=$run_id" --format '{{.Name}}' 2>>"$run_dir/cleanup.log") || cleanup_failed=1
    if [[ "$owned" == "$network" ]]; then
        timeout 10s docker network rm "$network" >>"$run_dir/cleanup.log" 2>&1 || cleanup_failed=1
    fi
    remaining=$(timeout 5s docker container ls --all --filter "label=$label=$run_id" --format '{{.ID}}' 2>>"$run_dir/cleanup.log") || cleanup_failed=1
    [[ -z "$remaining" ]] || cleanup_failed=1
    remaining=$(timeout 5s docker network ls --filter "label=$label=$run_id" --format '{{.ID}}' 2>>"$run_dir/cleanup.log") || cleanup_failed=1
    [[ -z "$remaining" ]] || cleanup_failed=1
    if ((cleanup_failed)); then
        printf 'Could not verify complete cleanup of task-owned resources; see %s/cleanup.log\n' "$run_dir" >&2
        if ((status == 0)); then status=1; fi
    fi
    if ((status != 0)); then
        printf 'Scale task failed (exit %s). Available reports/logs: %s\n' "$status" "$run_dir" >&2
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

run_timed() {
    local remaining=$((deadline - SECONDS)) status=0
    if ((remaining <= 0)); then
        printf 'Scale task exceeded its %s-second timeout.\n' "$task_timeout" >&2
        return 124
    fi
    timeout --signal=TERM --kill-after=10s "${remaining}s" "$@" &
    task_pid=$!
    wait "$task_pid" || status=$?
    task_pid=
    return "$status"
}

stage() {
    local title=$1 log=$2 status
    shift 2
    printf '%s\n  Log: %s\n' "$title" "$log"
    if run_timed "$@" >"$log" 2>&1; then
        return 0
    else
        status=$?
        tail -n 30 "$log" >&2
        return "$status"
    fi
}

printf 'Scale run: %s\nReports/logs: %s\n' "$run_id" "$run_dir"
stage 'Checking Docker Engine' "$run_dir/docker.log" docker info
dockerfile="$repo_root/tests/Integration/docker/scale.Dockerfile"
image_hash=$(sha256sum "$dockerfile")
image="sympress-nginx-cache-scale-php:${image_hash:0:12}"
stage 'Building reusable PHP 8.5 / Composer 2.10.3 image' "$run_dir/image.log" \
    docker build --file "$dockerfile" --tag "$image" "$(dirname "$dockerfile")"
run_timed docker network create --label "$label=$run_id" "$network" >/dev/null
run_timed docker run --detach --name "$php_container" --label "$label=$run_id" \
    --network "$network" --mount "type=bind,source=$repo_root,target=/source,readonly" \
    "$image" sleep infinity >/dev/null

# The quoted PHP and shell variables are evaluated inside the private container.
# shellcheck disable=SC2016
stage 'Preparing private source, dependencies and verified WordPress 7.1.2' "$run_dir/prepare.log" \
    docker exec "$php_container" sh -eu -c '
        cp -a /source/composer.json /source/src /source/Resources /source/tests /work/
        if [ -f /source/composer.lock ]; then cp /source/composer.lock /work/; fi
        composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
        php -r '\''foreach (["dom", "mysqli", "pdo_mysql", "sodium"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing PHP extension: " . $extension); } }'\''
        curl --fail --silent --show-error --location https://wordpress.org/wordpress-7.1.2.tar.gz --output /tmp/wordpress.tar.gz
        echo "c0c666689d66b870d8825500bb8e402ed04de52602c61a6f516c6236b8c9ac67  /tmp/wordpress.tar.gz" | sha256sum --check
        mkdir /wordpress
        tar -xzf /tmp/wordpress.tar.gz --directory /wordpress --strip-components=1
        rm /tmp/wordpress.tar.gz
        php --version
        composer --version
    '

engines=(mariadb mysql)
[[ "$engine" == all ]] || engines=("$engine")
overall_status=0
for current in "${engines[@]}"; do
    mkdir "$run_dir/$current"
    db_container="$prefix-$current"
    case "$current" in
        mariadb) db_image='mariadb:11.8@sha256:79d59758afc91b89b120b0a8904d637f5a3b3e1c4900f29b740d6d46c72fef68' ;;
        mysql) db_image='mysql:8.4@sha256:80f4933e3835f9dc4d35a28ec500d7986cb4414e6c6821c5461239cb7beb8995' ;;
    esac
    stage "Starting disposable $current (512 MiB InnoDB buffer pool)" "$run_dir/$current/database-start.log" \
        docker run --detach --name "$db_container" --label "$label=$run_id" \
        --network "$network" --network-alias task-db \
        --env MARIADB_ROOT_PASSWORD=sympress-db-test-only \
        --env MYSQL_ROOT_PASSWORD=sympress-db-test-only --env MYSQL_ROOT_HOST=% \
        "$db_image" --innodb-buffer-pool-size=512M
    # PHP variables must not be expanded by the host shell.
    # shellcheck disable=SC2016
    stage "Waiting for $current readiness" "$run_dir/$current/readiness.log" \
        docker exec --env NGINX_TEST_DB_HOST=task-db:3306 "$php_container" php -r '
            for ($attempt = 1; $attempt <= 120; $attempt++) {
                try { new PDO("mysql:host=task-db;port=3306", "root", "sympress-db-test-only"); exit(0); }
                catch (PDOException) { echo "Waiting for database ($attempt/120)\n"; sleep(1); }
            }
            fwrite(STDERR, "Database was not ready after 120 seconds.\n"); exit(1);
        '
    run_timed docker exec "$php_container" sh -eu -c 'mkdir -p build; rm -f build/scale-report.json'
    active_engine=$current
    benchmark_status=0
    if stage "Running full $current benchmark" "$run_dir/$current/scale.log" \
        docker exec --env NGINX_TEST_DB_HOST=task-db:3306 \
        --env NGINX_TEST_DB_NAME=sympress_review_nginx_scale_local \
        --env NGINX_TEST_DB_USER=root --env NGINX_TEST_DB_PASSWORD=sympress-db-test-only \
        --env NGINX_TEST_WORDPRESS_DIR=/wordpress \
        "$php_container" php tests/Integration/scale.php; then
        printf '%s benchmark passed.\n' "$current"
    else
        benchmark_status=$?
    fi
    if save_report; then
        printf 'Report: %s/%s/scale-report.json\n' "$run_dir" "$current"
    elif ((benchmark_status == 0)); then
        printf 'Benchmark produced no report.\n' >&2
        benchmark_status=1
    fi
    run_timed docker logs "$db_container" >"$run_dir/$current/database.log" 2>&1
    run_timed docker rm --force --volumes "$db_container" >/dev/null
    active_engine=
    if ((benchmark_status != 0 && overall_status == 0)); then overall_status=$benchmark_status; fi
done
exit "$overall_status"
