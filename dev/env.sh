# Sourced by the dev scripts: maps a WHMCS version to its PHP version, port
# and compose project, so two versions can run side by side.
#
#   9.x    PHP 8.3, http://localhost:8090
#   8.13.x PHP 8.2, http://localhost:8091   (ionCube-encoded for PHP <= 8.2)

DEV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

whmcs_env() {
    WHMCS_VERSION="${1:-}"

    case "$WHMCS_VERSION" in
        9.*)    PHP_VERSION=8.3; WHMCS_PORT=8090 ;;
        8.13.*) PHP_VERSION=8.2; WHMCS_PORT=8091 ;;
        *)
            echo "Unknown WHMCS version '$WHMCS_VERSION'. Installed: $(ls "$DEV_DIR/whmcs" 2>/dev/null | tr '\n' ' ')" >&2
            exit 2
            ;;
    esac

    if [ ! -d "$DEV_DIR/whmcs/$WHMCS_VERSION" ]; then
        echo "WHMCS $WHMCS_VERSION is not set up. Run: ./setup.sh <whmcs zip>" >&2
        exit 2
    fi

    PROJECT="whmcs-${WHMCS_VERSION//./}"
    HOST_UID="$(id -u)"
    HOST_GID="$(id -g)"
    export WHMCS_VERSION PHP_VERSION WHMCS_PORT HOST_UID HOST_GID
}

dc() {
    docker compose -p "$PROJECT" -f "$DEV_DIR/docker-compose.yml" "$@"
}

# mysql in the db container; credentials are the dev ones in docker-compose.yml.
db_query() {
    dc exec -T db mysql --default-character-set=utf8mb4 -uwhmcs -pwhmcs whmcs -N -e "$1" 2>/dev/null
}

# Points the module files of a WHMCS tree at the repository, mounted at
# /opt/khipu-module inside the container (see docker-compose.yml).
link_module() {
    local gateways="$1/modules/gateways"

    rm -rf "$gateways/khipu.php" "$gateways/khipu" \
        "$gateways/callback/khipu.php" "$gateways/callback/khipu_redirect.php"
    ln -s /opt/khipu-module/khipu.php "$gateways/khipu.php"
    ln -s /opt/khipu-module/khipu "$gateways/khipu"
}
