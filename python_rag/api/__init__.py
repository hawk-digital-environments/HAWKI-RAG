"""API package for the Python RAG FastAPI service.

The ASGI application is created in ``api.main``. Keeping package import free of
runtime construction avoids side effects when tests import submodules such as
``api.settings``.
"""
