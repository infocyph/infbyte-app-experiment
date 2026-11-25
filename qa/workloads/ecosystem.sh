#!/usr/bin/env bash
set -euo pipefail
case "$SCENARIO" in
  ecosystem-core-lifecycle) bash "$GITHUB_WORKSPACE/qa/workloads/core-lifecycle.sh" ;;
  ecosystem-*) php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log" ;;
  *) echo "Unknown ecosystem scenario" >&2; exit 1 ;;
esac
