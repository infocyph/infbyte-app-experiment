#!/usr/bin/env bash
set -euo pipefail
case "$SCENARIO" in
  ecosystem-redis-cluster|ecosystem-mongodb|ecosystem-s3|ecosystem-sftp) bash "$GITHUB_WORKSPACE/qa/workloads/remote-services.sh" ;;
  ecosystem-connected) bash "$GITHUB_WORKSPACE/qa/workloads/connected.sh" ;;
  ecosystem-core-lifecycle) bash "$GITHUB_WORKSPACE/qa/workloads/core-lifecycle.sh" ;;
  ecosystem-*) php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log" ;;
  *) echo "Unknown ecosystem scenario" >&2; exit 1 ;;
esac
