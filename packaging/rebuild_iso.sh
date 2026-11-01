#!/bin/bash
# ==============================================================================
# Micron OS — Official ISO Rebuild
# ==============================================================================
# Run this INSIDE a Linux build host (WSL or a Debian/Ubuntu machine), as root.
#
#   sudo packaging/rebuild_iso.sh
#
# Options:
#   --bump              Force incrementing patch version in VERSION file before building
#   --no-bump           Do not increment version; build with current version in VERSION
#   --version <ver>     Build with specified version and update VERSION file
#   -h, --help          Show help
#
# Behavior:
#   - Versioning: Increments patch version (e.g. 0.0.41 -> 0.0.42) automatically
#   - Integrity Checksums: Generates a single .checksums.txt file containing
#     MD5, SHA1, and SHA2 (SHA256, SHA512) hashes for the ISO.
#
# Environment overrides:
#   MICRONOS_BUILD_DIR   scratch build directory   (default /root/micronos-builder)
#   MICRONOS_REPO_DIR    repository to build from  (default: this repository)
#   MICRONOS_OUTPUT_DIR  where the ISO is written  (default: repository's parent)
#   MICRONOS_AUTO_BUMP   bump policy: 'auto' (default), 'always', 'never'
# ==============================================================================
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_REPO="$(cd "${here}/.." && pwd)"
if [ ! -f "${DEFAULT_REPO}/bin/micron-menu" ] && [ -d "/mnt/d/laragon/www/micronos/micronos" ]; then
    DEFAULT_REPO="/mnt/d/laragon/www/micronos/micronos"
fi

BUILD_DIR="${MICRONOS_BUILD_DIR:-/root/micronos-builder}"
REPO_DIR="${MICRONOS_REPO_DIR:-${DEFAULT_REPO}}"
OUTPUT_DIR="${MICRONOS_OUTPUT_DIR:-$(dirname "${REPO_DIR}")}"

if [ ! -f "${REPO_DIR}/bin/micron-menu" ] || [ ! -d "${REPO_DIR}/packaging/live-build" ]; then
    echo "ERROR: ${REPO_DIR} does not look like the Micron OS repository." >&2
    exit 1
fi

# The builder keeps its own copy (/root/micronos-builder/rebuild_iso.sh), and
# that copy is what people run. Without this it never picked up a fix made in
# the repository: when the repository's script differs, install it over this
# copy (as LF) and run it instead.
self_path="$(realpath "${BASH_SOURCE[0]}")"
repo_script="${REPO_DIR}/packaging/rebuild_iso.sh"
if [ -z "${MICRONOS_REBUILD_REEXEC:-}" ] && [ -f "${repo_script}" ] \
    && [ "${self_path}" != "$(realpath "${repo_script}")" ] \
    && ! tr -d '\r' < "${repo_script}" | cmp -s - "${self_path}"; then
    echo "=== Updating ${self_path} from ${repo_script} ==="
    tr -d '\r' < "${repo_script}" > "${self_path}.new" && chmod 0755 "${self_path}.new" && mv -f "${self_path}.new" "${self_path}"
    MICRONOS_REBUILD_REEXEC=1 exec bash "${self_path}" "$@"
fi

bump_mode="${MICRONOS_AUTO_BUMP:-auto}" # 'auto', 'always', 'never'
explicit_version=""

while [ $# -gt 0 ]; do
    case "$1" in
        --bump)
            bump_mode="always"
            shift
            ;;
        --no-bump)
            bump_mode="never"
            shift
            ;;
        --version)
            [ -n "${2:-}" ] || { echo "ERROR: --version requires a version argument (e.g. 0.0.41)" >&2; exit 2; }
            explicit_version="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: sudo packaging/rebuild_iso.sh [--bump | --no-bump | --version <version>]"
            echo ""
            echo "Options:"
            echo "  --bump           Force incrementing patch version in VERSION file before building"
            echo "  --no-bump        Do not increment version; build with current version in VERSION"
            echo "  --version <ver>  Build with specified version and update VERSION file"
            echo "  -h, --help       Show this help"
            exit 0
            ;;
        *)
            echo "ERROR: Unknown option: $1" >&2
            exit 2
            ;;
    esac
done

# 0.1.41 -> 0.1.42; a pre-release counts up its own number instead:
# 1.0.0-rc.1 -> 1.0.0-rc.2. Anything else is refused, so a rebuild can never
# reuse a version and overwrite the ISO already built for it.
bump_version() {
    local v="$1"
    if [[ "$v" =~ ^([0-9]+\.[0-9]+\.[0-9]+-[0-9A-Za-z-]+\.)([0-9]+)$ ]]; then
        echo "${BASH_REMATCH[1]}$((BASH_REMATCH[2] + 1))"
    elif [[ "$v" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
        echo "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.$((BASH_REMATCH[3] + 1))"
    else
        echo "ERROR: cannot work out the next version after '${v}'; use --version or --no-bump" >&2
        return 1
    fi
}

update_os_release() {
    local ver="$1"
    local os_rel="${REPO_DIR}/system/os-release"
    mkdir -p "$(dirname "${os_rel}")"
    cat > "${os_rel}" << OSRELEOF
PRETTY_NAME="Micron OS ${ver} (amd64)"
NAME="Micron OS"
VERSION_ID="${ver}"
VERSION="${ver} (amd64)"
VERSION_CODENAME=trixie
ID=micronos
ID_LIKE=debian
HOME_URL="https://micronos.local/"
SUPPORT_URL="https://micronos.local/support"
BUG_REPORT_URL="https://micronos.local/bugs"
OSRELEOF
}

# Single source of truth for release identity
CURRENT_VERSION="$(head -n1 "${REPO_DIR}/VERSION" 2>/dev/null | tr -d '[:space:]')"
[ -n "${CURRENT_VERSION}" ] || CURRENT_VERSION="0.0.41"

if [ -n "${explicit_version}" ]; then
    VERSION="${explicit_version}"
    echo "${VERSION}" > "${REPO_DIR}/VERSION"
    update_os_release "${VERSION}"
    echo "=== Version explicitly set to: ${VERSION} ==="
elif [ "${bump_mode}" = "always" ]; then
    VERSION="$(bump_version "${CURRENT_VERSION}")"
    echo "${VERSION}" > "${REPO_DIR}/VERSION"
    update_os_release "${VERSION}"
    echo "=== Version incremented: ${CURRENT_VERSION} -> ${VERSION} ==="
elif [ "${bump_mode}" = "never" ]; then
    VERSION="${CURRENT_VERSION}"
    update_os_release "${VERSION}"
    echo "=== Building with current version: ${VERSION} (--no-bump) ==="
else
    # 'auto' mode: if ISO for CURRENT_VERSION already exists in OUTPUT_DIR or its folder, auto-increment
    EXISTING_ISO="${OUTPUT_DIR}/micronos-${CURRENT_VERSION}-amd64.hybrid/micronos-${CURRENT_VERSION}-amd64.hybrid.iso"
    if [ ! -f "${EXISTING_ISO}" ]; then
        EXISTING_ISO="${OUTPUT_DIR}/micronos-${CURRENT_VERSION}-amd64.hybrid.iso"
    fi
    if [ -f "${EXISTING_ISO}" ]; then
        VERSION="$(bump_version "${CURRENT_VERSION}")"
        echo "${VERSION}" > "${REPO_DIR}/VERSION"
        update_os_release "${VERSION}"
        echo "=== Existing ISO found for ${CURRENT_VERSION}. Incrementing to next version: ${VERSION} ==="
    else
        VERSION="${CURRENT_VERSION}"
        echo "${VERSION}" > "${REPO_DIR}/VERSION"
        update_os_release "${VERSION}"
        echo "=== Building initial ISO for version: ${VERSION} ==="
    fi
fi

ISO_NAME="micronos-${VERSION}-amd64.hybrid.iso"
FOLDER_NAME="${ISO_NAME%.iso}"
TARGET_DIR="${OUTPUT_DIR}/${FOLDER_NAME}"
ISO_DEST="${TARGET_DIR}/${ISO_NAME}"
BUILD_LOG="${BUILD_DIR}/build_new_iso.log"

command -v lb >/dev/null 2>&1 || { echo "ERROR: live-build (lb) is not installed." >&2; exit 1; }

mkdir -p "${BUILD_DIR}" "${OUTPUT_DIR}" "${TARGET_DIR}"

# A Windows checkout can carry CRLF line endings (an old clone, an editor, a
# tool that ignores .gitattributes). bash then fails with "set: pipefail\r" or
# "/bin/bash^M: bad interpreter". Everything staged into the image comes from
# these folders, so normalise them to LF before anything runs. The stored git
# blobs are LF already, so git sees no change.
crlf_files=$(cd "${REPO_DIR}" && grep -rlI $'\r' packaging installer system bin app/Commands spark 2>/dev/null | grep -vE '\.(ps1|bat|cmd)$' || true)
if [ -n "${crlf_files}" ]; then
    echo "=== 0. Converting $(printf '%s\n' "${crlf_files}" | wc -l) file(s) from CRLF to LF in ${REPO_DIR} ==="
    (cd "${REPO_DIR}" && printf '%s\n' "${crlf_files}" | while IFS= read -r f; do sed -i 's/\r$//' "$f"; done)
fi

echo "=== 1. Syncing build configs, bootloaders, and templates from ${REPO_DIR} ==="
mkdir -p "${BUILD_DIR}/config/bootloaders"
cp -rf "${REPO_DIR}/packaging/live-build/config/bootloaders/"* "${BUILD_DIR}/config/bootloaders/"
cp -rf "${REPO_DIR}/packaging/live-build/config/hooks" "${BUILD_DIR}/config/"
cp -rf "${REPO_DIR}/packaging/live-build/config/package-lists" "${BUILD_DIR}/config/"
cp -f  "${REPO_DIR}/packaging/live-build/auto/config" "${BUILD_DIR}/auto/config"
if [ "$(realpath "$0" 2>/dev/null || true)" != "$(realpath "${BUILD_DIR}/rebuild_iso.sh" 2>/dev/null || true)" ]; then
    cp -f "${REPO_DIR}/packaging/rebuild_iso.sh" "${BUILD_DIR}/rebuild_iso.sh" 2>/dev/null || true
fi

# Remove any old ISO inside the repository so the artifact only ever lands in
# TARGET_DIR and cannot be confused with a stale image.
rm -f "${REPO_DIR}/micronos-"*"-amd64.hybrid.iso"

echo "=== 2. Cleaning old binary stage ==="
cd "${BUILD_DIR}"
lb clean

# Stage the overlay from the repository. `auto/config` also does this, but only
# a fresh full (non `noauto`) run picks that up, so do it unconditionally here.
echo "=== 3. Staging appliance overlay from ${REPO_DIR} (version ${VERSION}) ==="
bash "${REPO_DIR}/packaging/live-build/stage-overlay.sh" \
    --src "${REPO_DIR}" \
    --out "${BUILD_DIR}/config/includes.chroot"

echo "=== 4. Running lb config ==="
MICRONOS_SRC="${REPO_DIR}" auto/config

echo "=== 5. Running lb build (log: ${BUILD_LOG}) ==="
# Debian mirrors are briefly inconsistent while they sync ("Hash Sum
# mismatch") and networks drop downloads. Those are retried with fresh package
# lists; any other failure stops the build at once.
set -o pipefail
attempts="${MICRONOS_BUILD_ATTEMPTS:-4}"
attempt=1
until lb build 2>&1 | tee "${BUILD_LOG}"; do
    if [ "${attempt}" -ge "${attempts}" ] \
        || ! grep -qE 'Hash Sum mismatch|Failed to fetch|Temporary failure resolving|Could not (connect|resolve)|Connection (failed|timed out|reset)|File has unexpected size' "${BUILD_LOG}"; then
        echo "ERROR: lb build failed (attempt ${attempt}/${attempts}); see ${BUILD_LOG}" >&2
        exit 1
    fi
    wait_s=$((attempt * 120))
    echo "=== Debian download problem (attempt ${attempt}/${attempts}); retrying in ${wait_s} s with fresh package lists ==="
    rm -rf "${BUILD_DIR}/chroot/var/lib/apt/lists/"* 2>/dev/null || true
    sleep "${wait_s}"
    attempt=$((attempt + 1))
done

echo "=== 6. Outputting ISO to ${TARGET_DIR} ==="
if [ ! -f "${BUILD_DIR}/live-image-amd64.hybrid.iso" ]; then
    echo "ERROR: live-image-amd64.hybrid.iso was not produced; see ${BUILD_LOG}" >&2
    exit 1
fi

# An ISO already built for this version is kept, never overwritten: it is
# moved aside first (it may be the one uploaded or under test).
if [ -f "${ISO_DEST}" ]; then
    previous="${TARGET_DIR}.previous-$(date +%Y%m%d-%H%M%S)"
    echo "=== Keeping the earlier ${ISO_NAME} in ${previous} ==="
    mv "${TARGET_DIR}" "${previous}"
fi
mkdir -p "${TARGET_DIR}"
cp -f "${BUILD_DIR}/live-image-amd64.hybrid.iso" "${ISO_DEST}"

echo "=== 7. Verifying the artifact ==="
# A build that exits 0 but produces an unreadable or non-hybrid image is worse
# than a failed build, so assert the shape of the result before declaring success.
if command -v isoinfo >/dev/null 2>&1; then
    isoinfo -d -i "${ISO_DEST}" >/dev/null || { echo "ERROR: ISO metadata is unreadable." >&2; exit 1; }
fi
if command -v xorriso >/dev/null 2>&1; then
    xorriso -indev "${ISO_DEST}" -report_el_torito plain 2>/dev/null | grep -q 'El Torito' \
        || echo "WARNING: no El Torito catalog found; verifying boot mode manually."
fi

echo "=== 8. Generating cryptographic checksums (MD5, SHA1, SHA2) ==="
ISO_BASENAME="$(basename "${ISO_DEST}")"
CHECKSUM_FILE="${TARGET_DIR}/${ISO_BASENAME}.checksums.txt"

# Clean up any legacy separate checksum files
rm -f "${TARGET_DIR}/${ISO_BASENAME}.md5.txt" "${TARGET_DIR}/${ISO_BASENAME}.sha1.txt" "${TARGET_DIR}/${ISO_BASENAME}.sha2.txt" "${TARGET_DIR}/${ISO_BASENAME}.sha256.txt" "${TARGET_DIR}/${ISO_BASENAME}.sha512.txt" "${TARGET_DIR}/${ISO_BASENAME}.md5" "${TARGET_DIR}/${ISO_BASENAME}.sha1"

# 1. MD5
if command -v md5sum >/dev/null 2>&1; then
    MD5_VAL="$(md5sum "${ISO_DEST}" | awk '{print $1}')"
else
    echo "WARNING: md5sum command not found; skipping MD5."
    MD5_VAL="N/A"
fi

# 2. SHA1
if command -v sha1sum >/dev/null 2>&1; then
    SHA1_VAL="$(sha1sum "${ISO_DEST}" | awk '{print $1}')"
else
    echo "WARNING: sha1sum command not found; skipping SHA1."
    SHA1_VAL="N/A"
fi

# 3. SHA2 (SHA256 & SHA512)
if command -v sha256sum >/dev/null 2>&1; then
    SHA256_VAL="$(sha256sum "${ISO_DEST}" | awk '{print $1}')"
else
    echo "WARNING: sha256sum command not found; skipping SHA256."
    SHA256_VAL="N/A"
fi

SHA512_VAL=""
if command -v sha512sum >/dev/null 2>&1; then
    SHA512_VAL="$(sha512sum "${ISO_DEST}" | awk '{print $1}')"
fi

# Write all checksums into a single TXT file
BUILD_TIME_UTC="$(date -u '+%Y-%m-%d %H:%M:%S UTC' 2>/dev/null || date)"
cat > "${CHECKSUM_FILE}" << CHKEOF
# ==============================================================================
# Micron OS ISO Checksums
# ==============================================================================
# File:      ${ISO_BASENAME}
# Version:   ${VERSION}
# Timestamp: ${BUILD_TIME_UTC}
# ==============================================================================

MD5:    ${MD5_VAL}
SHA1:   ${SHA1_VAL}
SHA2:   ${SHA256_VAL}
$( [ -n "${SHA512_VAL}" ] && echo "SHA512: ${SHA512_VAL}" )

# Standard verification checksums:
${MD5_VAL}  ${ISO_BASENAME}
${SHA1_VAL}  ${ISO_BASENAME}
${SHA256_VAL}  ${ISO_BASENAME}
$( [ -n "${SHA512_VAL}" ] && echo "${SHA512_VAL}  ${ISO_BASENAME}" )
CHKEOF

# Also keep backward-compatible .sha256 verification file
printf '%s  %s\n' "${SHA256_VAL}" "${ISO_BASENAME}" > "${TARGET_DIR}/${ISO_BASENAME}.sha256"

# Copy build log into the target directory
if [ -f "${BUILD_LOG}" ]; then
    cp -f "${BUILD_LOG}" "${TARGET_DIR}/build.log" 2>/dev/null || true
fi

echo ""
echo "=============================================================================="
echo " SUCCESS: Micron OS ${VERSION} ISO created at: ${ISO_DEST}"
echo " Directory: ${TARGET_DIR}"
echo "=============================================================================="
ls -lh "${TARGET_DIR}"
echo ""
echo "Cryptographic Checksums (Saved in single file: $(basename "${CHECKSUM_FILE}")):"
echo "  MD5:    ${MD5_VAL}"
echo "  SHA1:   ${SHA1_VAL}"
echo "  SHA2:   ${SHA256_VAL} (SHA256)"
if [ -n "${SHA512_VAL}" ]; then
    echo "  SHA512: ${SHA512_VAL}"
fi
echo ""
echo "Checksum file written to: ${CHECKSUM_FILE}"
echo "=============================================================================="
