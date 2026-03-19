# v%%VERSION%%

### What's New

[//]: # (- The main new features and changes in this version.)

### Quality of Life

[//]: # (- Improvements and enhancements that improve the user experience.)
- Introduce a new `docker-compose.local.yml` file that can be used to override the default docker compose configuration with local settings. This allows developers to start up the environment a lot easier without having to modify the default configuration files. Just copy the `docker-compose.local.yml` file to `docker-compose.override.yml` and modify the environment variables as needed for your local setup.

### Bugfix

- fixed (dockerfile): improved ARM compatibility for the Ollama build and phpMyAdmin image
- Fix various issues around the gateway(laravel) container not being able to retrieve the correct url.

### Internals

[//]: # (- Changes that are mostly relevant to maintainers and contributors, such as refactors, dependency updates, CI changes, etc.)

### Deprecation

[//]: # (- List of features or functionalities that have been deprecated in this version.)
