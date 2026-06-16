# Build a CUDA-enabled Ollama binary
FROM nvidia/cuda:12.2.0-devel-ubuntu22.04 AS builder
SHELL ["/bin/bash", "-lc"]

ARG OLLAMA_GIT_REF=main
ARG GO_VERSION=1.24.1
ARG TARGETARCH
ARG OLLAMA_ARM_CPU_ARCH=armv8.6-a

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    git ca-certificates curl build-essential gpg wget lsb-release pkg-config \
    && wget -O - https://apt.kitware.com/keys/kitware-archive-latest.asc \
        | gpg --dearmor -o /usr/share/keyrings/kitware-archive-keyring.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/kitware-archive-keyring.gpg] https://apt.kitware.com/ubuntu/ $(lsb_release -cs) main" \
        > /etc/apt/sources.list.d/kitware.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends cmake \
    && rm -rf /var/lib/apt/lists/*

# Build Ollama from source with CUDA support
WORKDIR /tmp
RUN GO_ARCH="${TARGETARCH:-}" \
    && if [[ -z "${GO_ARCH}" ]]; then \
         case "$(uname -m)" in \
           x86_64) GO_ARCH="amd64" ;; \
           aarch64|arm64) GO_ARCH="arm64" ;; \
           *) echo "Unsupported architecture: $(uname -m)" >&2; exit 1 ;; \
         esac; \
       fi \
    && if [[ "${GO_ARCH}" != "amd64" && "${GO_ARCH}" != "arm64" ]]; then \
         echo "Unsupported TARGETARCH: ${GO_ARCH}" >&2; \
         exit 1; \
       fi \
    && curl -fsSLo /tmp/go.tgz "https://go.dev/dl/go${GO_VERSION}.linux-${GO_ARCH}.tar.gz" \
    && rm -rf /usr/local/go \
    && tar -C /usr/local -xzf /tmp/go.tgz \
    && rm -f /tmp/go.tgz

ENV PATH=/usr/local/go/bin:$PATH

# Force llama.cpp CUDA build for Ada (RTX 4070 Ti = sm_89)
ENV GGML_CUDA=1
ENV CUDA_DOCKER_ARCH=89
ENV CGO_ENABLED=1

WORKDIR /src
RUN git clone --depth 1 --branch ${OLLAMA_GIT_REF} https://github.com/ollama/ollama.git
WORKDIR /src/ollama

# Build native libs (CUDA) via CMake, then build Ollama.
# On ARM64, disable ggml's all-CPU-variants mode and pin a GCC 11-compatible
# architecture so the source build does not try unsupported ARMv9.2/SME flags.
RUN CMAKE_ARGS=() \
    && if [[ "$(uname -m)" == "aarch64" || "$(uname -m)" == "arm64" || "${TARGETARCH:-}" == "arm64" ]]; then \
         CMAKE_ARGS+=("-DGGML_CPU_ALL_VARIANTS=OFF" "-DGGML_CPU_ARM_ARCH=${OLLAMA_ARM_CPU_ARCH}"); \
       fi \
    && cmake -B build "${CMAKE_ARGS[@]}" \
    && cmake --build build -j"$(nproc)" \
    && go build -tags "cuda" -o /usr/bin/ollama .


# Runtime image
FROM nvidia/cuda:12.2.0-runtime-ubuntu22.04
SHELL ["/bin/bash", "-lc"]

ENV DEBIAN_FRONTEND=noninteractive
ENV OLLAMA_HOST=0.0.0.0:11434

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=builder /usr/bin/ollama /usr/bin/ollama
# Copy acceleration libraries so Ollama can find them at ../lib/ollama (fallback handled at runtime)
COPY --from=builder /src/ollama/build/lib/ollama /usr/lib/ollama

EXPOSE 11434

ENTRYPOINT ["/usr/bin/ollama"]
CMD ["serve"]
