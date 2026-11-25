#!/usr/bin/env bash
set -euo pipefail
case "$SCENARIO" in
  ecosystem-redis-cluster|ecosystem-mongodb|ecosystem-s3|ecosystem-sftp) bash "$GITHUB_WORKSPACE/qa/workloads/remote-services.sh" ;;
  ecosystem-http-policy) bash "$GITHUB_WORKSPACE/qa/workloads/http-policy.sh" ;;
  ecosystem-endurance) bash "$GITHUB_WORKSPACE/qa/workloads/endurance.sh" ;;
  ecosystem-closure)
    cd "$PROJECT"
    composer require opis/closure:^4.5 --no-interaction --prefer-dist --no-progress
    composer check-platform-reqs --no-dev
    composer show --format=json > "$EVIDENCE/packages.json"
    php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" closure | tee "$EVIDENCE/ecosystem.log" ;;
  ecosystem-connected) bash "$GITHUB_WORKSPACE/qa/workloads/connected.sh" ;;
  ecosystem-core-lifecycle) bash "$GITHUB_WORKSPACE/qa/workloads/core-lifecycle.sh" ;;
  ecosystem-*) php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log" ;;
  *) echo "Unknown ecosystem scenario" >&2; exit 1 ;;
esac
