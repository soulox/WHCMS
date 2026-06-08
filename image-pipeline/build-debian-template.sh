#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKER_DIR="${SCRIPT_DIR}/packer"
TEMPLATE_FILE="${PACKER_DIR}/debian12-golden.pkr.hcl"
DEFAULT_VARS_FILE="${PACKER_DIR}/debian12.auto.pkrvars.hcl"
EXAMPLE_VARS_FILE="${PACKER_DIR}/example.auto.pkrvars.hcl"
VALIDATE_ONLY=false

if [[ "${1:-}" == "--validate-only" ]]; then
  VALIDATE_ONLY=true
fi

if [[ ! -f "${TEMPLATE_FILE}" ]]; then
  echo "Packer template not found: ${TEMPLATE_FILE}" >&2
  exit 1
fi

if [[ -f "${DEFAULT_VARS_FILE}" ]]; then
  VARS_ARG=("-var-file=${DEFAULT_VARS_FILE}")
elif [[ "${VALIDATE_ONLY}" == "true" && -f "${EXAMPLE_VARS_FILE}" ]]; then
  VARS_ARG=("-var-file=${EXAMPLE_VARS_FILE}")
else
  echo "Missing ${DEFAULT_VARS_FILE}."
  echo "Copy ${PACKER_DIR}/example.auto.pkrvars.hcl to ${DEFAULT_VARS_FILE} and fill in secrets."
  exit 1
fi

echo "==> packer init"
packer init "${TEMPLATE_FILE}"

echo "==> packer validate"
packer validate "${VARS_ARG[@]}" "${TEMPLATE_FILE}"

if [[ "${VALIDATE_ONLY}" == "true" ]]; then
  echo "Validation completed. Skipping build (--validate-only)."
  exit 0
fi

echo "==> packer build"
packer build "${VARS_ARG[@]}" "${TEMPLATE_FILE}"
