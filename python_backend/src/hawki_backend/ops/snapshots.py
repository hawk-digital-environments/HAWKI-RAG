"""Dataset-scoped graph views owned by the authenticated service."""

from datetime import datetime, timezone
import json
from uuid import uuid4

from fastapi import HTTPException, Request
from pydantic import BaseModel, ConfigDict, Field
import sqlalchemy as sa

from hawki_backend.auth.models import PrincipalKind
from .schema import snapshots
from .api import Reader, Dataset, router, service


class SnapshotBody(BaseModel):
    model_config = ConfigDict(extra="forbid")
    dataset_id: str = Field(min_length=1, max_length=191)
    name: str | None = Field(default=None, max_length=255)
    scene: dict


def owned(principal):
    if principal.kind is not PrincipalKind.SERVICE:
        raise HTTPException(403, "service_required")
    return sa.and_(
        snapshots.c.tenant_id == principal.tenant_id,
        snapshots.c.client_id == principal.client_id,
        snapshots.c.principal_id == principal.principal_id,
    )


def payload(row, *, detail=False):
    result = {key: row[key] for key in ("id", "name", "dataset_id")}
    result["created_at"] = row["created_at"].isoformat()
    if detail:
        result["scene"] = row["scene"]
    return result


@router.get("/graph/snapshots")
def listing(request: Request, principal: Reader, dataset_id: Dataset):
    ops = service(request)
    ops.dataset(principal, dataset_id)
    with ops.engine.connect() as connection:
        rows = connection.execute(
            sa.select(snapshots)
            .where(owned(principal), snapshots.c.dataset_id == dataset_id)
            .order_by(snapshots.c.created_at.desc())
            .limit(100)
        ).mappings()
        return {"ok": True, "snapshots": [payload(row) for row in rows]}


@router.post("/graph/snapshots")
def create(request: Request, principal: Reader, body: SnapshotBody):
    ops = service(request)
    ops.dataset(principal, body.dataset_id)
    predicate = owned(principal)
    if len(json.dumps(body.scene).encode()) > 2 * 1024 * 1024:
        raise HTTPException(413, "snapshot_too_large")
    row = {
        "id": uuid4().hex,
        "tenant_id": principal.tenant_id,
        "client_id": principal.client_id,
        "principal_id": principal.principal_id,
        "dataset_id": body.dataset_id,
        "name": body.name or "Graph view",
        "scene": body.scene,
        "created_at": datetime.now(timezone.utc),
    }
    with ops.engine.begin() as connection:
        if (
            connection.scalar(
                sa.select(sa.func.count()).select_from(snapshots).where(predicate)
            )
            >= 100
        ):
            raise HTTPException(409, "snapshot_limit_reached")
        connection.execute(snapshots.insert().values(**row))
    return {"ok": True, "snapshot": payload(row, detail=True)}


@router.get("/graph/snapshots/{snapshot_id}")
@router.delete("/graph/snapshots/{snapshot_id}")
def item(snapshot_id: str, request: Request, principal: Reader):
    ops = service(request)
    with ops.engine.begin() as connection:
        row = (
            connection.execute(
                sa.select(snapshots).where(
                    owned(principal), snapshots.c.id == snapshot_id
                )
            )
            .mappings()
            .one_or_none()
        )
        if row is None:
            raise HTTPException(404, "snapshot_not_found")
        ops.dataset(principal, row["dataset_id"])
        if request.method == "DELETE":
            connection.execute(
                snapshots.delete().where(
                    owned(principal), snapshots.c.id == snapshot_id
                )
            )
            return {"ok": True}
        return {"ok": True, "snapshot": payload(row, detail=True)}
