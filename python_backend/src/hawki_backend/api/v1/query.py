"""Authenticated, authorized, dataset-scoped query endpoint.

The endpoint is the public boundary of the in-process query API. It accepts
only logical query input, authenticates the caller, authorizes the logical
dataset, resolves trusted physical scope on the server, and calls the shared
retrieval use case in-process. It never calls the bridge over HTTP.
"""

from __future__ import annotations

import logging
import re
from typing import Annotated

from fastapi import APIRouter, Depends, Request
from pydantic import BaseModel, ConfigDict, Field, field_validator

from hawki_backend.auth.dependencies import require_scopes
from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization import (
    AuthorizationAction,
    AuthorizationPort,
    AuthorizationRequest,
    AuthorizationUnavailableError,
)
from hawki_backend.query.errors import (
    authorization_unavailable,
    dataset_not_found,
    dataset_not_ready,
    query_failed,
    query_unavailable,
    retrieval_error_to_http_exception,
)
from hawki_backend.query.response import sanitize_query_response
from hawki_backend.query.settings import (
    DatasetScope,
    ProviderModels,
    QuerySettings,
)
from hawki_rag_contracts.retrieval.auth_scope import AuthorizedQueryScope
from hawki_rag_contracts.retrieval.query import (
    QueryFilterScalar,
    QueryRequest,
    QueryResponse,
)
from hawki_rag_retrieval import QueryDependencies, execute_authorized_query
from hawki_rag_retrieval.errors import BridgeQueryError

logger = logging.getLogger(__name__)

_DATASET_ID_PATTERN = r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$"

# Storage and model-selection keys are never user-searchable metadata. They
# are rejected here as well as dropped by the shared scoped-filter builder, so
# a caller cannot probe or replace trusted scope through a filter.
_RESERVED_FILTER_KEYS = frozenset(
    {
        "auth_context",
        "authorized_scope",
        "chat_model",
        "collection",
        "dataset_id",
        "embedding_model",
        "embedding_provider",
        "graph_enabled",
        "graph_model",
        "neo4j_namespace",
        "provider",
        "qdrant_collection",
        "vision_model",
    }
)

_MAX_FILTERS = 20
_MAX_FILTER_KEY_LENGTH = 200
_MAX_FILTER_VALUE_LENGTH = 4000
_MAX_PREFERRED_TAGS = 20
_MAX_PREFERRED_TAG_LENGTH = 80


class QueryRequestBody(BaseModel):
    """Public query input. Physical and model-selection scope is forbidden."""

    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    dataset_id: str = Field(
        min_length=1,
        max_length=191,
        pattern=_DATASET_ID_PATTERN,
    )
    query: str = Field(min_length=1, max_length=4000)
    top_k: int = Field(default=5, ge=1, le=100)
    is_optimized: bool = False
    generate: bool = True
    fast_mode: bool = True
    smart_lookup: bool = False
    preferred_tags: list[str] | None = Field(
        default=None,
        max_length=_MAX_PREFERRED_TAGS,
    )
    filters: dict[str, QueryFilterScalar] = Field(default_factory=dict)

    @field_validator("preferred_tags")
    @classmethod
    def validate_preferred_tags(
        cls,
        value: list[str] | None,
    ) -> list[str] | None:
        """Reject empty or oversized preferred tags."""

        if value is None:
            return None
        for tag in value:
            if not tag or len(tag) > _MAX_PREFERRED_TAG_LENGTH:
                raise ValueError(
                    "preferred_tags entries must be non-empty and at most "
                    f"{_MAX_PREFERRED_TAG_LENGTH} characters"
                )
        return value

    @field_validator("filters")
    @classmethod
    def validate_filters(
        cls,
        value: dict[str, QueryFilterScalar],
    ) -> dict[str, QueryFilterScalar]:
        """Reject reserved, empty or oversized metadata filters."""

        if len(value) > _MAX_FILTERS:
            raise ValueError(f"filters may contain at most {_MAX_FILTERS} keys")
        for key, item in value.items():
            if len(key) > _MAX_FILTER_KEY_LENGTH:
                raise ValueError(
                    f"filter keys may be at most {_MAX_FILTER_KEY_LENGTH} characters"
                )
            normalized = _normalize_filter_key(key)
            if not normalized:
                raise ValueError("filters must use named metadata keys")
            if normalized in _RESERVED_FILTER_KEYS:
                raise ValueError(
                    "filters must not contain reserved authorization or storage keys"
                )
            if isinstance(item, str) and len(item) > _MAX_FILTER_VALUE_LENGTH:
                raise ValueError(
                    "filter values may be at most "
                    f"{_MAX_FILTER_VALUE_LENGTH} characters"
                )
        return value

    def to_query_request(
        self,
        *,
        scope: DatasetScope,
        models: ProviderModels,
        defaults: QuerySettings,
    ) -> QueryRequest:
        """Build the internal request from public input and trusted scope."""

        return QueryRequest(
            query=self.query,
            authorized_scope=AuthorizedQueryScope(
                dataset_id=self.dataset_id,
                qdrant_collection=scope.qdrant_collection,
                neo4j_namespace=scope.neo4j_namespace,
                embedding_provider=scope.embedding_provider,
                embedding_model=scope.embedding_model,
                graph_enabled=scope.graph_enabled,
            ),
            top_k=self.top_k,
            provider=scope.embedding_provider,
            chat_model=models.chat_model,
            vision_model=models.vision_model,
            filters=self.filters,
            generate=self.generate,
            is_optimized=self.is_optimized,
            fast_mode=self.fast_mode,
            smart_lookup=self.smart_lookup,
            preferred_tags=self.preferred_tags,
            reranker=defaults.reranker,
            rerank_top_n=defaults.rerank_top_n,
            mix_mode=defaults.reranker_mix_mode,
            mix_weight=defaults.reranker_mix_weight,
        )


def _normalize_filter_key(key: str) -> str:
    """Normalize a public filter key the same way the current API does."""

    snake_case = re.sub(r"(?<!^)(?=[A-Z])", "_", key.strip())
    return snake_case.lower().replace("-", "_")


router = APIRouter()

_QUERY_PRINCIPAL = require_scopes(AuthorizationAction.QUERY.required_scope)


@router.post("/query", response_model=QueryResponse)
def query(
    body: QueryRequestBody,
    principal: Annotated[
        AuthenticatedPrincipal,
        Depends(_QUERY_PRINCIPAL),
    ],
    request: Request,
) -> QueryResponse:
    """Authorize and execute a dataset-scoped query in-process.

    The route is synchronous on purpose: the grant lookup and the retrieval
    pipeline are blocking. FastAPI runs it in the worker threadpool.
    """

    policy: AuthorizationPort | None = getattr(
        request.app.state,
        "grant_policy",
        None,
    )
    settings: QuerySettings | None = getattr(
        request.app.state,
        "query_settings",
        None,
    )
    dependencies: QueryDependencies | None = getattr(
        request.app.state,
        "query_dependencies",
        None,
    )

    if policy is None:
        raise authorization_unavailable()
    if settings is None or dependencies is None:
        raise query_unavailable()

    try:
        allowed = policy.decide(
            AuthorizationRequest(
                principal=principal,
                action=AuthorizationAction.QUERY,
                dataset_id=body.dataset_id,
            )
        )
    except AuthorizationUnavailableError:
        raise authorization_unavailable() from None

    if not allowed:
        raise dataset_not_found()

    scope = settings.resolve_scope(body.dataset_id, principal.tenant_id)
    if scope is None:
        raise dataset_not_ready()
    models = settings.models_for(scope.embedding_provider)
    if models is None:
        raise dataset_not_ready()

    internal_request = body.to_query_request(
        scope=scope,
        models=models,
        defaults=settings,
    )

    try:
        response = execute_authorized_query(
            internal_request,
            dependencies=dependencies,
        )
    except BridgeQueryError as exc:
        raise retrieval_error_to_http_exception(exc) from None
    except Exception as exc:  # noqa: BLE001 - stable public failure mapping
        logger.error(
            "query execution failed error=%s",
            type(exc).__name__,
        )
        raise query_failed() from None

    return sanitize_query_response(response)


__all__ = ["QueryRequestBody", "router"]
