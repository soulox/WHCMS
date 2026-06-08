#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

VARS_FILES=(
  "${SCRIPT_DIR}/packer/debian12.auto.pkrvars.hcl"
  "${SCRIPT_DIR}/packer/ubuntu2204.auto.pkrvars.hcl"
  "${SCRIPT_DIR}/packer/ubuntu2404.auto.pkrvars.hcl"
  "${SCRIPT_DIR}/packer/almalinux9.auto.pkrvars.hcl"
  "${SCRIPT_DIR}/packer/rocky9.auto.pkrvars.hcl"
  "${SCRIPT_DIR}/packer/centosstream9.auto.pkrvars.hcl"
)

VALIDATE_ONLY=false
if [[ "${1:-}" == "--validate-only" ]]; then
  VALIDATE_ONLY=true
fi

for vars_file in "${VARS_FILES[@]}"; do
  echo
  echo "===== Building with $(basename "${vars_file}") ====="
  if [[ "${VALIDATE_ONLY}" == "true" ]]; then
    "${SCRIPT_DIR}/build-template.sh" "${vars_file}" --validate-only
  else
    "${SCRIPT_DIR}/build-template.sh" "${vars_file}"
  fi
done
