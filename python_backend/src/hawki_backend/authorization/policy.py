"""The local dataset grant policy.

The policy answers one question: may this principal perform this action on
this dataset? It answers it from two facts and from nothing else, the
coarse scope the principal holds and the grant the principal holds on the
dataset. Both are required. Neither is sufficient on its own.

The policy never grants anything on its own. It does not know a dataset by
its identifier, it does not fall back to a query-all behaviour, it does not
create grants or owners, and it does not read physical storage identifiers.
Everything it allows has to come from a grant the reader confirms.
"""

from hawki_backend.authorization.models import AuthorizationRequest
from hawki_backend.authorization.ports import DatasetGrantReader


class LocalGrantPolicy:
    """Decide dataset access from the grants a reader confirms."""

    def __init__(self, reader: DatasetGrantReader) -> None:
        """Bind the policy to the source of the grants.

        Args:
            reader: The source of the dataset grants. It owns the tenant,
                the dataset existence and the dataset state conditions.
        """
        self._reader = reader

    def decide(self, request: AuthorizationRequest) -> bool:
        """Return whether the request is authorized.

        The coarse scope the action requires is checked first, so a
        principal that may not attempt the action at all never causes a grant
        lookup.

        Returns:
            True when the principal holds both the required coarse scope and
            the exact grant, False otherwise. Every refusal is the same
            ``False``.

        Raises:
            AuthorizationUnavailableError: When the grant source could not be
                consulted. The failure is passed through unchanged and is
                never turned into a denial.
        """
        if not request.principal.has_scopes(request.action.required_scope):
            return False
        if self._reader.has_grant(
            request.principal,
            request.dataset_id,
            request.action,
        ):
            return True
        return False


__all__ = ["LocalGrantPolicy"]
