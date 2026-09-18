# Diagrams

Nine diagrams of how zFeeder 2.0 is put together. Each exists twice: a `.mmd`
[Mermaid](https://mermaid.js.org/) source, which is the version under review, and a
rendered `.svg` so the diagram is readable outside GitHub — in an editor, in the release
archive, on the portfolio page.

Edit the `.mmd`, then regenerate:

```bash
./tools/render-diagrams.sh
```

It installs Mermaid into a scratch directory on first run and renders with the browser
the end-to-end tests already use. Nothing is added to the project's dependencies.

| | Diagram | What it answers |
|---|---|---|
| 1 | [Context](01-context.svg) · [src](01-context.mmd) | Who uses zFeeder and what it talks to. Note that every network arrow points outward: nothing calls in except readers and the operator. |
| 2 | [Containers](02-containers.svg) · [src](02-containers.mmd) | What actually runs — a web front controller, a command line tool and an include shim — and the data directory they share. |
| 3 | [Components](03-components.svg) · [src](03-components.mmd) | The code by layer, in the arrangement `deptrac.yaml` enforces. Domain at the bottom depends on nothing; the composition root at the side is allowed to see everything because wiring is its job. |
| 4 | [Request lifecycle](04-request-lifecycle.svg) · [src](04-request-lifecycle.mmd) | What happens between a request and a rendered page, including the conditional fetch, the stale-on-failure path and the single retry for a damaged cache entry. |
| 5 | [Render pipeline](05-render-pipeline.svg) · [src](05-render-pipeline.mmd) | How a template becomes output, and where each value is escaped. The most distinctive part of the program and the one most worth understanding before changing anything. |
| 6 | [Storage ports](06-storage-ports.svg) · [src](06-storage-ports.mmd) | One interface, two adapters, and the abstract test case that proves an installation cannot tell them apart. |
| 7 | [Trust boundaries](07-trust-boundaries.svg) · [src](07-trust-boundaries.mmd) | Where untrusted input crosses into the program and which control stands at each crossing. The companion to [../THREAT-MODEL.md](../THREAT-MODEL.md). |
| 8 | [Deployment](08-deployment.svg) · [src](08-deployment.mmd) | What the container holds, what has to be mounted, and the targets the same image runs on unchanged. |
| 9 | [1.6 to 2.0](09-1-6-to-2-0.svg) · [src](09-1-6-to-2-0.mmd) | What was kept from 2004, what was replaced and why, and the one feature that was removed. Green is kept, red is a defect that had to be closed, grey is gone. |

Diagrams 1 to 3 are also embedded in [../ARCHITECTURE.md](../ARCHITECTURE.md) and diagram 7
in [../THREAT-MODEL.md](../THREAT-MODEL.md), so those documents read on their own.

## Keeping them honest

A diagram that drifts from the code is worse than no diagram. Two of these are checkable
against the tree rather than against memory:

- **Components** must match the layers in `deptrac.yaml`. If you add a layer or an edge
  there, change this diagram in the same commit — `vendor/bin/deptrac analyse` will tell
  you the rule is right, but only a person will notice the picture is stale.
- **Trust boundaries** must match the control table in `docs/THREAT-MODEL.md`, which in
  turn names the test class that proves each control.
