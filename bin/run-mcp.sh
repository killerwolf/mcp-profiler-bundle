#!/bin/bash

# shamefully stolen from
#https://jolicode.com/blog/mcp-the-open-protocol-that-turns-llm-chatbots-into-intelligent-agents

# Auto-detect BASE environment variable if not set
if [ -z "$BASE" ]; then
  # Get the real path of the script
  SCRIPT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
  
  # Check if running from vendor/bin or original location
  if [[ "$SCRIPT_PATH" == *"/vendor/bin/"* ]]; then
    # Running from vendor/bin, BASE is two directories up
    BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
  elif [[ "$SCRIPT_PATH" == *"/vendor/killerwolf/mcp-profiler-bundle/bin/"* ]]; then
    # Running from original location, BASE is four directories up
    BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
  else
    # Fallback - try to find the project root by looking for composer.json
    CURRENT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    while [ "$CURRENT_DIR" != "/" ]; do
      if [ -f "$CURRENT_DIR/composer.json" ]; then
        BASE="$CURRENT_DIR"
        break
      fi
      CURRENT_DIR="$(dirname "$CURRENT_DIR")"
    done
  fi
  
  # Check if BASE was successfully determined
  if [ -z "$BASE" ]; then
    echo "Error: Could not automatically determine the project root directory." >&2
    echo "Please set the BASE environment variable manually." >&2
    exit 1
  fi
  
  #echo "Automatically set BASE to: $BASE"
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