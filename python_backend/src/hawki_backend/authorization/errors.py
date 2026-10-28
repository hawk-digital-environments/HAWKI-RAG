"""The failure taxonomy of the backend's authorization boundary.

A request that is refused is a normal answer, and it is expressed as
`False`. An exception from this module means something else: the grant source
could not be consulted, so no answer could be reached at all.

The two are deliberately not interchangeable. Turning an infrastructure
failure into a denial would report a broken grant store as a permission
problem, and it would hide the outage from the operator.
"""


class AuthorizationUnavailableError(Exception):
    """Raised when the grant source cannot make a decision.

    The signing keys, the grant store or the configuration were unavailable,
    so the request was never actually evaluated. This is a separate category
    from a refused request and is deliberately *not* a subclass of any
    refusal type, so the two can never be confused when they are mapped onto
    statuses.
    """

    def __init__(self) -> None:
        super().__init__("authorization_unavailable")


__all__ = ["AuthorizationUnavailableError"]
