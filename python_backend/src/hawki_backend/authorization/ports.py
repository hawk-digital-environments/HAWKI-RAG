"""The ports of the backend's authorization boundary.

`AuthorizationPort` is the inbound boundary. Application code asks it for a
decision and never learns how the answer was reached. `DatasetGrantReader`
is the outbound boundary. The policy asks it whether a grant exists.

The two are kept apart on purpose. The policy is written against the
reader, so replacing the in-memory reader with a database-backed one is a
change to the composition root and to nothing else.
"""

from typing import Protocol, runtime_checkable

from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization.models import (
    AuthorizationAction,
    AuthorizationRequest,
)


@runtime_checkable
class AuthorizationPort(Protocol):
    """Decide whether a principal may perform an action on a dataset."""

    def decide(self, request: AuthorizationRequest) -> bool:
        """Return whether the request is authorized.

        Returns:
            True when the request is authorized, False when it is not. Every
            refusal is the same ``False``: a missing dataset, an inactive
            dataset, a grant held by another tenant, a missing grant and a
            missing coarse scope are indistinguishable.

        Raises:
            AuthorizationUnavailableError: When the grant source could not be
                consulted, so no decision could be reached. The failure is
                passed through unchanged and is never turned into a denial.
        """
        ...


@runtime_checkable
class DatasetGrantReader(Protocol):
    """A source of truth about the dataset grants a principal holds.

    The reader owns every condition that makes a grant usable:

    * Tenant isolation. A grant belongs to the tenant of the principal that
      holds it, and a grant of one tenant never answers for another.
    * Dataset existence. A grant on a dataset that does not exist counts for
      nothing.
    * Dataset state. Only grants on an existing, *active* dataset count. A
      dataset that is archived or deleted counts for nothing.

    An unknown dataset, an inactive dataset and a missing grant are all
    reported the same way, as the absence of a grant. They must not be
    distinguishable through the return value, so that a caller cannot learn
    whether a dataset exists.

    A persistent reader enforces these conditions in the query it runs. This
    is the only place they are expressed, which keeps them out of the
    policy.
    """

    def has_grant(
        self,
        principal: AuthenticatedPrincipal,
        dataset_id: str,
        action: AuthorizationAction,
    ) -> bool:
        """Return whether the principal holds the grant for the action.

        Returns:
            True only when the principal holds exactly this grant: the same
            tenant, the same principal kind, the same principal id, the same
            dataset and the same action.

        Raises:
            AuthorizationUnavailableError: When the grant source could not be
                consulted. The failure is passed through unchanged.
        """
        ...


__all__ = ["AuthorizationPort", "DatasetGrantReader"]
