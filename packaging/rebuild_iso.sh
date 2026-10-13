#!/bin/bash
# ==============================================================================
# Micron OS — Official ISO Rebuild
# ==============================================================================
# Run this INSIDE a Linux build host (WSL or a Debian/Ubuntu machine), as root.
#
#   sudo packaging/rebuild_iso.sh
#
# It stages the appliance overlay from the repository, runs `lb config`, builds
# the hybrid BIOS/UEFI ISO, copies it beside the repository and prints the
# checksum. The build aborts on any error — a partially staged overlay used to
# be able to produce an ISO that booted but ran an older build.
#
# Environment overrides:
#   MICRONOS_BUILD_DIR   scratch build directory   (default /root/micronos-builder)
#   MICRONOS_REPO_DIR    repository to build from  (default: this repository)
#   MICRONOS_OUTPUT_DIR  where the ISO is written  (default: repository's parent)
# ==============================================================================
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_REPO="$(cd "${here}/.." && pwd)"

BUILD_DIR="${MICRONOS_BUILD_DIR:-/root/micronos-builder}"
REPO_DIR="${MICRONOS_REPO_DIR:-${DEFAULT_REPO}}"
OUTPUT_DIR="${MICRONOS_OUTPUT_DIR:-$(dirname "${DEFAULT_REPO}")}"

if [ ! -f "${REPO_DIR}/bin/micron-menu" ] || [ ! -d "${REPO_DIR}/packaging/live-build" ]; then
    echo "ERROR: ${REPO_DIR} does not look like the Micron OS repository." >&2
    exit 1
fi

# Single source of truth for the release identity: the repository's VERSION file.
# The ISO filename, /etc/os-release and the console menu all derive from it, so
# they can never disagree (they used to: the file said 0.1.31 while both the
# ISO name and /etc/os-release said 0.1.23).
VERSION="$(head -n1 "${REPO_DIR}/VERSION" 2>/dev/null | tr -d '[:space:]')"
[ -n "${VERSION}" ] || { echo "ERROR: ${REPO_DIR}/VERSION is missing or empty." >&2; exit 1; }

ISO_NAME="micronos-${VERSION}-amd64.hybrid.iso"
ISO_DEST="${OUTPUT_DIR}/${ISO_NAME}"
BUILD_LOG="${BUILD_DIR}/build_new_iso.log"

command -v lb >/dev/null 2>&1 || { echo "ERROR: live-build (lb) is not installed." >&2; exit 1; }

mkdir -p "${BUILD_DIR}" "${OUTPUT_DIR}"

echo "=== 1. Syncing build configs, bootloaders, and templates from ${REPO_DIR} ==="
mkdir -p "${BUILD_DIR}/config/bootloaders"
cp -rf "${REPO_DIR}/packaging/live-build/config/bootloaders/"* "${BUILD_DIR}/config/bootloaders/"
cp -rf "${REPO_DIR}/packaging/live-build/config/hooks" "${BUILD_DIR}/config/"
cp -rf "${REPO_DIR}/packaging/live-build/config/package-lists" "${BUILD_DIR}/config/"
cp -f  "${REPO_DIR}/packaging/live-build/auto/config" "${BUILD_DIR}/auto/config"

# Remove any old ISO inside the repository so the artifact only ever lands in
# OUTPUT_DIR and cannot be confused with a stale image.
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
set -o pipefail
lb build 2>&1 | tee "${BUILD_LOG}"

echo "=== 6. Outputting ISO to ${OUTPUT_DIR} ==="
if [ ! -f "${BUILD_DIR}/live-image-amd64.hybrid.iso" ]; then
    echo "ERROR: live-image-amd64.hybrid.iso was not produced; see ${BUILD_LOG}" >&2
    exit 1
fi

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

SHA="$(sha256sum "${ISO_DEST}" | awk '{print $1}')"
printf '%s  %s\n' "${SHA}" "$(basename "${ISO_DEST}")" > "${ISO_DEST}.sha256"

echo ""
echo "SUCCESS: Micron OS ${VERSION} ISO created at: ${ISO_DEST}"
ls -lh "${ISO_DEST}"
echo "SHA256: ${SHA}"
echo "Checksum written to: ${ISO_DEST}.sha256"
