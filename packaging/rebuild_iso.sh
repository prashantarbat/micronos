#!/bin/bash
set -euo pipefail

BUILD_DIR="/root/micronos-builder"
REPO_DIR="/mnt/d/laragon/www/micronos/micronos"
OUTPUT_DIR="/mnt/d/laragon/www/micronos"
ISO_NAME="micronos-0.1.23-amd64.hybrid.iso"
ISO_DEST="${OUTPUT_DIR}/${ISO_NAME}"

echo "=== 1. Syncing build configs, bootloaders, and templates from repo ==="
mkdir -p "${BUILD_DIR}/config/bootloaders"
cp -rf "${REPO_DIR}/packaging/live-build/config/bootloaders/"* "${BUILD_DIR}/config/bootloaders/"
cp -rf "${REPO_DIR}/packaging/live-build/config/hooks" "${BUILD_DIR}/config/"
cp -rf "${REPO_DIR}/packaging/live-build/config/package-lists" "${BUILD_DIR}/config/"
cp -f "${REPO_DIR}/packaging/live-build/auto/config" "${BUILD_DIR}/auto/config"
cp -f "${REPO_DIR}/packaging/live-build/stage-overlay.sh" "${BUILD_DIR}/stage-overlay.sh"

# Remove any old ISO inside repo root to ensure ISO is ONLY in D:\laragon\www\micronos
rm -f "${REPO_DIR}/${ISO_NAME}" "${REPO_DIR}/micronos-2.0.0-amd64.hybrid.iso" "${OUTPUT_DIR}/micronos-2.0.0-amd64.hybrid_.iso"

echo "=== 2. Cleaning old binary stage ==="
cd "${BUILD_DIR}"
lb clean

echo "=== 3. Staging appliance overlay ==="
bash "${BUILD_DIR}/stage-overlay.sh" --src "${REPO_DIR}" --out "${BUILD_DIR}/config/includes.chroot"

echo "=== 4. Running lb config ==="
auto/config

echo "=== 5. Running lb build ==="
lb build 2>&1 | tee "${BUILD_DIR}/build_new_iso.log"

echo "=== 6. Outputting ISO to ${OUTPUT_DIR} ==="
if [ -f "${BUILD_DIR}/live-image-amd64.hybrid.iso" ]; then
    cp -f "${BUILD_DIR}/live-image-amd64.hybrid.iso" "${ISO_DEST}"
    echo "SUCCESS: Micron OS ISO created at: ${ISO_DEST}"
    ls -lh "${ISO_DEST}"
else
    echo "ERROR: live-image-amd64.hybrid.iso not found!"
    exit 1
fi
