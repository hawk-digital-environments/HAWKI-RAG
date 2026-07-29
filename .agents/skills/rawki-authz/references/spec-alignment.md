# RAWKI Authz Spec Alignment

This note summarizes the March 2026 HAWKI RAG Architecture and API Specification that was provided alongside this repo task. Use it when the user asks for spec alignment, architecture review, or a forward-looking auth design.

## Target Architecture In The Spec

The spec describes:

- a Laravel gateway that owns tenant, app, scope, and authorization decisions
- application authentication through bearer tokens
- optional authorization as an infrastructure layer
- permission prefiltering in the gateway before the search request reaches Python
- Python as a pure search executor that receives `{ query, filters, limit }`
- internal user UUIDs for authorization relationships
- heap-oriented and app-oriented multi-tenant scoping
- adapters for external platforms such as Moodle, ILIAS, WordPress, and scrapers

## Important Differences From The Current Repo

Current repo:

- uses OIDC user authentication in Laravel for user-level access
- sends `auth_context` into Python
- performs document permission checks inside Python retrieval flow
- models LMS-neutral permissions around course-to-document relationships
- exposes a connector architecture where only `static` is complete

Spec target:

- describes application-authenticated API consumers and tenant-scoped read permissions
- expects gateway-side filter construction, including authorization constraints
- expects Python not to know about applications, tenants, or user identifiers
- centers the authorization boundary around heaps and app scope rather than only retrieval-time document filtering

## How To Handle Spec-Driven Tasks

If the task is small and repo-local:

- stay consistent with current repo behavior
- avoid partial migrations that split semantics across old and new models

If the task is explicitly architectural:

- separate current-state fixes from target-state changes
- identify which contracts move from Python to Laravel
- define migration steps for env flags, request payloads, and tests
- verify whether permission event storage is sufficient for backfilling a newly enabled graph backend

## Safe Language For Reviews

Use wording like:

- "current repo behavior"
- "spec target behavior"
- "alignment gap"
- "migration step"

Avoid wording like:

- "bug" when the repo is intentionally operating in a pre-alignment state
- "should already work" for connectors that are still scaffolds or placeholders

## Review Checklist For Alignment Work

- Does Laravel now compute the full authorization filter before Python search?
- Does Python still depend on `auth_context`, and if so is that intentional?
- Are internal identity mappings stable and hidden from API consumers?
- Are graph writes replayable or reconstructible if auth is enabled later?
- Do env flags and docs describe optional authorization consistently?
- Are tests updated on both sides of the Laravel and Python boundary?
