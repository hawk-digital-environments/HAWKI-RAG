# Website

The [Docusaurus](https://docusaurus.io/) build reads the handbook and changelog
from the parent directory. Run the following commands from this directory.

### Installation

```
npm ci
```

### Local Development

```
npm start
```

The development server reloads when documentation files change.

### Build

```
npm run build
```

The build writes static files to `build`. The production build also checks
the documentation links.

### Deployment

For a manual deployment over SSH, run the deploy script with `USE_SSH`.

```
USE_SSH=true npm run deploy
```

For a manual deployment over HTTPS, set `GIT_USER`.

```
GIT_USER=your-github-username npm run deploy
```

The repository also has a documentation publishing workflow under
`.github/workflows/publish-documentation.yml`.
