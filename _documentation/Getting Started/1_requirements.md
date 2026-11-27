# Requirements

Local builds use Docker with Compose v2. Use **Python 3.13.14** and
**uv 0.11.26**, the versions pinned for the Python builds and CI.
Allow disk space for models, indexes, and Docker volumes, and enough memory
for the selected models. CUDA deployments require the NVIDIA Container Toolkit.
The documentation site uses Node.js.

Provision a service JWT issuer and a restricted PostgreSQL runtime role with
only the required grants on `hawki_backend`. Use a separate, privileged
migration login to provision the schema. The runtime role must lack schema
creation rights, privileged role attributes, and access to the historical
`public` tables. See the [runtime guide](../Operations/python_runtime.md) for
the production image, database, and readiness requirements.
