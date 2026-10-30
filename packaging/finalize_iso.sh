#!/bin/bash
set -euo pipefail

REPO_DIR="/mnt/d/laragon/www/micronos/micronos"
OUTPUT_DIR="/mnt/d/laragon/www/micronos"
BUILD_DIR="/root/micronos-builder"

VERSION="$(head -n1 "${REPO_DIR}/VERSION" 2>/dev/null | tr -d '[:space:]')"
[ -n "${VERSION}" ] || VERSION="0.1.42"

ISO_NAME="micronos-${VERSION}-amd64.hybrid.iso"
FOLDER_NAME="${ISO_NAME%.iso}"
TARGET_DIR="${OUTPUT_DIR}/${FOLDER_NAME}"
ISO_DEST="${TARGET_DIR}/${ISO_NAME}"
CHECKSUM_FILE="${TARGET_DIR}/${ISO_NAME}.checksums.txt"

if [ ! -f "${BUILD_DIR}/live-image-amd64.hybrid.iso" ]; then
    echo "ERROR: ${BUILD_DIR}/live-image-amd64.hybrid.iso does not exist." >&2
    exit 1
fi

mkdir -p "${TARGET_DIR}"

echo "=== Copying live-image-amd64.hybrid.iso to ${ISO_DEST} ==="
cp -f "${BUILD_DIR}/live-image-amd64.hybrid.iso" "${ISO_DEST}"

echo "=== Generating Checksums (MD5, SHA1, SHA256, SHA512) ==="
ISO_BASENAME="$(basename "${ISO_DEST}")"

MD5_VAL="$(md5sum "${ISO_DEST}" | awk '{print $1}')"
SHA1_VAL="$(sha1sum "${ISO_DEST}" | awk '{print $1}')"
SHA256_VAL="$(sha256sum "${ISO_DEST}" | awk '{print $1}')"
SHA512_VAL="$(sha512sum "${ISO_DEST}" | awk '{print $1}')"

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
SHA256: ${SHA256_VAL}
SHA512: ${SHA512_VAL}

# Standard verification checksums:
${MD5_VAL}  ${ISO_BASENAME}
${SHA1_VAL}  ${ISO_BASENAME}
${SHA256_VAL}  ${ISO_BASENAME}
${SHA512_VAL}  ${ISO_BASENAME}
CHKEOF

printf '%s  %s\n' "${SHA256_VAL}" "${ISO_BASENAME}" > "${ISO_DEST}.sha256"

if [ -f "${BUILD_DIR}/build_new_iso.log" ]; then
    cp -f "${BUILD_DIR}/build_new_iso.log" "${TARGET_DIR}/build.log" 2>/dev/null || true
fi

echo "=== Artifacts generated in ${TARGET_DIR} ==="
echo "Folder:    ${TARGET_DIR}"
echo "ISO:       ${ISO_DEST}"
echo "Checksums: ${CHECKSUM_FILE}"
echo "SHA256:    ${ISO_DEST}.sha256"
ls -lh "${TARGET_DIR}"
