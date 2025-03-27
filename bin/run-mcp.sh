#!/bin/bash

# shamefully stolen from
#https://jolicode.com/blog/mcp-the-open-protocol-that-turns-llm-chatbots-into-intelligent-agents

# Check if BASE environment variable is set
if [ -z "$BASE" ]; then
  echo "Error: BASE environment variable is not set. Please set it before running this script." >&2
  exit 1
fi

set -e
set -o pipefail

mkdir -p "$BASE/var/mcp"
date >> "$BASE/var/mcp/run.log"

stdin_log="$BASE/var/mcp/stdin.log"
stdout_log="$BASE/var/mcp/stdout.log"
stderr_log="$BASE/var/mcp/stderr.log"

tee -a "$stdin_log" | \
  $BASE/bin/console mcp:server:run > >(tee -a "$stdout_log") 2> >(tee -a "$stderr_log" >&2)