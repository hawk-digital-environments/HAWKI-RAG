#!/bin/sh
# Entrypoint for the "ollama" service of the production stack.
#
# Combines what the development stack splits into "gpu_guard.sh" plus the
# "make up-core" model loop, so every start of the container (deploy.sh,
# Dokploy, a plain "docker compose up") leaves the server running AND the
# configured models present:
#
#   1. Disable GPU usage for this process when the free VRAM reported by
#      nvidia-smi drops below GPU_MIN_FREE_MB, so inference degrades to CPU
#      instead of starving other GPU tenants on a shared host.
#   2. Start "ollama" (with the given arguments) in the background.
#   3. Once the API answers, pull every model listed in
#      HAWKI_RAG_OLLAMA_MODELS. Pulls are cheap when a model is already
#      present in the volume; failures are reported but never stop the server.
#   4. Wait on the server process and exit with its status. Stop signals are
#      forwarded, so "docker stop" shuts the server down gracefully instead of
#      waiting for the grace period and SIGKILL.
#
# Expects the ollama image: the "ollama" binary on PATH, "serve" as the
# command. GPU_MIN_FREE_MB and HAWKI_RAG_OLLAMA_MODELS arrive through the
# environment file of the service.

set -uf

MIN_FREE_MB="${GPU_MIN_FREE_MB:-4096}"
MODELS="${HAWKI_RAG_OLLAMA_MODELS:-bge-m3 llama3.1:8b llama3.2:1b qwen2.5vl:7b}"

# --- 1. VRAM guard -----------------------------------------------------------

case "$MIN_FREE_MB" in
    *[!0-9]*)
        echo "ollama-entrypoint: GPU_MIN_FREE_MB must be a non-negative integer" >&2
        exit 1
        ;;
esac

if command -v nvidia-smi >/dev/null 2>&1; then
    free_mb=$(nvidia-smi --query-gpu=memory.free --format=csv,noheader,nounits 2>/dev/null | head -n 1 | tr -d ' ' || true)
    case "$free_mb" in
        ''|*[!0-9]*) ;;
        *)
            if [ "$free_mb" -lt "$MIN_FREE_MB" ]; then
                echo "ollama-entrypoint: free VRAM ${free_mb}MB < ${MIN_FREE_MB}MB; disabling GPU for this process" >&2
                export CUDA_VISIBLE_DEVICES=""
                export NVIDIA_VISIBLE_DEVICES=void
                export OLLAMA_VULKAN=0
            fi
            ;;
    esac
fi

# --- 2. Server ----------------------------------------------------------------

ollama "$@" &
server_pid=$!

# --- Stop signal forwarding -----------------------------------------------------

trap 'kill -TERM "$server_pid" 2>/dev/null || true' TERM INT

# --- 3. Model preloading --------------------------------------------------------

preload_models() {
    attempt=1
    until ollama list >/dev/null 2>&1; do
        if [ "$attempt" -gt 30 ]; then
            echo "WARNING: Ollama did not become ready in time; no models were pulled." >&2
            return
        fi
        attempt=$((attempt + 1))
        sleep 2
    done
    for model in $MODELS; do
        echo "Pulling $model..."
        ollama pull "$model" || echo "WARNING: failed to pull $model; queries against it fail until it is available." >&2
    done
}

# Wait on the server independently of long model downloads so PID 1 can
# forward stop signals promptly.
preload_models &

# --- 4. Hand the container to the server ----------------------------------------

wait "$server_pid"
status=$?
# A trapped stop signal interrupts "wait" with 128+signal; wait again for the
# server's real exit status so the container reports it.
if [ "$status" -gt 128 ] && kill -0 "$server_pid" 2>/dev/null; then
    wait "$server_pid"
    status=$?
fi
exit "$status"
