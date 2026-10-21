"""An in-memory source of dataset grants.

The reader is a development and test double for the persistent reader that a
later branch adds. It holds the grants it was given and answers exactly one
question about them.

Every grant it holds is treated as referring to an existing, active
dataset. The reader has no dataset registry, so it cannot check whether a
dataset exists or what state it is in. The persistent reader performs that
check in the query it runs, and this reader inherits that assumption for
the grants it was given.

The reader has no wildcard, no query-all mode, no anonymous mode and no way
to grant anything. The set of grants is fixed when the reader is built.
"""

from collections.abc import Iterable

from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization.models import AuthorizationAction, DatasetGrant


class InMemoryGrantReader:
    """Answer grant questions from a fixed set of grants."""

    def __init__(self, grants: Iterable[DatasetGrant] = ()) -> None:
        """Store the grants the reader answers from.

        Args:
            grants: The grants the reader holds. Each one names the tenant,
                the principal kind, the principal id, the dataset and the
                action it was granted for.
        """
        self._grants = frozenset(grants)

    def has_grant(
        self,
        principal: AuthenticatedPrincipal,
        dataset_id: str,
        action: AuthorizationAction,
    ) -> bool:
        """Return whether the principal holds the grant for the action.

        This reader cannot fail: it holds its grants in memory, so there is
        nothing to reach and nothing to wait for. A persistent reader raises
        `AuthorizationUnavailableError` when the grant store cannot be
        reached, and never turns that failure into a refusal.

        Returns:
            True only for the exact grant: the same tenant, the same
            principal kind, the same principal id, the same dataset and the
            same action. Every other combination is False.
        """
        try:
            key = DatasetGrant(
                tenant_id=principal.tenant_id,
                principal_kind=principal.kind,
                principal_id=principal.principal_id,
                dataset_id=dataset_id,
                action=action,
            )
        except ValueError:
            # An identifier that cannot name a grant names no grant. The
            # reader answers every lookup, so it never refuses to answer.
            return False
        return key in self._grants


__all__ = ["InMemoryGrantReader"]
