# Build a CUDA-enabled Ollama binary
FROM nvidia/cuda:12.2.0-devel-ubuntu22.04 AS builder
SHELL ["/bin/bash", "-lc"]

ARG OLLAMA_GIT_REF=main
ARG GO_VERSION=1.24.1

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    git ca-certificates curl build-essential cmake pkg-config \
    && rm -rf /var/lib/apt/lists/*

# Build Ollama from source with CUDA support
WORKDIR /tmp
RUN curl -fsSLo /tmp/go.tgz https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz \
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

# Build native libs (CUDA) via CMake, then build Ollama
RUN cmake -B build \
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
