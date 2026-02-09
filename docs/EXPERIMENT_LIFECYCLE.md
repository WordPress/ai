# Experiment Decisions and Lifecycle

This document describes how individual Experiments are proposed, approved, and evolved within the AI Experiments plugin, as well as how a specific Experiment may eventually graduate toward WordPress core.

This is a descriptive guide, not a rigid policy.  The goal is to help contributors understand how things typically work, set reasonable expectations, and reduce uncertainty when proposing or building new Experiments.

The process intentionally favors learning and iteration over certainty.

## How an Experiment Gets Approved for the Plugin

Each Experiment is evaluated independently.  Inclusion in the AI Experiments plugin does not imply endorsement for core, nor a guarantee of long-term support.

In practice, most Experiments follow a three-step path.

### 1. Proposal via GitHub Issue

New Experiments generally start with a [GitHub Issue](https://github.com/WordPress/ai/issues) that outlines the idea and intent.  This issue acts as the shared point of alignment before any meaningful implementation work begins.

A good proposal usually includes:

* The problem being explored and the user value it aims to test
* A short description of the proposed behavior or workflow
* Clear scope boundaries (what is in and out of scope)
* Low-fidelity mockups, diagrams, or written flows where applicable
* Known risks, unknowns, or open questions

At this stage, rough clarity is more important than completeness.  The goal is to decide whether the idea is worth experimenting on at all.

### 2. Implementation via Pull Request

Once there is general agreement on direction, a pull request can be opened to implement the Experiment.

Expectations for Experiments at this stage:

* Code is intentionally scoped and easy to remove or revise
* The Experiment is isolated from unrelated functionality
* Feature flags or Experiment toggles are used where appropriate
* Failure modes are considered and handled safely
* Minimal documentation is added to explain how the Experiment works (examples in `docs/experiments`)

Experiments are expected to evolve.  Early implementations do not need to be production-ready, but they should be reviewable, testable, and understandable by others.

### 3. Review, Testing, and Inclusion

Final approval for inclusion in the plugin is handled by the plugin maintainers listed in the [Credits](../CREDITS.md) file.

When reviewing an Experiment, maintainers typically consider:

* Alignment with the goals of the AI Experiments plugin
* Code quality and long-term maintainability
* User experience and clarity
* Safety, performance, and data handling considerations
* Whether the Experiment is sufficiently self-contained

Once approved, the Experiment ships as part of the plugin and is explicitly treated as experimental.  It may change significantly or be removed entirely based on learnings.

## How an Experiment Graduates Toward WordPress Core

Graduation is optional and uncommon.  Most Experiments are expected to inform future work rather than ship directly to core.

When graduation does happen, it applies to a specific Experiment, not the AI Experiments plugin as a whole.

### 1. Evidence of Value and Stability

Before any merge discussion, an Experiment typically demonstrates:

* Clear user value informed by testing or feedback
* Stability across multiple plugin releases
* Iteration based on real-world usage
* A well-defined and defensible scope

At this point, the Experiment should feel predictable and boring in the best sense.

### 2. Broader Testing via Gutenberg (When Appropriate)

For editor-facing Experiments, the most common next step is migration into the Gutenberg plugin.

This allows:

* Significantly broader testing than AI Experiments alone
* Use of established Gutenberg Experiment and feedback workflows
* Closer alignment with editor UX patterns and architecture

Not every Experiment requires this step, but it is the preferred path before proposing a core merge.

### 3. Proposal to WordPress Core

A proposal to merge into WordPress core should focus on a single Experiment and follow the [Feature Plugin Merge Criteria](https://make.wordpress.org/core/handbook/about/release-cycle/features-as-plugins/#feature-plugin-merge-criteria) used by core.

The proposal typically documents:

* The exact feature being proposed
* Why it belongs in core
* How it aligns with WordPress project goals
* Remaining risks or open questions
* A clear ownership and maintenance plan

Acceptance into core is ultimately a core leadership decision and may require further iteration or restructuring.

## Important Notes

* Experiments may be deprecated or removed at any time
* Not all Experiments are intended to graduate to core, in fact most are never expected to and instead are expected to stay within the plugin
* Learning and validation are considered successful outcomes

The AI Experiments plugin exists to make it safe to explore ideas early, learn quickly, and improve the quality of features that may eventually reach WordPress core.
