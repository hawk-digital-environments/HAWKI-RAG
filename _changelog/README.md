# Changelog

Find the release you are upgrading to in the changelog sidebar.

## What you'll find here

- **Release notes** list the features, fixes, and deprecations for each version.
- **Upgrade guides** appear alongside releases that require manual steps on
  existing installations.

## Need help?

Ask release questions in the [Discord community](https://discord.gg/zzR54sRWDE).

---

## For contributors

Update the draft release notes and upgrade instructions alongside the change.
The historical release pipeline used [hawk-pipeline-actions](https://github.com/hawk-digital-environments/hawk-pipeline-actions).

### Tracking changes day-to-day

For a pull request to `development` with changes users or operators need to
know about, update the relevant draft in the same pull request. Record
user-visible changes in `next.md` and manual upgrade steps in `next-upgrade.md`.

> The historical release workflow **reset both drafts** when it created the
> release branch.

#### Which parts are written?

Lines of the form `[//]: # (text)` are template comments. Write release notes
as ordinary Markdown under the appropriate heading. The historical release
workflow stripped the comments and omitted the resulting empty sections.

#### `next.md` — the next release notes

Add an entry under the relevant heading for a feature, bug fix, or deprecation.

#### `next-upgrade.md` — the next upgrade guide

This file is **optional**. Add steps only when an upgrade requires manual
action, such as a database migration or a change to an environment variable.

---

### Which version number to use?

Use [Semantic Versioning](https://semver.org/).

**Patch — `x.y.Z`**
Increment for backward-compatible bug fixes only. A bug fix corrects incorrect behaviour without changing any public interface.

**Minor — `x.Y.0`**
Increment when new, backward-compatible functionality is introduced. Also increment when public API functionality is marked as deprecated, or when substantial internal improvements are made. Reset the patch version to `0`.

**Major — `X.0.0`**
Increment when backward-incompatible changes are introduced. Reset both minor and patch versions to `0`.

If a backward-compatible change could qualify for either a patch or a minor
release, prefer a minor bump.

---

### Releasing a new version

The following procedure describes the historical release workflows. The
current checkout contains test and documentation workflows. Use the release
steps below only with a branch that provides the named workflows.

#### Step 1 — Create the release branch

1. Go to **Actions** → **[MANUAL] - 1. Create Release Branch**
2. Click **Run workflow**
3. Select the source branch from the **"Use workflow from"** dropdown:
    - `development` for a normal release
    - `main` for a hotfix (patch release on top of what's already in production)
4. Enter the version number (e.g. `2.1.0`) and run

The pipeline validates `next.md`, renames it to `2.1.0.md`, resets both working files to their templates, and pushes the result as a new `release/2.1.0` branch. You can review the branch, make last-minute corrections, or simply proceed.

#### Step 2 — Trigger the release

1. Go to **Actions** → **[MANUAL] - 2. Trigger Release**
2. Click **Run workflow**
3. Select **`release/2.1.0`** from the **"Use workflow from"** dropdown — this is your release selector, there is no separate input field for the branch
4. Run the workflow

The pipeline validates the branch name, runs a Docker build test, then squash-merges the release branch into `main` and back into `development`, pushes the version tag, and deletes the release branch. This tag push automatically triggers the automated release pipeline, which builds the Docker image, creates the GitHub Release, and announces the update on Discord.

> **Hotfix?** The process is identical — the only difference is that you selected `main` as the source in Step 1 instead of `development`. The rest of the workflow is the same.

---

### Versioning convention

Git **tags** and working **branch names** do not carry a `v` prefix. The version `2.1.0` is tagged as `2.1.0` and the release branch is named `release/2.1.0`.

The GitHub Release display name uses the `v` prefix.

| Thing               | Format            | Example                           |
|---------------------|-------------------|-----------------------------------|
| Git tag             | no prefix         | `2.1.0`                           |
| Release branch      | `release/` prefix | `release/2.1.0`                   |
| GitHub Release name | `v` prefix        | `v2.1.0`                          |
| Docker image tag    | no prefix         | `digitalenvironments/hawki:2.1.0` |
