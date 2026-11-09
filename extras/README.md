# extras/ — not part of the SEOlity plugin

Kept only so nothing from the original repository is lost. Nothing here runs automatically.

| Folder | What | Notes |
|---|---|---|
| `aria-ai-writer-runner/` | The GitHub Actions runner of the sister plugin **ARIAGH.AIWRITER** (`runner.mjs`, `aria-writer.yml`, its README) | In the original repository these lived at `runner/runner.mjs`, `.github/workflows/aria-writer.yml` and `README.md` and were written there automatically by the AI Writer plugin. Do not edit for SEOlity work. |
| `github-workflows/seolity-knowledge.yml` | Identical copy of `seolity/runner/seolity-knowledge.yml` (SEOlity knowledge builder workflow) | Moved out of `.github/workflows/` so it does not run weekly (and fail without secrets) in a backup repository. The plugin deploys the workflow itself into the repository chosen in its settings. To run it in this repository, copy it back to `.github/workflows/` and add the secrets listed in its header. |
