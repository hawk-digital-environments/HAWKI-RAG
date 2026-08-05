"""RAG-Anything client construction and graph settings helpers."""

from __future__ import annotations

import asyncio
import logging
import re
from pathlib import Path
from typing import Any

from hawki_rag_resilience.optional_imports import import_required_module
from hawki_indexer_worker.adapters.raganything.provider_config import (
    clone_provider_for_graph,
    graph_model_override,
    provider_fingerprint,
)
from hawki_indexer_worker.adapters.raganything.runtime import prepare_lightrag_neo4j_env
from hawki_indexer_worker.adapters.raganything.settings import (
    RagAnythingGraphSettings,
    parse_optional_int,
)
from hawki_indexer_worker.adapters.raganything.utilities import (
    graph_embed_junk_reason,
    junk_embedding_sentinel,
    normalize_graph_embed_text,
)

logger = logging.getLogger(__name__)


def _numpy_module() -> Any:
    return import_required_module(
        "numpy",
        install_hint="Run `make python-deps` to install the pinned indexer dependencies.",
    )


def graph_runtime_cache_key(
    working_dir: Path,
    provider: object,
    settings: RagAnythingGraphSettings,
    *,
    neo4j_database: str | None = None,
) -> str:
    db_name = (neo4j_database or settings.neo4j_database).strip()
    return "|".join(
        [
            str(working_dir),
            provider_fingerprint(provider),
            str(graph_model_override(provider) or ""),
            str(db_name),
            str(settings.graph_temperature).strip(),
            str(settings.ollama_chat_timeout).strip(),
            str(settings.vision_model).strip(),
            str(settings.graph_embedding_dimensions).strip(),
        ]
    )


def register_chunked_doc_status_storage() -> bool:
    storage_name = "ChunkedJsonDocStatusStorage"
    try:
        import lightrag.kg as lightrag_kg  # type: ignore

        implementations = lightrag_kg.STORAGE_IMPLEMENTATIONS["DOC_STATUS_STORAGE"][
            "implementations"
        ]
        if storage_name not in implementations:
            implementations.append(storage_name)

        lightrag_kg.STORAGE_ENV_REQUIREMENTS.setdefault(storage_name, [])
        lightrag_kg.STORAGES[storage_name] = (
            "hawki_indexer_worker.adapters.raganything.lightrag_status_store"
        )
        return True
    except Exception as exc:
        logger.warning(
            "Failed to register chunked LightRAG doc status storage: %s", exc
        )
        return False


def graph_runtime_summary_limits(
    settings: RagAnythingGraphSettings,
) -> dict[str, int | None]:
    return {
        "graph_doc_max_chars": settings.graph_doc_max_chars,
        "graph_doc_max_chunks": settings.graph_doc_max_chunks,
        "graph_min_chunk_chars": settings.graph_min_chunk_chars,
        "graph_min_doc_chars": settings.graph_min_doc_chars,
        "ollama_chat_timeout": parse_optional_int(settings.ollama_chat_timeout),
    }


_MAX_EMBEDDING_DIMENSION = 65_536
_MODEL_ALIAS_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/-]{0,159}$")


def _valid_embedding_dimension(value: str | int | float | None) -> int | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        dimension = int(value)
    except (TypeError, ValueError):
        return None
    if not 1 <= dimension <= _MAX_EMBEDDING_DIMENSION:
        return None
    return dimension


def _embedding_dimension_overrides(raw_value: str) -> dict[str, int]:
    dimensions: dict[str, int] = {}
    for item in str(raw_value or "").split(","):
        alias, separator, raw_dimension = item.strip().partition("=")
        if not separator or not _MODEL_ALIAS_PATTERN.fullmatch(alias):
            continue
        dimension = _valid_embedding_dimension(raw_dimension.strip())
        if dimension is not None:
            dimensions[alias.lower()] = dimension
    return dimensions


def _embed_model_dim(graph_provider: object, settings: RagAnythingGraphSettings) -> int:
    observed_dimension = _valid_embedding_dimension(
        getattr(graph_provider, "_last_embed_dim", None)
    )
    if observed_dimension is not None:
        return observed_dimension

    embed_model_name = str(getattr(graph_provider, "embed_model", "") or "").lower()
    configured_dimension = _embedding_dimension_overrides(
        settings.graph_embedding_dimensions
    ).get(embed_model_name)
    if configured_dimension is not None:
        return configured_dimension
    if "bge-m3" in embed_model_name:
        return 1024
    if "text-embedding-3-large" in embed_model_name:
        return 3072
    if "text-embedding-3-small" in embed_model_name:
        return 1536
    return 1024


def _build_llm_model_func(
    graph_provider: Any,
    settings: RagAnythingGraphSettings,
    *,
    logger_obj: logging.Logger,
) -> Any:
    async def llm_model_func(
        prompt: str,
        system_prompt: str | None = None,
        history_messages: list | None = None,
        max_tokens: int | None = None,
        **kwargs: object,
    ) -> str:
        del max_tokens, kwargs
        messages = list(history_messages or [])
        messages.append({"role": "user", "content": prompt})
        system = system_prompt or "You are a helpful assistant."

        graph_temp_env = settings.graph_temperature.strip()
        if graph_temp_env:
            try:
                temperature = float(graph_temp_env)
            except ValueError:
                temperature = None
        else:
            temperature = 0.0

        if settings.graph_debug_llm:
            logger_obj.debug("graph:raganything llm system=%s", system)
            logger_obj.debug("graph:raganything llm prompt=%s", prompt)
        response = await asyncio.to_thread(
            graph_provider.chat, system, messages, temperature=temperature
        )
        if settings.graph_debug_llm:
            logger_obj.debug("graph:raganything llm response=%s", response)
        return response

    return llm_model_func


def _graph_temperature(settings: RagAnythingGraphSettings) -> float | None:
    graph_temp_env = settings.graph_temperature.strip()
    if not graph_temp_env:
        return 0.0
    try:
        return float(graph_temp_env)
    except ValueError:
        return None


def _build_vision_model_func(
    graph_provider: Any,
    settings: RagAnythingGraphSettings,
    *,
    logger_obj: logging.Logger,
) -> Any:
    async def vision_model_func(
        prompt: str,
        system_prompt: str | None = None,
        history_messages: list | None = None,
        image_data: str | None = None,
        messages: list | None = None,
        **kwargs: object,
    ) -> str:
        del kwargs
        system = system_prompt or "You are a helpful visual assistant."
        temperature = _graph_temperature(settings)

        if settings.graph_debug_llm:
            logger_obj.debug("graph:raganything vision system=%s", system)
            logger_obj.debug("graph:raganything vision prompt=%s", prompt)

        if hasattr(graph_provider, "vision_chat"):
            response = await asyncio.to_thread(
                graph_provider.vision_chat,
                system,
                prompt,
                image_data=image_data,
                messages=messages or history_messages,
                temperature=temperature,
            )
        else:
            text_messages = list(history_messages or messages or [])
            if prompt:
                text_messages.append({"role": "user", "content": prompt})
            response = await asyncio.to_thread(
                graph_provider.chat,
                system,
                text_messages,
                temperature=temperature,
            )

        if settings.graph_debug_llm:
            logger_obj.debug("graph:raganything vision response=%s", response)
        return response

    return vision_model_func


def _build_embed_many_func(
    graph_provider: Any,
    settings: RagAnythingGraphSettings,
    *,
    embed_dim: int,
    logger_obj: logging.Logger,
) -> Any:
    async def embed_many(texts: Any) -> Any:
        np = _numpy_module()
        text_list = [texts] if isinstance(texts, str) else list(texts or [])
        if not text_list:
            return np.zeros((0, 0), dtype=float)

        out_vectors: list[Any] = [None] * len(text_list)
        embed_jobs: list[Any] = []
        embed_job_indices: list[int] = []
        filtered = 0
        filtered_samples: list[str] = []

        allowlist_raw = settings.graph_embed_junk_allowlist
        denylist_raw = settings.graph_embed_junk_denylist
        strict = settings.graph_embed_junk_strict

        for idx, raw in enumerate(text_list):
            text_norm = normalize_graph_embed_text(raw)
            reason = graph_embed_junk_reason(
                text_norm,
                allowlist_raw=allowlist_raw,
                denylist_raw=denylist_raw,
                strict=strict,
            )
            if reason is not None:
                filtered += 1
                out_vectors[idx] = junk_embedding_sentinel(
                    text_norm or str(raw or ""), embed_dim
                )
                if settings.graph_debug and len(filtered_samples) < 3:
                    sample = text_norm[:80] if text_norm else str(raw or "")[:80]
                    filtered_samples.append(f"{reason}:{sample}")
                continue

            embed_jobs.append(asyncio.to_thread(graph_provider.embed, text_norm))
            embed_job_indices.append(idx)

        if embed_jobs:
            vectors = await asyncio.gather(*embed_jobs)
            for idx, vec in zip(embed_job_indices, vectors):
                out_vectors[idx] = vec

        if filtered and settings.graph_debug:
            logger_obj.debug(
                "graph:embed_many junk-filtered=%s/%s samples=%s",
                filtered,
                len(text_list),
                filtered_samples,
            )

        for idx, value in enumerate(out_vectors):
            if value is None:
                out_vectors[idx] = junk_embedding_sentinel(
                    str(text_list[idx] or ""), embed_dim
                )
        return np.asarray(out_vectors, dtype=float)

    return embed_many


def build_raganything_client(
    *,
    working_dir: Path,
    provider: Any,
    settings: RagAnythingGraphSettings,
    logger_obj: logging.Logger,
    neo4j_database: str | None = None,
) -> tuple[Any | None, dict[str, Any], dict[str, Any]]:
    base_runtime_meta: dict[str, Any] = {
        "doc_status_storage": "JsonDocStatusStorage",
        "graph_storage": "NetworkXStorage(default)",
        "graph_client_initialized": False,
    }

    try:
        from raganything import RAGAnything  # type: ignore
        from raganything.config import RAGAnythingConfig  # type: ignore
    except Exception as exc:
        logger_obj.info("RAG-Anything import failed: %s", exc)
        base_runtime_meta["init_error"] = str(exc)
        return None, base_runtime_meta, {}

    try:
        from lightrag.utils import EmbeddingFunc  # type: ignore
    except Exception as exc:
        logger_obj.info("LightRAG embedding wrapper import failed: %s", exc)
        base_runtime_meta["init_error"] = str(exc)
        return None, base_runtime_meta, {}

    graph_provider = clone_provider_for_graph(provider)
    embed_dim = _embed_model_dim(graph_provider, settings)

    llm_model_func = _build_llm_model_func(
        graph_provider, settings, logger_obj=logger_obj
    )
    vision_model_func = _build_vision_model_func(
        graph_provider, settings, logger_obj=logger_obj
    )
    emb_func = EmbeddingFunc(
        embedding_dim=embed_dim,
        func=_build_embed_many_func(
            graph_provider, settings, embed_dim=embed_dim, logger_obj=logger_obj
        ),
        max_token_size=8192,
        model_name=(getattr(graph_provider, "embed_model", None) or None),
    )

    config = RAGAnythingConfig(
        working_dir=str(working_dir),
        parser_output_dir=str(working_dir / "parser_output"),
        parse_method="auto",
        parser="mineru",
        display_content_stats=settings.graph_debug,
        max_concurrent_files=1,
    )

    chunked_doc_status_ok = register_chunked_doc_status_storage()
    neo4j_graph_ok, neo4j_env_applied = prepare_lightrag_neo4j_env(
        settings,
        neo4j_database=neo4j_database,
    )

    lightrag_kwargs: dict[str, Any] = {}
    if chunked_doc_status_ok:
        lightrag_kwargs["doc_status_storage"] = "ChunkedJsonDocStatusStorage"
    if neo4j_graph_ok:
        lightrag_kwargs["graph_storage"] = "Neo4JStorage"
    else:
        logger_obj.warning(
            "LightRAG Neo4JStorage not enabled (missing NEO4J_URI/NEO4J_USERNAME/NEO4J_PASSWORD); using default graph storage"
        )

    try:
        client = RAGAnything(
            llm_model_func=llm_model_func,
            vision_model_func=vision_model_func,
            embedding_func=emb_func,
            config=config,
            lightrag_kwargs=lightrag_kwargs,
        )
        logger_obj.info(
            "RAG-Anything graph client initialized (working_dir=%s, provider=%s, rag_model=%s, vision_model=%s, embed_model=%s, doc_status_storage=%s, graph_storage=%s)",
            working_dir,
            graph_provider.__class__.__name__,
            getattr(graph_provider, "rag_model", None),
            getattr(graph_provider, "vision_model", None),
            getattr(graph_provider, "embed_model", None),
            lightrag_kwargs.get("doc_status_storage", "JsonDocStatusStorage"),
            lightrag_kwargs.get("graph_storage", "NetworkXStorage(default)"),
        )
        runtime_meta = {
            "doc_status_storage": lightrag_kwargs.get(
                "doc_status_storage", "JsonDocStatusStorage"
            ),
            "graph_storage": lightrag_kwargs.get(
                "graph_storage", "NetworkXStorage(default)"
            ),
            "graph_client_initialized": True,
        }
        if neo4j_env_applied:
            logger_obj.info(
                "LightRAG Neo4j env prepared: %s",
                {k: v for k, v in neo4j_env_applied.items()},
            )
        return client, runtime_meta, neo4j_env_applied
    except Exception as exc:
        runtime_meta = dict(base_runtime_meta)
        runtime_meta.update(
            {
                "doc_status_storage": lightrag_kwargs.get(
                    "doc_status_storage", "JsonDocStatusStorage"
                ),
                "graph_storage": lightrag_kwargs.get(
                    "graph_storage", "NetworkXStorage(default)"
                ),
                "init_error": str(exc),
            }
        )
        logger_obj.info("RAG-Anything graph client init failed: %s", exc)
        return None, runtime_meta, {}
