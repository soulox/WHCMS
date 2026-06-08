#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKER_DIR="${SCRIPT_DIR}/packer"
TEMPLATE_FILE="${PACKER_DIR}/debian12-golden.pkr.hcl"

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <vars-file> [--validate-only]" >&2
  exit 1
fi

VARS_FILE="$1"
VALIDATE_ONLY=false

if [[ "${2:-}" == "--validate-only" ]]; then
  VALIDATE_ONLY=true
fi

if [[ ! -f "${TEMPLATE_FILE}" ]]; then
  echo "Packer template not found: ${TEMPLATE_FILE}" >&2
  exit 1
fi

if [[ ! -f "${VARS_FILE}" ]]; then
  echo "Vars file not found: ${VARS_FILE}" >&2
  exit 1
fi

echo "==> packer init"
packer init "${TEMPLATE_FILE}"

echo "==> packer validate (${VARS_FILE})"
packer validate "-var-file=${VARS_FILE}" "${TEMPLATE_FILE}"

if [[ "${VALIDATE_ONLY}" == "true" ]]; then
  echo "Validation completed. Skipping build (--validate-only)."
  exit 0
fi

echo "==> packer build (${VARS_FILE})"
packer build "-var-file=${VARS_FILE}" "${TEMPLATE_FILE}"
