#!/usr/bin/env sh
set -eu

MIN_FREE_MB="${GPU_MIN_FREE_MB:-4096}"

if command -v nvidia-smi >/dev/null 2>&1; then
  free_mb=$(nvidia-smi --query-gpu=memory.free --format=csv,noheader,nounits 2>/dev/null | head -n 1 | tr -d ' ' || true)
  case "$free_mb" in
    ''|*[!0-9]*)
      exec "$@"
      ;;
  esac

  if [ "$free_mb" -lt "$MIN_FREE_MB" ]; then
    echo "gpu-guard: free VRAM ${free_mb}MB < ${MIN_FREE_MB}MB; disabling GPU for this process" >&2
    export CUDA_VISIBLE_DEVICES=""
    export NVIDIA_VISIBLE_DEVICES="void"
  fi
fi

exec "$@"
