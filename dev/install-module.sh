#!/usr/bin/env bash
#
# Chooses how the Khipu module is installed in a local WHMCS.
#
#   ./install-module.sh 9.0.9 repo            live from the repository (the default)
#   ./install-module.sh 9.0.9 zip [file.zip]  as merchants do: the release zip
#                                             extracted at the WHMCS root
#                                             (default ../dist/whmcs-khipu.zip, built if missing)
#   ./install-module.sh 9.0.9 git <ref>       an old release from git history, in
#                                             its own layout (e.g. aec2d01 for 2.6)
#
# zip installs over whatever is there, like a merchant upgrading: switch to
# "git aec2d01" first to test the 2.x upgrade. The gateway's settings in the
# database are left alone.
#
# Files are written from inside the container. Docker Desktop's file sharing
# can keep serving a path's previous target (a symlink after it became a file,
# even to a new container) when the change is made on the host.

set -euo pipefail

source "$(dirname "$0")/env.sh"
whmcs_env "${1:-}"
mode="${2:-}"
tree="$DEV_DIR/whmcs/$WHMCS_VERSION"
gateways=/var/www/html/modules/gateways

case "$mode" in
    repo|zip|git) ;;
    *)
        echo "Usage: $0 <version> repo | zip [file.zip] | git <ref>" >&2
        exit 2
        ;;
esac

if [ "$mode" = "git" ] && [ -z "${3:-}" ]; then
    echo "Usage: $0 <version> git <ref>" >&2
    exit 2
fi

# Everything the switch needs is checked before the current install is touched.
payload=$(mktemp)
trap 'rm -f "$payload"' EXIT

if [ "$mode" = "zip" ]; then
    if [ -n "${3:-}" ]; then
        zip="$3"
        [ -f "$zip" ] || { echo "No such zip: $zip" >&2; exit 1; }
    else
        zip="$DEV_DIR/../dist/whmcs-khipu.zip"
        [ -f "$zip" ] || "$DEV_DIR/../package.sh" >/dev/null
    fi
    if ! unzip -tq "$zip" >/dev/null || ! unzip -l "$zip" | grep -q ' modules/gateways/khipu\.php$'; then
        echo "$zip is not a Khipu module zip (no modules/gateways/khipu.php, or damaged)." >&2
        exit 1
    fi
    cp "$zip" "$payload"
fi

if [ "$mode" = "git" ]; then
    # Only 2.x releases that ship vendor/ are self-contained: older ones need a
    # git submodule this cannot materialise, and 3.x is installed with "zip".
    for path in khipu.php callback vendor; do
        git -C "$DEV_DIR/.." cat-file -e "$3:$path" 2>/dev/null \
            || { echo "$3 has no $path: use a 2.x release with vendor/ (e.g. aec2d01 for 2.6), or zip for 3.x." >&2; exit 1; }
    done
    git -C "$DEV_DIR/.." archive "$3" khipu.php callback vendor > "$payload"
fi

# As you, not root: on Docker Engine (unlike Docker Desktop) root-owned files
# in the mounted tree could not be edited or deleted from the host.
in_container() {
    dc exec -T -u "$HOST_UID:$HOST_GID" whmcs sh -c "$1"
}

# The callbacks come from the tree for zip and git, from the repository for
# repo. Switch the mount first, so the files below land where WHMCS reads them.
echo "${mode}${3:+ $3}" > "$tree/.khipu-module"
whmcs_env "$WHMCS_VERSION"
dc up -d --force-recreate whmcs >/dev/null 2>&1

case "$mode" in
    repo)
        in_container "rm -rf $gateways/khipu.php $gateways/khipu \
            && ln -s /opt/khipu-module/khipu.php $gateways/khipu.php \
            && ln -s /opt/khipu-module/khipu $gateways/khipu"
        ;;
    zip)
        # Only repo mode's symlinks go; files of an older release stay, as on a real upgrade.
        in_container "for p in $gateways/khipu.php $gateways/khipu; do [ -L \$p ] && rm -f \$p; true; done"
        dc exec -T -u "$HOST_UID:$HOST_GID" whmcs sh -c 'cat > /tmp/khipu-module.zip && unzip -q -o /tmp/khipu-module.zip -d /var/www/html && rm /tmp/khipu-module.zip' < "$payload"
        ;;
    git)
        # 2.x zips held khipu.php, callback/ and vendor/, extracted into modules/gateways.
        in_container "rm -rf $gateways/khipu.php $gateways/khipu $gateways/callback/khipu.php $gateways/callback/khipu_redirect.php"
        dc exec -T -u "$HOST_UID:$HOST_GID" whmcs tar -x --no-same-owner -C "$gateways" < "$payload"
        ;;
esac

echo "Khipu module in WHMCS $WHMCS_VERSION: $(cat "$tree/.khipu-module")"
