# Experiment Documentation

Every experiment in this plugin must ship with a companion doc in this directory. These short references make it easier to understand what a feature does without digging through PHP or JS files.

## Format

Each markdown file should follow this naming scheme:

```
docs/experiments/<experiment-id>.md
```

Recommended sections:

1. **Summary** – One paragraph that explains the goal of the experiment and the WordPress surface it touches.
2. **Key Hooks & Entry Points** – The PHP hooks, REST routes, or filters that attach the experiment to WordPress.
3. **Assets & Data Flow** – JS/CSS entry points, localized data, and ability/REST endpoints it calls.
4. **Testing** – Manual steps to exercise the feature and expected outcomes.
5. **Notes** – Edge cases, feature flags, or future TODOs.

When you add a new experiment:

- Create the markdown file alongside your code changes.
- Link to it from pull requests or issue threads for quick context.
- Update the file whenever the experiment’s UX or architecture changes.
